<?php
/**
 * Spacing and shape options of the gallery block (#198).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Gallery_Look;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Reserved_Space;

class Gallery_Look_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	public function test_without_any_look_option_nothing_new_is_written(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		foreach ( array( 'data-gap-row', 'data-gap-column', 'data-radius', 'data-hover', 'data-frame', 'data-shadow' ) as $name ) {
			$this->assertStringNotContainsString( ' ' . $name . '="', $html, $name );
		}
	}

	public function test_the_frame_is_one_attribute_of_colour_and_width(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'frame_color' => '#1A1A1A', 'frame_width' => '8' ) );

		$this->assertStringContainsString( ' data-frame="#1a1a1a,8"', $html );
		$this->assertStringNotContainsString( 'data-frame-color', $html );
		$this->assertStringNotContainsString( 'data-frame-width', $html );
	}

	public function test_a_width_alone_draws_a_white_frame_and_a_colour_alone_draws_none(): void {
		$this->assertStringContainsString( ' data-frame="#ffffff,6"', $this->renderer->render( array( 'id' => 'g-1', 'frame_width' => '6' ) ) );
		$this->assertStringNotContainsString( 'data-frame', $this->renderer->render( array( 'id' => 'g-1', 'frame_color' => '#000000' ) ) );
	}

	public function test_the_frame_follows_the_block_then_the_site(): void {
		$this->options['profotograaf_settings'] = array(
			'gallery_frame_color' => '#eeeeee',
			'gallery_frame_width' => 12,
		);

		$this->assertStringContainsString( ' data-frame="#eeeeee,12"', $this->renderer->render( array( 'id' => 'g-1' ) ) );
		$this->assertStringContainsString( ' data-frame="#000,12"', $this->renderer->render( array( 'id' => 'g-1', 'frame_color' => '#000' ) ) );
		$this->assertStringNotContainsString( 'data-frame', $this->renderer->render( array( 'id' => 'g-1', 'frame_width' => '0' ) ) );
		$this->assertStringNotContainsString( 'data-frame', $this->renderer->render( array( 'id' => 'g-1', 'frame_width' => 'auto' ) ) );
	}

	public function test_a_frame_wider_than_embed_js_draws_is_capped_and_a_bad_colour_is_dropped(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'frame_color' => 'red', 'frame_width' => '80' ) );

		$this->assertStringContainsString( ' data-frame="#ffffff,24"', $html );
	}

	public function test_the_slideshow_gets_no_option_it_ignores(): void {
		$args = array(
			'id'          => 'g-1',
			'layout'      => 'slideshow',
			'gap_row'     => '4',
			'gap_column'  => '4',
			'frame_width' => '8',
			'shadow'      => 'soft',
			'hover'       => 'grow',
			'radius'      => 'rounded',
		);

		$html = $this->renderer->render( $args );

		foreach ( array( 'data-gap-row', 'data-gap-column', 'data-frame', 'data-shadow', 'data-hover' ) as $name ) {
			$this->assertStringNotContainsString( ' ' . $name . '="', $html, $name );
		}
		$this->assertStringContainsString( ' data-radius="12"', $html );
		$this->assertStringContainsString( ' data-hover="none"', $this->renderer->render( array( 'hover' => 'none' ) + $args ) );
	}

	public function test_grow_and_circle_reach_a_grid(): void {
		$html = $this->renderer->render(
			array(
				'id'             => 'g-1',
				'layout'         => 'grid',
				'hover'          => 'grow',
				'hover_strength' => 'strong',
				'radius'         => 'full',
			)
		);

		$this->assertStringContainsString( ' data-hover="grow" data-hover-strength="strong"', $html );
		$this->assertStringContainsString( ' data-radius="full"', $html );
	}

	public function test_a_grown_tile_is_not_clipped_by_the_host(): void {
		$this->assertStringContainsString( '[data-profotograaf-gallery][data-hover=grow]{overflow:visible}', Reserved_Space::rule() );
	}

	public function test_block_attributes_map_to_the_look_arguments(): void {
		$args = Gallery_Renderer::options_from_block(
			array(
				'gapRow'        => '4',
				'gapColumn'     => '12',
				'radius'        => 'subtle',
				'hover'         => 'grow',
				'hoverStrength' => 'strong',
				'frameColor'    => '#ffffff',
				'frameWidth'    => '6',
				'shadow'        => 'soft',
			)
		);

		$this->assertSame( array( '4', '12', 'subtle', 'grow', 'strong', '#ffffff', '6', 'soft' ), array_values( array_intersect_key( $args, Gallery_Look::OPTIONS ) ) );
	}

	public function test_frame_colours_are_cleaned(): void {
		$this->assertSame( '#abc', Gallery_Look::clean_color( ' #ABC ' ) );
		$this->assertSame( '', Gallery_Look::clean_color( '' ) );
		$this->assertNull( Gallery_Look::clean_color( 'white' ) );
		$this->assertNull( Gallery_Look::clean_color( array() ) );
	}
}
