<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Forms\Cf7FormWriter;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * A Contact Form 7 form in another language — a VERSION of the form, linked to it in the
 * translation plugin, never a second form.
 *
 * Why a version: a separately created Polish form is one the translation plugin would never
 * know belongs with the original.
 *
 * Needs WPML Multilingual for CF7, which is what makes forms translatable at all. It is shipped
 * to WPML account holders only — not on WordPress.org — so without it the answer is to ask the
 * site admin.
 */
final class TranslateCf7FormAbility extends AbstractAbility {

	private const TYPE = 'wpcf7_contact_form';

	public function name(): string {
		return 'fabrement/translate-cf7-form';
	}

	public function label(): string {
		return 'Translate Contact Form 7 form';
	}

	public function description(): string {
		return 'Make a Contact Form 7 form\'s version in another language, linked to the original. Place the version\'s own'
			. ' shortcode on that language\'s page. Copies the form (fields, mail, settings), sets'
			. ' its locale, and applies what you send: translated template (same control names), subject, messages,'
			. ' title. Edit it later with update-cf7-form by its own id. One version per language.';
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
			'required'             => array( 'formId', 'language' ),
			'properties'           => array(
				'formId'   => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The ORIGINAL form (list-forms).' ),
				'language' => array( 'type' => 'string', 'description' => 'Language code from list-languages.' ),
				'title'    => array( 'type' => 'string', 'description' => 'Title of this version. Default: the original\'s title with the language code.' ),
				'template' => array( 'type' => 'string', 'description' => 'The original markup (get-cf7-form) with labels, placeholders, options and the submit text translated. Control NAMES stay as they are.' ),
				'subject'  => array( 'type' => 'string', 'description' => 'Notification email subject in this language.' ),
				'messages' => CreateCf7FormAbility::messages_schema(),
			),
			'additionalProperties' => false,
		);
	}

	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'form', 'originalId', 'language' ),
			'properties' => array(
				'form'         => array( 'type' => 'object' ),
				'originalId'   => array( 'type' => 'integer' ),
				'language'     => array( 'type' => 'string' ),
				'translations' => array( 'type' => 'object', 'description' => 'language → form id, the whole group.' ),
			),
		);
	}

	public function execute( array $input ) {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return new WP_Error( 'cf7_inactive', 'Contact Form 7 is not active on this site — ask the site admin to install and activate it.' );
		}
		$bridge = Translation::bridge();
		if ( $bridge->is_post_type_translatable( self::TYPE ) !== true ) {
			return new WP_Error( 'forms_not_translatable', Translation::FORMS_MISSING );
		}

		$lang  = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$codes = array_column( $bridge->languages(), 'code' );
		if ( ! in_array( $lang, $codes, true ) ) {
			return new WP_Error( 'bad_language', sprintf( 'Unknown language "%s". Languages here: %s.', $lang, implode( ', ', $codes ) ) );
		}

		$asked = (int) ( $input['formId'] ?? 0 );
		if ( get_post_type( $asked ) !== self::TYPE ) {
			return new WP_Error( 'not_found', sprintf( 'No Contact Form 7 form with id %d — list-forms shows them.', $asked ) );
		}
		$original = $bridge->original_of( $asked, self::TYPE ) ?: $asked;

		$group = $bridge->all_versions( $original, self::TYPE );
		if ( isset( $group[ $lang ] ) ) {
			return new WP_Error(
				'exists',
				sprintf( 'Form %d already has its %s version: %d. Change it with update-cf7-form {formId:%d}.', $original, $lang, $group[ $lang ], $group[ $lang ] )
			);
		}

		$source = \WPCF7_ContactForm::get_instance( $original );
		if ( ! $source ) {
			return new WP_Error( 'not_found', sprintf( 'Contact Form 7 could not load form %d.', $original ) );
		}

		// CF7's own copy: form, mail, mail_2, messages and additional settings, as they stand.
		$copy = $source->copy();
		$copy->set_title( (string) ( $input['title'] ?? '' ) !== '' ? (string) $input['title'] : $source->title() . ' (' . strtoupper( $lang ) . ')' );
		$id = (int) $copy->save();
		if ( $id <= 0 ) {
			return new WP_Error( 'save_failed', 'Contact Form 7 refused to save the copy.' );
		}

		$bridge->link_translation( $id, $original, $lang, self::TYPE );

		// The copy carries the ORIGINAL's locale, and CF7 formats dates, numbers and its own
		// strings by it.
		$locale = self::locale_of( $lang );
		if ( $locale !== '' ) {
			update_post_meta( $id, '_locale', $locale );
		}

		$edits = array_intersect_key( $input, array_flip( array( 'template', 'subject', 'messages' ) ) );
		if ( $edits !== array() ) {
			$result = Cf7FormWriter::update( $id, $edits );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					$result->get_error_code(),
					sprintf( 'The %s version was created (id %d) but your changes were refused: %s Fix them with update-cf7-form {formId:%d}.', $lang, $id, $result->get_error_message(), $id )
				);
			}
		}

		return array(
			'form'         => array(
				'id'        => $id,
				'title'     => (string) get_post_field( 'post_title', $id, 'raw' ),
			),
			'originalId'   => $original,
			'language'     => $lang,
			'translations' => (object) $bridge->visible_versions( $original, self::TYPE ),
		);
	}

	private static function locale_of( string $lang ): string {
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		return is_array( $active ) && isset( $active[ $lang ]['default_locale'] ) ? (string) $active[ $lang ]['default_locale'] : '';
	}
}
