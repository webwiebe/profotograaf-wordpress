<?php
/**
 * Spacing and shape of the full-page gallery sections.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Full_Page_Look;
use Profotograaf\Section_Sequencer;
use Profotograaf\Sections_Schema;

class Full_Page_Look_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	public function test_the_defaults_are_a_subtle_zoom_and_nothing_else(): void {
		$grid = Sections_Schema::normalise( array( 'type' => 'grid' ) );

		$this->assertSame( '', Full_Page_Look::style( $grid ) );
		$this->assertSame(
			array(
				'data-hover'          => 'zoom',
				'data-hover-strength' => 'subtle',
			),
			Full_Page_Look::data( $grid )
		);
		$this->assertSame( array(), Full_Page_Look::data( Sections_Schema::normalise( array( 'type' => 'text' ) ) ) );
	}

	public function test_every_option_is_written(): void {
		$grid = Sections_Schema::normalise(
			array(
				'type'          => 'grid',
				'gapRow'        => '24',
				'gapColumn'     => 0,
				'radius'        => 'rounded',
				'hover'         => 'grow',
				'hoverStrength' => 'strong',
				'frameColor'    => '#F4F1EA',
				'frameWidth'    => 30,
				'shadow'        => 'soft',
			)
		);

		$this->assertSame( '--pf-gap-row:24px;--pf-gap-col:0px;--pf-radius:12px;--pf-frame:24px;--pf-frame-color:#f4f1ea;', Full_Page_Look::style( $grid ) );
		$this->assertSame(
			array(
				'data-hover'          => 'grow',
				'data-hover-strength' => 'strong',
				'data-shadow'         => 'soft',
			),
			Full_Page_Look::data( $grid )
		);
	}

	public function test_a_circle_needs_a_square_grid(): void {
		$square = Sections_Schema::normalise( array( 'type' => 'grid', 'ratio' => '1-1', 'radius' => 'full' ) );
		$wide   = Sections_Schema::normalise( array( 'type' => 'grid', 'ratio' => '3-2', 'radius' => 'full' ) );

		$this->assertSame( '--pf-radius:50%;', Full_Page_Look::style( $square ) );
		$this->assertSame( '', Full_Page_Look::style( $wide ) );
		$this->assertSame( 'none', Sections_Schema::normalise( array( 'type' => 'masonry', 'radius' => 'full' ) )['radius'] );
		$this->assertSame( '--pf-radius:4px;', Full_Page_Look::style( array( 'type' => 'masonry', 'radius' => 'subtle' ) ) );
	}

	public function test_a_frame_without_a_valid_colour_is_white_and_no_hover_writes_nothing(): void {
		$this->assertSame( '--pf-frame:6px;--pf-frame-color:#ffffff;', Full_Page_Look::style( array( 'type' => 'hero', 'frameWidth' => 6, 'frameColor' => 'red' ) ) );
		$this->assertSame( array(), Full_Page_Look::data( array( 'type' => 'hero', 'hover' => 'none', 'shadow' => 'none' ) ) );
	}

	public function test_type_limits_keep_grow_and_shadow_where_they_fit(): void {
		$this->assertSame( 'zoom', Sections_Schema::normalise( array( 'type' => 'hero', 'hover' => 'grow' ) )['hover'] );
		$this->assertSame( 'zoom', Sections_Schema::normalise( array( 'type' => 'filmstrip', 'hover' => 'grow' ) )['hover'] );
		$this->assertArrayNotHasKey( 'shadow', Sections_Schema::normalise( array( 'type' => 'filmstrip' ) ) );
		$this->assertArrayNotHasKey( 'gapRow', Sections_Schema::normalise( array( 'type' => 'hero' ) ) );
		$this->assertSame( 'grow', Sections_Schema::normalise( array( 'type' => 'pair', 'hover' => 'grow' ) )['hover'] );
	}

	public function test_gap_per_axis_and_frame_colour_are_cleaned(): void {
		$grid = Sections_Schema::normalise(
			array(
				'type'       => 'grid',
				'gapRow'     => 'wide',
				'gapColumn'  => 200,
				'frameColor' => '#12345',
			)
		);

		$this->assertSame( '', $grid['gapRow'] );
		$this->assertSame( 96, $grid['gapColumn'] );
		$this->assertSame( '#ffffff', $grid['frameColor'] );
		$this->assertSame( '#abc', Sections_Schema::normalise( array( 'type' => 'grid', 'frameColor' => ' #ABC ' ) )['frameColor'] );
	}

	public function test_the_setting_key_follows_the_type_and_field_in_snake_case(): void {
		// No section setting exists yet, so the file default applies.
		$this->assertNull( Sections_Schema::setting_key( 'grid', 'gapRow' ) );
		$this->assertNull( Sections_Schema::setting_key( 'grid', 'hover' ) );
	}

	public function test_a_field_with_a_setting_resolves_through_the_option_resolver(): void {
		$keys = new \ReflectionProperty( Sections_Schema::class, 'settings' );
		$keys->setValue( null, array( 'sections_grid_hover_strength' => true ) );
		try {
			$this->assertSame( 'sections_grid_hover_strength', Sections_Schema::setting_key( 'grid', 'hoverStrength' ) );
			$this->assertSame( 'strong', Sections_Schema::normalise( array( 'type' => 'grid', 'hoverStrength' => 'strong' ) )['hoverStrength'] );
			// The schema has no such entry, so the plugin layer is empty and the file default applies.
			$this->assertSame( 'subtle', Sections_Schema::normalise( array( 'type' => 'grid', 'hoverStrength' => 'loud' ) )['hoverStrength'] );
		} finally {
			$keys->setValue( null, null );
		}
	}

	public function test_the_rest_keeps_the_look_of_the_section_it_replaces(): void {
		$photos   = array_map( static fn( int $i ): array => array( 'id' => 'p' . $i ), range( 1, 5 ) );
		$sections = Section_Sequencer::sequence(
			$photos,
			'custom',
			array(
				array( 'type' => 'grid', 'columns' => 3, 'rows' => 1 ),
				array(
					'type'       => 'pair',
					'gapRow'     => 2,
					'radius'     => 'subtle',
					'hover'      => 'grow',
					'frameWidth' => 4,
					'shadow'     => 'strong',
				),
			)
		);

		$tail = $sections[1];
		$this->assertSame( 'grid', $tail['type'] );
		$this->assertSame( array( 2, 'subtle', 'grow', 4, 'strong' ), array( $tail['gapRow'], $tail['radius'], $tail['hover'], $tail['frameWidth'], $tail['shadow'] ) );
	}
}
