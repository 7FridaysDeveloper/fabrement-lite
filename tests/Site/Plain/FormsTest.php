<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Contact Form 7 forms as an agent makes them on a real site: create-cf7-form writes a form CF7
 * itself keeps (its post and meta), a page carries it with the shortcode the tool answered, a
 * visitor gets CF7's form and sends it to CF7's own endpoint, the agent changes the form and its
 * messages, and deleting it takes it off the page.
 */
final class FormsTest extends SiteTestCase {

    private const TEMPLATE = '<div class="row">[text* your-name]</div><div class="row">[email* your-email]</div><div class="row">[textarea your-message]</div>[submit "Send"]';

    /** A CF7 meta value as CF7 stores it (unserialized when it is an array). */
    private function cf7_meta( int $form, string $key ) {
        $raw = $this->site()->meta( $form, $key )[0] ?? null;
        if ( $raw === null ) {
            return null;
        }
        $value = @unserialize( $raw, [ 'allowed_classes' => false ] );
        return $value === false && $raw !== 'b:0;' ? $raw : $value;
    }

    private function form_count(): int {
        return (int) $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wpcf7_contact_form'" );
    }

    /** A published page whose content is the form's shortcode, as the agent places it. @return string Its address. */
    private function page_with( string $title, string $shortcode ): string {
        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'title' => $title, 'blocks' => [
            [ 'markup' => '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->' ],
        ] ] )['contentId'];
        return $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] )['url'];
    }

    /** What a browser's CF7 script posts: the form's hidden inputs from the page, plus the visitor's values. */
    private function send( int $form, string $html, array $values ): array {
        preg_match( '#<form[^>]*class="[^"]*wpcf7-form.*?</form>#s', $html, $markup );
        $hidden = [];
        preg_match_all( '#<input[^>]*type="hidden"[^>]*>#', $markup[0] ?? '', $inputs );
        foreach ( $inputs[0] as $input ) {
            if ( preg_match( '#name="([^"]+)"#', $input, $name ) && preg_match( '#value="([^"]*)"#', $input, $value ) ) {
                $hidden[ html_entity_decode( $name[1] ) ] = html_entity_decode( $value[1] );
            }
        }
        $this->assertArrayHasKey( '_wpcf7_unit_tag', $hidden, 'CF7 printed its hidden inputs' );
        // CF7's script posts FormData; its REST route refuses any other payload (415).
        $boundary = 'qa' . bin2hex( random_bytes( 8 ) );
        $body     = '';
        foreach ( $values + $hidden as $name => $value ) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $answer = Http::request( $this->site(), 'POST', $this->site()->url( '/wp-json/contact-form-7/v1/contact-forms/' . $form . '/feedback' ),
            [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ], $body . "--$boundary--\r\n" );
        $this->assertSame( 200, $answer['status'], $answer['body'] );
        return (array) json_decode( $answer['body'], true );
    }

    public function test_a_form_lives_from_creation_through_a_visitor_to_deletion(): void {
        // Made: a CF7 form, with the mail derived from its controls.
        $made = $this->mcp( 'create-cf7-form', [ 'title' => 'QA contact', 'template' => self::TEMPLATE, 'recipient' => 'qa@example.org', 'subject' => 'QA subject' ] );
        $id   = (int) $made['form']['id'];
        $this->assertSame( [ 'your-name', 'your-email', 'your-message' ], $made['form']['fields'] );
        $this->assertStringContainsString( 'contact-form-7 id=', $made['form']['shortcode'] );

        $this->assertSame( 'wpcf7_contact_form', $this->site()->value( 'SELECT post_type FROM wp_posts WHERE ID = ?', [ $id ] ) );
        $this->assertSame( 'QA contact', $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $id ] ) );
        $this->assertSame( self::TEMPLATE, $this->cf7_meta( $id, '_form' ), 'CF7 keeps the markup as written' );
        $mail = $this->cf7_meta( $id, '_mail' );
        $this->assertSame( 'qa@example.org', $mail['recipient'] );
        $this->assertSame( 'QA subject', $mail['subject'] );
        $this->assertStringContainsString( '[your-email]', (string) $mail['additional_headers'], 'Reply-To from the first required email' );
        $this->assertStringContainsString( '[your-message]', (string) $mail['body'] );

        // Read and listed by the agent.
        $listed = $this->mcp( 'list-forms' );
        $this->assertTrue( $listed['cf7']['active'] );
        $row = array_column( $listed['cf7']['forms'], null, 'id' )[ $id ] ?? null;
        $this->assertNotNull( $row, 'listed' );
        $this->assertStringContainsString( 'your-email', $row['html'], 'the rendered markup, for styling' );
        $read = $this->mcp( 'get-cf7-form', [ 'formId' => $id ] )['form'];
        $this->assertSame( 'QA contact', $read['title'] );
        $this->assertSame( self::TEMPLATE, $read['form'] );
        $this->assertSame( 'qa@example.org', $read['mail']['recipient'] );

        // On a page: the visitor gets CF7's form inside the agent's own wrappers.
        $url  = $this->page_with( 'QA contact page', $made['form']['shortcode'] );
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'] );
        $this->assertMatchesRegularExpression( '#<form[^>]*class="[^"]*wpcf7-form#', $page['body'] );
        foreach ( [ 'your-name', 'your-email', 'your-message' ] as $control ) {
            $this->assertStringContainsString( 'name="' . $control . '"', $page['body'] );
        }
        $this->assertStringContainsString( '<div class="row">', $page['body'], 'the layout is the agent\'s' );
        $this->assertMatchesRegularExpression( '#<input[^>]*type="submit"[^>]*value="Send"#', $page['body'] );

        // The visitor sends it without the required name: CF7 refuses it with its own message.
        $feedback = $this->send( $id, $page['body'], [ 'your-name' => '', 'your-email' => 'visitor@example.org', 'your-message' => 'Hi' ] );
        $this->assertSame( 'validation_failed', $feedback['status'] );
        $this->assertContains( 'your-name', array_column( (array) $feedback['invalid_fields'], 'field' ) );

        // Changed: a new template and a message; what was not sent stays.
        $updated = $this->mcp( 'update-cf7-form', [ 'formId' => $id, 'template' => '<p class="qa-line">[email* your-email]</p>[submit "Go"]',
            'messages' => [ 'mail_sent_ok' => 'QA thanks', 'validation_error' => 'QA please check the fields' ] ] );
        $this->assertSame( [ 'your-email' ], $updated['form']['fields'] );
        $this->assertSame( 'QA thanks', $updated['form']['messages']['mail_sent_ok']['text'] ?? $updated['form']['messages']['mail_sent_ok'] );
        $this->assertStringContainsString( '[submit "Go"]', $this->cf7_meta( $id, '_form' ) );
        $this->assertSame( 'QA thanks', $this->cf7_meta( $id, '_messages' )['mail_sent_ok'] );
        $this->assertSame( 'QA contact', $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $id ] ), 'the title stays' );
        $this->assertSame( 'qa@example.org', $this->cf7_meta( $id, '_mail' )['recipient'], 'the recipient stays' );
        $this->assertStringContainsString( 'not a valid email address', $this->mcp_error( 'update-cf7-form', [ 'formId' => $id, 'recipient' => 'not-an-address' ] ) );
        $this->assertSame( 'qa@example.org', $this->cf7_meta( $id, '_mail' )['recipient'], 'a bad recipient changes nothing' );

        $page = $this->get( $url );
        $this->assertStringNotContainsString( 'name="your-name"', $page['body'], 'the visitor gets the new form' );
        $this->assertStringContainsString( 'class="qa-line"', $page['body'] );
        $this->assertMatchesRegularExpression( '#<input[^>]*type="submit"[^>]*value="Go"#', $page['body'] );
        $feedback = $this->send( $id, $page['body'], [ 'your-email' => 'not an address' ] );
        $this->assertSame( 'validation_failed', $feedback['status'] );
        $this->assertSame( 'QA please check the fields', $feedback['message'], 'the message the agent set is what the visitor reads' );

        // An unknown message key is refused and changes nothing.
        $before = $this->cf7_meta( $id, '_messages' );
        $this->assertStringContainsString( 'Unknown message key', $this->mcp_error( 'update-cf7-form', [ 'formId' => $id, 'title' => 'QA renamed', 'messages' => [ 'qa_no_such_key' => 'x' ] ] ) );
        $this->assertSame( $before, $this->cf7_meta( $id, '_messages' ) );
        $this->assertSame( 'QA contact', $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $id ] ) );

        // Deleted: gone from CF7, from the agent's list and from the page.
        $this->assertTrue( (bool) $this->mcp( 'delete-cf7-form', [ 'formId' => $id ] )['deleted'] );
        $this->assertNull( $this->site()->value( 'SELECT ID FROM wp_posts WHERE ID = ?', [ $id ] ) );
        $this->assertNotContains( $id, array_column( $this->mcp( 'list-forms' )['cf7']['forms'], 'id' ) );
        $this->assertTrue( $this->client()->call( 'get-cf7-form', [ 'formId' => $id ] )['error'], 'get-cf7-form no longer reads it' );
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], 'the page stays' );
        $this->assertDoesNotMatchRegularExpression( '#<form[^>]*class="[^"]*wpcf7-form#', $page['body'], 'no form on it any more' );
    }

    public function test_a_messages_only_update_keeps_the_controls_and_the_words_as_typed(): void {
        $made = $this->mcp( 'create-cf7-form', [ 'title' => 'QA words', 'template' => self::TEMPLATE, 'recipient' => 'qa@example.org' ] );
        $id   = (int) $made['form']['id'];

        $updated = $this->mcp( 'update-cf7-form', [ 'formId' => $id, 'title' => 'QA words 2', 'messages' => [ 'mail_sent_ok' => 'Thanks <you> & bye, kids <12' ] ] );
        $this->assertSame( $made['form']['fields'], $updated['form']['fields'], 'the fields as they stand, not an empty list' );
        $this->assertSame( 'Thanks <you> & bye, kids <12', $this->cf7_meta( $id, '_messages' )['mail_sent_ok'], 'as Contact Form 7 keeps a message' );
        $this->assertSame( 'Thanks <you> & bye, kids <12', $this->mcp( 'get-cf7-form', [ 'formId' => $id ] )['form']['messages']['mail_sent_ok']['text'] );
        $this->assertSame( self::TEMPLATE, $this->cf7_meta( $id, '_form' ), 'the template untouched' );
        $this->assertSame( 'QA words 2', $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $id ] ) );
    }

    public function test_a_control_named_after_a_query_var_is_refused_not_renamed(): void {
        $before = $this->form_count();
        $error  = $this->mcp_error( 'create-cf7-form', [ 'title' => 'QA bad', 'template' => '[text* name][submit "Send"]' ] );
        $this->assertStringContainsString( 'name', $error );
        $this->assertSame( $before, $this->form_count(), 'no form was made' );
        $this->assertNotContains( 'QA bad', array_column( $this->mcp( 'list-forms' )['cf7']['forms'], 'title' ) );

        // The same rule on an update: the form keeps what it had.
        $made = $this->mcp( 'create-cf7-form', [ 'title' => 'QA kept', 'template' => self::TEMPLATE ] );
        $this->mcp_error( 'update-cf7-form', [ 'formId' => (int) $made['form']['id'], 'template' => '[text* search][submit "Send"]' ] );
        $this->assertSame( self::TEMPLATE, $this->cf7_meta( (int) $made['form']['id'], '_form' ) );
    }
}
