<?php
/**
 * Shortcode tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Shortcode;

class Shortcode_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_html_class' )->alias( fn( $name ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $name ) );
	}

	public function test_it_registers_the_tag(): void {
		Functions\expect( 'add_shortcode' )->once()->with( 'profotograaf_gallery', \Mockery::type( 'array' ) );

		( new Shortcode( $this->renderer ) )->register();
	}

	public function test_it_renders_like_the_block_does(): void {
		$shortcode = new Shortcode( $this->renderer );
		$atts      = array(
			'id'     => 'g-1',
			'layout' => 'slideshow',
			'title'  => 'Spring wedding',
			'url'    => 'https://profotograaf.nl/share/g/spring-wedding',
		);

		$this->assertSame( $shortcode->render( $atts ), self::HINT . $this->renderer->render( $atts ) );
		$this->assertStringContainsString( 'data-layout="slideshow"', $shortcode->render( $atts ) );
	}

	public function test_unknown_attributes_are_dropped(): void {
		$html = ( new Shortcode( $this->renderer ) )->render(
			array(
				'id'      => 'g-1',
				'onclick' => 'alert(1)',
				'url'     => 'https://profotograaf.nl/share/g/x',
			)
		);

		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function test_the_id_is_required_and_a_bare_tag_renders_nothing(): void {
		$shortcode = new Shortcode( $this->renderer );

		$this->assertSame( '', $shortcode->render( array() ) );
		$this->assertSame( '', $shortcode->render( '' ) );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_display_attributes_override_the_site_default(): void {
		$this->options['profotograaf_settings'] = array(
			'gallery_columns'  => 5,
			'gallery_captions' => 'below',
		);

		$html = ( new Shortcode( $this->renderer ) )->render(
			array(
				'id'             => 'g-1',
				'columns'        => '3',
				'columns_mobile' => '1',
				'per_page'       => '12',
				'load_more'      => 'on',
				'lightbox'       => 'off',
			)
		);

		$this->assertStringContainsString( ' data-columns="3"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="1"', $html );
		$this->assertStringContainsString( ' data-per-page="12"', $html );
		$this->assertStringContainsString( ' data-load-more="on"', $html );
		$this->assertStringContainsString( ' data-lightbox="off"', $html );
		$this->assertStringContainsString( ' data-captions="below"', $html );
	}

	public function test_class_and_align_reach_the_wrapper(): void {
		$html = ( new Shortcode( $this->renderer ) )->render(
			array(
				'id'    => 'g-1',
				'class' => 'my-gallery  "><script>',
				'align' => 'wide',
			)
		);

		$this->assertStringContainsString( 'class="profotograaf-gallery my-gallery script alignwide"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_an_unknown_align_value_adds_no_class(): void {
		$html = ( new Shortcode( $this->renderer ) )->render(
			array(
				'id'    => 'g-1',
				'align' => 'left',
			)
		);

		$this->assertStringContainsString( 'class="profotograaf-gallery"', $html );
	}

	public function test_the_exclude_attribute_lists_photos_to_leave_out(): void {
		$html = ( new Shortcode( $this->renderer ) )->render( array( 'id' => 'g-1', 'exclude' => 'p-1, p-2' ) );

		$this->assertStringContainsString( ' data-exclude="p-1,p-2"', $html );
	}
}
