<?php
/**
 * Block pattern tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Modules\Patterns;
use Profotograaf\Gallery_Renderer;

class Patterns_Test extends Wp_Test_Case {

	/**
	 * Registered patterns, by name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $patterns = array();

	/**
	 * Registered pattern categories, by name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $categories = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
		Functions\when( 'register_block_pattern' )->alias(
			function ( string $name, array $properties ) {
				$this->patterns[ $name ] = $properties;
				return true;
			}
		);
		Functions\when( 'register_block_pattern_category' )->alias(
			function ( string $name, array $properties ) {
				$this->categories[ $name ] = $properties;
				return true;
			}
		);
	}

	private function register(): void {
		( new Patterns() )->register_patterns();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function block_json( string $block ): array {
		$json = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/' . $block . '/block.json' ), true );
		$this->assertIsArray( $json );
		return $json;
	}

	/**
	 * Attributes of every block of one type in a pattern.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function block_attributes( string $content, string $name ): array {
		preg_match_all( '#<!-- wp:' . preg_quote( $name, '#' ) . '( \{.*?\})? /-->#', $content, $matches );
		return array_map( fn( $json ) => '' === $json ? array() : (array) json_decode( $json, true ), $matches[1] );
	}

	public function test_register_hooks_into_init(): void {
		Actions\expectAdded( 'init' )->once();
		( new Patterns() )->register( \Profotograaf\Plugin::instance() );
	}

	public function test_it_registers_the_profotograaf_category(): void {
		$this->register();

		$this->assertArrayHasKey( 'profotograaf', $this->categories );
		$this->assertSame( 'Profotograaf', $this->categories['profotograaf']['label'] );
	}

	public function test_it_registers_the_four_patterns_in_the_category(): void {
		$this->register();

		$this->assertSame(
			array( 'profotograaf/client-portal-cta', 'profotograaf/contact-page', 'profotograaf/masonry-showcase', 'profotograaf/portfolio-grid' ),
			array_keys( $this->patterns )
		);
		foreach ( $this->patterns as $name => $pattern ) {
			$this->assertSame( array( 'profotograaf' ), $pattern['categories'], $name );
			$this->assertNotSame( '', $pattern['title'], $name );
			$this->assertNotSame( '', $pattern['description'], $name );
		}
	}

	public function test_all_pattern_text_goes_through_translation(): void {
		Functions\when( '__' )->alias( fn( $text ) => '[t]' . $text );
		Functions\when( 'esc_html__' )->alias( fn( $text ) => '[t]' . $text );
		$this->register();

		foreach ( $this->patterns as $name => $pattern ) {
			$this->assertStringStartsWith( '[t]', $pattern['title'], $name );
			$this->assertStringStartsWith( '[t]', $pattern['description'], $name );
			foreach ( $pattern['keywords'] as $keyword ) {
				$this->assertStringStartsWith( '[t]', $keyword, $name );
			}
			// Every text node between tags is translated.
			preg_match_all( '#>([^<>]+)<#', $pattern['content'], $matches );
			foreach ( $matches[1] as $text ) {
				if ( '' !== trim( $text ) ) {
					$this->assertStringStartsWith( '[t]', $text, $name );
				}
			}
			// Text inside block attributes too.
			foreach ( $this->block_attributes( $pattern['content'], 'profotograaf/client-galleries' ) as $attributes ) {
				foreach ( array( 'heading', 'description', 'buttonLabel' ) as $key ) {
					$this->assertStringStartsWith( '[t]', $attributes[ $key ], $name . ' ' . $key );
				}
			}
		}
	}

	public function test_gallery_blocks_use_only_known_display_options(): void {
		$this->register();
		$known = array_merge(
			array( 'galleryId', 'galleryTitle', 'galleryUrl', 'layout' ),
			array_column( Gallery_Renderer::OPTIONS, 'attribute' )
		);
		$json  = $this->block_json( 'gallery' );

		$found = 0;
		foreach ( $this->patterns as $name => $pattern ) {
			foreach ( $this->block_attributes( $pattern['content'], 'profotograaf/gallery' ) as $attributes ) {
				++$found;
				foreach ( $attributes as $key => $value ) {
					$this->assertContains( $key, $known, $name . ' ' . $key );
					$this->assertIsString( $value, $name . ' ' . $key );
				}
				$this->assertContains( $attributes['layout'], $json['attributes']['layout']['enum'], $name );
			}
		}
		$this->assertGreaterThanOrEqual( 3, $found );
	}

	public function test_client_portal_blocks_use_only_known_attributes(): void {
		$this->register();
		$json   = $this->block_json( 'client-galleries' );
		$blocks = $this->block_attributes( $this->patterns['profotograaf/client-portal-cta']['content'], 'profotograaf/client-galleries' );

		$this->assertCount( 1, $blocks );
		foreach ( array_keys( $blocks[0] ) as $key ) {
			$this->assertArrayHasKey( $key, $json['attributes'], $key );
		}
		$this->assertSame( 2, $blocks[0]['headingLevel'] );
	}

	public function test_the_layouts_match_the_pattern_names(): void {
		$this->register();

		$layout = fn( string $name ) => $this->block_attributes( $this->patterns[ $name ]['content'], 'profotograaf/gallery' )[0]['layout'];
		$this->assertSame( 'grid', $layout( 'profotograaf/portfolio-grid' ) );
		$this->assertSame( 'masonry', $layout( 'profotograaf/masonry-showcase' ) );
	}

	public function test_the_contact_page_form_can_be_replaced_with_a_filter(): void {
		Filters\expectApplied( 'profotograaf_pattern_enquiry_form' )->once()->andReturn( '<!-- wp:html --><form></form><!-- /wp:html -->' );
		$this->register();

		$this->assertStringContainsString( '<form></form>', $this->patterns['profotograaf/contact-page']['content'] );
	}

	public function test_the_contact_page_has_a_placeholder_for_the_form_by_default(): void {
		$this->register();

		$this->assertStringContainsString( 'Replace this text with your enquiry form block.', $this->patterns['profotograaf/contact-page']['content'] );
		$this->assertCount( 1, $this->block_attributes( $this->patterns['profotograaf/contact-page']['content'], 'profotograaf/gallery' ) );
	}

	public function test_markup_helpers_escape_text_and_attributes(): void {
		Functions\when( 'esc_html' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );

		$this->assertStringContainsString( '&lt;b&gt;', Patterns::heading( '<b>' ) );
		$this->assertStringContainsString( '&lt;b&gt;', Patterns::paragraph( '<b>' ) );
		$this->assertStringNotContainsString( '<b>', Patterns::block( 'profotograaf/x', array( 'a' => '<b>&' ) ) );
		$this->assertSame( '<!-- wp:profotograaf/x /-->', Patterns::block( 'profotograaf/x' ) );
	}
}
