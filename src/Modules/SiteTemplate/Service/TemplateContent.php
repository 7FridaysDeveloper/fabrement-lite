<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;

/**
 * The template markup to render FOR A READER — the version in the reader's language.
 *
 * Each language version carries its own blocks and words. Source rules live in
 * one shared manifest, selected identically from every version.
 *
 * A language version is a record of its own (see {@see SiteTemplateTranslator}): its content
 * may carry other words, or another header altogether, and it is rendered exactly as saved.
 * Which template dresses which page is NOT part of this: that resolution runs on the
 * ORIGINAL's id stored in page meta and options, and stays language-blind on purpose — the
 * reader is then handed the version the way they are handed a translated page.
 *
 * That split also means changing the site's default language cannot break anything structural.
 * The worst it can do is show a template's original where a version was expected — never an
 * unassigned page, never a missing header.
 *
 * DELIBERATELY NOT in {@see SiteTemplateRegistry}. The registry feeds the Templates screen
 * and the MCP abilities as well as the front end, and every one of those wants
 * the record it was asked for: `get-template {id}` shows an agent what to edit, and handing it
 * the Polish version for the original's id would have the agent's next save write Polish into
 * the original. Only rendering wants the reader's language, so only rendering asks for it.
 */
final class TemplateContent {

    /**
     * Markup for the language being rendered, falling back to the template's own content.
     *
     * Falls back when there is no translation, and ALSO when the translation exists but is
     * empty — a record created and never filled in. An empty return would render the page with
     * no header and no footer at all, which looks like the template broke rather than like a
     * translation nobody wrote yet.
     */
    public static function for_reader( SiteTemplateRecord $template ): string {
        $original = $template->render_content();

        $translated_id = Translation::bridge()->version_in( $template->id, SiteTemplatePostType::POST_TYPE );
        if ( $translated_id <= 0 || $translated_id === $template->id ) {
            return $original;
        }

        // Through the registry, which holds PUBLISHED templates with their readiness. The id
        // comes from the translation plugin's tables, which are status-blind: the translate
        // route hands the admin a normal edit screen, "Move to Trash" is one click away, and a
        // trashed post keeps its content. And a version has its own header and footer now, so
        // it is used as it is — an empty place where a side of its own is not drawn.
        $version = SiteTemplateRegistry::find( $translated_id );
        if ( $version === null ) {
            return $original;
        }

        $content = $version->render_content();

        // renderContent injects the group's shared source rules into this version's frame.
        return trim( $content ) !== '' ? $content : $original;
    }

}
