<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Translation\FieldSync;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use Fabrement\Modules\Fields\Translation\PostTranslations;
use Fabrement\Modules\Fields\Values\Store\OptionRows;
use Fabrement\Modules\Translation\Translation;

/**
 * One object that can carry fields — a post, term, user or comment, or one language of an
 * options page — plus the owner whose file describes its fields. Resolved from what PHP code
 * passes: `42`, `'options:site'`, `'options:site:fr'`.
 */
final class FieldObject {

    public string $type;
    /** A record id; for an options page, the name of its row in wp_options. */
    public int|string $id;
    public Owner $owner;
    /** Options pages only: the language of this row; '' on a site with one language. */
    public string $lang = '';

    private function __construct( string $type, int|string $id, Owner $owner, string $lang = '' ) {
        $this->type = $type;
        $this->id = $id;
        $this->owner = $owner;
        $this->lang = $lang;
    }

    /** @param int|string $ref */
    public static function resolve( $ref ): ?self {
        if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
            return self::post( (int) $ref );
        }
        // options:site — the page in the reader's language; options:site:uk — in that language.
        if ( is_string( $ref ) && preg_match( '/^options:([a-z0-9][a-z0-9_-]{0,31})(?::([a-zA-Z0-9_-]{1,20}))?$/D', $ref, $m ) === 1 ) {
            return self::options( $m[1], $m[2] ?? '' );
        }
        // Fields exist on post types and options pages only ({@see Owner::ENABLED}).
        return null;
    }

    public static function post( int $id ): ?self {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post || wp_is_post_revision( $post ) ) {
            return null;
        }
        $owner = Owner::of( 'post', $post->post_type );
        return $owner ? new self( 'post', $id, $owner ) : null;
    }

    /**
     * One language of an options page; null when the page has no field file or the language is
     * not one of the site's. $lang '' = the current language (the reader's, or the admin's); on a
     * single-language site $lang is ignored.
     * Which row that is: {@see OptionRows}.
     */
    public static function options( string $slug, string $lang = '' ): ?self {
        $owner = Owner::of( 'options', $slug );
        if ( ! $owner || ! SchemaRepository::get( $owner ) ) {
            return null;
        }
        $bridge = Translation::bridge();
        $languages = array_column( $bridge->languages(), 'code' );
        if ( ! $languages ) {
            // One language: the page itself, whatever language is asked for.
            return new self( 'options', Keys::options_page( $slug ), $owner );
        }
        $default = $bridge->default_language();
        if ( $lang === '' ) {
            $lang = $bridge->current_language();
            $lang = in_array( $lang, $languages, true ) ? $lang : $default;
        }
        if ( ! in_array( $lang, $languages, true ) ) {
            return null;
        }
        return new self( 'options', Keys::options_page( $slug, $lang === $default ? '' : $lang ), $owner, $lang );
    }

    /**
     * The object whose values this one's follow: an options page's original language for any
     * other language; null for the original, a site with one language and records (whose
     * translations PostTranslations keeps in line on write).
     */
    public function original(): ?self {
        if ( $this->type !== 'options' || $this->lang === '' || $this->lang === Translation::bridge()->default_language() ) {
            return null;
        }
        return self::options( $this->owner->slug, Translation::bridge()->default_language() );
    }

    /**
     * Whether this object is a translation of another — a record's WPML translation, or an options
     * page in another language than the original — whose `copy` values and followed rows belong to
     * that original.
     */
    public function is_translation(): bool {
        if ( $this->type === 'options' ) {
            return $this->original() !== null;
        }
        $original = PostTranslations::original_of( $this );
        return $original && $original !== $this->id;
    }

    /**
     * What the original of this translation stores (its own values, as ValueRepository reads them).
     * Only meaningful when is_translation().
     *
     * @return array{values: array<string, mixed>, exists: array<string, bool>, present: array<string, true>}
     */
    public function original_stored( array $fields ): array {
        if ( $this->type === 'options' ) {
            return $this->original()->stored( $fields );
        }
        return ValueRepository::read( 'post', PostTranslations::original_of( $this ), $fields );
    }

    /**
     * Where WPML's translation editor owns this translation: its jobs rewrite the WORDS they carry,
     * so those are translated there. No job carries an options page.
     */
    public function translation_editor_owns_words(): bool {
        if ( $this->type === 'options' ) {
            return false;
        }
        // The original's editor choice ({@see PostTranslations::editor_context()}).
        return ! Translation::bridge()->writes_translations_manually( PostTranslations::original_of( $this ) ?: (int) $this->id );
    }

    /**
     * The edit form's translation state: what is shared with the original, and which words
     * WPML's editor manages. Historical lockedFields/lockedRows keys now identify shared values.
     */
    public function translation_context(): array {
        if ( $this->type !== 'options' ) {
            return PostTranslations::editor_context( $this );
        }
        if ( ! $this->is_translation() ) {
            return [ 'isTranslation' => false ];
        }
        $locks = TranslationPolicy::locks( $this->all_fields() );
        return [
            'isTranslation' => true,
            'originalId'    => 0,
            'managedByWpml' => false,
            'lockedFields'  => $locks['fields'],
            'wordFields'    => [],
            'lockedRows'    => $locks['rows'],
            'error'         => '',
        ];
    }

    /**
     * What this object itself stores, nothing added.
     *
     * @return array{values: array<string, mixed>, exists: array<string, bool>, present: array<string, true>}
     */
    public function stored( array $fields ): array {
        return ValueRepository::read( $this->type, $this->id, $fields );
    }

    /** Values of this object only. Missing values resolve to schema defaults, never another language. */
    public function values( array $fields ): array {
        return $this->stored( $fields );
    }

    /**
     * Write preparation only: a missing options version will be seeded before these edits commit.
     * Keep this separate from editorValues so GET, frontend and PHP reads remain side-effect free
     * and never substitute the original. Uses exactly the seed that explicit creation publishes.
     */
    public function write_values( array $fields ): array {
        if ( $this->type === 'options' && ! OptionVersions::exists( $this ) ) {
            return ValueRepository::decode( $fields, FieldSync::initial( $this, $fields ) );
        }
        return $this->editor_values( $fields );
    }

    /**
     * What the edit form and get-field-values show: values(), and for a record's translation that
     * holds nothing yet (a "+" translation still pending, or one linked before its seed ran) the
     * values its original seeds it with, by each field's policy — the same values PostTranslations
     * writes once WPML links it. Not for templates: a front-end read stays one lookup of the
     * record's own meta.
     *
     * @return array{values: array<string, mixed>, exists: array<string, bool>, present: array<string, true>}
     */
    public function editor_values( array $fields ): array {
        $own = $this->values( $fields );
        if ( $this->type !== 'post' || in_array( true, $own['exists'], true ) || ! $this->is_translation() ) {
            return $own;
        }
        return ValueRepository::decode( $fields, FieldSync::initial( $this, $fields ) );
    }

    /** Whether it stores a value of any of its active fields itself. */
    public function holds_values(): bool {
        return in_array( true, $this->stored( ValueCodec::active( $this->all_fields() ) )['exists'], true );
    }

    /** The owner's document when its fields are switched on for this release; null otherwise. */
    public function document(): ?array {
        return $this->owner->enabled() ? SchemaRepository::get( $this->owner ) : null;
    }

    /** @return array<string, array> Every top-level field (retired included), keyed by key. */
    public function all_fields(): array {
        $fields = [];
        foreach ( $this->document()['fields'] ?? [] as $field ) {
            $fields[ $field['key'] ] = $field;
        }
        return $fields;
    }

    /** An active top-level field by its name, or by its key when no name matches. */
    public function field( string $selector ): ?array {
        $by_key = null;
        foreach ( $this->all_fields() as $key => $field ) {
            if ( ! empty( $field['retired'] ) ) {
                continue;
            }
            if ( $field['name'] === $selector ) {
                return $field;
            }
            if ( $key === $selector ) {
                $by_key = $field;
            }
        }
        return $by_key;
    }

    public function can_edit(): bool {
        return $this->type === 'options' ? current_user_can( 'manage_options' ) : current_user_can( 'edit_post', $this->id );
    }
}
