<?php

namespace Fabrement\Modules\Forms;

use WPCF7_ContactForm;
use WP_Error;

/**
 * Creates and edits Contact Form 7 forms from markup the caller writes.
 *
 * A thin, self-contained wrapper over CF7's own public API
 * (get_template → set_properties → save). The caller hands over the form template, CF7 tags
 * included; everything else a working form needs — the mail body, the Reply-To header, the
 * attachments — is read back out of that template's tags. Returns a plain array on success or
 * a WP_Error naming the refusal.
 */
final class Cf7FormWriter {

	/**
	 * Slugs CF7 rejects as form-control names.
	 *
	 * WordPress's public query vars, which is what CF7's config validator checks against
	 * (contact-form-7/includes/config-validator/form.php::detect_unavailable_names). Copied rather
	 * than read from $GLOBALS['wp']: that list is only populated once WordPress has parsed a
	 * request, and this runs from REST and the CLI too.
	 *
	 * @var list<string>
	 */
	private const RESERVED_NAMES = array(
		'm', 'p', 'posts', 'w', 'cat', 'withcomments', 'withoutcomments', 's', 'search', 'exact',
		'sentence', 'calendar', 'page', 'paged', 'more', 'tb', 'pb', 'author', 'order', 'orderby',
		'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'name', 'category_name', 'tag',
		'feed', 'author_name', 'static', 'pagename', 'page_id', 'error', 'attachment',
		'attachment_id', 'subpost', 'subpost_id', 'preview', 'robots', 'taxonomy', 'term', 'cpage',
		'post_type', 'embed',
	);

	/** Hard cap on controls per form. */
	private const MAX_FIELDS = 40;

	/**
	 * Create a published CF7 form from the markup the caller wrote.
	 *
	 * THE CALLER OWNS THE LAYOUT. This used to take a `fields` array and emit one
	 * `<p><label>…</label></p>` per field — a vertical column and nothing else. Two fields on one
	 * row, a fieldset, a hint under a control, a consent checkbox beside a link: none of them could
	 * be expressed, so the one shape this tool could make was the one shape a real design rarely
	 * asks for. The template comes in as CF7 markup now, and everything that used to be derived
	 * from `fields` is derived from the TAGS IN IT instead — CF7 parses them for us.
	 *
	 * @param string              $title    Form title (admin label).
	 * @param string              $template CF7 form markup, including its [tags].
	 * @param array<string,mixed> $options  { recipient?, subject?, messages? }.
	 * @return array{id:int,title:string,shortcode:string,fields:list<string>}|WP_Error
	 */
	public static function create( string $title, string $template, array $options = array() ) {
		if ( ! class_exists( WPCF7_ContactForm::class ) ) {
			return new WP_Error( 'cf7_inactive', 'Contact Form 7 is not active on this site — ask the site admin to install and activate it.' );
		}

		$title = self::clean_text( $title );
		if ( $title === '' ) {
			return new WP_Error( 'invalid_title', 'A non-empty form title is required.' );
		}

		$form_template = trim( $template );
		if ( $form_template === '' ) {
			return new WP_Error( 'no_template', 'The form template is empty — write the CF7 markup, tags included.' );
		}
		$recipient = self::recipient( $options['recipient'] ?? '' );
		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}

		// Seed CF7's default properties (messages, mail_2, additional_settings),
		// then override only `form` + `mail` — set_properties merges over these.
		$form = WPCF7_ContactForm::get_template( array( 'title' => $title ) );
		$form->set_title( $title );

		// READ THE TAGS BACK OUT OF THE MARKUP. Everything the old `fields` argument was used for
		// — the mail body, the Reply-To, the attachments, the reserved-name check — is a property
		// of the tags, and CF7's own parser is the only thing that agrees with CF7 about what a
		// tag is. Set the template first so the scan sees it.
		$form->set_properties( array( 'form' => $form_template ) );
		$built = self::scan_template( $form );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$mail            = (array) $form->prop( 'mail' );
		$mail['body']    = self::mail_body( $built['rows'], $title );
		$subject         = self::clean_text( (string) ( $options['subject'] ?? '' ) );
		$mail['subject'] = $subject !== ''
			? $subject
			: sprintf( '%s — %s', wp_specialchars_decode( (string) get_bloginfo( 'name' ) ), $title );

		if ( $recipient !== '' ) {
			$mail['recipient'] = $recipient;
		}
		$mail = self::apply_reply_to( $mail, $built['reply_to'] );
		$mail = self::apply_attachments( $mail, $built['attachments'] );

		$props = array(
			'form' => $form_template,
			'mail' => $mail,
		);
		if ( is_array( $options['messages'] ?? null ) && $options['messages'] !== array() ) {
			$merged = self::merge_messages( $form, $options['messages'] );
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
			$props['messages'] = $merged;
		}
		$form->set_properties( $props );

		$post_id = (int) $form->save();
		if ( $post_id < 1 ) {
			return new WP_Error( 'save_failed', 'Contact Form 7 refused to save the form.' );
		}

		return array(
			'id'        => $post_id,
			'title'     => $title,
			'shortcode' => sprintf( '[contact-form-7 id="%d" title="%s"]', $post_id, esc_attr( $title ) ),
			'fields'    => array_column( $built['rows'], 'name' ),
			'messages'  => self::messages_of( $form ),
		);
	}

	/**
	 * Read one form's editable config (for an update round-trip).
	 *
	 * @return array{id:int,title:string,form:string,mail:array{recipient:string,subject:string},shortcode:string}|WP_Error
	 */
	public static function get( int $id ) {
		$form = self::load( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$mail = (array) $form->prop( 'mail' );
		return array(
			'id'        => (int) $form->id(),
			'title'     => (string) $form->title(),
			'form'      => (string) $form->prop( 'form' ),
			'mail'      => array(
				'recipient' => (string) ( $mail['recipient'] ?? '' ),
				'subject'   => (string) ( $mail['subject'] ?? '' ),
			),
			'messages'  => self::messages_of( $form ),
			'shortcode' => sprintf( '[contact-form-7 id="%d" title="%s"]', (int) $form->id(), esc_attr( (string) $form->title() ) ),
		);
	}

	/**
	 * The editable CF7 "Messages" (Messages tab): key → { text, default, description }.
	 * Keys come from CF7's own registry (wpcf7_messages()), so the list always matches
	 * the installed CF7 version; `text` is what this form currently says.
	 *
	 * @return array<string,array{text:string,default:string,description:string}>
	 */
	public static function messages_of( WPCF7_ContactForm $form ): array {
		$current = (array) $form->prop( 'messages' );
		$out     = array();
		foreach ( self::message_registry() as $key => $meta ) {
			$out[ $key ] = array(
				'text'        => (string) ( $current[ $key ] ?? $meta['default'] ),
				'default'     => $meta['default'],
				'description' => $meta['description'],
			);
		}
		return $out;
	}

	/**
	 * CF7's message registry: key → { default, description }.
	 *
	 * @return array<string,array{default:string,description:string}>
	 */
	private static function message_registry(): array {
		$out = array();
		if ( function_exists( 'wpcf7_messages' ) ) {
			foreach ( (array) wpcf7_messages() as $key => $arr ) {
				$out[ (string) $key ] = array(
					'default'     => (string) ( $arr['default'] ?? '' ),
					'description' => (string) ( $arr['description'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Validate + sanitise a caller-supplied messages patch against CF7's registry.
	 * Unknown keys are refused (a typo would silently do nothing); "" resets a key to
	 * CF7's default. Returns the merged messages array ready for set_properties().
	 *
	 * @param array<string,mixed> $patch
	 * @return array<string,string>|WP_Error
	 */
	private static function merge_messages( WPCF7_ContactForm $form, array $patch ) {
		$registry = self::message_registry();
		if ( $registry === array() ) {
			return new WP_Error( 'cf7_messages_unavailable', 'Contact Form 7 did not expose its message registry.' );
		}
		$messages = (array) $form->prop( 'messages' );
		foreach ( $patch as $key => $text ) {
			$key = (string) $key;
			if ( ! isset( $registry[ $key ] ) ) {
				return new WP_Error(
					'unknown_message',
					sprintf( 'Unknown message key "%s". Known keys: %s.', $key, implode( ', ', array_keys( $registry ) ) )
				);
			}
			if ( ! is_string( $text ) ) {
				return new WP_Error( 'invalid_message', sprintf( 'Message "%s" must be a string.', $key ) );
			}
			// As Contact Form 7 saves them (wpcf7_sanitize_messages() only trims; it escapes them on
			// output): as typed for someone who may post unfiltered HTML, through kses for anyone else.
			$text = trim( \Fabrement\Modules\Fields\Types\WordsType::words( $text ) );
			if ( strlen( $text ) > 500 ) {
				return new WP_Error( 'message_too_long', sprintf( 'Message "%s" is longer than 500 characters.', $key ) );
			}
			$messages[ $key ] = $text !== '' ? $text : $registry[ $key ]['default'];
		}
		return $messages;
	}

	/**
	 * Update an existing form. Only provided pieces change: `template` (when given)
	 * replaces the form body WHOLE and regenerates the mail from the tags in it;
	 * title/recipient/subject patch in place.
	 *
	 * A template REPLACES, it does not merge. get-cf7-form returns the current markup — edit that
	 * and send it all back, or the fields left out are gone.
	 *
	 * @param array<string,mixed> $input { title?, template?, recipient?, subject?, messages? }.
	 * @return array{id:int,title:string,shortcode:string,fields:list<string>}|WP_Error
	 */
	public static function update( int $id, array $input ) {
		$form = self::load( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$title         = array_key_exists( 'title', $input ) ? self::clean_text( (string) $input['title'] ) : '';
		$template      = trim( (string) ( $input['template'] ?? '' ) );
		$has_recipient = array_key_exists( 'recipient', $input );
		$has_subject   = array_key_exists( 'subject', $input );
		$messages_in   = is_array( $input['messages'] ?? null ) ? $input['messages'] : array();

		if ( $title === '' && $template === '' && ! $has_recipient && ! $has_subject && $messages_in === array() ) {
			return new WP_Error( 'nothing_to_update', 'Provide at least one of: title, template, recipient, subject, messages.' );
		}
		$recipient = $has_recipient ? self::recipient( $input['recipient'] ) : '';
		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}

		if ( $title !== '' ) {
			$form->set_title( $title );
		}

		$props = array();
		$mail  = (array) $form->prop( 'mail' );
		$names = array();

		if ( $template !== '' ) {
			// Set it before scanning: scan_form_tags() parses whatever `form` currently holds, so
			// the new markup has to be in place for the scan to describe the new form and not the
			// old one. A refusal below leaves this unsaved — save() is the only write.
			$form->set_properties( array( 'form' => $template ) );
			$built = self::scan_template( $form );
			if ( is_wp_error( $built ) ) {
				return $built;
			}
			$props['form'] = $template;
			$mail['body']  = self::mail_body( $built['rows'], $title !== '' ? $title : (string) $form->title() );
			$mail          = self::apply_reply_to( $mail, $built['reply_to'] );
			$mail          = self::apply_attachments( $mail, $built['attachments'] );
			$names         = array_column( $built['rows'], 'name' );
		} else {
			// The form's fields as they stand: a call that changed only a title or a message still
			// answers with them, not with an empty list that reads as a form lost.
			$current = self::scan_template( $form );
			$names   = is_wp_error( $current ) ? array() : array_column( $current['rows'], 'name' );
		}

		if ( $recipient !== '' ) {
			$mail['recipient'] = $recipient;
		}
		if ( $has_subject ) {
			$subject = self::clean_text( (string) $input['subject'] );
			if ( $subject !== '' ) {
				$mail['subject'] = $subject;
			}
		}

		if ( $messages_in !== array() ) {
			$merged = self::merge_messages( $form, $messages_in );
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
			$props['messages'] = $merged;
		}

		$props['mail'] = $mail;
		$form->set_properties( $props );

		$post_id = (int) $form->save();
		if ( $post_id < 1 ) {
			return new WP_Error( 'save_failed', 'Contact Form 7 refused to save the form.' );
		}

		return array(
			'id'        => $post_id,
			'title'     => (string) $form->title(),
			'shortcode' => sprintf( '[contact-form-7 id="%d" title="%s"]', $post_id, esc_attr( (string) $form->title() ) ),
			'fields'    => $names,
			'messages'  => self::messages_of( $form ),
		);
	}

	/**
	 * Delete a form by id.
	 *
	 * @return array{deleted:bool,id:int,title:string}|WP_Error
	 */
	public static function delete( int $id ) {
		$form = self::load( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$title = (string) $form->title();
		if ( ! $form->delete() ) {
			return new WP_Error( 'delete_failed', sprintf( 'Contact Form 7 could not delete form %d.', $id ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
			'title'   => $title,
		);
	}

	/**
	 * Load a saved CF7 form by id, or a WP_Error when CF7 is inactive / not found.
	 *
	 * @return \WPCF7_ContactForm|WP_Error
	 */
	private static function load( int $id ) {
		if ( ! class_exists( WPCF7_ContactForm::class ) ) {
			return new WP_Error( 'cf7_inactive', 'Contact Form 7 is not active on this site — ask the site admin to install and activate it.' );
		}
		if ( $id < 1 ) {
			return new WP_Error( 'invalid_id', 'A valid form id is required.' );
		}
		$form = WPCF7_ContactForm::get_instance( $id );
		if ( ! $form instanceof WPCF7_ContactForm || (int) $form->id() < 1 ) {
			return new WP_Error( 'not_found', sprintf( 'No Contact Form 7 form with id %d.', $id ) );
		}
		return $form;
	}

	/**
	 * What the template's own tags say: the rows for the mail body, the Reply-To field, the file
	 * fields to attach — and a refusal when a control is named something WordPress reserves.
	 *
	 * A RESERVED NAME IS REFUSED, NOT RENAMED. The caller wrote that name, and rewriting it would edit
	 * their markup behind their back, leaving a template whose tag no longer matches the label
	 * beside it. CF7 rejects such a control outright ("Unavailable names … are used for form
	 * controls"), so this is a refusal with the fix in it.
	 *
	 * @return array{rows:list<array{label:string,name:string}>,reply_to:string,attachments:list<string>}|WP_Error
	 */
	private static function scan_template( \WPCF7_ContactForm $form ) {
		$names   = array_values( array_filter( array_map( static fn( $t ): string => (string) $t->name, $form->scan_form_tags() ) ) );
		$unknown = self::unknown_tags( (string) $form->prop( 'form' ), $names );
		if ( $unknown !== array() ) {
			return new WP_Error(
				'unknown_tag',
				sprintf(
					/* translators: %s: the unrecognised tag types, comma-separated. */
					__( 'Contact Form 7 has no such form tag: %s. An unregistered tag is not a control — CF7 prints it on the page as literal text, it never reaches the mail and it is in no field list. Note that [radio] and [acceptance] have NO starred form: a radio group is required by always having a selection, and acceptance is required unless you write `optional`.', 'fabrement-lite' ),
					implode( ', ', array_map( static fn( string $t ): string => '[' . $t . ']', $unknown ) )
				),
				array( 'status' => 422, 'unknownTags' => $unknown )
			);
		}

		$rows        = array();
		$attachments = array();
		$reply_to    = '';
		$reserved    = array();

		foreach ( $form->scan_form_tags() as $tag ) {
			$name = (string) $tag->name;
			$type = (string) $tag->basetype;
			if ( $name === '' || $type === 'submit' ) {
				continue;
			}

			if ( in_array( $name, self::RESERVED_NAMES, true ) ) {
				$reserved[ $name ] = true;
				continue;
			}

			// The label is for the mail body only. CF7 has no notion of one, so the name is the
			// honest fallback: "your-name" reads well enough in an email, and inventing a prettier
			// one from markup we did not write would be guessing.
			$rows[] = array( 'label' => $name, 'name' => $name );

			if ( 'file' === $type ) {
				$attachments[] = $name;
			}
			if ( 'email' === $type && '' === $reply_to && $tag->is_required() ) {
				$reply_to = $name;
			}
		}

		if ( $reserved !== array() ) {
			return new WP_Error(
				'reserved_name',
				sprintf(
					/* translators: 1: the offending control names, 2: a suggested rename. */
					__( 'Rename these form controls — WordPress reserves the names and CF7 refuses them: %1$s. Prefix each one, the way CF7 names its own: %2$s.', 'fabrement-lite' ),
					implode( ', ', array_keys( $reserved ) ),
					implode( ', ', array_map( static fn( string $n ): string => 'your-' . $n, array_keys( $reserved ) ) )
				),
				array( 'status' => 422, 'reservedNames' => array_keys( $reserved ) )
			);
		}

		if ( $rows === array() ) {
			return new WP_Error( 'no_fields', 'The template has no form controls — a [text], [email] or other tag is needed, not just markup.' );
		}
		if ( count( $rows ) > self::MAX_FIELDS ) {
			return new WP_Error( 'too_many_fields', sprintf( 'A form may have at most %d controls.', self::MAX_FIELDS ) );
		}

		return array( 'rows' => $rows, 'reply_to' => $reply_to, 'attachments' => $attachments );
	}

	/**
	 * The bracketed things in the template that were MEANT as a form tag and are not one.
	 *
	 * THE FAILURE THIS CATCHES IS SILENT. CF7 substitutes only a tag type it has registered, so
	 * `[radio* your-plan "A" "B"]` — `radio*` is not registered, only `radio` is — is left alone:
	 * the page shows the tag's source text, the control never exists, and neither
	 * scan_form_tags() nor the config validator says a word.
	 *
	 * AN UNREGISTERED TAG IS INDISTINGUISHABLE FROM TEXT BY TYPE ALONE, which is exactly why CF7
	 * ignores it. So this does not ask "is the word a known type" — `[Required]`, "fields
	 * [see terms]", `data-x="[foo]"` and a `[gallery ids="1"]` shortcode are all unknown types and
	 * all legitimate. It asks whether the brackets carry a sign of a TAG being written, one no
	 * prose carries:
	 *
	 *   - a star on the type — `[radio*`, `[emial*`. Nothing but a CF7 tag is spelled that way;
	 *   - a second word shaped like a control name — `[emial your-email]`: a letter, then a
	 *     hyphen or underscore, and no `=` or quote, which is what separates it from a
	 *     shortcode's `ids="1"` or two words of prose;
	 *   - a lone mail-tag of a field THIS form has — `[your-name]` in the form body, which CF7
	 *     only substitutes in the mail and prints verbatim on the page.
	 *
	 * Matched the way CF7 matches: no space after the opening bracket, and `[[…]]` skipped,
	 * because doubling the brackets is CF7's own way of printing a tag literally.
	 *
	 * @param list<string> $names The control names the template does declare.
	 * @return list<string>
	 */
	private static function unknown_tags( string $template, array $names ): array {
		if ( ! class_exists( '\WPCF7_FormTagsManager' ) ) {
			return array();
		}

		$manager = \WPCF7_FormTagsManager::get_instance();
		if ( ! method_exists( $manager, 'tag_type_exists' ) ) {
			return array();
		}

		if ( ! preg_match_all( '/(?<!\[)\[([a-zA-Z][0-9a-zA-Z:._-]*)(\*?)(?:\s+([^\s\]]+))?[^\]]*\](?!\])/', $template, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$unknown = array();
		foreach ( $matches as $m ) {
			$type   = $m[1];
			$star   = $m[2];
			$second = $m[3] ?? '';

			if ( $manager->tag_type_exists( $type . $star ) ) {
				continue;
			}

			$meant = $star !== ''
				|| ( $second !== '' && preg_match( '/^[a-zA-Z][0-9a-zA-Z]*[-_][0-9a-zA-Z_-]*$/', $second ) )
				|| ( $second === '' && in_array( $type, $names, true ) );

			if ( $meant ) {
				$unknown[ $type . $star ] = true;
			}
		}
		return array_keys( $unknown );
	}

	/**
	 * A plain-text mail body echoing every field via CF7 mail-tags.
	 *
	 * @param list<array{label:string,name:string}> $rows
	 */
	private static function mail_body( array $rows, string $title ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = sprintf( '%s: [%s]', $row['label'], $row['name'] );
		}
		return sprintf(
			"New submission from \"%s\":\n\n%s\n\n-- \nSent from [_site_title] ([_site_url])",
			$title,
			implode( "\n", $lines )
		);
	}

	/**
	 * Normalise the mail's Reply-To: strip any existing Reply-To header (CF7's
	 * default template references a `[your-email]` tag the caller's form may not
	 * contain), then point it at $reply_to when the form has an email field.
	 *
	 * @param array<string,mixed> $mail
	 * @return array<string,mixed>
	 */
	private static function apply_reply_to( array $mail, string $reply_to ): array {
		$headers = trim( (string) ( $mail['additional_headers'] ?? '' ) );
		$headers = trim( (string) preg_replace( '/^\s*Reply-To:.*$/mi', '', $headers ) );
		if ( $reply_to !== '' ) {
			$headers = trim( "Reply-To: [{$reply_to}]\n{$headers}" );
		}
		$mail['additional_headers'] = $headers;
		return $mail;
	}

	/**
	 * Reference every uploaded-file field in the mail's `attachments` list (one
	 * `[field-name]` mail-tag per line) so CF7 attaches the upload to the notification.
	 *
	 * DERIVED, SO REPLACED — the same rule as apply_reply_to() above. The mail-tag lines are the
	 * template's file fields and nothing else, so they are dropped and written again from the
	 * current tags. Appending instead added a second `[your-cv]` on every update that kept the
	 * field, and returning early when there were none left the tag of a REMOVED field behind,
	 * pointing at a control that no longer exists. A line that is not a mail-tag — a file path
	 * somebody typed into the CF7 admin — was never ours to derive and stays.
	 *
	 * @param array<string,mixed> $mail
	 * @param list<string>        $names File field slugs.
	 * @return array<string,mixed>
	 */
	private static function apply_attachments( array $mail, array $names ): array {
		$kept = array();
		foreach ( preg_split( '/\R/', (string) ( $mail['attachments'] ?? '' ) ) ?: array() as $line ) {
			$line = trim( $line );
			if ( $line !== '' && ! preg_match( '/^\[[^\[\]]+\]$/', $line ) ) {
				$kept[] = $line;
			}
		}
		foreach ( $names as $name ) {
			$kept[] = '[' . $name . ']';
		}
		$mail['attachments'] = implode( "\n", $kept );
		return $mail;
	}

	/** Trim, collapse whitespace and strip tags from a short user-supplied string. */
	/**
	 * The mail recipient as given: '' keeps the current one, anything else must be one valid address.
	 *
	 * @param mixed $value
	 * @return string|WP_Error
	 */
	private static function recipient( $value ) {
		$value = trim( is_string( $value ) ? $value : '' );
		if ( $value === '' ) {
			return '';
		}
		if ( ! is_email( $value ) ) {
			return new WP_Error( 'invalid_recipient', sprintf( 'recipient "%s" is not a valid email address. Nothing was saved.', $value ) );
		}
		return $value;
	}

	private static function clean_text( string $value ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
	}
}
