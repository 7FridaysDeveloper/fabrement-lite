<?php

use Fabrement\Modules\Fields\Values\FieldMessages;
use Fabrement\Modules\Fields\Values\FieldValueError;
use Fabrement\Modules\Fields\Values\ValueCodec;
use PHPUnit\Framework\TestCase;

final class FieldMessagesTest extends TestCase {

    protected function setUp(): void {
        \Fabrement\Tests\WordPressState::reset();
    }

    /** @dataProvider keys */
    public function test_every_key_has_an_english_and_a_person_wording( string $key ): void {
        $error = ( new FieldValueError( $key ) )->at( [ 'label' => 'Price', 'name' => 'price' ], 'price' );
        self::assertStringStartsWith( 'price: Price: ', $error->getMessage() );
        self::assertStringContainsString( 'Price', FieldMessages::person( $error ) );
    }

    public static function keys(): array {
        $keys = array_values( array_diff( ( new \ReflectionClass( FieldValueError::class ) )->getConstants(), [ FieldValueError::SEVERAL ] ) );
        return array_combine( $keys, array_map( static fn( string $key ): array => [ $key ], $keys ) );
    }

    public function test_several_failing_fields_are_named_in_one_message(): void {
        $price = ( new FieldValueError( FieldValueError::REQUIRED ) )->at( [ 'label' => 'Price', 'name' => 'price' ], 'price' )->as_saved( [ 'Price' ] );
        $city = ( new FieldValueError( FieldValueError::REQUIRED ) )->at( [ 'label' => 'City', 'name' => 'city' ], 'venue.city' )->as_saved( [ 'Venue', 'City' ] );
        $error = FieldValueError::several( [ $price, $city ] );
        self::assertSame( 'Several fields need a value or a fix: price: Price: This field is required. venue.city: City: This field is required.', $error->getMessage() );
        self::assertSame( "Several fields need attention before saving:\nPrice is required. Fill it in to save.\nVenue › City is required. Fill it in to save.", FieldMessages::person( $error ) );
    }

    public function test_the_field_and_path_closest_to_the_value_are_kept(): void {
        $group = [ 'key' => 'fab_venue', 'name' => 'venue', 'label' => 'Venue', 'type' => 'group', 'required' => false, 'sub_fields' => [
            [ 'key' => 'seats', 'name' => 'seats', 'label' => 'Seats', 'type' => 'number', 'required' => false, 'max' => 10 ],
        ] ];
        try {
            ValueCodec::normalize( $group, [ 'seats' => 11 ], 'venue' );
            self::fail( 'A number over its max is refused.' );
        } catch ( FieldValueError $e ) {
            self::assertSame( FieldValueError::OUT_OF_RANGE, $e->key );
            self::assertSame( 'venue.seats: Seats: The number is outside the allowed range (at most 10).', $e->getMessage() );
            self::assertSame( 'Seats: the number must be at most 10.', FieldMessages::person( $e ) );
        }
    }

    public function test_a_list_item_is_named_by_its_place(): void {
        $related = [ 'key' => 'fab_related', 'name' => 'related', 'label' => 'Related', 'type' => 'relationship', 'required' => false ];
        try {
            ValueCodec::normalize( $related, [ 0 ], 'related' );
            self::fail( 'An empty item is refused.' );
        } catch ( FieldValueError $e ) {
            self::assertSame( 'related: Related: Select an existing record.', $e->getMessage() );
            self::assertSame( 'Related, item 1: nothing is chosen here. Choose an item or remove it.', FieldMessages::person( $e ) );
        }
    }
}
