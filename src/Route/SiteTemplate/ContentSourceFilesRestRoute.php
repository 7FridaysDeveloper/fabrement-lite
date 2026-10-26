<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\ContentSource\ThemeTemplateFiles;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** GET /content-source/files — the theme PHP files a template's content slot may show. */
final class ContentSourceFilesRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/content-source/files';
    }

    public function get_methods() {
        return WP_REST_Server::READABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function handle( WP_REST_Request $request ) {
        $files = [];
        foreach ( ThemeTemplateFiles::all() as $file ) {
            $files[] = [
                'path'     => (string) $file['path'],
                'label'    => (string) ( $file['label'] ?? $file['path'] ),
                'source'   => 'theme',
                'kind'     => $file['kind'] ?? null,
                'declares' => $file['declares'] ?? null,
            ];
        }
        return new WP_REST_Response( [ 'files' => $files ], 200 );
    }
}
