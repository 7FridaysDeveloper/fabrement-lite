<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;

/** Which template dresses a singular view: the record's own pick, else its type's default. */
final class TemplateResolver {
    public static function picked_template(): array {
        $slug = (string) get_page_template_slug();
        return $slug === '' || $slug === 'default' ? [ false, null ] : [ true, SiteTemplateRegistry::find_by_slug( $slug ) ];
    }

    public static function default_template_for( string $post_type ): ?SiteTemplateRecord {
        return SiteTemplateRegistry::find( SiteTemplatePostType::default_template_id_for( $post_type ) );
    }

    public static function singular_template(): ?SiteTemplateRecord {
        [ $picked, $record ] = self::picked_template();
        if ( $picked ) { return $record; }
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post || ( is_front_page() && locate_template( 'front-page.php' ) !== '' ) ) { return null; }
        return self::default_template_for( $post->post_type );
    }
}
