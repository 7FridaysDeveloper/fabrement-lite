<?php

namespace Fabrement\Tests\Wp;

use WP_UnitTestCase;

/**
 * The form tools against the real Contact Form 7 (vendor/wp-plugins/contact-form-7, pinned in
 * composer.lock): a form an agent writes is a CF7 form CF7 itself reads back.
 *
 * Skipped when the suite runs without CF7 (FABREMENT_TEST_PLUGINS=none).
 */
final class Cf7FormsTest extends WP_UnitTestCase {

    private const TEMPLATE = '<div class="row">[text* your-name]</div><div class="row">[email* your-email]</div><div class="row">[textarea your-message]</div>[submit "Send"]';

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPCF7_VERSION' ) ) {
            $this->markTestSkipped( 'Contact Form 7 is not loaded in this run.' );
        }
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    /** @return mixed */
    private function tool( string $name, array $input = [] ) {
        return wp_get_ability( 'fabrement/' . $name )->execute( $input );
    }

    public function test_a_form_is_created_read_listed_updated_and_deleted_through_cf7(): void {
        $made = $this->tool( 'create-cf7-form', [ 'title' => 'QA contact', 'template' => self::TEMPLATE, 'recipient' => 'qa@example.org', 'subject' => 'QA subject' ] );
        $this->assertFalse( is_wp_error( $made ), is_wp_error( $made ) ? $made->get_error_message() : '' );
        $id = (int) $made['form']['id'];

        $form = \WPCF7_ContactForm::get_instance( $id );
        $this->assertInstanceOf( \WPCF7_ContactForm::class, $form, 'CF7 reads it' );
        $this->assertSame( 'QA contact', $form->title() );
        $this->assertSame( self::TEMPLATE, $form->prop( 'form' ) );
        $mail = $form->prop( 'mail' );
        $this->assertSame( 'qa@example.org', $mail['recipient'] );
        $this->assertStringContainsString( '[your-email]', (string) $mail['additional_headers'], 'Reply-To from the first required email' );
        $this->assertStringContainsString( '[your-message]', (string) $mail['body'] );
        $this->assertStringContainsString( 'contact-form-7 id=', (string) $made['form']['shortcode'] );

        $listed = $this->tool( 'list-forms' );
        $this->assertTrue( $listed['cf7']['active'] );
        $this->assertContains( $id, array_map( static fn( $f ) => (int) $f['id'], $listed['cf7']['forms'] ) );

        $this->assertSame( 'QA contact', $this->tool( 'get-cf7-form', [ 'formId' => $id ] )['form']['title'] );

        $updated = $this->tool( 'update-cf7-form', [ 'formId' => $id, 'template' => '[email* your-email][submit "Go"]', 'messages' => [ 'mail_sent_ok' => 'QA thanks' ] ] );
        $this->assertFalse( is_wp_error( $updated ), is_wp_error( $updated ) ? $updated->get_error_message() : '' );
        $form = \WPCF7_ContactForm::get_instance( $id );
        $this->assertStringContainsString( '[submit "Go"]', $form->prop( 'form' ) );
        $this->assertSame( 'QA thanks', $form->prop( 'messages' )['mail_sent_ok'] );
        $this->assertSame( 'QA contact', $form->title(), 'what was not sent stays' );

        $this->assertTrue( (bool) $this->tool( 'delete-cf7-form', [ 'formId' => $id ] )['deleted'] );
        $this->assertNull( get_post( $id ) );
    }

    public function test_an_update_of_messages_only_answers_with_the_forms_fields_and_keeps_its_words(): void {
        $made = $this->tool( 'create-cf7-form', [ 'title' => 'QA words', 'template' => self::TEMPLATE, 'recipient' => 'qa@example.org' ] );
        $this->assertFalse( is_wp_error( $made ), is_wp_error( $made ) ? $made->get_error_message() : '' );
        $id = (int) $made['form']['id'];

        $updated = $this->tool( 'update-cf7-form', [ 'formId' => $id, 'title' => 'QA words 2', 'messages' => [ 'mail_sent_ok' => 'Thanks <you> & bye, kids <12' ] ] );
        $this->assertFalse( is_wp_error( $updated ), is_wp_error( $updated ) ? $updated->get_error_message() : '' );
        $this->assertSame( $made['form']['fields'], $updated['form']['fields'], 'the fields as they stand, not an empty list' );
        $this->assertSame( 'Thanks <you> & bye, kids <12', \WPCF7_ContactForm::get_instance( $id )->prop( 'messages' )['mail_sent_ok'], 'as Contact Form 7 keeps a message' );
    }

    public function test_a_control_named_after_a_query_var_is_refused_not_renamed(): void {
        $out = $this->tool( 'create-cf7-form', [ 'title' => 'QA bad', 'template' => '[text* name][submit "Send"]' ] );

        $this->assertWPError( $out );
        $this->assertStringContainsString( 'name', $out->get_error_message() );
    }
}
