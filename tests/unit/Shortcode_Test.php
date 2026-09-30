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

		$this->assertSame( $this->renderer->render( $atts ), $shortcode->render( $atts ) );
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
}
