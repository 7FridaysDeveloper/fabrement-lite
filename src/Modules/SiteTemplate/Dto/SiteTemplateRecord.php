<?php

namespace Fabrement\Modules\SiteTemplate\Dto;

use Fabrement\Modules\SiteTemplate\Service\TemplateParts;

/**
 * Immutable snapshot of one site template. A template is its content: blocks in order, one of them
 * the content slot, arranged by an agent, the Templates screen or a person in the editor. It
 * dresses pages as it is — what is missing draws nothing, as anywhere in WordPress — and
 * {@see problem()} says what is wrong where templates are listed. What sits above and below the
 * slot is blocks like any other. What renders is {@see render_content()}.
 */
final class SiteTemplateRecord {

    public int     $id;
    public string  $title;
    public string  $slug;
    /** 'full' (default, full-bleed content) or 'constrained' (centred column). */
    public string  $content_width;
    /**
     * The template's raw block content (post_content) — every block the admin
     * arranged, in order. Rendered as-is.
     */
    public ?string $raw_content;
    /**
     * The name of this template's manifest, or '' for one from before the store.
     *
     * The KEY, not the rules. Reading the file where the record is built looked tidier and was a
     * straight regression: {@see SiteTemplateRegistry::all()} builds a record for every published
     * template on ordinary front-end requests, so every page view read every manifest on the site
     * to render one of them. The key is a meta value the registry is already priming, and the file
     * is opened below, once, by whoever actually renders.
     */
    public string  $manifest_key;

    /** Memoised output of render_content(): the injection below is done once per record. */
    private ?string $renderable = null;

    public function __construct(
        int     $id,
        string  $title,
        string  $slug,
        string  $content_width = 'full',
        ?string $raw_content = null,
        string  $manifest_key = ''
    ) {
        $this->id            = $id;
        $this->title         = $title;
        $this->slug          = $slug;
        $this->content_width  = $content_width;
        $this->raw_content    = $raw_content;
        $this->manifest_key   = $manifest_key;
    }

    /** Prepare the frame: the slot learns its template, whose stored theme-file choices it reads. */
    public function render_content(): string {
        $blocks = parse_blocks( (string) ( $this->raw_content ?? '' ) );
        $walk = function ( array &$items ) use ( &$walk ): void {
            foreach ( $items as &$block ) {
                if ( ( $block['blockName'] ?? '' ) === 'fabrement/content-source' ) {
                    unset( $block['attrs']['rules'] ); // A stale copy from older saves; the template's JSON decides.
                    $block['attrs']['templateId'] = $this->id;
                }
                if ( in_array( $block['blockName'] ?? '', [ 'fabrement/content-source', 'core/post-content' ], true ) && ! isset( $block['attrs']['layout'] ) ) { $block['attrs']['layout'] = [ 'type' => $this->content_width === 'constrained' ? 'constrained' : 'default' ]; }
                if ( ! empty( $block['innerBlocks'] ) ) { $walk( $block['innerBlocks'] ); }
            }
        };
        $walk( $blocks );
        return serialize_blocks( $blocks );
    }

    /**
     * What is wrong with this template, for whoever fixes it; '' when nothing is. It dresses pages
     * all the same: a global block that is not drawn leaves an empty place.
     */
    public function problem(): string {
        $problems = [];
        if ( ! preg_match( '/<!-- wp:(?:(?:core\/)?post-content|fabrement\/content-source)(?:\s|\/)/', (string) $this->raw_content ) ) {
            $problems[] = __( 'It has no content slot, so the page\'s own content is not shown. Put the "Content Source" block back into it.', 'fabrement-lite' );
        }
        foreach ( TemplateParts::refs( (string) $this->raw_content ) as $pattern ) {
            $problems[] = TemplateParts::problem( $pattern );
        }
        if ( $this->manifest_key !== '' && ! \Fabrement\Modules\SiteTemplate\Service\TemplateManifestStore::exists( $this->manifest_key ) ) {
            $problems[] = "Its rules file is missing, so the content slot shows each page's own content and no list or layout. Save the template again to write it.";
        }
        return implode( ' ', array_filter( $problems ) );
    }

    /**
     * What the Templates screen and the tools answer (markup stays server-side).
     *
     * @param array<string,int> $type_defaults post_type => default template id.
     */
    public function to_summary( array $type_defaults ): array {
        // strval: PHP turns a numeric-string array key ("404", the not-found view) into an int, and
        // the SPA and the tools expect every entry to be a string.
        $default_for = array_values( array_map( 'strval', array_keys( array_filter( $type_defaults, fn( $id ) => (int) $id === $this->id ) ) ) );
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'slug'          => $this->slug,
            // What is wrong with it, in a sentence; '' when nothing is.
            'problem'       => $this->problem(),
            // Post types this template is the default for (empty = not a default).
            'defaultFor'    => $default_for,
            'contentWidth'  => $this->content_width,
        ];
    }
}
