<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;

/**
 * Reads all site templates. Both theme appliers and the REST routes read through here;
 * the result is memoised per request because the appliers run on every `init`.
 */
final class SiteTemplateRegistry {

    /** @var SiteTemplateRecord[]|null */
    private static ?array $cache = null;

    /** @var SiteTemplateRecord[]|null Templates that are their own original — see {@see originals()}. */
    private static ?array $originals = null;

    /** @var array<int,array{language:string,translations:array<string,int>,isOriginal:bool}>|null */
    private static ?array $languages = null;

    /** Drop the per-request cache after a create/update/delete. */
    public static function flush(): void {
        TemplateParts::forget_templates();
        self::$cache     = null;
        self::$originals = null;
        self::$languages = null;
    }

    /** @return SiteTemplateRecord[] Every published template, ones with a problem included. */
    public static function all(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        $posts = get_posts(
            [
                'post_type'      => SiteTemplatePostType::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );

        self::$cache = array_map( [ self::class, 'build_record' ], $posts );
        return self::$cache;
    }

    /**
     * @return SiteTemplateRecord[] Templates a person MANAGES — translations excluded.
     *
     * A template's language version is a post of the same type, so a plain listing shows it as
     * a SECOND template: same name, assignable to pages, choosable as a post type's default, and
     * offered in WordPress's own "Template" dropdown. It is not one: a version is reached the
     * way a translated page is — the original is assigned, and the translation plugin hands the
     * reader the version in their language ({@see TemplateContent}). The version is edited on
     * its own (blocks, width), but it holds no role on the site; the original does.
     *
     * Kept OUT of {@see all()} deliberately, and BATCHED: the appliers read it on every front-end
     * request through the appliers, so a per-template question to the translation plugin would
     * put N round trips on every page view — where version_facts() answers for the whole set
     * in one. Memoised beside {@see all()} for the same reason.
     *
     * A record the plugin knows nothing about is absent from that answer and counts as its own
     * original, which is how an untranslatable site keeps every template.
     */
    public static function originals(): array {
        if ( self::$originals !== null ) {
            return self::$originals;
        }

        $records = self::all();
        $langs   = self::languages_of_all();

        self::$originals = array_values(
            array_filter(
                $records,
                static fn( SiteTemplateRecord $t ): bool => $langs[ $t->id ]['isOriginal'] ?? true
            )
        );
        return self::$originals;
    }


    /**
     * The same batch {@see originals()} reads, for a listing that also needs each template's
     * sibling languages. Memoised, so asking for it after originals() really is free — without
     * that the Templates screen would run the identical cross-join twice per paint.
     *
     * @return array<int,array{language:string,translations:array<string,int>,isOriginal:bool}>
     */
    public static function languages_of_all(): array {
        if ( self::$languages !== null ) {
            return self::$languages;
        }

        // is_active(), NOT languages(). This runs at `init` through the theme appliers, on every
        // page view — and languages() asks WPML for its language-switcher list, which clones and
        // replaces the global $wp_query. After that `is_main_query()` is false for the rest of
        // the request and every other plugin gating on it stops working. The same hazard the
        // editor payload is fenced against; a plugin-presence check answers this question
        // without touching the query.
        if ( ! Translation::bridge()->is_active() ) {
            self::$languages = [];
            return self::$languages;
        }

        self::$languages = Translation::bridge()->version_facts(
            array_map( static fn( SiteTemplateRecord $t ): int => $t->id, self::all() ),
            SiteTemplatePostType::POST_TYPE
        );
        return self::$languages;
    }

    /**
     * Why {@see find_assignable()} answered null for $template_id, in words an agent can act
     * on. A language version is a template get-template returns in full, so "not found" for the
     * same id reads as a bug; the useful answer is which original to pass instead.
     */
    public static function not_assignable_message( int $template_id ): string {
        $record = self::find_any( $template_id );
        if ( $record !== null && ! Translation::bridge()->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            $original = self::original_id( $template_id );

            // Naming the wrong id here is worse than naming none: this message exists to stop an
            // agent assigning a version, and it is followed literally. It used to read the group's
            // DEFAULT-LANGUAGE member as the original — measured on a group whose original is
            // Polish, that handed back the English VERSION, which for template 10085 was 10085
            // itself: the answer pointed at the id it had just refused.
            if ( $original <= 0 || $original === $template_id ) {
                return sprintf(
                    /* translators: %d: the version's id. */
                    __( 'Template %d is a language version, not a template of its own: pages, defaults and archives are assigned to the ORIGINAL. Which record that is could not be resolved here — read `translation.originalId` from get-template.', 'fabrement-lite' ),
                    $template_id
                );
            }

            return sprintf(
                /* translators: 1: version id, 2: original template id. */
                __( 'Template %1$d is a language version, not a template of its own: pages, defaults and archives are assigned to the ORIGINAL (%2$d), and readers in that language are handed the version.', 'fabrement-lite' ),
                $template_id,
                $original
            );
        }
        return __( 'Template not found.', 'fabrement-lite' );
    }

    /**
     * The record its group started from, or 0 when there is no group to ask about.
     *
     * ORIGINAL IS A ROLE, NOT A LANGUAGE. It is fixed when the group is made and it is whatever
     * language somebody happened to author in — a template written in Polish has a Polish original
     * and an English translation, and the site's default language says nothing about either. Two
     * callers read this as "the default language's version" and both were wrong for such a group;
     * this is the one place that asks the question properly, so a third does not invent a third
     * answer.
     *
     * Batched on purpose: version_facts() answers language AND isOriginal for a set of ids in
     * one query, where is_original() per member costs a lookup each.
     */
    public static function original_id( int $template_id ): int {
        $bridge = Translation::bridge();
        if ( $bridge->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            return $template_id;
        }

        $group = $bridge->all_versions( $template_id, SiteTemplatePostType::POST_TYPE );
        $ids   = array_values( array_map( 'intval', $group ) );
        if ( $ids === [] ) {
            return 0;
        }

        foreach ( $bridge->version_facts( $ids, SiteTemplatePostType::POST_TYPE ) as $id => $info ) {
            if ( ! empty( $info['isOriginal'] ) ) {
                return (int) $id;
            }
        }

        return 0;
    }

    /**
     * Like {@see find()}, but only a template that can be CONFIGURED — never a translation.
     *
     * The read paths were taught that a translation is not a template of its own; the write
     * paths must agree, or a translation's id assigned to a page (or made a post type's default)
     * is written into `_wp_page_template` and then resolves to nothing, because only originals
     * are ever registered. The page loses its template and nothing says why.
     */
    public static function find_assignable( int $template_id ): ?SiteTemplateRecord {
        $record = self::find( $template_id );
        if ( $record === null ) {
            return null;
        }

        // Asked of the plugin directly rather than by walking originals(): the only thing this
        // guard adds to find() is excluding translations. Published-only is find()'s rule, and
        // it is the right one HERE — a draft assigned to pages or made a type's default renders
        // nothing. The write paths that must reach a draft use find_any().
        return Translation::bridge()->is_original( $template_id, SiteTemplatePostType::POST_TYPE )
            ? $record
            : null;
    }

    /**
     * The record behind `$template_id` for EDITING — any status, original or one language's
     * version.
     *
     * A language version is a post of this very type, edited the way a translated page is
     * edited in WordPress's own editor: a record of its own, with its own blocks and
     * width, saved and deleted through the same routes as the template it belongs to. What it
     * cannot do is be assigned, made a default, or bound to an archive — those pointers resolve
     * to originals only ({@see find_assignable()}), and the reader is handed the version by the
     * translation plugin at render time ({@see TemplateContent}).
     */
    public static function find_any( int $template_id ): ?SiteTemplateRecord {
        $post = get_post( $template_id );
        if ( ! $post instanceof \WP_Post || $post->post_type !== SiteTemplatePostType::POST_TYPE ) {
            return null;
        }
        return self::find( $template_id ) ?? self::build_record( $post );
    }

    public static function find( int $template_id ): ?SiteTemplateRecord {
        foreach ( self::all() as $record ) {
            if ( $record->id === $template_id ) {
                return $record;
            }
        }
        return null;
    }

    /** Resolve a `_wp_page_template` value to its template, or null. */
    public static function find_by_slug( string $slug ): ?SiteTemplateRecord {
        $id = SiteTemplatePostType::id_from_slug( $slug );
        if ( $id === null ) {
            return null;
        }
        $record = self::find( $id );
        return $record;
    }

    private static function build_record( \WP_Post $post ): SiteTemplateRecord {
        // A template dresses pages as it is: a global block that is gone or not published draws
        // nothing in its place, as anywhere in WordPress, and {@see SiteTemplateRecord::problem()}
        // says so where templates are listed.
        $manifest_key = SiteTemplatePostType::manifest_key( (int) $post->ID );

        return new SiteTemplateRecord(
            (int) $post->ID,
            (string) $post->post_title,
            SiteTemplatePostType::slug_for( (int) $post->ID ),
            SiteTemplatePostType::content_width( (int) $post->ID ),
            (string) $post->post_content,
            // The KEY of the record's manifest, not its contents. This method builds a record for
            // every published template on ordinary front-end requests, so opening the file here
            // would read every manifest on the site to render one of them. Meta only; the file is
            // opened by render_content(), once, and only by whoever renders.
            $manifest_key
        );
    }
}
