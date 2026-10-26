<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\McpAbilityException;

/**
 * Puts a media file into this site's library and hands the agent back the {id, url}
 * pair image-shaped block attributes expect — or a plain file URL for
 * documents.
 *
 * Two sources, exactly one per call. Nothing is read from the server's own disk.
 *  - `sourceUrl` — a remote http(s) file, downloaded by the site. Cheapest: any
 *    web asset arrives this way.
 *  - `base64`    — raw bytes inline. The only way to move a file off the agent's own
 *    machine onto a REMOTE site, but the agent must emit every byte as tokens, so it is
 *    size-capped and should stay the last resort.
 */
final class UploadMediaAbility extends AbstractAbility {

    /** Image extensions — these REQUIRE altText and get a decodability check. */
    private const IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];

    /**
     * Safe non-image extensions the library accepts (documents, av media). Everything
     * else is rejected here, and WordPress re-validates the real mime on sideload —
     * nothing can smuggle a PHP file in under a fake name.
     */
    private const FILE_EXTENSIONS = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'mp4', 'webm', 'mov', 'mp3', 'ogg', 'wav', 'zip' ];

    /** download_url() deadline for a remote file, in seconds. */
    private const DOWNLOAD_TIMEOUT = 30;

    /**
     * Decoded-size ceiling for `base64`. Not a server limit — a token limit: 512 KB of
     * bytes is ~700 KB of base64 text the agent has to type. Anything bigger belongs
     * behind a URL.
     */
    private const MAX_BASE64_BYTES = 512 * 1024;

    public function name(): string {
        return 'fabrement/upload-media';
    }

    public function label(): string {
        return 'Upload media';
    }

    public function description(): string {
        return 'Upload one file into the media library from EXACTLY ONE source — sourceUrl (preferred)'
            . ' or base64 (≤512 KB) — and get {id, url, …} for a block\'s media '
            . 'attribute. altText is REQUIRED for images. list-media first: it may already exist.';
    }

    public function contract(): ?string {
        return 'site-assets-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'sourceUrl' => [
                    'type'        => 'string',
                    'description' => 'Remote http(s) URL to download into the library. One source only.',
                ],
                'base64'    => [
                    'type'        => 'string',
                    'description' => 'File bytes as base64 (a "data:…;base64," prefix is accepted and stripped). '
                        . 'Requires filename. Max 512 KB decoded. Last resort — prefer sourceUrl.',
                ],
                'filename'  => [
                    'type'        => 'string',
                    'description' => 'Filename to store it under, e.g. "hero-bg.webp". Defaults to the source filename; REQUIRED with base64 (it carries the extension).',
                ],
                'title'     => [
                    'type'        => 'string',
                    'description' => 'Optional media library title.',
                ],
                'altText'   => [
                    'type'        => 'string',
                    'description' => 'REQUIRED for images: what the image shows, for screen readers and SEO. Ignored for non-images.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'id', 'url', 'mimeType', 'filename', 'altText' ],
            'properties' => [
                'id'       => [ 'type' => 'integer', 'description' => 'With `url`, the {id, url} pair an image attribute stores; alone, the siteIcon update-site-settings takes.' ],
                'url'      => [ 'type' => 'string' ],
                // Null for everything without raster metadata, so a caller computing an aspect
                // ratio cannot assume a number is there.
                'width'    => [ 'type' => [ 'integer', 'null' ], 'description' => 'Null for documents and AV.' ],
                'height'   => [ 'type' => [ 'integer', 'null' ] ],
                'mimeType' => [ 'type' => 'string', 'description' => 'Detected from the real bytes, not the filename.' ],
                'filename' => [ 'type' => 'string', 'description' => 'After sanitising and de-duplicating — need not match what was sent.' ],
                'altText'  => [ 'type' => 'string' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        try {
            return $this->upload( $input );
        } catch ( McpAbilityException $e ) {
            return $e->to_error();
        }
    }

    private function upload( array $input ): array {
        $source_url = trim( (string) ( $input['sourceUrl'] ?? '' ) );
        $base64     = trim( (string) ( $input['base64'] ?? '' ) );

        $given = array_filter( [ $source_url, $base64 ], static fn( $v ) => '' !== $v );
        if ( 1 !== count( $given ) ) {
            throw new McpAbilityException( 'Pass exactly one source: sourceUrl or base64.' );
        }

        $filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
        if ( '' !== $base64 && '' === $filename ) {
            throw new McpAbilityException( 'filename is required with base64 — it carries the extension (e.g. "hero.png").' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if ( '' !== $source_url ) {
            [ $tmp_path, $default_name ] = $this->fetch_remote( $source_url );
        } else {
            [ $tmp_path, $default_name ] = $this->decode_base64( $base64, $filename );
        }

        if ( '' === $filename ) {
            $filename = $default_name;
        }

        $alt = sanitize_text_field( (string) ( $input['altText'] ?? '' ) );
        try {
            $is_image = $this->assert_safe_file( $tmp_path, $filename );
            if ( $is_image && '' === $alt ) {
                throw new McpAbilityException(
                    'altText is required for images — one sentence describing what the image shows.'
                );
            }
        } catch ( McpAbilityException $e ) {
            wp_delete_file( $tmp_path );
            throw $e;
        }

        // media_handle_sideload() MOVES tmp_name into uploads and deletes it on failure.
        // The alt text goes in with the attachment (meta_input is written before `add_attachment`
        // fires), so a translation plugin copying the image per language on that hook — WPML —
        // copies the alt text with it.
        $attachment_id = media_handle_sideload(
            [ 'name' => $filename, 'tmp_name' => $tmp_path ],
            0,
            isset( $input['title'] ) ? (string) $input['title'] : null,
            $is_image && '' !== $alt ? [ 'meta_input' => [ '_wp_attachment_image_alt' => $alt ] ] : []
        );
        if ( is_wp_error( $attachment_id ) ) {
            throw new McpAbilityException( 'Could not add the file to the media library: ' . esc_html( $attachment_id->get_error_message() ) );
        }

        $meta = wp_get_attachment_metadata( (int) $attachment_id );

        return [
            'id'       => (int) $attachment_id,
            'url'      => (string) wp_get_attachment_url( (int) $attachment_id ),
            'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : null,
            'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
            'mimeType' => (string) get_post_mime_type( (int) $attachment_id ),
            'filename' => wp_basename( (string) get_attached_file( (int) $attachment_id ) ),
            'altText'  => $alt,
        ];
    }

    /**
     * @return array{0: string, 1: string} Temp file path and the filename derived from the URL.
     */
    private function fetch_remote( string $url ): array {
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( 'http' !== $scheme && 'https' !== $scheme ) {
            throw new McpAbilityException( 'sourceUrl must be an http(s) URL.' );
        }

        // download_url() runs through the HTTP API, so wp_http_validate_url() blocks
        // loopback/private hosts for us — no SSRF hole from an agent-supplied URL.
        $tmp = download_url( $url, self::DOWNLOAD_TIMEOUT );
        if ( is_wp_error( $tmp ) ) {
            throw new McpAbilityException( 'Could not download sourceUrl: ' . esc_html( $tmp->get_error_message() ) );
        }

        $name = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        return [ $tmp, sanitize_file_name( '' !== $name ? $name : 'file' ) ];
    }

    /**
     * Decodes inline base64 into a temp file. Strict decoding: a payload with junk in it
     * is rejected rather than silently truncated into a corrupt file.
     *
     * @return array{0: string, 1: string} Temp file path and the caller-supplied filename.
     */
    private function decode_base64( string $payload, string $filename ): array {
        // Accept a data URL and keep only the bytes.
        $payload = (string) preg_replace( '#^data:[^;,]*;base64,#i', '', $payload );
        $payload = (string) preg_replace( '/\s+/', '', $payload );

        $bytes = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a media file the agent sent inline; strict mode, size-capped below.
        if ( false === $bytes || '' === $bytes ) {
            throw new McpAbilityException( 'base64 is not valid base64 data.' );
        }
        if ( strlen( $bytes ) > self::MAX_BASE64_BYTES ) {
            throw new McpAbilityException(
                sprintf(
                    'base64 payload is %d KB decoded, over the %d KB cap. Host it somewhere and pass sourceUrl instead.',
                    (int) round( strlen( $bytes ) / 1024 ),
                    (int) ( self::MAX_BASE64_BYTES / 1024 )
                )
            );
        }

        $tmp = wp_tempnam( $filename );
        if ( ! $tmp ) {
            throw new McpAbilityException( 'Could not create a temp file for the decoded payload.' );
        }
        // WP_Filesystem is not initialised on this path; the target is a temp file we just made.
        if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- writes to the temp file just created by wp_tempnam(); WP_Filesystem is not initialised here.
            wp_delete_file( $tmp );
            throw new McpAbilityException( 'Could not write the decoded payload to a temp file.' );
        }

        return [ $tmp, $filename ];
    }

    /**
     * Extension whitelist + real-image check for images, so no source can smuggle
     * wp-config.php into the library. WordPress re-validates the mime on sideload.
     *
     * @return bool Whether the file is an image (drives the altText requirement).
     */
    private function assert_safe_file( string $tmp_path, string $filename ): bool {
        $ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( in_array( $ext, self::IMAGE_EXTENSIONS, true ) ) {
            if ( false === wp_getimagesize( $tmp_path ) ) {
                throw new McpAbilityException( 'The file is not a readable image (its bytes are not a decodable image).' );
            }
            return true;
        }

        if ( in_array( $ext, self::FILE_EXTENSIONS, true ) ) {
            return false;
        }

        throw new McpAbilityException(
            sprintf(
                'Unsupported file type "%s". Images: %s. Files: %s.',
                esc_html( $filename ),
                esc_html( implode( ', ', self::IMAGE_EXTENSIONS ) ),
                esc_html( implode( ', ', self::FILE_EXTENSIONS ) )
            )
        );
    }
}
