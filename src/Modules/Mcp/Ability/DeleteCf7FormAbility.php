<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Forms\Cf7FormWriter;

/**
 * Delete a Contact Form 7 form by id. Destructive + irreversible — thin adapter
 * over {@see Cf7FormWriter::delete()}.
 */
final class DeleteCf7FormAbility extends DestructiveAbility {

	public function name(): string {
		return 'fabrement/delete-cf7-form';
	}

	public function label(): string {
		return 'Delete Contact Form 7 form';
	}

	public function description(): string {
		return 'Delete a Contact Form 7 form (irreversible); a block rendering it shows nothing afterwards. '
			. 'Confirm with the user first.';
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
			'required'   => array( 'deleted', 'id' ),
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
				'title'   => array( 'type' => 'string' ),
			),
		);
	}

	public function execute( array $input ) {
		$id = (int) ( $input['formId'] ?? 0 );
		if ( class_exists( '\WPCF7_ContactForm' ) && ! current_user_can( 'wpcf7_delete_contact_form', $id ) ) {
			return new \WP_Error( 'forbidden', sprintf( 'You may not delete form %d (wpcf7_delete_contact_form).', $id ) );
		}
		return Cf7FormWriter::delete( $id );
	}
}
