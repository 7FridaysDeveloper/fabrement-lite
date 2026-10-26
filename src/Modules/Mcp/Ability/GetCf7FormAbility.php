<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Forms\Cf7FormWriter;

/**
 * Read one Contact Form 7 form's editable config. Thin adapter over
 * {@see Cf7FormWriter::get()} — call before update-cf7-form.
 */
final class GetCf7FormAbility extends AbstractAbility {

	public function name(): string {
		return 'fabrement/get-cf7-form';
	}

	public function label(): string {
		return 'Get Contact Form 7 form';
	}

	public function description(): string {
		return 'Read one Contact Form 7 form: title, raw template, mail recipient/subject, every message key '
			. '(current, default, description), shortcode (page content only — the Contact Form 7 block takes the form id in its `id` attribute). Read before update-cf7-form.';
	}

	public function contract(): ?string {
		return 'forms-contract';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'formId' ),
			'properties'           => array(
				'formId' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Form id from list-forms.',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'form' ),
			'properties' => array( 'form' => array( 'type' => 'object' ) ),
		);
	}

	public function annotations(): array {
		return array( 'readOnlyHint' => true );
	}

	public function execute( array $input ) {
		$result = Cf7FormWriter::get( (int) ( $input['formId'] ?? 0 ) );
		return is_wp_error( $result ) ? $result : array( 'form' => $result );
	}
}
