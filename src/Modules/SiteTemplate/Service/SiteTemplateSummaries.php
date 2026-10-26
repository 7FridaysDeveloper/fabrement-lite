<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;

/** The Templates screen's data, served by the REST list. */
final class SiteTemplateSummaries {

    /**
     * @return array{templates:list<array<string,mixed>>,defaults:object,postTypes:array{available:object},languages:list<array<string,mixed>>}
     */
    public static function payload(): array {
        $type_defaults = SiteTemplatePostType::type_defaults();
        $counts        = SiteTemplatePages::counts();
        // One batched lookup for every template's language versions.
        $langs = SiteTemplateRegistry::languages_of_all();

        $summary = static fn( SiteTemplateRecord $t ): array => $t->to_summary( $type_defaults ) + [
            'pageCount'            => $counts[ $t->slug ] ?? 0,
            'contentSource'        => ContentSourceConfig::read( $t->id ),
            // (object): an empty PHP array encodes as [], and the screen reads these as maps.
            'translations'         => (object) ( $langs[ $t->id ]['translations'] ?? [] ),
            // The translation plugin's own screen where it owns the versions; '' where they are edited here.
            'translationScreenUrl' => self::screen_url( $t->id ),
            'versions'             => (object) self::versions( $t->id, $langs[ $t->id ] ?? [] ),
        ];

        return [
            'templates' => array_map( $summary, SiteTemplateRegistry::originals() ),
            'defaults'  => (object) $type_defaults,
            'postTypes' => [ 'available' => (object) SiteTemplatePostType::available_post_types() ],
            // Empty unless a translation plugin reports more than one language.
            'languages' => Translation::bridge()->languages(),
        ];
    }

    /**
     * @param array<string,mixed> $facts The batched language map's entry for the original.
     * @return array<string,array{id:int,problem:string}>
     */
    private static function versions( int $template_id, array $facts ): array {
        $out = [];
        foreach ( ( $facts['translations'] ?? [] ) as $code => $id ) {
            $id = (int) $id;
            // find_any: a version switched to draft is still listed, so it can be saved or deleted.
            $record = $id === $template_id ? null : SiteTemplateRegistry::find_any( $id );
            if ( $record !== null ) {
                $out[ (string) $code ] = [ 'id' => $record->id, 'problem' => $record->problem() ];
            }
        }
        return $out;
    }

    private static function screen_url( int $template_id ): string {
        $bridge = Translation::bridge();
        if ( $bridge->languages() === [] || $bridge->writes_translations_manually( $template_id ) ) {
            return '';
        }
        return $bridge->translation_screen_url( $template_id );
    }
}
