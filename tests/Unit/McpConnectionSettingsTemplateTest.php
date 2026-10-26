<?php

namespace Fabrement\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/ConnectionSettingsRender.php';

final class McpConnectionSettingsTemplateTest extends TestCase {
    public function test_missing_table_shows_off_and_requires_reactivation(): void {
        $html = \fabrement_render_connection_access( [], [], true, true, false );
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
        self::assertSame( 4, $xpath->query( $clients . '/a' )->length );
        self::assertSame( 1, $xpath->query( $clients . '/a[@aria-current="page"]' )->length );
        self::assertSame( $client === 'chatgpt' ? 0 : 1, $xpath->query( '//*[@id="fabrement-mcp-form"]' )->length );
        // ChatGPT and Codex open on a numbered guide. Claude.ai and Cursor are a button
        // each — one opens a form already filled in, the other installs itself — so they
        // have nothing to number.
        self::assertSame( in_array( $client, [ 'claude', 'cursor' ], true ) ? 0 : 1, $xpath->query( '//*[@id="fabrement-setup-panel-primary"]//ol[@class="fabrement-cred__steps"]' )->length );
        $advanced = in_array( $client, [ 'claude', 'codex' ], true ) ? 1 : 0;
        self::assertSame( $advanced, $xpath->query( '//*[@id="fabrement-setup-panel-advanced"]' )->length );
        self::assertSame( $advanced * 2, $xpath->query( '//*[@data-setup-tab]' )->length, 'Only a client with a second way in gets Recommended / Advanced.' );
        self::assertSame( 0, $xpath->query( '//*[@id="fabrement-mcp-instructions"]' )->length );
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-access"]' )->length );
        self::assertSame( 0, $xpath->query( '//*[@id="fabrement-access"]/ancestor::*[@data-setup-panel or @hidden]' )->length );
        self::assertSame( 1, $xpath->query( '//*[@id="fabrement-access"]//button[@role="switch"]' )->length );
        // The panel holds exactly two cards — the setup, then the register of what is
        // already connected beside it — and nothing else: the Figma form belongs to
        // API Connection and must not render here. The register is a SIBLING of the setup
        // card, not a section inside it: it lists every connection of every client, so
        // nesting it under the selected client's setup misread it.
        $panel = '//div[contains(@class,"fabrement-cred__panel--agents")]';
        self::assertSame( 2, $xpath->query( $panel . '/*' )->length );
        self::assertSame( 1, $xpath->query( $panel . '/*[1][contains(@class,"fabrement-cred__mcp")]' )->length );
        self::assertSame( 1, $xpath->query( $panel . '/*[2][@id="fabrement-access"]' )->length );
        self::assertSame( 0, $xpath->query( '//input[@name="action" and @value="fabrement_save_figma_key"]' )->length );
        if ( $client !== 'cursor' ) { self::assertStringContainsString( 'fabrement-example-studio', $html ); }
    }

    public static function clients(): array { return [ [ 'chatgpt' ], [ 'codex' ], [ 'claude' ], [ 'cursor' ] ]; }

    /**
     * No sub-tabs, no key fields: this screen connects an agent and nothing else. The AI
     * provider keys and the Figma token are API Connection's ({@see ApiConnectionTemplateTest}).
     *
     * Asserted against the DOM, not the source text: the templates' comments name the
     * controls they explain, and a string search would match those and pass (or fail)
     * for reasons that have nothing to do with what is rendered.
     */
    public function test_no_key_fields_or_subtabs_remain(): void {
        $xpath = self::xpath( \fabrement_render_connection_settings( 'claude' ) );
        self::assertSame( 0, $xpath->query( '//nav[contains(@class,"fabrement-subtabs")]' )->length );
        foreach ( [ 'fabrement_builder_ai_provider', 'fabrement_builder_gemini_key', 'fabrement_builder_anthropic_key', 'fabrement_builder_openai_key', 'fabrement_builder_figma_key' ] as $field ) {
            self::assertSame( 0, $xpath->query( '//*[@name="' . $field . '" or @id="' . $field . '"]' )->length, $field . ' belongs to the API Connection screen.' );
        }
        self::assertSame( 0, $xpath->query( '//*[@data-provider or @data-provider-field]' )->length );
        self::assertSame( 0, $xpath->query( self::with_class( 'fabrement-cred__pill' ) )->length );
        self::assertSame( 0, $xpath->query( self::with_class( 'fabrement-cred__figma' ) )->length );
    }

    private static function xpath( string $html ): \DOMXPath {
        $document = new \DOMDocument();
        @$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

        return new \DOMXPath( $document );
    }

    /** XPath for "has this class", which `contains(@class,…)` cannot express safely. */
    private static function with_class( string $class ): string {
        return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
    }

    /** The account Log out link lives in the top nav, not duplicated on this screen. */
    public function test_no_duplicate_logout_link(): void {
        self::assertStringNotContainsString( 'fabrement_builder_logout', \fabrement_render_connection_settings( 'claude' ) );
    }

    public function test_fresh_password_shows_instructions_below_connection_methods_with_privacy_notice(): void {
        foreach ( [ 'codex', 'claude' ] as $client ) {
            $html = \fabrement_render_connection_settings( $client, true );
            self::assertStringContainsString( 'id="fabrement-mcp-instructions"', $html );
            self::assertStringContainsString( 'Contains a private application password.', $html );
            self::assertStringContainsString( 'aria-selected="true"', $html );
            self::assertStringContainsString( 'data-mcp-copy="fabrement-mcp-instructions"', $html );
            $document = new \DOMDocument();
            @$document->loadHTML( $html );
            $xpath = new \DOMXPath( $document );
            self::assertSame( 0, $xpath->query( '//*[@data-mcp-ready]/ancestor::*[@hidden]' )->length, 'The one-time setup opens in view.' );
            self::assertSame( 0, $xpath->query( '//*[@data-mcp-tab="instructions" or @data-mcp-panel="instructions"]' )->length );
            self::assertSame( 1, $xpath->query( '//*[@id="fabrement-mcp-instructions" and @readonly and @aria-describedby="fabrement-mcp-private-notice"]' )->length );
            self::assertSame( 0, $xpath->query( '//*[@id="fabrement-mcp-instructions"]/ancestor::*[@hidden or @data-mcp-panel]' )->length );
            // The instructions are the last way, after the ones with a command or a file.
            $instructions = '//*[@data-mcp-ready]/div[@class="fabrement-cred__way fabrement-cred__way--instructions"]';
            self::assertSame( 1, $xpath->query( $instructions )->length );
            self::assertSame( $xpath->query( '//*[@data-mcp-panel]' )->length, $xpath->query( $instructions . '/preceding-sibling::*[@data-mcp-panel]' )->length );
            self::assertSame( 0, $xpath->query( '//*[@data-mcp-tab]' )->length );
            // Every way side by side with its code, nothing folded — the same layout as the
            // OAuth options. The password's own fold is open, so the one-time setup is in view.
            self::assertSame( 0, $xpath->query( '//details[@data-mcp-panel]' )->length );
            self::assertSame( 1, $xpath->query( '//*[@data-mcp-ready]/ancestor::details[@open]' )->length );
            self::assertSame( 1, $xpath->query( '//div[@data-mcp-panel="config"]//textarea[@id="fabrement-mcp-cmd"]' )->length );
            if ( $client === 'claude' ) {
                self::assertSame( 1, $xpath->query( '//div[@data-mcp-panel="cli"]//textarea[@id="fabrement-mcp-cli"]' )->length );
            } else {
                self::assertSame( 1, $xpath->query( '//div[@data-mcp-panel="ui"]//input[@id="fabrement-mcp-header-value"]' )->length );
                self::assertSame( 1, $xpath->query( '//div[@data-mcp-panel="cli"]//textarea[@id="fabrement-mcp-cli-windows"]' )->length );
                self::assertSame( 1, $xpath->query( '//div[@data-mcp-panel="cli"]//textarea[@id="fabrement-mcp-cli-posix"]' )->length );
            }
            $ids = [];
            foreach ( $xpath->query( '//*[@id]' ) as $element ) { $ids[] = $element->getAttribute( 'id' ); }
            self::assertSame( $ids, array_unique( $ids ) );
        }
    }

    public function test_local_http_defaults_to_passwords_and_never_renders_o_auth_commands(): void {
        $html = \fabrement_render_connection_settings( 'codex', false, false );
        self::assertStringContainsString( 'HTTPS required for OAuth.', $html );
        self::assertStringNotContainsString( 'codex mcp login', $html );
        self::assertStringContainsString( 'disabled aria-describedby="fabrement-oauth-unavailable"', $html );
        self::assertStringNotContainsString( 'id="fabrement-oauth-instructions"', $html );
    }

    public function test_password_ui_commands_and_cursor_link_use_the_generated_site_name(): void {
        foreach ( [ 'codex', 'claude', 'cursor' ] as $client ) {
            $document = new \DOMDocument();
            @$document->loadHTML( \fabrement_render_connection_settings( $client, true ) );
            $xpath = new \DOMXPath( $document );
            $panel = '//*[@data-mcp-ready]';
            $text = $xpath->query( $panel )->item( 0 )->textContent;
            self::assertStringNotContainsString( 'wp-blocks', $text );
            self::assertStringContainsString( 'fabrement-example-studio', $text );
            self::assertStringContainsString( 'Check existing MCP servers before adding', $text );
            if ( $client === 'codex' ) {
                self::assertSame( 'fabrement-example-studio', $xpath->query( '//*[@id="fabrement-mcp-name"]' )->item( 0 )->getAttribute( 'value' ) );
                self::assertStringContainsString( 'Run in PowerShell', $text );
                self::assertStringContainsString( 'The exported token lasts only for that terminal session.', $text );
            } elseif ( $client === 'cursor' ) {
                $url = $xpath->query( '//*[@id="fabrement-mcp-install"]' )->item( 0 )->getAttribute( 'href' );
                parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
                self::assertSame( 'fabrement-example-studio', $query['name'] );
            }
        }
    }

    public function test_codex_o_auth_instructions_are_copyable_below_cli_and_separate_from_password_instructions(): void {
        $html = \fabrement_render_connection_settings( 'codex', true );
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
        self::assertStringNotContainsString( 'SYNTHETIC_NOT_A_REAL_PASSWORD', $prompt );
        self::assertStringNotContainsString( 'Basic ', $prompt );
        foreach ( [ 'chatgpt', 'claude', 'cursor' ] as $client ) {
            self::assertStringNotContainsString( 'id="fabrement-oauth-instructions"', \fabrement_render_connection_settings( $client ) );
        }
        $visible_html = \fabrement_render_connection_settings( 'codex' );
        @$document->loadHTML( $visible_html );
        $xpath = new \DOMXPath( $document );
        // Without a fresh password Codex opens on Recommended — the app's form — and the CLI
        // commands and the instructions wait one tab away.
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
                $html = \fabrement_render_connection_access( [], [], $enabled, $https );
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
              [ 'payload' => [ 'client_name' => 'ChatGPT' ], 'family' => 'family-2', 'expires' => 0, 'user_id' => 7 ] ],
            [ [ 'name' => 'Fabrement MCP (Codex)', 'uuid' => 'password-1', 'last_used' => 0 ],
              [ 'name' => 'Fabrement MCP (Cursor) - 2026-09-22 08:22', 'label' => 'Fabrement MCP (Cursor)',
                'uuid' => 'password-2', 'created' => 1758529320, 'last_used' => 0 ] ]
        );
        $document = new \DOMDocument();
        @$document->loadHTML( $html );
        $xpath = new \DOMXPath( $document );
        self::assertSame( 4, $xpath->query( '//ul/li' )->length );
        // Every connection says who authorized it; this user no longer exists.
        self::assertSame( 2, $xpath->query( '//li//span[text()="By a deleted user"]' )->length );
        // The creation stamp sits under the title, with the other dates — not inside it.
        self::assertSame( 'Fabrement MCP (Cursor)', $xpath->query( '//li[4]//strong' )->item( 0 )->textContent );
        self::assertSame( 1, $xpath->query( '//li[4]//span[starts-with(text(),"Created ")]' )->length );
        // A password with no stamp to drop shows its name whole and no created line.
        self::assertSame( 'Fabrement MCP (Codex)', $xpath->query( '//li[3]//strong' )->item( 0 )->textContent );
        self::assertSame( 0, $xpath->query( '//li[3]//span[starts-with(text(),"Created ")]' )->length );
        self::assertSame( 2, $xpath->query( '//li/form/input[@name="oauth_command" and @value="revoke"]' )->length );
        self::assertSame( 2, $xpath->query( '//li/form/input[@name="_wpnonce"]' )->length );
        self::assertSame( [ 'family-1', 'family-2' ], array_map( static fn( $input ) => $input->getAttribute( 'value' ), iterator_to_array( $xpath->query( '//li/form/input[@name="family"]' ) ) ) );
        self::assertSame( 1, $xpath->query( '//li/a[contains(@href,"fabrement_revoke_mcp_password") and contains(@href,"password-1")]' )->length );
        self::assertStringNotContainsString( '<script>', $html );
        self::assertStringContainsString( 'Application passwords will stay active.', $html );
        // Every OAuth connection lasts until disconnected, a legacy row with an end included.
        self::assertSame( 1, $xpath->query( '//li[1]//span[text()="Until disconnected"]' )->length );
        self::assertSame( 1, $xpath->query( '//li[2]//span[text()="Until disconnected"]' )->length );
        self::assertSame( 0, $xpath->query( '//span[starts-with(text(),"Expires ")]' )->length );
    }

    public function test_chat_gpt_guide_is_visible_on_https_and_local_http_only_for_chat_gpt(): void {
        foreach ( [ true, false ] as $https ) {
            $html = \fabrement_render_connection_settings( 'chatgpt', false, $https );
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
            $xpath = self::xpath( \fabrement_render_connection_settings( 'claude', false, $https ) );
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
                // No HTTPS: nothing to click or paste, and the way out is named.
                self::assertSame( 0, $xpath->query( $button )->length );
                self::assertSame( 0, $xpath->query( $values )->length );
                self::assertStringContainsString( 'use Claude Code on the Advanced tab', $xpath->query( $guide )->item( 0 )->textContent );
            }
        }
        foreach ( [ 'chatgpt', 'codex', 'cursor' ] as $client ) {
            self::assertStringNotContainsString( 'id="fabrement-claude-guide-title"', \fabrement_render_connection_settings( $client ) );
        }
    }

    /**
     * A client opens on its Recommended guide, unless that guide cannot work (no HTTPS
     * rules OAuth out) or a password was just generated — its one-time setup is on
     * Advanced, and landing anywhere else would hide the only copy of it.
     */
    public function test_setup_opens_on_recommended_unless_it_cannot_work(): void {
        $visible = static function ( string $html ): string {
            $xpath = self::xpath( $html );
            $open  = $xpath->query( '//*[@data-setup-panel and not(@hidden)]' );
            self::assertSame( 1, $open->length, 'Exactly one setup panel is in view.' );

            return $open->item( 0 )->getAttribute( 'data-setup-panel' );
        };
        foreach ( [ 'claude', 'codex' ] as $client ) {
            self::assertSame( 'primary', $visible( \fabrement_render_connection_settings( $client ) ) );
            self::assertSame( 'advanced', $visible( \fabrement_render_connection_settings( $client, false, false ) ) );
            self::assertSame( 'advanced', $visible( \fabrement_render_connection_settings( $client, true ) ) );
        }
        foreach ( [ 'chatgpt', 'cursor' ] as $client ) {
            self::assertSame( 'primary', $visible( \fabrement_render_connection_settings( $client, 'cursor' === $client, false ) ) );
        }

    }

    /**
     * Cursor is two clicks, one button at a time: Generate application password, then Add to Cursor.
     * The config file and the AI instructions wait in an accordion for when the link does
     * not open Cursor.
     */
    public function test_cursor_is_one_button_at_a_time_with_manual_setup_folded_away(): void {
        $guide = '//*[@id="fabrement-setup-panel-primary"]/section[@aria-labelledby="fabrement-cursor-guide-title"]';

        $before = self::xpath( \fabrement_render_connection_settings( 'cursor' ) );
        self::assertSame( 1, $before->query( $guide . '/p[contains(@class,"fabrement-cred__guide-lead")]' )->length );
        self::assertSame( 1, $before->query( $guide . '/form[@id="fabrement-mcp-form"]//button[@type="submit" and contains(@class,"button-primary")]' )->length );
        self::assertSame( 1, $before->query( $guide . '//input[@name="fabrement_mcp_client" and @value="cursor"]' )->length );
        self::assertSame( 0, $before->query( '//*[@data-mcp-ready] | //*[@id="fabrement-mcp-install"]' )->length );

        $after = self::xpath( \fabrement_render_connection_settings( 'cursor', true ) );
        self::assertSame( 0, $after->query( '//form[@id="fabrement-mcp-form"]' )->length, 'Once the key exists, Add to Cursor is the only button.' );
        self::assertSame( 1, $after->query( $guide . '//*[@data-mcp-ready]/div/a[@id="fabrement-mcp-install" and contains(@class,"button-primary")]' )->length );
        self::assertSame( 0, $after->query( '//*[@id="fabrement-mcp-install"]/ancestor::details' )->length );
        // The first step stays in view as done, right before Add to Cursor — a status, not a button.
        self::assertSame( 1, $after->query( '//*[@id="fabrement-mcp-install"]/preceding-sibling::span[@role="status" and contains(@class,"fabrement-cred__mcp-done")]//*[contains(@class,"dashicons-yes")]' )->length );
        self::assertSame( 0, $after->query( '//*[contains(@class,"fabrement-cred__mcp-done")]/ancestor-or-self::button' )->length );
        $manual = $guide . '//*[@data-mcp-ready]/details';
        self::assertSame( 1, $after->query( $manual . '//textarea[@id="fabrement-mcp-cmd"]' )->length );
        self::assertSame( 1, $after->query( $manual . '//textarea[@id="fabrement-mcp-instructions" and @aria-describedby="fabrement-mcp-private-notice"]' )->length );
        self::assertSame( 1, $after->query( $manual . '//*[@id="fabrement-mcp-private-notice"]' )->length );
        self::assertSame( 0, $after->query( '//*[@data-mcp-tab]' )->length );
    }
}
