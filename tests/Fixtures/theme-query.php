<?php
/** Trusted test-theme code, never loaded or shipped by the plugin. */
use Fabrement\Modules\Query\Filter;
use Fabrement\Sdk\Http\Request;

class QaThemeQuery {
    private ?Filter $qa_filter = null;
    private string $qa_listing = '';

    public function request(): Request { return new Request(); }
    public function post(): WP_Post { return get_queried_object(); }
    public function filters(): array { return []; }
    public function sort(): array { return []; }
    public function per_page(): int { return 0; }
    public function query(WP_Query $query): void {}

    /** A theme owns its pre_get_posts hook; only filtering is supplied by Lite. */
    public function apply(WP_Query $query): void {
        $this->query($query);
        $args = $this->filters() + ['sort' => $this->sort()];
        if ($this->per_page() !== 0) { $args['limit'] = $this->per_page(); }
        $this->qa_filter = Filter::for_main($args, $query);
        $this->qa_filter->apply($query);
    }
    public function filter(): Filter { return $this->qa_filter; }
    public function found(): int { return (int) $GLOBALS['wp_query']->found_posts; }
    public function is_empty(): bool { return $this->found() === 0; }
    public function content_blocks(): string { return fabrement_archive_content(); }

    public function content(): object {
        return new class {
            public function posts(array $args): array { return Filter::from($args)->posts(); }
            public function posts_query(array $args): WP_Query { return Filter::from($args)->query(); }
            public function filter(array $args): Filter { return Filter::from($args); }
        };
    }

    /** Ordinary WordPress loop with restoration around nested archive content. */
    public function loop(): Generator {
        $query = $GLOBALS['wp_query'];
        $previous = [$query->current_post, $query->post, $query->in_the_loop, $GLOBALS['post'] ?? null];
        $query->rewind_posts();
        try {
            while ($query->have_posts()) { $query->the_post(); yield $query->post; }
        } finally {
            [$query->current_post, $query->post, $query->in_the_loop, $GLOBALS['post']] = $previous;
            if ($GLOBALS['post'] instanceof WP_Post) { setup_postdata($GLOBALS['post']); }
        }
    }

    public function draw(string $file, string $listing = ''): void {
        $this->qa_listing = $listing;
        $this->include_fixture($file);
    }
    public function listing(): string {
        if ($this->qa_listing === '') { return ''; }
        ob_start();
        try { $this->include_fixture($this->qa_listing); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    private function include_fixture(string $file): void {
        // The trusted theme render file calls its own object's private helpers.
        $render = Closure::bind(function(string $path): void { include $path; }, $this, get_class($this));
        $render($file);
    }
}
