<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Forms\Cf7FormWriter;

/**
 * Update an existing Contact Form 7 form. Thin adapter over
 * {@see Cf7FormWriter::update()} — provide only the pieces that change.
 */
final class UpdateCf7FormAbility extends AbstractAbility {

	public function name(): string {
		return 'fabrement/update-cf7-form';
	}

	public function label(): string {
		return 'Update Contact Form 7 form';
	}

	public function description(): string {
		return 'Update a Contact Form 7 form — send only what changes. `template` REPLACES the form body whole'
			. ' and re-derives the mail from the tags in it, so edit the markup get-cf7-form returns rather than'
			. ' sending a fragment: what you leave out is gone. `messages` patches only the keys sent ("" resets'
			. ' one to the default). get-cf7-form first.';
	}

	public function contract(): ?string {
		return 'forms-contract';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'formId' ),
			'properties'           => array(
				'formId'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Form id from list-forms.',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => 'New form title (omit to keep).',
				),
				'template'    => array(
					'type'        => 'string',
					'description' => 'The FULL CF7 form markup, replacing what is there — get-cf7-form returns the current one to edit. Same rules as create-cf7-form: your HTML around CF7 [tags], [submit "…"] for the button, a reserved control name (name, search, author, page, …) refused rather than renamed, at most 40 controls.',
				),
				'recipient'   => array(
					'type'        => 'string',
					'description' => 'Email address the submissions are sent to.',
				),
				'subject'     => array(
					'type'        => 'string',
					'description' => 'Notification email subject line.',
				),
				'messages'    => CreateCf7FormAbility::messages_schema(),
			),
			'additionalProperties' => false,
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
						'id'        => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'shortcode' => array( 'type' => 'string', 'description' => CreateCf7FormAbility::SHORTCODE_NOTE ),
						'fields'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'The control names read back out of the template THIS CALL wrote. Empty when the call sent no template — the form keeps its controls; get-cf7-form lists them.',
						),
						'messages'  => array( 'type' => 'object', 'description' => 'Only when the call SENT messages: the keys as they now stand.' ),
					),
				),
			),
		);
	}

	public function annotations(): array {
		return array( 'destructiveHint' => true );
	}

	public function execute( array $input ) {
		$id     = (int) ( $input['formId'] ?? 0 );
		$result = Cf7FormWriter::update( $id, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Same rule as create-cf7-form: the ~23-key messages dump is 4KB of CF7 boilerplate, and
		// a call that changed a subject line has no use for it. Kept only as confirmation when
		// the call sent messages.
		if ( ! isset( $input['messages'] ) || ! is_array( $input['messages'] ) || $input['messages'] === array() ) {
			unset( $result['messages'] );
		}

		return array( 'form' => $result );
	}
}
