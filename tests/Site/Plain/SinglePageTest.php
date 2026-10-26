<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\PageBundleTestCase;

/**
 * A single page rendered by trusted theme PHP selected in the type's default block template:
 * a film's fields, its people, its moods and a strip of films in the
 * same city printed for the visitor; values changed, a film of the strip trashed, a field retired;
 * what the page prints is read from its markup and from #qa-state.
 */
final class SinglePageTest extends PageBundleTestCase {

    private const TYPE = 'qa_film';
    private const MOOD = 'qa_mood';

    /** @var array<string,int> */
    private static array $ids = [];

    private static bool $ready = false;

    private static string $path = '';

    private const PAGE = <<<'PHP'
<?php
defined( 'ABSPATH' ) || exit;

return new class extends \QaThemeQuery {

    public function body_class( array $classes ): array {
        return [ ...$classes, 'is-qa-film' ];
    }

    private function qa_titles( array $ids ): array {
        $out = [];
        foreach ( $ids as $id ) {
            $out[] = get_the_title( (int) $id );
        }
        return $out;
    }

    private function qa_related(): array {
        $post = $this->post();
        $city = (string) fabrement_get_field( 'city', $post->ID );
        $out  = [];
        foreach ( $this->content()->posts( [ 'type' => 'qa_film', 'limit' => 3, 'not' => $post->ID, 'fields' => [ 'city' => $city ], 'sort' => [ 'price' => 'ASC' ] ] ) as $one ) {
            $out[] = $one->post_title;
        }
        return $out;
    }

    private function qa_moods(): array {
        $terms = get_the_terms( $this->post(), 'qa_mood' );
        $out   = [];
        foreach ( is_array( $terms ) ? $terms : [] as $term ) {
            $out[] = $term->name;
        }
        sort( $out );
        return $out;
    }
};
PHP;

    private const RENDER = <<<'PHP'
<?php
/**
 * Fabrement: singular:qa_film
 */
defined( 'ABSPATH' ) || exit;

$post     = $this->post();
$price    = fabrement_get_field( 'price', $post->ID );
$director = (int) fabrement_get_field( 'director', $post->ID );
$state    = [
    'title'      => get_the_title( $post ),
    'price'      => $price,
    'premiere'   => (string) fabrement_get_field( 'premiere', $post->ID ),
    'city'       => (string) fabrement_get_field( 'city', $post->ID ),
    'genre'      => (string) fabrement_get_field( 'genre', $post->ID ),
    'genreLabel' => fabrement_get_choice_label( 'genre', $post->ID ),
    'open'       => (bool) fabrement_get_field( 'open', $post->ID ),
    'director'   => $director > 0 ? get_the_title( $director ) : '',
    'cast'       => $this->qa_titles( (array) fabrement_get_field( 'cast', $post->ID ) ),
    'moods'      => $this->qa_moods(),
    'related'    => $this->qa_related(),
];
?>
<article class="qa-film">
    <h1 class="qa-film-title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
    <dl class="qa-fields">
        <?php if ( $price !== null && $price !== '' ) : ?>
            <dt>Price</dt><dd data-field="price"><?php echo esc_html( (string) $price ); ?></dd>
        <?php endif; ?>
        <?php if ( $state['city'] !== '' ) : ?>
            <dt>City</dt><dd data-field="city"><?php echo esc_html( $state['city'] ); ?></dd>
        <?php endif; ?>
        <dt>Genre</dt><dd data-field="genre"><?php echo esc_html( $state['genreLabel'] ); ?></dd>
    </dl>
    <ul class="qa-related">
        <?php foreach ( $state['related'] as $title ) : ?>
            <li><?php echo esc_html( $title ); ?></li>
        <?php endforeach; ?>
    </ul>
    <script type="application/json" id="qa-state"><?php echo wp_json_encode( $state, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
</article>
PHP;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            self::$ready = true;
            $this->build();
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        self::$ids   = [];
        parent::tearDownAfterClass();
    }

    private static function own_fields(): array {
        return [
            self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
            self::field( 'premiere', 'date_picker' ),
            self::field( 'city', 'text' ),
            self::field( 'genre', 'select', [ 'choices' => [ [ 'value' => 'noir', 'label' => 'Film noir' ], [ 'value' => 'comedy', 'label' => 'Comedy' ] ] ] ),
            self::field( 'open', 'true_false' ),
            self::field( 'director', 'post_object', [ 'postTypes' => [ 'page' ] ] ),
            self::field( 'cast', 'relationship', [ 'postTypes' => [ 'page' ] ] ),
        ];
    }

    private function build(): void {
        $this->post_type( self::TYPE );
        $this->fields( self::TYPE, self::own_fields() );
        $this->taxonomy( self::MOOD, [ 'singular' => 'Mood', 'postTypes' => [ self::TYPE ], 'hierarchical' => true ] );
        $this->mcp( 'create-term', [ 'taxonomy' => self::MOOD, 'name' => 'Dark', 'slug' => 'dark' ] );
        $this->mcp( 'create-term', [ 'taxonomy' => self::MOOD, 'name' => 'Rainy', 'slug' => 'rainy', 'parent' => 'dark' ] );
        foreach ( [ 'Ann', 'Bob', 'Cid' ] as $person ) {
            self::$ids[ $person ] = $this->record( 'page', $person );
        }
        foreach ( [ 'One' => [ 12.5, 'Kyiv' ], 'Two' => [ 8, 'Kyiv' ], 'Three' => [ 5, 'Lviv' ], 'Four' => [ 20, 'Kyiv' ] ] as $title => [ $price, $city ] ) {
            self::$ids[ $title ] = $this->record( self::TYPE, $title );
            $this->mcp( 'set-field-values', [ 'postId' => self::$ids[ $title ], 'values' => [ 'price' => $price, 'city' => $city ] ] );
        }
        self::$path = $this->single( 'qa-film-single', self::TYPE, self::PAGE, self::RENDER );
    }

    private function url( string $film ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => self::$ids[ $film ] ] )['url'];
    }

    public function test_a_film_page_prints_its_fields_people_moods_and_a_strip_and_follows_every_change(): void {
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['One'], 'values' => [
            'premiere' => '2021-05-01', 'genre' => 'noir', 'open' => true,
            'director' => self::$ids['Ann'], 'cast' => [ self::$ids['Cid'], self::$ids['Bob'] ],
        ] ] );
        $this->mcp( 'set-post-terms', [ 'contentId' => self::$ids['One'], 'taxonomy' => self::MOOD, 'terms' => [ 'rainy' ], 'mode' => 'replace' ] );

        $page  = $this->visit( $this->url( 'One' ) );
        $state = $page['state'];
        $this->assertSame( 'One', $state['title'] );
        $this->assertSame( 12.5, $state['price'] );
        $this->assertSame( '2021-05-01', $state['premiere'] );
        $this->assertSame( 'Film noir', $state['genreLabel'], 'a choice printed by its label' );
        $this->assertTrue( $state['open'] );
        $this->assertSame( 'Ann', $state['director'] );
        $this->assertSame( [ 'Cid', 'Bob' ], $state['cast'], 'a list in the order set' );
        $this->assertSame( [ 'Rainy' ], $state['moods'] );
        $this->assertSame( [ 'Two', 'Four' ], $state['related'], 'the same city, itself left out, cheapest first' );
        $this->assertStringContainsString( '<dd data-field="price">12.5</dd>', $page['body'] );
        $this->assertStringContainsString( '<dd data-field="genre">Film noir</dd>', $page['body'] );

        // Changed: the next visitor reads the new values.
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['One'], 'values' => [ 'price' => null, 'city' => 'Lviv', 'cast' => [ self::$ids['Bob'] ] ] ] );
        $state = $this->visit( $this->url( 'One' ) )['state'];
        $this->assertNull( $state['price'], 'a cleared number reads as nothing' );
        $this->assertStringNotContainsString( 'data-field="price"', $this->get( $this->url( 'One' ) )['body'], 'and is not printed as an empty label' );
        $this->assertSame( [ 'Bob' ], $state['cast'] );
        $this->assertSame( [ 'Three' ], $state['related'], 'moved to Lviv: the strip follows' );

        // A film of the strip trashed: gone from it at once.
        $this->mcp( 'set-content-status', [ 'contentId' => self::$ids['Three'], 'status' => 'trash' ] );
        $this->assertSame( [], $this->visit( $this->url( 'One' ) )['state']['related'] );
        $this->mcp( 'set-content-status', [ 'contentId' => self::$ids['Three'], 'status' => 'restore' ] );
        $this->assertSame( [ 'Three' ], $this->visit( $this->url( 'One' ) )['state']['related'] );

        // The film itself trashed: no page.
        $url = $this->url( 'Four' );
        $this->mcp( 'set-content-status', [ 'contentId' => self::$ids['Four'], 'status' => 'trash' ] );
        $this->assertSame( 404, $this->get( $url )['status'] );
        $this->assertNotContains( 'Four', $this->visit( $this->url( 'Two' ) )['state']['related'] );
    }

    public function test_a_retired_field_keeps_its_values_and_the_page_still_draws(): void {
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['Two'], 'values' => [ 'genre' => 'comedy' ] ] );
        $this->fields( self::TYPE, array_values( array_filter( self::own_fields(), static fn( array $f ): bool => $f['name'] !== 'genre' ) ) );
        $this->assertSame( [ 'comedy' ], $this->site()->meta( self::$ids['Two'], 'fab_genre' ), 'retired, its values kept' );
        $page = $this->visit( $this->url( 'Two' ) );
        $this->assertSame( 'Two', $page['state']['title'], 'the page draws' );
        $this->assertEquals( 8, $page['state']['price'], 'the other fields as before' );
    }


}
