<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Taxonomy\TermWriter;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Give a term a version in another language — its own name, slug and description.
 *
 * This is what makes a TERM ARCHIVE read in that language. A term archive has no page behind it:
 * `/pl/gatunki/fikcja/` is the taxonomy's translated base followed by the term's own slug,
 * which is this. Nothing else writes the second half.
 *
 * Not create-term with a language argument, and the difference matters. create-term asks WordPress
 * whether a term with that slug exists, and under WPML that question is answered across every
 * language at once — so a Polish "Fikcja" slugged the same as an English original comes back as the
 * English term rather than as a new one. A translation has to be created and then JOINED to its
 * original, which is a different operation, not a flag on the same one.
 */
final class TranslateTermAbility extends AbstractAbility {

	public function name(): string {
		return 'fabrement/translate-term';
	}

	public function label(): string {
		return 'Translate a term';
	}

	public function description(): string {
		return 'Give a term a version in one language — name, slug and description of its own. The term archive'
			. ' answers on that slug in that language. Its BASE (the taxonomy part of the URL) is set for the taxonomy in the translation plugin. Its SEO title'
			. ' and description are set-seo {termId: the version}; get-seo {termId} shows what each language still lacks.';
	}

	public function contract(): ?string {
		return 'translation-contract';
	}

	public function is_available(): bool {
		return Translation::is_multilingual();
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'termId', 'language', 'name' ),
			'properties'           => array(
				'termId'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'The ORIGINAL term id (from list-terms).',
				),
				'language'    => array(
					'type'        => 'string',
					'description' => 'Language code (from list-languages). The term\'s own language is refused — edit it with update-term.',
				),
				'name'        => array(
					'type'        => 'string',
					'description' => 'The term name in this language.',
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => 'The term slug in this language, and the last segment of its archive URL. WordPress keeps term'
						. ' slugs unique across ALL languages, so leaving this out on a name that matches the original gets'
						. ' you a "-pl" suffix rather than a clean address. Give one.',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'The term description in this language.',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function execute( array $input ) {
		$term_id  = (int) ( $input['termId'] ?? 0 );
		$language = trim( (string) ( $input['language'] ?? '' ) );
		$name     = trim( (string) ( $input['name'] ?? '' ) );

		if ( $name === '' ) {
			return new WP_Error( 'fabrement_bad_name', 'name is required — a term version with no name has nothing to show in a menu or an archive heading.', array( 'status' => 400 ) );
		}

		// WP_Term::get_instance, NOT get_term(): get_term() is language-adjusted, so an id given for
		// one version comes back as another version's row — and everything read off it belongs to
		// that other term. In a hierarchical taxonomy that means `parent` is the OTHER language's
		// parent, and the new version is filed under it: the translation lands somewhere else in
		// the tree, silently. The refusal about an untranslated parent then names a term the caller
		// never mentioned.
		//
		// Same call, same reason, as TermWriter::term_id() and ArchiveTemplateBinding.
		$term = \WP_Term::get_instance( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return new WP_Error( 'fabrement_term_missing', sprintf( 'No term with id %d — see list-terms.', $term_id ), array( 'status' => 404 ) );
		}
		$taxonomy = (string) $term->taxonomy;
		$refused  = TermWriter::refuse_term_taxonomy( $taxonomy );
		if ( $refused !== null ) {
			return $refused;
		}

		$bridge = Translation::bridge();

		$codes = array_column( $bridge->languages(), 'code' );
		if ( ! in_array( $language, $codes, true ) ) {
			// NO languages at all is a different answer from "that one is not among these". The
			// site is monolingual — no translation plugin, or one Fabrement has no bridge for —
			// and "Active: " with nothing after it reads like the list failed to load, sending
			// the reader to list-languages to find the same emptiness.
			return new WP_Error(
				'fabrement_language_missing',
				$codes === array()
					? 'This site has one language, so a term has no version in another. A translation plugin (WPML) has to be active first.'
					: sprintf( 'The site has no "%s" language. Active: %s (list-languages).', $language, implode( ', ', $codes ) ),
				array( 'status' => 400 )
			);
		}

		// A term of an untranslated taxonomy has no language at all, so there is no original for a
		// version to be a version OF.
		$source = $bridge->term_language( $term_id, $taxonomy );
		if ( $source === '' ) {
			return new WP_Error(
				'fabrement_taxonomy_not_translated',
				sprintf( 'Terms of taxonomy "%s" have no language — the translation plugin does not translate it, so this term cannot have a version in another. That is set in the translation plugin settings.', $taxonomy ),
				array( 'status' => 409 )
			);
		}
		if ( $source === $language ) {
			return new WP_Error(
				'fabrement_same_language',
				sprintf( 'Term %d is already the "%s" version — edit it with update-term rather than translating it into its own language.', $term_id, $language ),
				array( 'status' => 400 )
			);
		}

		// THE ORIGINAL, as the schema says and nothing checked. Given a VERSION's id this went on
		// working: it found the group's member in the target language and wrote the name into it —
		// so translating a Polish version "into English" RENAMED the English original, answered
		// success, and reported the version as `originalId`. The only hint was `created: false`,
		// which reads like an ordinary idempotent repeat.
		//
		// translate-content and translate-template have refused this from the start; terms are the
		// path that did not. Same refusal code, so an agent meets one rule rather than three.
		$original = $bridge->original_term_id( $term_id, $taxonomy );
		if ( $original > 0 && $original !== $term_id ) {
			return new WP_Error(
				'not_an_original',
				sprintf( 'Term %d is itself a language version ("%s"). Pass the original — id %d (list-terms reports it as `originalId`). Translating a version would write over whichever member of the group speaks the language you asked for.', $term_id, $source, $original ),
				array( 'status' => 409, 'originalId' => $original )
			);
		}

		// A child term whose parent has no version in this language would land at the top level:
		// silently reparented, and its archive URL short of a segment. Said rather than guessed.
		$parent = 0;
		if ( (int) $term->parent > 0 ) {
			$parent = $bridge->version_in( (int) $term->parent, $taxonomy, $language );
			if ( $parent <= 0 ) {
				// The parent the term actually names — get_term() would answer with whichever version
				// this request's language resolves to, and the refusal would name a term the caller
				// never mentioned.
				$parent_term = \WP_Term::get_instance( (int) $term->parent, $taxonomy );

				return new WP_Error(
					'fabrement_parent_untranslated',
					sprintf(
						'Term %d sits under "%s" (id %d), which has no "%s" version — translating this one alone would move it to the top level in that language. Translate the parent first: translate-term {termId:%d, language:"%s", ...}.',
						$term_id,
						$parent_term instanceof \WP_Term ? $parent_term->name : (string) $term->parent,
						(int) $term->parent,
						$language,
						(int) $term->parent,
						$language
					),
					array( 'status' => 409 )
				);
			}
		}

		$fields = array( 'name' => $name, 'parent' => $parent );
		if ( isset( $input['slug'] ) && trim( (string) $input['slug'] ) !== '' ) {
			$fields['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( array_key_exists( 'description', $input ) ) {
			$fields['description'] = (string) $input['description'];
		}

		// The third door into wp_terms: a version is a fresh row with a name of its own.
		$long = \Fabrement\Modules\Taxonomy\TermWriter::refuse_long_term_name( $name );
		if ( is_wp_error( $long ) ) {
			return $long;
		}

		$existing = $bridge->version_in( $term_id, $taxonomy, $language );
		$created  = $existing <= 0 || $existing === $term_id;

		// WordPress keeps term slugs unique across the whole taxonomy, and WPML makes term_exists()
		// answer across every language at once — so a slug held by another language's term is not
		// available here. Said before the write, because the write's own failure is a bare
		// "term_exists" with nothing in it about which term, or in what language.
		// WordPress refuses a second term with the same NAME under the same parent, and under WPML
		// that check is cross-language too — so a version named exactly like its original cannot be
		// created at all. Without this the write fails with a bare "term_exists" and the caller is
		// told the plugin refused, with nothing about which of the two fields was the problem.
		// WordPress will not take a second term with this slug anywhere in the taxonomy, nor a
		// second term with this name under the same parent. Both are checked here so the caller is
		// told WHICH field collided and with what; the write's own failure is a bare "term_exists".
		//
		// Asked of the DATABASE, not of term_exists(). That function is language-adjusted, and the
		// adjustment defeats the very check: asked from the target language about a slug held by
		// another language's term, it answers with that term's counterpart IN the target language —
		// which is the version being written, so the collision maps itself away and is never seen.
		$clash = self::slug_owner( $taxonomy, $fields['slug'] ?? '' );
		if ( $clash > 0 && $clash !== $existing ) {
			return self::taken( 'slug', (string) $fields['slug'], $clash, $taxonomy, $bridge );
		}

		// The NAME check only applies when the caller did NOT give a slug of their own. WordPress
		// refuses a duplicate name under one parent in exactly one case (wp_insert_term):
		//
		//     if ( ! $slug_provided || $name_match->slug === $slug || $slug_match )
		//
		// — a repeated name with an explicit, free slug is accepted. And that is the ordinary shape
		// of a translation: "Rock" in English and "Rock" in Polish are the same word, told apart by
		// rock / rock-pl. Refusing it told the caller to "pick another name", which is an
		// instruction to mistranslate in order to get past a check of ours that WordPress does not
		// make.
		//
		// The slug is already known to be free — the guard above returned otherwise.
		$slug_given = ( $fields['slug'] ?? '' ) !== '';

		if ( ! $slug_given ) {
			$clash = self::name_owner( $taxonomy, $name, $parent );
			if ( $clash > 0 && $clash !== $existing ) {
				return self::taken( 'name', $name, $clash, $taxonomy, $bridge );
			}
		}

		$new_id = $bridge->translate_term( $term_id, $taxonomy, $language, $fields );
		if ( $new_id <= 0 ) {
			return new WP_Error( 'fabrement_term_write_failed', 'The translation plugin refused to store the term version. Nothing was changed.', array( 'status' => 500 ) );
		}

		// READ IT BACK IN ITS OWN LANGUAGE. get_term() and get_term_link() are language-adjusted by
		// the translation plugin: asked from the request's language they answer with that language's
		// counterpart, so a version just written in another one is reported as the term it was made
		// from — its name, its slug, its address. Measured: a version written with slug "novel-en"
		// came back as "novel" with the original's URL, and the warning below fired on the
		// difference between a term and itself.
		$read = $bridge->in_language(
			$language,
			static fn() => array( get_term( $new_id, $taxonomy ), get_term_link( $new_id, $taxonomy ) )
		);

		$version = $read[0];
		$link    = $read[1];

		$warnings = array();
		$asked    = isset( $fields['slug'] ) ? $fields['slug'] : '';
		if ( $asked !== '' && $version instanceof \WP_Term && $version->slug !== $asked ) {
			$warnings[] = sprintf(
				'The slug became "%s", not "%s": WordPress keeps term slugs unique across every language at once, and that one was taken. Pick a different slug if this address matters.',
				$version->slug,
				$asked
			);
		}

		return array(
			'termId'      => $new_id,
			'originalId'  => $term_id,
			'taxonomy'    => $taxonomy,
			'language'    => $language,
			'created'     => $created,
			'name'        => $version instanceof \WP_Term ? $version->name : $name,
			'slug'        => $version instanceof \WP_Term ? $version->slug : '',
			// A taxonomy nobody can view has no term pages, though WordPress still builds an address.
			'url'         => is_string( $link ) && is_taxonomy_viewable( $taxonomy ) ? $link : '',
			'translations' => Translation::term_translations( $term_id, $taxonomy ),
			'warnings'    => $warnings,
		);
	}

	/** The term holding this slug anywhere in the taxonomy, or 0 — read past every language filter. */
	private static function slug_owner( string $taxonomy, string $slug ): int {
		global $wpdb;

		if ( $slug === '' ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the slug owner in any language: WordPress keeps term slugs unique across the whole taxonomy, but term queries are language-filtered.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1",
				$taxonomy,
				$slug
			)
		);

		return $id === null ? 0 : (int) $id;
	}

	/** The term holding this name UNDER THE SAME PARENT — WordPress allows the name elsewhere. */
	private static function name_owner( string $taxonomy, string $name, int $parent ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the name owner under the same parent in any language: term queries are language-filtered.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tt.taxonomy = %s AND t.name = %s AND tt.parent = %d LIMIT 1",
				$taxonomy,
				$name,
				$parent
			)
		);

		return $id === null ? 0 : (int) $id;
	}

	private static function taken( string $field, string $value, int $owner, string $taxonomy, $bridge ): WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read past the translation plugin's get_term() language adjustment: the owner's own name.
		$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $owner ) );
		$lang = $bridge->term_language( $owner, $taxonomy );

		return new WP_Error(
			'fabrement_' . $field . '_taken',
			sprintf(
				'The %s "%s" already belongs to term "%s" (id %d, language "%s") in this taxonomy. WordPress keeps term slugs unique across the whole taxonomy — every language at once — and names unique under one parent, so pick another.',
				$field,
				$value,
				$name,
				$owner,
				$lang !== '' ? $lang : 'none'
			),
			array( 'status' => 409 )
		);
	}
}
