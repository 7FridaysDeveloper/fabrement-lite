<?php

namespace Fabrement\Modules\Fields\Schema;

/**
 * Who a set of fields belongs to: a `type` and, for posts, terms and options pages, a `slug`.
 * The pair names the file (`post.project.json`, `options.site.json`, `user.json`) and nothing
 * else identifies it. An options page is not a record: its values live in `wp_options`
 * ({@see \Fabrement\Modules\Fields\Values\Store\OptionStore}).
 */
final class Owner {

    public const TYPES = [ 'post', 'term', 'user', 'comment', 'options' ];

    /** Types this release can edit. The file format already accepts the others. */
    public const ENABLED = [ 'post', 'options' ];

    public string $type;
    public string $slug;

    private function __construct( string $type, string $slug ) {
        $this->type = $type;
        $this->slug = $slug;
    }

    /** @return self|null Null when the pair cannot name a file. */
    public static function of( string $type, string $slug = '' ): ?self {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return null;
        }
        $needs_slug = in_array( $type, [ 'post', 'term', 'options' ], true );
        if ( $needs_slug !== ( $slug !== '' ) ) {
            return null;
        }
        // Post type keys are at most 20 characters, taxonomy keys 32; both are a-z0-9_-. An options
        // page slug follows the same rule.
        if ( $needs_slug && preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/D', $slug ) !== 1 ) {
            return null;
        }
        // "__" separates the page from the field in its SEO variables (%%fabopt_site__phone%%).
        if ( $type === 'options' && strpos( $slug, '__' ) !== false ) {
            return null;
        }
        return new self( $type, $slug );
    }

    /** Parse a file name back into its owner; null for anything that is not a fields file. */
    public static function from_file( string $basename ): ?self {
        if ( preg_match( '/^(post|term|user|comment|options)(?:\.([a-z0-9][a-z0-9_-]{0,31}))?\.json$/D', $basename, $m ) !== 1 ) {
            return null;
        }
        return self::of( $m[1], $m[2] ?? '' );
    }

    public function file(): string {
        return $this->slug === '' ? $this->type . '.json' : $this->type . '.' . $this->slug . '.json';
    }

    public function id(): string {
        return $this->slug === '' ? $this->type : $this->type . '.' . $this->slug;
    }

    public function enabled(): bool {
        return in_array( $this->type, self::ENABLED, true );
    }

    /** Whether the owner exists on this site right now (a vanished owner keeps its file and values). */
    public function exists(): bool {
        switch ( $this->type ) {
            case 'post':
                $object = get_post_type_object( $this->slug );
                return $object !== null && ! empty( $object->show_ui ) && ( strpos( $this->slug, 'wp_' ) !== 0 )
                    && ! in_array( $this->slug, [ 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset' ], true );
            default:
                // An options page exists by having a file: save-fields creates it.
                return true;
        }
    }
}
