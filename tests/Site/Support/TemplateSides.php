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
     * Derived header/footer facts, read from the stored block frame:
     * {headerPatternId, footerPatternId, headerBlockId, footerBlockId}. Nothing of it is stored,
     * and the tools do not answer it — an agent reads the frame's blocks.
     */
    protected function screen_sides( int $template ): array {
        return (array) $this->site_runs(
            '$r = \\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplateRegistry::find_any( ' . $template . ' );'
            . ' return $r === null ? [] : [ "headerPatternId" => $r->headerPatternId(), "footerPatternId" => $r->footerPatternId(), "headerBlockId" => $r->headerBlockId(), "footerBlockId" => $r->footerBlockId() ];'
        ) + [ 'headerPatternId' => 0, 'footerPatternId' => 0, 'headerBlockId' => 0, 'footerBlockId' => 0 ];
    }

    /** The pattern inferred from a template's header / footer (0 = none). */
    protected function side_of( int $template, string $kind ): int {
        return (int) $this->screen_sides( $template )[ $kind . 'PatternId' ];
    }

    /** A template's row of list-templates, with independently read block-frame facts beside it. */
    protected function listed_with_sides( int $template ): array {
        foreach ( (array) $this->mcp( 'list-templates' )['templates'] as $row ) {
            if ( (int) $row['id'] === $template ) {
                $this->assertArrayNotHasKey( 'headerPatternId', $row, 'the tools answer no sides: an agent reads the frame' );
                return $row + $this->screen_sides( $template );
            }
        }
        $this->fail( "list-templates has no template $template" );
    }
}
