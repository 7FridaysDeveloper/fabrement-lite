<?php

use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Translation\FieldSync;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueRepository;
use PHPUnit\Framework\TestCase;

final class FieldTranslationTest extends TestCase {

    protected function setUp(): void {
        \Fabrement\Tests\WordPressState::reset();
    }

    private const A = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'row_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const C = 'row_cccccccccccccccccccccccccccccccc';

    /** @dataProvider seeded_containers */
    public function test_seed_copies_stored_null_but_never_materializes_absent_defaults( string $type, string $policy ): void {
        $field = self::field( 'fab_box', $type, $policy, [ 'sub_fields' => [
            self::field( 'absent', 'number', 'copy_once', [ 'default' => 7 ] ),
            self::field( 'cleared', 'number', 'copy_once', [ 'default' => 9 ] ),
            self::field( 'private', 'text', 'ignore' ),
        ] ] );
        $prefix = $type === 'group' ? 'fab_box' : 'fab_box_0';
        $source = [ 'fab_box' => '1', $prefix . '_cleared' => null, $prefix . '_private' => 'EN' ];
        if ( $type === 'repeater' ) {
            $source[ 'fab_box_0__rid' ] = self::A;
        }
        $result = self::sync_map( $field, $source, [] );
        self::assertArrayNotHasKey( $prefix . '_absent', $result );
        self::assertArrayNotHasKey( $prefix . '_private', $result );
        self::assertArrayHasKey( $prefix . '_cleared', $result );
        self::assertNull( $result[ $prefix . '_cleared' ] );
        self::assertSame( $result, self::sync_map( $field, $source, $result ), 'repeated sync retains the exact sparse map' );
        if ( $type === 'repeater' ) {
            self::assertSame( self::A, $result['fab_box_0__rid'] );
        }
    }

    public static function seeded_containers(): array {
        return [ [ 'group', 'copy' ], [ 'group', 'copy_once' ], [ 'repeater', 'copy' ], [ 'repeater', 'copy_once' ] ];
    }

    public function test_sparse_nested_children_follow_row_identity_after_reordering(): void {
        $field = self::field( 'fab_rows', 'repeater', 'copy', [ 'sub_fields' => [
            self::field( 'group', 'group', 'copy', [ 'sub_fields' => [
                self::field( 'word', 'text', 'translate' ), self::field( 'price', 'number', 'copy', [ 'default' => 7 ] ),
            ] ] ),
        ] ] );
        $source = [ 'fab_rows' => '2', 'fab_rows_0__rid' => self::B, 'fab_rows_1__rid' => self::A,
            'fab_rows_0_group' => '1', 'fab_rows_0_group_price' => null, 'fab_rows_1_group' => '1' ];
        $target = [ 'fab_rows' => '2', 'fab_rows_0__rid' => self::A, 'fab_rows_1__rid' => self::B,
            'fab_rows_0_group' => '1', 'fab_rows_0_group_word' => 'A translated', 'fab_rows_0_group_price' => '3',
            'fab_rows_1_group' => '1', 'fab_rows_1_group_word' => 'B translated' ];
        $result = self::sync_map( $field, $source, $target );
        self::assertSame( 'B translated', $result['fab_rows_0_group_word'] );
        self::assertSame( 'A translated', $result['fab_rows_1_group_word'] );
        self::assertNull( $result['fab_rows_0_group_price'] );
        self::assertArrayNotHasKey( 'fab_rows_1_group_price', $result, 'a copied source absence removes a stale target row' );
    }

    public function test_duplicate_and_job_updates_do_not_create_absent_siblings(): void {
        $field = self::field( 'fab_group', 'group', 'copy', [ 'sub_fields' => [
            self::field( 'word', 'text', 'translate' ), self::field( 'absent', 'number', 'copy', [ 'default' => 7 ] ),
            self::field( 'private', 'text', 'ignore' ),
        ] ] );
        $source = [ 'fab_group' => '1', 'fab_group_word' => null ];
        $target = [ 'fab_group' => '1', 'fab_group_private' => 'Local', 'fab_group_absent' => '99' ];
        $mirrored = self::sync_map( $field, $source, $target, [], true );
        self::assertSame( [ 'fab_group' => '1', 'fab_group_word' => null, 'fab_group_private' => 'Local' ], $mirrored );
        $translated = self::sync_map( $field, $source, [], [ TranslationPolicy::token( [ 'fab_group', 'word' ] ) => 'PL' ] );
        self::assertSame( [ 'fab_group' => '1', 'fab_group_word' => 'PL' ], $translated );
    }

    public function test_a_completed_job_clears_words_the_original_no_longer_has(): void {
        $word = self::field( 'fab_title', 'text', 'translate' );
        self::assertSame( 'Ancien', self::sync_map( $word, [ 'fab_title' => null ], [ 'fab_title' => 'Ancien' ] )['fab_title'], 'a plain sync keeps the translation\'s words' );
        self::assertNull( self::sync_map( $word, [ 'fab_title' => null ], [ 'fab_title' => 'Ancien' ], [], false, [] )['fab_title'] );
        self::assertSame( 'Ancien', self::sync_map( $word, [ 'fab_title' => null ], [ 'fab_title' => 'Ancien' ], [], false,
            [ TranslationPolicy::token( [ 'fab_title' ] ) => true ] )['fab_title'], 'a field the job carried, finished or not, is kept' );
        self::assertSame( 'Ancien', self::sync_map( $word, [ 'fab_title' => 'Old' ], [ 'fab_title' => 'Ancien' ], [], false, [] )['fab_title'], 'words the original still has are kept' );
        self::assertArrayNotHasKey( 'fab_title', self::sync_map( $word, [], [ 'fab_title' => 'Ancien' ], [], false, [] ), 'an original without the field leaves none' );
    }

    public function test_a_completed_job_clears_emptied_words_in_groups_and_rows_by_identity(): void {
        $group = self::field( 'fab_box', 'group', 'copy', [ 'sub_fields' => [ self::field( 'note', 'text', 'translate' ), self::field( 'memo', 'text', 'ignore' ) ] ] );
        $result = self::sync_map( $group, [ 'fab_box' => '1', 'fab_box_note' => null ], [ 'fab_box' => '1', 'fab_box_note' => 'Ancienne', 'fab_box_memo' => 'Mine' ], [], false, [] );
        self::assertNull( $result['fab_box_note'] );
        self::assertSame( 'Mine', $result['fab_box_memo'] );

        $rows = self::field( 'fab_steps', 'repeater', 'copy', [ 'sub_fields' => [ self::field( 'title', 'text', 'translate' ) ] ] );
        $source = [ 'fab_steps' => '2', 'fab_steps_0__rid' => self::B, 'fab_steps_0_title' => 'Second', 'fab_steps_1__rid' => self::A, 'fab_steps_1_title' => null ];
        $target = [ 'fab_steps' => '2', 'fab_steps_0__rid' => self::A, 'fab_steps_0_title' => 'Premier', 'fab_steps_1__rid' => self::B, 'fab_steps_1_title' => 'Deuxième' ];
        $result = self::sync_map( $rows, $source, $target, [], false, [ TranslationPolicy::token( [ 'fab_steps', self::B, 'title' ] ) => true ] );
        self::assertSame( 'Deuxième', $result['fab_steps_0_title'] );
        self::assertNull( $result['fab_steps_1_title'] );

        $own = self::field( 'fab_own', 'group', 'copy_once', [ 'sub_fields' => [
            self::field( 'note', 'text', 'translate' ), self::field( 'memo', 'text', 'ignore' ), self::field( 'once', 'text', 'copy_once' ),
        ] ] );
        $held = [ 'fab_own' => '1', 'fab_own_note' => 'Locale', 'fab_own_memo' => 'Mine', 'fab_own_once' => 'Une' ];
        $result = self::sync_map( $own, [ 'fab_own' => '1', 'fab_own_note' => null ], $held, [], false, [] );
        self::assertNull( $result['fab_own_note'], 'job-carried words of a copy_once group follow the original\'s emptiness' );
        self::assertSame( [ 'Mine', 'Une' ], [ $result['fab_own_memo'], $result['fab_own_once'] ] );
        $result = self::sync_map( $own, [], $held, [], false, [] );
        self::assertSame( [ 'fab_own' => '1', 'fab_own_memo' => 'Mine', 'fab_own_once' => 'Une' ], $result, 'an absent original keeps the group and its own values, only the words go' );

        $once = self::field( 'fab_list', 'repeater', 'copy_once', [ 'sub_fields' => [ self::field( 'title', 'text', 'translate' ) ] ] );
        $result = self::sync_map( $once, [ 'fab_list' => '1', 'fab_list_0__rid' => self::A, 'fab_list_0_title' => null ],
            [ 'fab_list' => '2', 'fab_list_0__rid' => self::A, 'fab_list_0_title' => 'Partagé', 'fab_list_1__rid' => self::C, 'fab_list_1_title' => 'Propre' ], [], false, [] );
        self::assertNull( $result['fab_list_0_title'] );
        self::assertSame( 'Propre', $result['fab_list_1_title'], 'a row only the translation holds is its own' );
    }

    public function test_a_completed_job_removes_a_child_the_original_lacks_and_blanks_one_it_cleared(): void {
        $group = self::field( 'fab_box', 'group', 'copy', [ 'sub_fields' => [
            self::field( 'note', 'text', 'translate', [ 'default' => 'Read more' ] ), self::field( 'plain', 'text', 'translate' ),
        ] ] );
        $result = self::sync_map( $group, [ 'fab_box' => '1' ], [ 'fab_box' => '1', 'fab_box_note' => 'Lire', 'fab_box_plain' => 'Texte' ], [], false, [] );
        self::assertSame( [ 'fab_box' => '1' ], $result, 'a child the original never stored is removed, so its default shows as on the original' );
        $result = self::sync_map( $group, [ 'fab_box' => '1', 'fab_box_note' => null ], [ 'fab_box' => '1', 'fab_box_note' => 'Lire' ], [], false, [] );
        self::assertArrayHasKey( 'fab_box_note', $result );
        self::assertNull( $result['fab_box_note'], 'a child the original cleared is cleared too' );
    }

    public function test_a_completed_job_empties_a_record_link_the_original_no_longer_has(): void {
        $link = self::field( 'fab_more', 'link', 'translate' );
        $record = [ 'url' => '', 'label' => '', 'postId' => 123, 'lang' => '', 'newTab' => false, 'useRecordTitle' => true ];
        self::assertNull( self::sync_map( $link, [ 'fab_more' => null ], [ 'fab_more' => $record ], [], false, [] )['fab_more'] );
        self::assertArrayNotHasKey( 'fab_more', self::sync_map( $link, [], [ 'fab_more' => $record ], [], false, [] ) );
        self::assertSame( $record, self::sync_map( $link, [ 'fab_more' => [ 'postId' => 9 ] + $record ], [ 'fab_more' => $record ], [], false, [] )['fab_more'] );
    }

    public function test_words_the_next_job_would_clear_are_named_before_they_are_written(): void {
        $word = self::field( 'fab_title', 'text', 'translate', [ 'default' => 'Read more' ] );
        $token = TranslationPolicy::token( [ 'fab_title' ] );
        self::assertSame( [ $token ], TranslationPolicy::lost_on_next_job( $word, 'Nouveau', '', [ '' ] ), 'new words where the original is blank' );
        self::assertSame( [ $token ], TranslationPolicy::lost_on_next_job( $word, 'Nouveau', 'Read more', null ), 'new words where the original holds none' );
        self::assertSame( [], TranslationPolicy::lost_on_next_job( $word, '', 'Ancien', [ '' ] ), 'clearing is allowed' );
        self::assertSame( [], TranslationPolicy::lost_on_next_job( $word, 'Ancien', 'Ancien', [ '' ] ), 'words already held, sent back unchanged' );
        self::assertSame( [], TranslationPolicy::lost_on_next_job( $word, 'Nouveau', 'Ancien', [ 'Old' ] ), 'the original has words: the job carries them' );

        $group = self::field( 'fab_box', 'group', 'copy_once', [ 'sub_fields' => [ self::field( 'note', 'text', 'translate' ), self::field( 'once', 'text', 'copy_once' ) ] ] );
        self::assertSame( [ TranslationPolicy::token( [ 'fab_box', 'note' ] ) ],
            TranslationPolicy::lost_on_next_job( $group, [ 'note' => 'Nouveau', 'once' => 'Une' ], [ 'note' => '', 'once' => 'Une' ], [ [ 'note' => '' ] ] ) );
        self::assertSame( [], TranslationPolicy::lost_on_next_job( $group, [ 'note' => 'Ancien', 'once' => 'Deux' ], [ 'note' => 'Ancien', 'once' => 'Une' ], [ [ 'note' => '' ] ] ),
            'a sibling of its own changes while stale words come back unchanged' );

        $rows = self::field( 'fab_list', 'repeater', 'copy_once', [ 'sub_fields' => [ self::field( 'title', 'text', 'translate' ) ] ] );
        $own = [ [ 'id' => self::C, 'values' => [ 'title' => 'Propre' ] ] ];
        self::assertSame( [], TranslationPolicy::lost_on_next_job( $rows, $own, [], [ [ [ 'id' => self::A, 'values' => [ 'title' => '' ] ] ] ] ), 'a row of its own' );
        self::assertCount( 1, TranslationPolicy::lost_on_next_job( $rows, [ [ 'id' => self::A, 'values' => [ 'title' => 'Partagé' ] ] ],
            [ [ 'id' => self::A, 'values' => [ 'title' => '' ] ] ], [ [ [ 'id' => self::A, 'values' => [ 'title' => '' ] ] ] ] ), 'a row shared with the original' );

        $link = self::field( 'fab_more', 'link', 'translate' );
        $empty = [ 'url' => '', 'label' => '', 'postId' => 0, 'lang' => '', 'newTab' => false, 'useRecordTitle' => false ];
        self::assertCount( 1, TranslationPolicy::lost_on_next_job( $link, [ 'postId' => 12, 'useRecordTitle' => true ] + $empty, $empty, null ), 'a record picked where the original has no link' );
        self::assertSame( [], TranslationPolicy::lost_on_next_job( $link, $empty, [ 'postId' => 12 ] + $empty, null ) );
        self::assertSame( [], TranslationPolicy::lost_on_next_job( self::field( 'fab_memo', 'text', 'ignore' ), 'Mine', '', null ) );
    }

    public function test_a_completed_job_clears_nested_rows_and_groups_in_rows_by_identity(): void {
        $field = self::field( 'fab_steps', 'repeater', 'copy', [ 'sub_fields' => [
            self::field( 'parts', 'repeater', 'copy', [ 'sub_fields' => [ self::field( 'name', 'text', 'translate' ) ] ] ),
            self::field( 'own', 'group', 'copy_once', [ 'sub_fields' => [ self::field( 'note', 'text', 'translate' ), self::field( 'once', 'text', 'copy_once' ) ] ] ),
        ] ] );
        $source = [ 'fab_steps' => '1', 'fab_steps_0__rid' => self::A,
            'fab_steps_0_parts' => '2', 'fab_steps_0_parts_0__rid' => self::C, 'fab_steps_0_parts_0_name' => 'Kept', 'fab_steps_0_parts_1__rid' => self::B, 'fab_steps_0_parts_1_name' => null,
            'fab_steps_0_own' => '1', 'fab_steps_0_own_note' => null ];
        $target = [ 'fab_steps' => '1', 'fab_steps_0__rid' => self::A,
            'fab_steps_0_parts' => '2', 'fab_steps_0_parts_0__rid' => self::B, 'fab_steps_0_parts_0_name' => 'Effacé', 'fab_steps_0_parts_1__rid' => self::C, 'fab_steps_0_parts_1_name' => 'Gardé',
            'fab_steps_0_own' => '1', 'fab_steps_0_own_note' => 'Note', 'fab_steps_0_own_once' => 'Une' ];
        $job = [ TranslationPolicy::token( [ 'fab_steps', self::A, 'parts', self::C, 'name' ] ) => true ];
        $result = self::sync_map( $field, $source, $target, [], false, $job );
        self::assertSame( self::C, $result['fab_steps_0_parts_0__rid'], 'nested rows follow the original\'s order' );
        self::assertSame( 'Gardé', $result['fab_steps_0_parts_0_name'], 'a nested word the job carried stays' );
        self::assertNull( $result['fab_steps_0_parts_1_name'], 'a nested word the original emptied goes, found by both row ids' );
        self::assertNull( $result['fab_steps_0_own_note'] );
        self::assertSame( 'Une', $result['fab_steps_0_own_once'], 'a copy_once group in a row keeps its own value' );
    }

    public function test_the_box_locks_copy_once_group_words_but_not_copy_once_rows(): void {
        $locks = TranslationPolicy::word_locks( [
            self::field( 'fab_own', 'group', 'copy_once', [ 'sub_fields' => [ self::field( 'note', 'text', 'translate' ) ] ] ),
            self::field( 'fab_list', 'repeater', 'copy_once', [ 'sub_fields' => [ self::field( 'title', 'text', 'translate' ) ] ] ),
        ] );
        self::assertSame( [ 'fab_own/note' ], $locks );
    }

    public function test_a_completed_job_clears_a_link_caption_or_the_whole_emptied_link(): void {
        $link = self::field( 'fab_more', 'link', 'translate' );
        $fr = [ 'url' => '/fr/plus', 'label' => 'Voir plus', 'postId' => 0, 'lang' => '', 'newTab' => false, 'useRecordTitle' => false ];
        $result = self::sync_map( $link, [ 'fab_more' => [ 'url' => '/more', 'label' => '' ] + $fr ], [ 'fab_more' => $fr ], [], false, [] );
        self::assertSame( '', $result['fab_more']['label'] );
        self::assertSame( '/fr/plus', $result['fab_more']['url'], 'an address the original still has stays as translated' );
        $result = self::sync_map( $link, [ 'fab_more' => [ 'url' => '', 'label' => '' ] + $fr ], [ 'fab_more' => $fr ], [], false, [] );
        self::assertArrayHasKey( 'fab_more', $result );
        self::assertNull( $result['fab_more'], 'an emptied original link empties the translation\'s whole link' );
    }

    private static function sync_map( array $field, array $source, array $target, array $texts = [], bool $mirror = false, ?array $job = null ): array {
        $fields = [ $field['key'] => $field ];
        $plan = FieldSync::project( $fields, ValueRepository::decode( $fields, $source ), ValueRepository::decode( $fields, $target ), $texts, false, $mirror, $job );
        $patch = ValueRepository::patch( $fields, $target, $plan['changes'], $plan['deletes'] );
        return array_replace( array_diff_key( $target, array_flip( $patch['remove'] ) ), $patch['insert'] );
    }

    private static function field( string $key, string $type, string $policy, array $extra = [] ): array {
        return $extra + [ 'key' => $key, 'name' => substr( $key, 0, strrpos( $key, '_' ) ), 'label' => $key, 'type' => $type, 'translation' => $policy, 'required' => false ];
    }

    /** @dataProvider following_row_policies */
    public function test_linking_independent_rows_preserves_links_and_clears_by_position( string $policy ): void {
        $field = self::field( 'fab_steps', 'repeater', $policy, [ 'sub_fields' => [
            self::field( 'cta', 'link', 'translate' ), self::field( 'shared', 'link', 'copy' ),
            self::field( 'once', 'link', 'copy_once' ), self::field( 'private', 'link', 'ignore' ),
        ] ] );
        $en = [ 'url' => '/en/contact', 'label' => 'Contact' ];
        $fr = [ 'url' => '/fr/contact', 'label' => 'Contactez-nous' ];
        $source = [ [ 'id' => self::A, 'values' => array_fill_keys( [ 'cta', 'shared', 'once', 'private' ], $en ) ] ];
        $target = [
            [ 'id' => self::B, 'values' => [ 'cta' => null, 'shared' => $fr, 'once' => null, 'private' => $fr ] ],
            [ 'id' => self::C, 'values' => [ 'cta' => $fr ] ],
        ];
        $adopted = TranslationPolicy::adopt_rows( $field, $source, $target );
        self::assertSame( self::A, $adopted[0]['id'] );
        self::assertSame( $target[0]['values'], $adopted[0]['values'], 'adoption changes identity, not values' );
        $merged = TranslationPolicy::merge( $field, $source, $adopted, true );
        self::assertSame( [ [ 'id' => self::A, 'values' => [ 'cta' => null, 'shared' => $en, 'once' => null, 'private' => $fr ] ] ], $merged );
        self::assertSame( $merged, TranslationPolicy::adopt_rows( $field, $source, $merged ), 'an already linked row is stable' );
    }

    public static function following_row_policies(): array {
        return [ 'copy rows' => [ 'copy' ], 'translated rows' => [ 'translate' ] ];
    }

    public function test_linking_with_any_shared_identity_never_guesses_other_rows_by_position(): void {
        $field = self::field( 'fab_steps', 'repeater', 'translate', [ 'sub_fields' => [ self::field( 'cta', 'link', 'translate' ) ] ] );
        $row = static fn( string $id, $cta ): array => [ 'id' => $id, 'values' => [ 'cta' => $cta ] ];
        $source = [ $row( self::A, [ 'url' => '/one' ] ), $row( self::B, [ 'url' => '/two' ] ) ];
        $target = [ $row( self::C, [ 'url' => '/unrelated' ] ), $row( self::A, null ) ];
        self::assertSame( $target, TranslationPolicy::adopt_rows( $field, $source, $target ) );
        self::assertSame( [ $row( self::A, null ), $source[1] ], TranslationPolicy::merge( $field, $source, $target, true ) );
        $reordered = array_reverse( $source );
        self::assertSame( $target, TranslationPolicy::adopt_rows( $field, $reordered, $target ) );
        self::assertSame( [ $source[1], $row( self::A, null ) ], TranslationPolicy::merge( $field, $reordered, $target, true ), 'the clear follows A, not index 1' );
    }

    /** @dataProvider independent_containers */
    public function test_linking_leaves_independent_container_ids_and_values_alone( string $type, string $policy ): void {
        $rows = self::field( 'rows', 'repeater', 'translate', [ 'sub_fields' => [ self::field( 'title', 'text', 'translate' ) ] ] );
        $field = self::field( 'fab_private', $type, $policy, [ 'sub_fields' => [ $rows ] ] );
        $en = [ 'rows' => [ [ 'id' => self::A, 'values' => [ 'title' => 'One' ] ] ] ];
        $fr = [ 'rows' => [ [ 'id' => self::B, 'values' => [ 'title' => null ] ] ] ];
        $source = $type === 'group' ? $en : [ [ 'id' => self::A, 'values' => $en ] ];
        $target = $type === 'group' ? $fr : [ [ 'id' => self::C, 'values' => $fr ] ];
        self::assertSame( $target, TranslationPolicy::adopt_rows( $field, $source, $target ) );
        self::assertSame( $target, TranslationPolicy::merge( $field, $source, $target, true ) );
    }

    public static function independent_containers(): array {
        return [ [ 'group', 'copy_once' ], [ 'group', 'ignore' ], [ 'repeater', 'copy_once' ], [ 'repeater', 'ignore' ] ];
    }

    public function test_linking_adopts_nested_rows_through_groups_without_losing_storage_presence(): void {
        $items = self::field( 'items', 'repeater', 'translate', [ 'sub_fields' => [ self::field( 'cta', 'link', 'translate' ) ] ] );
        $group = self::field( 'details', 'group', 'copy', [ 'sub_fields' => [ $items ] ] );
        $field = self::field( 'fab_sections', 'repeater', 'copy', [ 'sub_fields' => [ $group ] ] );
        $tree = static fn( string $outer, string $inner, array $values ): array => [
            [ 'id' => $outer, 'values' => [ 'details' => [ 'items' => [ [ 'id' => $inner, 'values' => $values ] ] ] ] ],
        ];
        $source = $tree( self::A, self::B, [ 'cta' => [ 'url' => '/en' ] ] );
        foreach ( [ 'cleared' => [ 'cta' => null ], 'missing' => [] ] as $case => $values ) {
            $raw = [];
            ValueCodec::flatten( $field, $tree( self::C, self::C, $values ), 'fab_sections', $raw );
            $stored = ValueRepository::decode( [ 'fab_sections' => $field ], $raw );
            $adopted = TranslationPolicy::adopt_rows( $field, $source, $stored['values']['fab_sections'] );
            $merged = TranslationPolicy::merge( $field, $source, $adopted, true, 'fab_sections', $stored['present'] );
            self::assertSame( self::A, $merged[0]['id'] );
            $nested = $merged[0]['values']['details']['items'][0];
            self::assertSame( self::B, $nested['id'] );
            self::assertSame( $case === 'missing' ? '/en' : '', $nested['values']['cta']['url'], $case );
            self::assertSame( $case === 'cleared', array_key_exists( 'fab_sections_0_details_items_0_cta', $raw ) );
        }
    }

    public function test_scalar_policies(): void {
        foreach ( [
            // policy, target has a value?, expected
            [ 'copy', true, 'EN' ], [ 'copy', false, 'EN' ],
            [ 'translate', true, 'PL' ], [ 'translate', false, 'EN' ],
            [ 'copy_once', true, 'PL' ], [ 'copy_once', false, 'EN' ],
            [ 'ignore', true, 'PL' ], [ 'ignore', false, '' ],
        ] as [ $policy, $has, $expected ] ) {
            self::assertSame( $expected, TranslationPolicy::merge( self::field( 't_a1b2c3', 'text', $policy ), 'EN', 'PL', $has ), $policy . ( $has ? ' +' : ' -' ) );
        }
    }

    public function test_translated_repeater_follows_the_original_rows_and_keeps_its_own_text(): void {
        $field = self::field( 'rows_r4t8w1', 'repeater', 'translate', [ 'sub_fields' => [
            self::field( 'title_h3n5c2', 'text', 'translate' ),
            self::field( 'link_u0gmw2', 'text', 'copy' ),
        ] ] );
        // Original: rows reordered (B before A), A's link changed, C added.
        $source = [
            [ 'id' => self::B, 'values' => [ 'title_h3n5c2' => 'Launch', 'link_u0gmw2' => '/b' ] ],
            [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'Discovery', 'link_u0gmw2' => '/a-new' ] ],
            [ 'id' => self::C, 'values' => [ 'title_h3n5c2' => 'Support', 'link_u0gmw2' => '/c' ] ],
        ];
        $target = [
            [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'Odkrycie', 'link_u0gmw2' => '/a' ] ],
            [ 'id' => self::B, 'values' => [ 'title_h3n5c2' => 'Start', 'link_u0gmw2' => '/b' ] ],
        ];
        self::assertSame( [
            [ 'id' => self::B, 'values' => [ 'title_h3n5c2' => 'Start', 'link_u0gmw2' => '/b' ] ],
            [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'Odkrycie', 'link_u0gmw2' => '/a-new' ] ],
            [ 'id' => self::C, 'values' => [ 'title_h3n5c2' => 'Support', 'link_u0gmw2' => '/c' ] ],
        ], TranslationPolicy::merge( $field, $source, $target, true ) );
    }

    public function test_copy_once_repeater_is_seeded_then_left_alone(): void {
        $field = self::field( 'rows_r4t8w1', 'repeater', 'copy_once', [ 'sub_fields' => [ self::field( 'title_h3n5c2', 'text', 'translate' ) ] ] );
        $source = [ [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'EN' ] ] ];
        $target = [ [ 'id' => self::B, 'values' => [ 'title_h3n5c2' => 'PL' ] ] ];
        self::assertSame( $source, TranslationPolicy::merge( $field, $source, [], false ) );
        self::assertSame( $target, TranslationPolicy::merge( $field, $source, $target, true ) );
    }

    public function test_a_copy_once_group_is_the_translations_own_after_seeding(): void {
        $group = self::field( 'meta_g5k1m8', 'group', 'copy_once', [ 'sub_fields' => [
            self::field( 'priority_p1p2p3', 'number', 'copy' ),
            self::field( 'note_n1n2n3', 'text', 'ignore' ),
        ] ] );
        $source = [ 'priority_p1p2p3' => 10, 'note_n1n2n3' => 'en' ];
        self::assertSame( [ 'priority_p1p2p3' => 10 ], TranslationPolicy::merge( $group, $source, [], false ), 'ignore is not initialized' );
        $target = [ 'priority_p1p2p3' => 1, 'note_n1n2n3' => 'pl' ];
        self::assertSame( $target, TranslationPolicy::merge( $group, $source, $target, true ) );
        TranslationPolicy::assert_editable( $group, [ 'priority_p1p2p3' => 5, 'note_n1n2n3' => 'x' ], $source, 'meta' );
        self::assertSame( [ 'fields' => [], 'rows' => [] ], TranslationPolicy::locks( [ $group ] ) );
    }

    public function test_a_translation_cannot_change_what_the_original_owns(): void {
        $copy = self::field( 'n_a1b2c3', 'text', 'copy' );
        TranslationPolicy::assert_editable( $copy, 'same', 'same', 'n' );
        try {
            TranslationPolicy::assert_editable( $copy, 'changed', 'same', 'n' );
            self::fail( 'A copied value was editable.' );
        } catch ( InvalidArgumentException $e ) {
            self::assertStringStartsWith( 'n: ', $e->getMessage() );
        }
        $rows = self::field( 'rows_r4t8w1', 'repeater', 'translate', [ 'sub_fields' => [ self::field( 'title_h3n5c2', 'text', 'translate' ) ] ] );
        $source = [ [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'EN' ] ] ];
        TranslationPolicy::assert_editable( $rows, [ [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'PL' ] ] ], $source, 'rows' );
        $this->expectException( InvalidArgumentException::class );
        TranslationPolicy::assert_editable( $rows, [ [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'PL' ] ], [ 'id' => self::B, 'values' => [ 'title_h3n5c2' => 'x' ] ] ], $source, 'rows' );
    }

    public function test_job_texts_round_trip_by_row_identity(): void {
        $field = self::field( 'rows_r4t8w1', 'repeater', 'translate', [ 'sub_fields' => [
            self::field( 'title_h3n5c2', 'text', 'translate' ),
            self::field( 'code_x1y2z3', 'text', 'copy' ),
        ] ] );
        $value = [ [ 'id' => self::A, 'values' => [ 'title_h3n5c2' => 'Discovery', 'code_x1y2z3' => 'D-1' ] ] ];
        $texts = TranslationPolicy::texts( $field, $value );
        self::assertCount( 1, $texts, 'Only translate text goes to the job.' );
        $token = array_key_first( $texts );
        self::assertSame( 'Discovery', $texts[ $token ]['text'] );
        $translated = TranslationPolicy::apply_texts( $field, $value, [ $token => 'Odkrycie' ] );
        self::assertSame( 'Odkrycie', $translated[0]['values']['title_h3n5c2'] );
        self::assertSame( 'D-1', $translated[0]['values']['code_x1y2z3'] );
    }

    public function test_locks_name_copied_fields_and_row_owned_repeaters(): void {
        $fields = [
            self::field( 'client_k7m2q9', 'text', 'copy' ),
            self::field( 'title_a1b2c3', 'text', 'translate' ),
            self::field( 'rows_r4t8w1', 'repeater', 'translate', [ 'sub_fields' => [ self::field( 'link_u0gmw2', 'text', 'copy' ) ] ] ),
            self::field( 'notes_n1n2n3', 'repeater', 'copy_once', [ 'sub_fields' => [ self::field( 'text_t1t2t3', 'text', 'translate' ) ] ] ),
        ];
        self::assertSame( [ 'fields' => [ 'client_k7m2q9', 'rows_r4t8w1/link_u0gmw2' ], 'rows' => [ 'rows_r4t8w1' ] ], TranslationPolicy::locks( $fields ) );
    }

    public function test_a_sub_field_added_later_is_seeded_while_one_written_empty_is_kept(): void {
        // Rows follow the original (copy); the new "description" is translated per language.
        $field = self::field( 'fab_modules', 'repeater', 'copy', [ 'sub_fields' => [
            self::field( 'title', 'text', 'translate' ),
            self::field( 'description', 'text', 'translate' ),
        ] ] );
        $source = [
            [ 'id' => self::A, 'values' => [ 'title' => 'Intro', 'description' => 'EN intro' ] ],
            [ 'id' => self::B, 'values' => [ 'title' => 'Deep', 'description' => 'EN deep' ] ],
        ];
        // The translation holds the rows in the OTHER order: B at 0, A at 1. Row A never had a
        // description row (added to the schema later); row B's was written empty by a translator.
        $target = [
            [ 'id' => self::B, 'values' => [ 'title' => 'Głęboko', 'description' => '' ] ],
            [ 'id' => self::A, 'values' => [ 'title' => 'Wstęp', 'description' => '' ] ],
        ];
        $present = array_fill_keys( [ 'fab_modules', 'fab_modules_0__rid', 'fab_modules_0_title', 'fab_modules_0_description',
            'fab_modules_1__rid', 'fab_modules_1_title' ], true );
        self::assertSame( [
            [ 'id' => self::A, 'values' => [ 'title' => 'Wstęp', 'description' => 'EN intro' ] ],
            [ 'id' => self::B, 'values' => [ 'title' => 'Głęboko', 'description' => '' ] ],
        ], TranslationPolicy::merge( $field, $source, $target, true, 'fab_modules', $present ) );

        // Without the map of stored rows, a sub-field counts as held whenever its row is.
        self::assertSame( 'Wstęp', TranslationPolicy::merge( $field, $source, $target, true )[0]['values']['title'] );
        self::assertSame( '', TranslationPolicy::merge( $field, $source, $target, true )[0]['values']['description'] );
    }

    public function test_a_group_child_added_later_is_seeded(): void {
        $group = self::field( 'fab_venue', 'group', 'copy', [ 'sub_fields' => [
            self::field( 'city', 'text', 'translate' ),
            self::field( 'address', 'text', 'translate' ),
        ] ] );
        $source = [ 'city' => 'Kyiv', 'address' => 'EN street' ];
        $target = [ 'city' => 'Kijów', 'address' => '' ];
        self::assertSame( [ 'city' => 'Kijów', 'address' => 'EN street' ],
            TranslationPolicy::merge( $group, $source, $target, true, 'fab_venue', [ 'fab_venue' => true, 'fab_venue_city' => true ] ) );
        self::assertSame( [ 'city' => 'Kijów', 'address' => '' ],
            TranslationPolicy::merge( $group, $source, $target, true, 'fab_venue', [ 'fab_venue' => true, 'fab_venue_city' => true, 'fab_venue_address' => true ] ) );
    }

    public function test_a_stale_copy_value_sent_back_unchanged_passes_and_is_healed(): void {
        $group = self::field( 'fab_venue', 'group', 'copy', [ 'sub_fields' => [
            self::field( 'city', 'text', 'translate' ),
            self::field( 'zip', 'text', 'copy' ),
        ] ] );
        $source  = [ 'city' => 'Kyiv', 'zip' => '01001' ];
        $current = [ 'city' => 'Kijów', 'zip' => '99999' ];      // stale copy in the translation
        $sent    = [ 'city' => 'Kijów!', 'zip' => '99999' ];     // the translator edits the city only
        TranslationPolicy::assert_editable( $group, $sent, $source, 'venue', $current );
        self::assertSame( [ 'city' => 'Kijów!', 'zip' => '01001' ], TranslationPolicy::with_original( $group, $sent, $source ) );

        // A copy value really changed — neither the original's nor the one held — is refused.
        $this->expectException( \InvalidArgumentException::class );
        TranslationPolicy::assert_editable( $group, [ 'city' => 'Kijów', 'zip' => '55555' ], $source, 'venue', $current );
    }

    public function test_copy_cells_of_repeater_rows_are_healed_too(): void {
        $field = self::field( 'fab_modules', 'repeater', 'copy', [ 'sub_fields' => [
            self::field( 'title', 'text', 'translate' ),
            self::field( 'code', 'text', 'copy' ),
        ] ] );
        $source  = [ [ 'id' => self::A, 'values' => [ 'title' => 'Intro', 'code' => 'c2' ] ] ];
        $current = [ [ 'id' => self::A, 'values' => [ 'title' => 'Wstęp', 'code' => 'c1' ] ] ];
        $sent    = [ [ 'id' => self::A, 'values' => [ 'title' => 'Wstęp!', 'code' => 'c1' ] ] ];
        TranslationPolicy::assert_editable( $field, $sent, $source, 'modules', $current );
        self::assertSame( [ [ 'id' => self::A, 'values' => [ 'title' => 'Wstęp!', 'code' => 'c2' ] ] ], TranslationPolicy::with_original( $field, $sent, $source ) );
    }

    /**
     * The site's code writing a translation (fabrement()->fields( $fr )->save()): what reaches the
     * original is what the code CHANGED there, judged against what the translation held — a
     * translation that fell behind its original does not push stale values or rows back.
     */
    public function test_code_on_a_translation_sends_only_its_changes_to_the_original(): void {
        $copy = self::field( 'price_p1p2p3', 'number', 'copy' );
        self::assertSame( 20, TranslationPolicy::to_original( $copy, 10, 10, 20 ), 'unchanged here: the original keeps its newer value' );
        self::assertSame( 30, TranslationPolicy::to_original( $copy, 30, 10, 20 ), 'changed here: every language gets it' );
        self::assertSame( 'EN', TranslationPolicy::to_original( self::field( 't_a1b2c3', 'text', 'translate' ), 'FR!', 'FR', 'EN' ), 'words belong to the translation' );

        $rows = self::field( 'steps_s1s2s3', 'repeater', 'copy', [ 'sub_fields' => [
            self::field( 'title_t1t2t3', 'text', 'translate' ),
            self::field( 'code_c1c2c3', 'text', 'copy' ),
        ] ] );
        $row = static fn( string $id, string $title, string $code ): array => [ 'id' => $id, 'values' => [ 'title_t1t2t3' => $title, 'code_c1c2c3' => $code ] ];
        $d = 'row_dddddddddddddddddddddddddddddddd';

        // The original added C since; the code adds D after B: C keeps its place, D is new everywhere.
        self::assertSame(
            [ $row( self::A, 'One', 'a' ), $row( self::B, 'Two', 'b' ), $row( self::C, 'Three', 'c' ), $row( $d, 'Quatre', 'd' ) ],
            TranslationPolicy::to_original( $rows,
                [ $row( self::A, 'Un', 'a' ), $row( self::B, 'Deux', 'b' ), $row( $d, 'Quatre', 'd' ) ],
                [ $row( self::A, 'Un', 'a' ), $row( self::B, 'Deux', 'b' ) ],
                [ $row( self::A, 'One', 'a' ), $row( self::B, 'Two', 'b' ), $row( self::C, 'Three', 'c' ) ] )
        );
        // Rows untouched, one copy cell changed: the original's rows as they are, that cell changed.
        self::assertSame(
            [ $row( self::A, 'One', 'a2' ) ],
            TranslationPolicy::to_original( $rows,
                [ $row( self::A, 'Un', 'a2' ), $row( self::B, 'Deux', 'b' ) ],
                [ $row( self::A, 'Un', 'a' ), $row( self::B, 'Deux', 'b' ) ],
                [ $row( self::A, 'One', 'a' ) ] ),
            'B, removed on the original since, does not come back'
        );
        // Reordered here: B stays removed all the same.
        self::assertSame(
            [ $row( self::A, 'One', 'a' ) ],
            TranslationPolicy::to_original( $rows,
                [ $row( self::B, 'Deux', 'b' ), $row( self::A, 'Un', 'a' ) ],
                [ $row( self::A, 'Un', 'a' ), $row( self::B, 'Deux', 'b' ) ],
                [ $row( self::A, 'One', 'a' ) ] )
        );
        // Cleared here: no rows anywhere.
        self::assertSame( [], TranslationPolicy::to_original( $rows, [], [ $row( self::A, 'Un', 'a' ) ], [ $row( self::A, 'One', 'a' ) ] ) );
    }
}
