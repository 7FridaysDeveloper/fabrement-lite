<?php

namespace Fabrement\Tests\Site\Support;

/** Synced patterns of installed core blocks; no generated block records or files. */
trait TemplateSides {

    /** A global block a client made by hand: its own blocks, no Fabrement block. @return int Its id. */
    protected function own_global_block( string $kind, string $title ): int {
        $class = 'qa-own-' . strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $title ) );
        return (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => $title, 'kind' => $kind, 'blocks' => [
            [ 'markup' => '<!-- wp:paragraph --><p class="' . $class . '">' . $title . '</p><!-- /wp:paragraph -->' ],
        ] ] )['patternId'];
    }

    /**
     * A template's header and footer: the first synced pattern of each kind its blocks place,
     * {headerPatternId, footerPatternId} (0 = none). Read from its content; nothing of it is stored.
     */
    protected function screen_sides( int $template ): array {
        return (array) $this->site_runs(
            '$r = \\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplateRegistry::find_any( ' . $template . ' );'
            . ' $sides = [ "headerPatternId" => 0, "footerPatternId" => 0 ];'
            . ' foreach ( $r === null ? [] : \\Fabrement\\Modules\\SiteTemplate\\Service\\TemplateParts::refs( (string) $r->raw_content ) as $id ) {'
            . ' $key = \\Fabrement\\Modules\\SiteTemplate\\Service\\SyncedPatterns::kind( (int) $id ) . "PatternId";'
            . ' if ( ( $sides[ $key ] ?? -1 ) === 0 ) { $sides[ $key ] = (int) $id; } }'
            . ' return $sides;'
        );
    }

    /** The pattern inferred from a template's header / footer (0 = none). */
    protected function side_of( int $template, string $kind ): int {
        return (int) $this->screen_sides( $template )[ $kind . 'PatternId' ];
    }

    /** A template's summary, with independently read block-frame facts beside it. */
    protected function listed_with_sides( int $template ): array {
        $row = $this->template_row( $template );
        $this->assertNotSame( [], $row, "no template $template" );
        return $row + $this->screen_sides( $template );
    }
}
