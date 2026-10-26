<?php

namespace Fabrement\Framework;

/**
 * Every name the plugin writes into the database: options, post meta, transients and the
 * table — and, for the names built at run time, the one method that builds each.
 *
 * A key's VALUE is what live sites already hold: renaming a constant is free, changing its string
 * is a migration. Nothing else in the plugin spells one of these names; a new stored name is added
 * here first.
 */
final class Keys {

    // ───────────────────────────── Options ─────────────────────────────

    /** The database version the updater brought the site to. */
    public const OPTION_DB_VERSION = 'fabrement_db_version';

    /** Why the last database update failed ('' when it did not). */
    public const OPTION_DB_UPDATE_ERROR = 'fabrement_db_update_error';

    /** When a failed database update may be tried again. */
    public const OPTION_DB_RETRY_AFTER = 'fabrement_db_retry_after';

    /** Which template each archive uses. */
    public const OPTION_ARCHIVE_TEMPLATES = 'fabrement_archive_templates';

    /**
     * Per-post-type default templates — array<string post_type, int template_id>. Each public type
     * may have AT MOST ONE default template (radio across templates); resolved at render time by the
     * appliers for every singular of that type without its own explicit pick.
     */
    public const OPTION_TEMPLATE_TYPE_DEFAULTS = 'fabrement_st_type_defaults';

    /** The page shown for "not found". */
    public const OPTION_NOT_FOUND_PAGE = 'fabrement_not_found_page';

    /** The structured-data types set per post type. */
    public const OPTION_SCHEMA_TYPES = 'fabrement_schema_types';

    /** Whether MCP sign-in over OAuth is on. */
    public const OPTION_MCP_OAUTH_ENABLED = 'fabrement_mcp_oauth_enabled';

    /** Changing it revokes every OAuth token issued before. */
    public const OPTION_MCP_OAUTH_GENERATION = 'fabrement_mcp_oauth_generation';

    /** The version of the OAuth table the site holds. */
    public const OPTION_MCP_OAUTH_SCHEMA = 'fabrement_mcp_oauth_schema';

    /** An options page's values: {@see self::options_page()}. */
    public const OPTIONS_PAGE_PREFIX = 'fabrement_options_';

    // ───────────────────────────── Post meta ─────────────────────────────

    /** A field's value: {@see self::field_meta()}. Marks our meta keys among other plugins' ones. */
    public const FIELD_META_PREFIX = 'fab_';

    /** What the fields' own service keys start with (the two below). */
    public const META_FIELDS_PREFIX = '_fabrement_fields_';

    /** Why copying a record's fields to its translation failed. */
    public const META_FIELDS_SYNC_ERROR = '_fabrement_fields_sync_error';

    /** On an auto-draft opened with WPML's "+" link: the original it is being translated from. */
    public const META_FIELDS_TRANSLATION_OF = '_fabrement_fields_translation_of';

    /** A record's own structured data. */
    public const META_SCHEMA = '_fabrement_schema';

    /**
     * The template's own name in {@see \Fabrement\Modules\SiteTemplate\Service\TemplateManifestStore} —
     * the file that holds its content-source rules.
     *
     * ON EVERY LANGUAGE VERSION, naming the ORIGINAL's file. A version does not get one of its own:
     * two files would be two copies of the rules again, which is the drift this store exists to
     * end. Carried by the translator, and declared `copy` in
     * wpml-config.xml so a version made outside our routes carries it too.
     *
     * Meta, not the slot block's markup: an attribute nobody declared in block.json is dropped by
     * the editor on every save, silently — and a key inside post_content travels with a
     * copy-pasted arrangement into a template it does not belong to.
     */
    public const META_TEMPLATE_KEY = '_fabrement_st_key';

    /**
     * How wide the page content renders inside this template: 'full' (default — full-bleed,
     * sections reach edge to edge) or 'constrained' (the classic centred content
     * column). Read by both theme appliers + canvas.php, so width is a property
     * of the template, not hard-coded in the render path.
     */
    public const META_TEMPLATE_CONTENT_WIDTH = '_fabrement_st_content_width';

    // ───────────────────────────── Transients ─────────────────────────────

    /** A shareable content preview: {@see self::content_preview()}. */
    public const TRANSIENT_CONTENT_PREVIEW_PREFIX = 'fabrement_content_preview_';

    /** An adapter problem already logged today: {@see self::mcp_adapter_notice()}. */
    public const TRANSIENT_MCP_ADAPTER_NOTICE_PREFIX = 'fabrement_mcp_adapter_';

    /** Per user ({@see self::for_user()}): the MCP connection command shown once after it is made. */
    public const TRANSIENT_MCP_COMMAND = 'fabrement_mcp_command';

    // ───────────────────────────── Tables ─────────────────────────────

    /** The OAuth grants and tokens, behind the site's table prefix. */
    public const TABLE_MCP_OAUTH = 'fabrement_mcp_oauth';

    // ───────────────────────────── Names built at run time ─────────────────────────────

    /** The meta key of a top-level field: `fab_<name>`. */
    public static function field_meta( string $name ): string {
        return self::FIELD_META_PREFIX . $name;
    }

    /**
     * The option row of an options page:
     *
     *   fabrement_options_<slug>          the page in the site's default language (the original);
     *   fabrement_options_<slug>.<lang>   what another language set itself, nothing more.
     *
     * A dot, which no slug holds, so a page called `site_uk` never meets `site` in Ukrainian.
     */
    public static function options_page( string $slug, string $lang = '' ): string {
        return self::OPTIONS_PAGE_PREFIX . $slug . ( $lang !== '' ? '.' . $lang : '' );
    }

    public static function content_preview( string $token ): string {
        return self::TRANSIENT_CONTENT_PREVIEW_PREFIX . $token;
    }

    public static function mcp_adapter_notice( string $message ): string {
        return self::TRANSIENT_MCP_ADAPTER_NOTICE_PREFIX . md5( $message );
    }

    /** A per-user transient: `<key>_<user id>`. */
    public static function for_user( string $key, int $user_id ): string {
        return $key . '_' . $user_id;
    }
}
