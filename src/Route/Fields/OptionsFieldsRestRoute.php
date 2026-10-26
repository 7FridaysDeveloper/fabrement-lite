<?php

namespace Fabrement\Route\Fields;

use Fabrement\Modules\Fields\Values\FieldObject;
use WP_REST_Request;

/** Options use the same editor request and save pipeline, with a language-specific target. */
final class OptionsFieldsRestRoute extends PostFieldsRestRoute {
    public function get_route(): string {
        return '/fields/options/(?P<slug>[a-z0-9][a-z0-9_-]{0,31})';
    }

    public function get_route_args(): array {
        return [
            'slug' => [ 'type' => 'string', 'required' => true ],
            'lang' => [ 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{0,20}$', 'default' => '' ],
        ];
    }

    protected function object( WP_REST_Request $request ): ?FieldObject {
        return FieldObject::options( (string) $request['slug'], (string) $request['lang'] );
    }
}
