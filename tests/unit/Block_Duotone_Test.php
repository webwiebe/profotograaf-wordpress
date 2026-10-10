<?php
/**
 * Tests for the duotone of a gallery block.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Block_Duotone;
use Profotograaf\Gallery_Renderer;

class Block_Duotone_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	public function test_a_block_duotone_of_two_colours_replaces_the_site_duotone(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_duotone' => '#111111,#eeeeee' );

		$args = Gallery_Renderer::options_from_block( array( 'style' => array( 'color' => array( 'duotone' => array( '#1A1A2E', '#f5c542' ) ) ) ) );
		$html = $this->renderer->render( array( 'id' => 'g-1' ) + $args );

		$this->assertSame( '#1a1a2e,#f5c542', $args['duotone'] );
		$this->assertStringContainsString( ' data-duotone="#1a1a2e,#f5c542"', $html );
		$this->assertStringNotContainsString( '#111111', $html );
	}

	public function test_a_block_duotone_preset_resolves_through_the_global_settings(): void {
		Functions\when( 'wp_get_global_settings' )->alias(
			function ( $path ) {
				$this->assertSame( array( 'color', 'duotone' ), $path );
				return array(
					'default' => array( array( 'slug' => 'midnight', 'colors' => array( '#000000', '#ffffff' ) ) ),
					'theme'   => array( array( 'slug' => 'midnight', 'colors' => array( '#1a1a2e', '#f5c542' ) ) ),
				);
			}
		);

		foreach ( array( 'var:preset|duotone|midnight', 'var(--wp--preset--duotone--midnight)' ) as $reference ) {
			$args = Gallery_Renderer::options_from_block( array( 'style' => array( 'color' => array( 'duotone' => $reference ) ) ) );
			$this->assertSame( '#1a1a2e,#f5c542', $args['duotone'] );
		}
		$this->assertSame( '', Block_Duotone::resolve( 'var:preset|duotone|missing' ) );
	}

	public function test_a_flat_preset_list_and_a_preset_with_other_colours_are_handled(): void {
		Functions\when( 'wp_get_global_settings' )->justReturn(
			array(
				array( 'slug' => 'flat', 'colors' => array( '#000', '#fff' ) ),
				array( 'slug' => 'rgb', 'colors' => array( 'rgb(0,0,0)', 'rgb(255,255,255)' ) ),
				array( 'slug' => 'three', 'colors' => array( '#000', '#888', '#fff' ) ),
			)
		);

		$this->assertSame( '#000,#fff', Block_Duotone::resolve( 'var:preset|duotone|flat' ) );
		$this->assertSame( '', Block_Duotone::resolve( 'var:preset|duotone|rgb' ) );
		$this->assertSame( '', Block_Duotone::resolve( 'var:preset|duotone|three' ) );
	}

	public function test_a_block_without_a_usable_duotone_keeps_the_site_duotone(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_duotone' => '#111111,#eeeeee' );

		foreach ( array( array(), array( 'style' => array( 'color' => array( 'duotone' => 'junk' ) ) ), array( 'style' => array( 'color' => array( 'duotone' => array( 'red' ) ) ) ) ) as $attributes ) {
			$args = Gallery_Renderer::options_from_block( $attributes );
			$this->assertSame( '', $args['duotone'] );
			$html = $this->renderer->render( array( 'id' => 'g-1' ) + $args );
			$this->assertStringContainsString( ' data-duotone="#111111,#eeeeee"', $html );
		}
	}

	public function test_a_block_that_turns_duotone_off_also_switches_the_site_duotone_off(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_duotone' => '#111111,#eeeeee' );

		$args = Gallery_Renderer::options_from_block( array( 'style' => array( 'color' => array( 'duotone' => 'unset' ) ) ) );
		$html = $this->renderer->render( array( 'id' => 'g-1' ) + $args );

		$this->assertSame( 'none', $args['duotone'] );
		$this->assertStringNotContainsString( 'data-duotone', $html );
	}
}
