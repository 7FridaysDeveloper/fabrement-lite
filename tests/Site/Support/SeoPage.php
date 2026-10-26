<?php

namespace Fabrement\Tests\Site\Support;

/**
 * What a crawler reads in a page Yoast SEO printed: the <title>, the meta and link tags of the
 * <head>, the JSON-LD graph — and the URLs and images of a Yoast XML sitemap. Values come back as a
 * browser shows them (entities decoded).
 */
final class SeoPage {

    /** @var string The whole HTML of the page. */
    public string $html;

    private function __construct( string $html ) {
        $this->html = $html;
    }

    public static function of( string $html ): self {
        return new self( $html );
    }

    private static function decode( string $value ): string {
        return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /** The document title, as the browser tab shows it. */
    public function title(): string {
        return preg_match( '#<title[^>]*>(.*?)</title>#s', $this->html, $m ) ? self::decode( trim( $m[1] ) ) : '';
    }

    /** content="" of <meta name|property="$name">; '' when absent. */
    public function meta( string $name ): string {
        return $this->metas( $name )[0] ?? '';
    }

    /** @return list<string> Every content="" of <meta name|property="$name">, in order. */
    public function metas( string $name ): array {
        $out = [];
        preg_match_all( '#<meta\s[^>]*>#i', $this->html, $tags );
        foreach ( $tags[0] as $tag ) {
            if ( preg_match( '#\s(?:name|property)=["\']' . preg_quote( $name, '#' ) . '["\']#i', $tag ) && preg_match( '#\scontent=["\']([^"\']*)["\']#i', $tag, $c ) ) {
                $out[] = self::decode( $c[1] );
            }
        }
        return $out;
    }

    /** href="" of <link rel="$rel">; '' when absent. */
    public function link( string $rel ): string {
        preg_match_all( '#<link\s[^>]*>#i', $this->html, $tags );
        foreach ( $tags[0] as $tag ) {
            if ( preg_match( '#\srel=["\']' . preg_quote( $rel, '#' ) . '["\']#i', $tag ) && preg_match( '#\shref=["\']([^"\']*)["\']#i', $tag, $h ) ) {
                return self::decode( $h[1] );
            }
        }
        return '';
    }

    public function description(): string {
        return $this->meta( 'description' );
    }

    public function canonical(): string {
        return $this->link( 'canonical' );
    }

    public function robots(): string {
        return $this->meta( 'robots' );
    }

    public function noindex(): bool {
        return str_contains( $this->robots(), 'noindex' );
    }

    /** @return list<string> */
    public function og_images(): array {
        return $this->metas( 'og:image' );
    }

    /** @return array[] Every node of every ld+json block. */
    public function graph(): array {
        $nodes = [];
        preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#s', $this->html, $m );
        foreach ( $m[1] as $json ) {
            $data = json_decode( html_entity_decode( $json ), true );
            foreach ( (array) ( $data['@graph'] ?? [ $data ] ) as $node ) {
                if ( is_array( $node ) ) {
                    $nodes[] = $node;
                }
            }
        }
        return $nodes;
    }

    /** The node with that @id, or null. */
    public function node_by_id( string $id ): ?array {
        foreach ( $this->graph() as $node ) {
            if ( ( $node['@id'] ?? null ) === $id ) {
                return $node;
            }
        }
        return null;
    }

    /** The first node whose @type holds $type, or null. */
    public function node_of_type( string $type ): ?array {
        foreach ( $this->graph() as $node ) {
            if ( in_array( $type, (array) ( $node['@type'] ?? [] ), true ) ) {
                return $node;
            }
        }
        return null;
    }

    /** @return int How many nodes carry that @id. */
    public function count_id( string $id ): int {
        return count( array_filter( $this->graph(), static fn( array $n ): bool => ( $n['@id'] ?? null ) === $id ) );
    }

    // ── sitemaps ─────────────────────────────────────────────────────────────────────────────

    /** @return list<string> The <loc> of every <url> (or <sitemap>) in a Yoast sitemap, in order. */
    public static function sitemap_urls( string $xml ): array {
        preg_match_all( '#<(?:url|sitemap)>\s*<loc>(.*?)</loc>#s', $xml, $m );
        return array_map( static fn( string $loc ): string => self::decode( trim( $loc ) ), $m[1] );
    }

    /** @return array<string,list<string>> url => the <image:loc> listed under it, in order. */
    public static function sitemap_images( string $xml ): array {
        $out = [];
        preg_match_all( '#<url>(.*?)</url>#s', $xml, $urls );
        foreach ( $urls[1] as $entry ) {
            if ( ! preg_match( '#<loc>(.*?)</loc>#s', $entry, $loc ) ) {
                continue;
            }
            preg_match_all( '#<image:loc>(.*?)</image:loc>#s', $entry, $images );
            $out[ self::decode( trim( $loc[1] ) ) ] = array_map( static fn( string $i ): string => self::decode( trim( $i ) ), $images[1] );
        }
        return $out;
    }
}
