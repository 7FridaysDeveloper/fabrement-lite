<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Taxonomy\TermWriter;

/**
 * Add a term to ANY registered taxonomy of the site.
 *
 * Terms are ordinary content (rows in `wp_terms`), not configuration: adding "Fiction" to
 * `category` is the same act as adding it to a theme's `genre`.
 */
final class CreateTermAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/create-term';
    }

    public function label(): string {
        return 'Create term';
    }

    public function description(): string {
        return 'Add a term to any taxonomy with an admin screen (category and post_tag included). Idempotent: an existing name is '
            . 'returned with created:false. A parent that does not exist is refused.';
    }

    public function contract(): ?string {
        return 'content-model-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'taxonomy', 'name' ],
            'properties'           => [
                'taxonomy'    => [ 'type' => 'string', 'description' => 'Taxonomy slug (see list-taxonomies).' ],
                'name'        => [ 'type' => 'string', 'description' => 'Human name of the term, e.g. "Fiction".' ],
                'slug'        => [ 'type' => 'string', 'description' => 'URL slug (default: derived from the name).' ],
                'parent'      => [
                    'type'        => [ 'string', 'integer' ],
                    'description' => 'Parent term id, slug or name — hierarchical taxonomies only.',
                ],
                'description' => [ 'type' => 'string', 'description' => 'Optional description shown on term archives.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'termId', 'taxonomy', 'name' ],
            'properties' => [
                'termId'   => [ 'type' => 'integer' ],
                'taxonomy' => [ 'type' => 'string' ],
                'name'     => [ 'type' => 'string' ],
                'slug'     => [ 'type' => 'string' ],
                'parent'   => [ 'type' => 'integer' ],
                'created'  => [ 'type' => 'boolean', 'description' => 'False when the term already existed.' ],
                'url'      => [ 'type' => 'string' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        return TermWriter::create_term( (string) ( $input['taxonomy'] ?? '' ), $input );
    }
}
