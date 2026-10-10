<?php
/**
 * Sections schema tests: the shared vocabulary of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Sections_Schema;

class Sections_Schema_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	public function test_the_types_and_presets_match_the_issue(): void {
		$this->assertSame( array( 'grid', 'masonry', 'feature', 'hero', 'pair', 'filmstrip', 'text' ), Sections_Schema::types() );
		$this->assertSame( array( 'calm', 'editorial', 'playful' ), Sections_Schema::presets() );
		$this->assertSame( 'editorial', Sections_Schema::default_preset() );
		$this->assertSame( 600, Sections_Schema::phone_breakpoint() );
	}

	/**
	 * @return array<string,array{string,int}>
	 */
	public static function cycles(): array {
		return array(
			'calm'      => array( 'calm', 8 ),
			'editorial' => array( 'editorial', 19 ),
			'playful'   => array( 'playful', 32 ),
		);
	}

	/**
	 * @dataProvider cycles
	 */
	public function test_a_preset_cycle_takes_the_documented_number_of_photos( string $preset, int $photos ): void {
		$this->assertSame( $photos, array_sum( array_map( array( Sections_Schema::class, 'take' ), Sections_Schema::pattern( $preset ) ) ) );
	}

	public function test_every_type_default_is_valid_for_its_field(): void {
		foreach ( Sections_Schema::types() as $type ) {
			$this->assertSame( array( 'type' => $type ) + Sections_Schema::defaults( $type ) + array( 'photoIds' => array() ), Sections_Schema::normalise( array( 'type' => $type ) ), $type );
		}
	}

	public function test_take_follows_the_type_rule(): void {
		$this->assertSame( 12, Sections_Schema::take( Sections_Schema::normalise( array( 'type' => 'grid', 'columns' => 4, 'rows' => 3 ) ) ) );
		$this->assertSame( 3, Sections_Schema::take( Sections_Schema::normalise( array( 'type' => 'feature', 'count' => 3 ) ) ) );
		$this->assertSame( 1, Sections_Schema::take( Sections_Schema::normalise( array( 'type' => 'hero' ) ) ) );
		$this->assertSame( 3, Sections_Schema::take( Sections_Schema::normalise( array( 'type' => 'pair' ) ) ) );
		$this->assertSame( 0, Sections_Schema::take( Sections_Schema::normalise( array( 'type' => 'text' ) ) ) );
	}

	public function test_normalise_clamps_numbers_and_rejects_unknown_values(): void {
		$section = Sections_Schema::normalise(
			array(
				'type'     => 'GRID',
				'columns'  => '9',
				'rows'     => -1,
				'ratio'    => '5-1',
				'gap'      => '',
				'captions' => 'below',
				'unknown'  => 'dropped',
				'photoIds' => array( 'a1', 'a1', 'b<2>', 7, '' ),
			)
		);
		$this->assertSame(
			array(
				'type'     => 'grid',
				'columns'  => 6,
				'rows'     => 1,
				'ratio'    => '3-2',
				'gap'      => 8,
				'captions' => 'below',
				'photoIds' => array( 'a1', 'b2' ),
			),
			$section
		);
	}

	public function test_a_type_limit_narrows_the_field_range(): void {
		$this->assertSame( 3, Sections_Schema::normalise( array( 'type' => 'feature', 'count' => 8 ) )['count'] );
		$this->assertSame( 2, Sections_Schema::normalise( array( 'type' => 'feature', 'count' => 1 ) )['count'] );
		$this->assertSame( 24, Sections_Schema::normalise( array( 'type' => 'filmstrip', 'count' => 99 ) )['count'] );
	}

	public function test_booleans_and_text(): void {
		$pair = Sections_Schema::normalise( array( 'type' => 'pair', 'mirror' => '0', 'side' => 'right' ) );
		$this->assertFalse( $pair['mirror'] );
		$this->assertSame( 'right', $pair['side'] );
		$this->assertTrue( Sections_Schema::normalise( array( 'type' => 'pair', 'mirror' => true ) )['mirror'] );
		$this->assertSame( 'Ceremony', Sections_Schema::normalise( array( 'type' => 'text', 'heading' => ' <b>Ceremony</b> ' ) )['heading'] );
	}

	public function test_an_unknown_or_missing_type_is_dropped(): void {
		$this->assertNull( Sections_Schema::normalise( array( 'type' => 'carousel' ) ) );
		$this->assertNull( Sections_Schema::normalise( array( 'columns' => 3 ) ) );
		$this->assertNull( Sections_Schema::normalise( 'grid' ) );
	}

	public function test_preset_id_falls_back_to_the_default(): void {
		$this->assertSame( 'calm', Sections_Schema::preset_id( ' Calm ' ) );
		$this->assertSame( 'custom', Sections_Schema::preset_id( 'custom' ) );
		$this->assertSame( 'editorial', Sections_Schema::preset_id( 'loud' ) );
		$this->assertSame( 'editorial', Sections_Schema::preset_id( null ) );
		$this->assertSame( array(), Sections_Schema::pattern( 'custom' ) );
	}

	public function test_phone_values_exist_for_every_type(): void {
		$this->assertSame( array( 'columns' => 2 ), Sections_Schema::phone( 'grid' ) );
		$this->assertSame( array( 'rowHeight' => 240 ), Sections_Schema::phone( 'filmstrip' ) );
		$this->assertSame( array(), Sections_Schema::phone( 'nope' ) );
	}
}
