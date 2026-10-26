<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\Translation\Translation;

/**
 * The server `instructions` manual — injected into the agent's context by MCP clients at
 * initialize. This IS the usage manual of the server: it teaches the agent the authoring
 * flow before it calls a single tool. Kept apart from wiring so prompt edits never touch
 * protocol code. Kept SHORT — it is a tax on every agent session; the deep law lives in
 * the contract docs the agent fetches itself.
 */
final class Instructions {

    public static function text(): string {
        return self::base() . self::multilingual();
    }

    private static function base(): string {
        return implode( "\n", [
            'You are connected to Fabrement Lite on ONE WordPress site. Work with content using installed',
            'Gutenberg and ACF blocks, records of the site\'s post types, fields, terms, menus, forms, media and SEO.',
            'The active theme and installed plugins provide rendering and design: these tools write content',
            'and data, never code. Templates and shared headers/footers use installed blocks. Content Source can show page content or select',
            'an existing PHP file from the active/parent theme\'s templates directory; it cannot edit that file.',
            'FIELDS here are Fabrement\'s own: save-fields defines them for a post type, save-options-page for an',
            'options page, get-field-values / set-field-values fill them. ACF comes in as Gutenberg blocks, filled through',
            'save-content. ACF fields attached to a post type are only listed (list-post-type-fields): asked to',
            'add or change one, say these tools do not write ACF fields and offer a Fabrement field instead.',
            '',
            'THE RULES SHIP WITH THIS PLUGIN. Every tool that has rules ends with "Rules: get-skill-doc {id:…}" — read',
            'that doc once, before the first call in its area, and follow its references when relevant to',
            'the task. Fetch the needed documents as parallel get-skill-doc calls, one id each, before writing.',
            '',
            'Name things the way a person would (the "Hero" block, the About page) — ids go into tool',
            'arguments only. List before you create: the usual failure is a second copy of what already exists.',
            'On a site you have not seen this session, LOOK FIRST — get-site-settings, list-post-types,',
            'list-content and list-blocks. Read get-block-source {name} for attributes, writeMode and ACF limits.',
            'The server does not execute JavaScript save(). A render callback alone does not imply an',
            'attribute-only block: preserve complete saved markup when writeMode requires it.',
            'Read before writing, patch the intended part and preserve unrelated content. Use the current hash',
            'for content edits. Verify the frontend and Gutenberg editor; a successful save is not that check.',
            'Do not use raw HTML, scripts or post-meta writes to bypass a missing feature or ACF limitation.',
            'Every writing tool refuses text that holds scripts, event handlers, iframe/style tags or executable',
            'links — in titles, labels, field values and form templates too, and says which part it refused.',
            '"a < b" is plain text; to show a tag as words write it with &lt; and &gt;.',
        ] );
    }

    /**
     * One paragraph more, ONLY where the site has languages. On a single-language site — most of
     * them — this is "", and the manual is byte-for-byte what it was: a rule about languages
     * where there is one is a line every agent must read and dismiss on every task.
     *
     * Why here and not only in list-languages' description: a tool's description is read when the
     * agent PICKS a tool; the manual is read when it PLANS the task. Asked to "translate the
     * header", an agent that never looked at the language tools goes and edits the header block.
     */
    private static function multilingual(): string {
        if ( ! Translation::is_multilingual() ) {
            return '';
        }

        return implode( "\n", [
            '',
            '',
            'MULTILINGUAL — this site has several languages. list-languages FIRST when a task mentions a',
            'language or before creating anything: a page you cannot find by its English title may exist in',
            'another language. Read get-skill-doc {id:"translation-contract"}. Use translate-content for a',
            'version of an existing record, then edit its returned id. Inspect and verify every changed language;',
            'do not assume copying content translates its text or configures ACF translation preferences.',
        ] );
    }
}
