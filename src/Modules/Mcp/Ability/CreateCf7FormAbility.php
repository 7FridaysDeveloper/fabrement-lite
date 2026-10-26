<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Forms\Cf7FormWriter;

/**
 * Create a Contact Form 7 form from the markup the caller writes. Thin adapter over
 * {@see Cf7FormWriter::create()} — the form shows up in list-forms exactly like a hand-made one.
 */
final class CreateCf7FormAbility extends AbstractAbility {

	public function name(): string {
		return 'fabrement/create-cf7-form';
	}

	public function label(): string {
		return 'Create Contact Form 7 form';
	}

	public function description(): string {
		return 'Create a Contact Form 7 form (CF7 must be active). YOU WRITE THE MARKUP: `template` is the CF7'
			. ' form body — your HTML plus CF7 [tags] — so the layout is whatever the design asks for, not a'
			. ' column of generated paragraphs. The mail body, Reply-To and attachments are derived from the tags'
			. ' in it. Returns { form: { id, title, shortcode, fields } } — place it with the Contact Form 7 block or its shortcode.'
			. ' LIST-FORMS FIRST: a duplicate title is NOT refused, so calling this'
			. ' twice leaves two forms with the same name and no way to tell which one a page renders. Reuse the'
			. ' existing form, or update it with update-cf7-form.';
	}

	public function contract(): ?string {
		return 'forms-contract';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'title', 'template' ),
			'properties'           => array(
				'title'       => array(
					'type'        => 'string',
					'description' => 'Form title (admin label).',
				),
				'template'    => array(
					'type'        => 'string',
					'description' => 'CF7 form markup — the layout is yours to write, tags included. One control per CF7 tag: [text* your-name], [email* your-email], [tel your-phone], [textarea your-message], [select your-topic "Sales" "Support"], [acceptance your-consent], [file your-cv filetypes:pdf|doc], and [submit "Send"] for the button — a trailing * means required. Everything around them is your HTML: rows, fieldsets, hints, a consent line with a link. Wrap each control in its own element and give it classes, because the CSS is written against YOUR wrappers (CF7 adds its own classes to the control itself at render time). WHAT IS DERIVED FROM THIS MARKUP: the notification mail body (one line per control), the Reply-To (the first REQUIRED email tag — an optional one is skipped, CF7 rejects a possibly-blank mailbox), and the file attachments (every file tag). A control named after a WordPress query var (name, search, author, page, day, order, term, …) is REFUSED, not renamed: prefix it the way CF7 does, your-name. At most 40 controls.',
				),
				'recipient'   => array(
					'type'        => 'string',
					'description' => 'Email address the submissions are sent to (default: site admin).',
				),
				'subject'     => array(
					'type'        => 'string',
					'description' => 'Notification email subject line.',
				),
				'messages'    => self::messages_schema(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * What the `shortcode` a form tool returns is for — one text for every tool that returns it, so
	 * the answer never differs by which tool the agent happened to call.
	 */
	public const SHORTCODE_NOTE = 'For placing the form in page content with a shortcode block.';

	/** The `messages` patch schema (CF7 Messages tab), shared by create-cf7-form and update-cf7-form. */
	public static function messages_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => 'CF7 "Messages" tab as {key: text} — mail_sent_ok, validation_error, invalid_required, invalid_email, … (get-cf7-form lists every key with its current text and default). Only the keys sent change; "" resets one to the default; unknown keys are refused.',
			'additionalProperties' => array( 'type' => 'string' ),
		);
	}

	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'form' ),
			'properties' => array(
				'form' => array(
					'type'       => 'object',
					'required'   => array( 'id', 'title', 'shortcode', 'fields' ),
					'properties' => array(
						'id'        => array( 'type' => 'integer', 'description' => 'The form id the shortcode carries.' ),
						'title'     => array( 'type' => 'string' ),
						'shortcode' => array( 'type' => 'string', 'description' => self::SHORTCODE_NOTE ),
						'fields'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'The control names read back out of the saved template, in order — what the mail tags and the CSS selectors have to match.',
						),
						'messages'  => array( 'type' => 'object', 'description' => 'Only when the call SENT messages: the keys as they now stand, to check what landed. get-cf7-form lists all of them with their defaults.' ),
					),
				),
				'note' => array( 'type' => 'string', 'description' => 'Present when another form already carries this title.' ),
			),
		);
	}

	public function annotations(): array {
		return array( 'destructiveHint' => false );
	}

	public function execute( array $input ) {
		$result = Cf7FormWriter::create(
			(string) ( $input['title'] ?? '' ),
			(string) ( $input['template'] ?? '' ),
			array(
				'recipient'   => $input['recipient'] ?? null,
				'subject'     => $input['subject'] ?? null,
				'messages'    => $input['messages'] ?? null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// THE MESSAGES DUMP IS 97% OF THE ANSWER. CF7 seeds ~23 message keys with their defaults
		// and their descriptions, and create() hands all of them back — 4.2KB of boilerplate on a
		// 127-byte form, on every call, whether or not the caller ever mentioned messages. Kept
		// only when the call SENT some, where it is the confirmation of what landed; get-cf7-form
		// is where the full list belongs.
		if ( ! isset( $input['messages'] ) || ! is_array( $input['messages'] ) || $input['messages'] === array() ) {
			unset( $result['messages'] );
		}

		$out = array( 'form' => $result );

		// A DUPLICATE TITLE IS ALLOWED, so say when one happened. CF7 itself permits it and the
		// forms are told apart only by id, which is exactly how a site ends up with two "Contact"
		// forms and a page rendering whichever one somebody pasted. Refusing would be wrong —
		// there are real reasons for two — but silence is worse than a sentence.
		$twins = self::same_title( (string) ( $input['title'] ?? '' ), (int) ( $result['id'] ?? 0 ) );
		if ( $twins !== array() ) {
			$out['note'] = sprintf(
				/* translators: 1: the shared title, 2: comma-separated ids of the other forms. */
				__( 'Another form is already called "%1$s" (id %2$s). Nothing stops two, but they are told apart only by id — reuse the existing one with update-cf7-form, or rename this one, unless two really were wanted.', 'fabrement-lite' ),
				(string) ( $input['title'] ?? '' ),
				implode( ', ', $twins )
			);
		}

		return $out;
	}

	/**
	 * Ids of other CF7 forms carrying this exact title.
	 *
	 * @return list<int>
	 */
	private static function same_title( string $title, int $exclude ): array {
		$title = trim( $title );
		if ( $title === '' || ! class_exists( '\WPCF7_ContactForm' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'              => 'wpcf7_contact_form',
				'post_status'            => 'any',
				'title'                  => $title,
				'posts_per_page'         => 5,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id !== $exclude ) );
	}
}
