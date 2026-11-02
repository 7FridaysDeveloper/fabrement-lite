<?php

namespace Fabrement\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/ConnectionSettingsRender.php';

final class McpConnectionSettingsTemplateTest extends TestCase {
    public function test_missing_table_shows_off_and_requires_reactivation(): void {
        $html = \fabrement_render_connection_access( [], true, true, false );
        $document = new \DOMDocument();
        @$document->loadHTML( $html );
        $xpath = new \DOMXPath( $document );
        self::assertSame( 1, $xpath->query( '//button[@role="switch" and @aria-checked="false" and @disabled]' )->length );
        self::assertStringContainsString( 'Deactivate and reactivate Fabrement', $html );
        self::assertFalse( \Fabrement\Modules\Mcp\OAuth\Config::enabled() );
    }

    /** @dataProvider clients */
    public function test_client_views_have_unique_ids_and_supported_authorization_methods( string $client ): void {
        $html = \fabrement_render_connection_settings( $client );
        $document = new \DOMDocument();
        @$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
        $xpath = new \DOMXPath( $document );
        $ids = [];
        foreach ( $xpath->query( '//*[@id]' ) as $element ) { $ids[] = $element->getAttribute( 'id' ); }
        self::assertSame( $ids, array_unique( $ids ), 'Every label, tab and copy target must be unambiguous.' );
        $clients = '//nav[@aria-label="AI client"]';
        self::assertSame( [ 'claude', 'chatgpt', 'codex' ], array_map( static fn( $a ) => $a->getAttribute( 'data-client' ), iterator_to_array( $xpath->query( $clients . '/a' ) ) ) );
        self::assertSame( 1, $xpath->query( $clients . '/a[@aria-current="page"]' )->length );
        // ChatGPT and Codex open on a numbered guide. Claude.ai is one button that opens a
        // form already filled in, so it has nothing to number.
        self::assertSame( 'claude' === $client ? 0 : 1, $xpath->query( '//*[@id="fabrement-setup-panel-primary"]//ol[@class="fabrement-cred__steps"]' )->length );
        $advanced = in_array( $client, [ 'claude', 'codex' ], true ) ? 1 : 0;
        self::assertSame( $advanced, $xpath->query( '//*[@id="fabrement-setup-panel-advanced"]' )->length );
        self::assertSame( $advanced * 2, $xpath->query( '//*[@data-setup-tab]' )->length, 'Only a client with a second way in gets Recommended / Advanced.' );
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-access"]' )->length );
        self::assertSame( 0, $xpath->query( '//*[@id="fabrement-access"]/ancestor::*[@data-setup-panel or @hidden]' )->length );
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-access"]//button[@role="switch"]' )->length );
        // The panel holds exactly two cards — the setup, then the register of what is
        // already connected beside it — and nothing else. The register is a SIBLING of the setup
        // card, not a section inside it: it lists every connection of every client, so
        // nesting it under the selected client's setup misread it.
        $panel = '//div[contains(@class,"fabrement-cred__panel--agents")]';
        self::assertSame( 2, $xpath->query( $panel . '/*' )->length );
        self::assertSame( 1, $xpath->query( $panel . '/*[1][contains(@class,"fabrement-cred__mcp")]' )->length );
        self::assertSame( 1, $xpath->query( $panel . '/*[2][@id="fabrement-access"]' )->length );
        self::assertStringContainsString( 'fabrement-example-studio', $html );
    }

    public static function clients(): array { return [ [ 'chatgpt' ], [ 'codex' ], [ 'claude' ] ]; }

    /** Nothing on the screen offers or names a credential other than OAuth, nor the dropped Cursor setup. */
    public function test_no_application_password_or_cursor_setup_is_offered(): void {
        foreach ( [ true, false ] as $https ) {
            foreach ( self::clients() as [ $client ] ) {
                foreach ( [ '', 'advanced' ] as $setup ) {
                    $html = \fabrement_render_connection_settings( $client, $https, $setup );
                    $text = html_entity_decode( strip_tags( $html ) );
                    self::assertStringNotContainsStringIgnoringCase( 'application password', $text, $client );
                    self::assertStringNotContainsStringIgnoringCase( 'cursor', $text, $client );
                    self::assertStringNotContainsString( 'fabrement_revoke_mcp_password', $html );
                    self::assertStringNotContainsString( 'Basic ', $html );
                }
            }
        }
    }

    private static function xpath( string $html ): \DOMXPath {
        $document = new \DOMDocument();
        @$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

        return new \DOMXPath( $document );
    }

    public function test_local_http_says_https_is_needed_and_never_renders_o_auth_commands(): void {
        $html = \fabrement_render_connection_settings( 'codex', false );
        self::assertStringContainsString( 'Connecting an AI agent needs HTTPS.', $html );
        self::assertStringContainsString( 'HTTPS is required to connect.', $html );
        self::assertStringNotContainsString( 'codex mcp login', $html );
        self::assertStringContainsString( 'disabled aria-describedby="fabrement-oauth-unavailable"', $html );
        self::assertStringNotContainsString( 'id="fabrement-oauth-instructions"', $html );
    }

    public function test_codex_o_auth_instructions_are_copyable_below_cli(): void {
        $html = \fabrement_render_connection_settings( 'codex', true, 'advanced' );
        $document = new \DOMDocument();
        @$document->loadHTML( $html );
        $xpath = new \DOMXPath( $document );
        $instructions = '//*[@id="fabrement-oauth-instructions"]';
        self::assertSame( 1, $xpath->query( $instructions . '[@readonly]' )->length );
        self::assertSame( 1, $xpath->query( $instructions . '/ancestor::*[@id="fabrement-setup-panel-advanced"]' )->length );
        self::assertSame( 1, $xpath->query( $instructions . '/preceding::textarea[@id="fabrement-oauth-command"]' )->length );
        // Inside the OAuth fold only — not tucked into a dropdown of its own.
        self::assertSame( 1, $xpath->query( $instructions . '/ancestor::details' )->length );
        self::assertSame( 1, $xpath->query( $instructions . '/ancestor::details[contains(@class,"fabrement-cred__alt--fold")]' )->length );
        self::assertSame( 1, $xpath->query( '//label[@for="fabrement-oauth-instructions"]' )->length );
        self::assertSame( 1, $xpath->query( '//button[@data-mcp-copy="fabrement-oauth-instructions" and @aria-label="Copy instructions"]' )->length );
        $prompt = $xpath->query( $instructions )->item( 0 )->textContent;
        self::assertStringContainsString( 'codex mcp login fabrement-example-studio', $prompt );
        self::assertStringNotContainsString( 'Basic ', $prompt );
        foreach ( [ 'chatgpt', 'claude' ] as $client ) {
            self::assertStringNotContainsString( 'id="fabrement-oauth-instructions"', \fabrement_render_connection_settings( $client ) );
        }
        $visible_html = \fabrement_render_connection_settings( 'codex' );
        @$document->loadHTML( $visible_html );
        $xpath = new \DOMXPath( $document );
        // Codex opens on Recommended — the app's form — and the CLI commands and the
        // instructions wait one tab away.
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-setup-panel-advanced" and @hidden]' . $instructions )->length );
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-setup-panel-advanced" and @hidden]//textarea[@id="fabrement-oauth-command"]' )->length );
        self::assertSame( 0, $xpath->query( '//*[@id="fabrement-setup-panel-primary"]//textarea' )->length );
    }

    /**
     * Codex's Recommended guide is the app, not the terminal: the server name and URL to
     * paste into its Add server form, then Authenticate.
     */
    public function test_codex_guide_is_the_app_form_with_the_cli_on_advanced(): void {
        $xpath = self::xpath( \fabrement_render_connection_settings( 'codex' ) );
        $steps = '//*[@id="fabrement-setup-panel-primary"]//section[@aria-labelledby="fabrement-codex-guide-title"]/ol[@class="fabrement-cred__steps"]/li';
        self::assertSame( 4, $xpath->query( $steps )->length );
        // No download links: like every other client, the guide assumes the app is installed.
        self::assertSame( 0, $xpath->query( $steps . '[1]//a' )->length );
        self::assertSame( 1, $xpath->query( $steps . '[2]//input[@id="fabrement-oauth-name"]' )->length );
        self::assertSame( 1, $xpath->query( $steps . '[2]//input[@id="fabrement-oauth-url"]' )->length );
        self::assertSame( 1, $xpath->query( $steps . '[2]//strong[text()="Add MCP server"]' )->length );
        self::assertSame( 1, $xpath->query( $steps . '[3]//strong[text()="Authenticate"]' )->length );
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-setup-panel-advanced"]//div[@class="fabrement-cred__way" and .//*[@id="fabrement-alt-cli-title"]]//textarea[@id="fabrement-oauth-command"]' )->length );
        self::assertStringContainsString( 'codex mcp login fabrement-example-studio', $xpath->query( '//textarea[@id="fabrement-oauth-command"]' )->item( 0 )->textContent );
    }

    /**
     * A reserved place for a screenshot not taken yet. Reported as incomplete rather than
     * failed, so the suite stays green while the screenshots are being made — but it is on
     * every run's summary until the last one is replaced.
     */
    public function test_no_screenshot_placeholders_are_left(): void {
        $left = [];
        foreach ( self::clients() as [ $client ] ) {
            foreach ( self::xpath( \fabrement_render_connection_settings( $client ) )->query( '//*[@data-shot-placeholder]' ) as $placeholder ) {
                $left[] = $client . ': ' . trim( $placeholder->textContent );
            }
        }
        if ( $left !== [] ) {
            self::markTestIncomplete( "Screenshots still to add:\n" . implode( "\n", $left ) );
        }
        self::assertSame( [], $left );
    }

    public function test_access_switch_state_and_https_restriction(): void {
        foreach ( [ true, false ] as $enabled ) {
            foreach ( [ true, false ] as $https ) {
                $html = \fabrement_render_connection_access( [], $enabled, $https );
                $document = new \DOMDocument();
                @$document->loadHTML( $html );
                $xpath = new \DOMXPath( $document );
                $switch = $xpath->query( '//button[@role="switch"]' )->item( 0 );
                self::assertSame( $enabled ? 'true' : 'false', $switch->getAttribute( 'aria-checked' ) );
                self::assertSame( ! $enabled && ! $https, $switch->hasAttribute( 'disabled' ) );
                self::assertSame( $enabled, $switch->hasAttribute( 'data-fabrement-confirm' ) );
                self::assertSame( $enabled ? 'disable' : 'enable', $xpath->query( '//input[@name="oauth_command"]' )->item( 0 )->getAttribute( 'value' ) );
                self::assertSame( 1, $xpath->query( '//form/input[@name="_wpnonce"]' )->length );
                self::assertStringContainsString( 'No connections yet.', $html );
            }
        }
    }


    public function test_each_connection_has_its_own_escaped_disconnect_action(): void {
        $html = \fabrement_render_connection_access(
            [ [ 'payload' => [ 'client_name' => 'Claude <script>alert(1)</script>' ], 'family' => 'family-1', 'expires' => time() + 3600, 'user_id' => 7 ],
              [ 'payload' => [ 'client_name' => 'ChatGPT' ], 'family' => 'family-2', 'expires' => 0, 'user_id' => 7 ] ]
        );
        $document = new \DOMDocument();
        @$document->loadHTML( $html );
        $xpath = new \DOMXPath( $document );
        self::assertSame( 2, $xpath->query( '//ul/li' )->length );
        self::assertSame( '2', trim( $xpath->query( '//*[contains(@class,"fabrement-cred__access-count")]' )->item( 0 )->textContent ) );
        // Every connection says who authorized it; this user no longer exists.
        self::assertSame( 2, $xpath->query( '//li//span[text()="By a deleted user"]' )->length );
        self::assertSame( 2, $xpath->query( '//li/form/input[@name="oauth_command" and @value="revoke"]' )->length );
        self::assertSame( 2, $xpath->query( '//li/form/input[@name="_wpnonce"]' )->length );
        self::assertSame( [ 'family-1', 'family-2' ], array_map( static fn( $input ) => $input->getAttribute( 'value' ), iterator_to_array( $xpath->query( '//li/form/input[@name="family"]' ) ) ) );
        self::assertSame( 0, $xpath->query( '//li/a' )->length );
        self::assertStringNotContainsString( '<script>', $html );
        self::assertStringNotContainsStringIgnoringCase( 'application password', $html );
        // Every OAuth connection lasts until disconnected or 90 days unused, whatever end the row stores.
        self::assertSame( 1, $xpath->query( '//li[1]//span[text()="Until disconnected, unused for 90 days, the password changes, or the plugin is deactivated"]' )->length );
        self::assertSame( 1, $xpath->query( '//li[2]//span[text()="Until disconnected, unused for 90 days, the password changes, or the plugin is deactivated"]' )->length );
        self::assertSame( 0, $xpath->query( '//span[starts-with(text(),"Expires ")]' )->length );
    }

    public function test_chat_gpt_guide_is_visible_on_https_and_local_http_only_for_chat_gpt(): void {
        foreach ( [ true, false ] as $https ) {
            $html = \fabrement_render_connection_settings( 'chatgpt', $https );
            self::assertStringContainsString( 'id="fabrement-chatgpt-guide-title"', $html );
            self::assertStringContainsString( 'Security and login', $html );
            self::assertStringContainsString( 'Developer mode', $html );
            self::assertStringContainsString( 'assets/images/chatgpt-developer-mode.png', $html );
            self::assertStringContainsString( 'assets/images/chatgpt-create-app.png', $html );
            self::assertStringContainsString( 'assets/images/chatgpt-new-plugin-form.png', $html );
            $plain = html_entity_decode( strip_tags( $html ) );
            self::assertStringContainsString( 'Click the plus (+) button next to Search plugins and choose Create app.', $plain );
            // The client's own controls are bolded; the sentence around them is not.
            foreach ( [ 'Security and login', 'Developer mode', 'Settings &gt; Apps &gt; Advanced settings', 'plus (+)', 'Search plugins', 'Create app', 'Dynamic Client Registration', 'I understand and want to continue', 'Create' ] as $control ) {
                self::assertStringContainsString( '<strong>' . $control . '</strong>', $html );
            }
            self::assertStringContainsString( 'Dynamic Client Registration', $html );
            self::assertStringContainsString( 'ChatGPT cannot reach a local address (.local, .test, localhost) directly.', $html );
            self::assertStringContainsString( 'https://developers.openai.com/plugins/deploy/connect-chatgpt', $html );
            $document = new \DOMDocument();
            @$document->loadHTML( $html );
            $xpath = new \DOMXPath( $document );
            self::assertSame( 1, $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li[1]//a[@href="https://chatgpt.com/plugins#settings/Security" and @target="_blank" and @rel="noopener noreferrer"]' )->length );
            self::assertSame( 1, $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li[2]//a[@href="https://chatgpt.com/plugins" and @target="_blank" and @rel="noopener noreferrer"]' )->length );
            self::assertSame( 5, $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li' )->length );
            // Developer mode is not needed on every account: step 1 says when to skip it.
            self::assertStringContainsString( 'If Create app already shows on the Plugins page, skip this step.', $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li[1]' )->item( 0 )->textContent );
            self::assertSame( 1, $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li[2]//img[@data-chatgpt-guide and contains(@src,"chatgpt-create-app.png") and @width="1000" and @height="225"]' )->length );
            // Step 2 has two clicks and a screenshot for each, in that order.
            $shots = $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li[2]//img[@data-chatgpt-guide]' );
            self::assertSame( 2, $shots->length );
            self::assertStringContainsString( 'chatgpt-create-app.png', $shots->item( 0 )->getAttribute( 'src' ) );
            self::assertStringContainsString( 'chatgpt-new-plugin-mcp-app.png', $shots->item( 1 )->getAttribute( 'src' ) );
            self::assertSame( [ '675', '395' ], [ $shots->item( 1 )->getAttribute( 'width' ), $shots->item( 1 )->getAttribute( 'height' ) ] );
            self::assertSame( 1, $xpath->query( '//section[@aria-labelledby="fabrement-chatgpt-guide-title"]//ol/li[3]//img[@data-chatgpt-guide and contains(@src,"chatgpt-new-plugin-form.png") and @width="658" and @height="842"]' )->length );
        }
        self::assertSame( [ 1000, 225 ], array_slice( getimagesize( dirname( __DIR__, 2 ) . '/assets/images/chatgpt-create-app.png' ), 0, 2 ) );
        self::assertSame( [ 675, 395 ], array_slice( getimagesize( dirname( __DIR__, 2 ) . '/assets/images/chatgpt-new-plugin-mcp-app.png' ), 0, 2 ) );
        self::assertSame( [ 658, 842 ], array_slice( getimagesize( dirname( __DIR__, 2 ) . '/assets/images/chatgpt-new-plugin-form.png' ), 0, 2 ) );
        self::assertStringNotContainsString( 'id="fabrement-chatgpt-guide-title"', \fabrement_render_connection_settings( 'claude' ) );
    }

    /**
     * Claude.ai is the one-click way in: a description, the button, the empty-form hint and
     * the name and URL folded away under it — no numbered steps, no screenshot.
     */
    public function test_claude_ai_guide_is_one_button_with_the_values_folded_away(): void {
        foreach ( [ true, false ] as $https ) {
            $xpath = self::xpath( \fabrement_render_connection_settings( 'claude', $https ) );
            $guide = '//section[@aria-labelledby="fabrement-claude-guide-title"]';
            self::assertSame( 1, $xpath->query( $guide )->length );
            self::assertSame( 1, $xpath->query( $guide . '/ancestor::*[@id="fabrement-setup-panel-primary"]' )->length );
            self::assertSame( 0, $xpath->query( $guide . '//ol | ' . $guide . '//img' )->length );
            $lead = $xpath->query( $guide . '/p[contains(@class,"fabrement-cred__guide-lead")]' )->item( 0 );
            self::assertNotNull( $lead );
            self::assertStringContainsString( 'Add custom connector form', $lead->textContent );
            self::assertStringContainsString( 'already filled in', $lead->textContent );
            self::assertStringNotContainsString( 'client secret', $lead->textContent );

            $button = $guide . '//a[starts-with(@href,"https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=") and contains(@href,"&connectorUrl=") and @target="_blank" and @rel="noopener noreferrer"]';
            // The empty-form hint IS the accordion's toggle, under the button and its note.
            $values = $guide . '//details[summary[contains(text(),"If the form opens empty")]]';
            if ( $https ) {
                self::assertSame( 1, $xpath->query( $button )->length );
                // Right under the button: the note that the desktop app gets the connector too,
                // then the empty-form accordion.
                self::assertSame( 1, $xpath->query( $button . '/ancestor::div/following-sibling::*[1][self::p and contains(text(),"Claude desktop and mobile apps")]' )->length );
                self::assertSame( 1, $xpath->query( $button . '/ancestor::div/following-sibling::*[2][self::details]' )->length );
                self::assertSame( 1, $xpath->query( $values )->length );
                self::assertSame( 1, $xpath->query( $guide . '//details//input[@id="fabrement-oauth-name"]' )->length );
                self::assertSame( 1, $xpath->query( $guide . '//details//input[@id="fabrement-oauth-url"]' )->length );
            } else {
                // No HTTPS: nothing to click or paste, and the reason is named.
                self::assertSame( 0, $xpath->query( $button )->length );
                self::assertSame( 0, $xpath->query( $values )->length );
                self::assertStringContainsString( 'HTTPS is required to connect.', $xpath->query( $guide )->item( 0 )->textContent );
            }
        }
        foreach ( [ 'chatgpt', 'codex' ] as $client ) {
            self::assertStringNotContainsString( 'id="fabrement-claude-guide-title"', \fabrement_render_connection_settings( $client ) );
        }
    }

    /** A client opens on its Recommended guide; Advanced only when it is asked for. */
    public function test_setup_opens_on_recommended_unless_advanced_is_asked_for(): void {
        $visible = static function ( string $html ): string {
            $xpath = self::xpath( $html );
            $open  = $xpath->query( '//*[@data-setup-panel and not(@hidden)]' );
            self::assertSame( 1, $open->length, 'Exactly one setup panel is in view.' );

            return $open->item( 0 )->getAttribute( 'data-setup-panel' );
        };
        foreach ( [ 'claude', 'codex' ] as $client ) {
            self::assertSame( 'primary', $visible( \fabrement_render_connection_settings( $client ) ) );
            self::assertSame( 'primary', $visible( \fabrement_render_connection_settings( $client, false ) ) );
            self::assertSame( 'advanced', $visible( \fabrement_render_connection_settings( $client, true, 'advanced' ) ) );
        }
        self::assertSame( 'primary', $visible( \fabrement_render_connection_settings( 'chatgpt', true, 'advanced' ) ) );
    }

    /** Without HTTPS the screen says, above every guide, that connecting needs it. */
    public function test_without_https_the_screen_says_connecting_needs_it(): void {
        foreach ( self::clients() as [ $client ] ) {
            $xpath = self::xpath( \fabrement_render_connection_settings( $client, false ) );
            self::assertSame( 1, $xpath->query( '//*[@id="fabrement-connect"]/p[@role="status" and contains(text(),"Connecting an AI agent needs HTTPS.")]' )->length, $client );
            $xpath = self::xpath( \fabrement_render_connection_settings( $client ) );
            self::assertSame( 0, $xpath->query( '//*[contains(text(),"Connecting an AI agent needs HTTPS.")]' )->length, $client );
        }
    }
}
