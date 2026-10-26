<?php
/**
 * Declarative shadow root renderer tests: held to the golden files of the embed
 * package (tests/fixtures/embed, from professionals packages/embed/fixtures).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM API names its properties in camel case.

use PHPUnit\Framework\TestCase;
use Profotograaf\Declarative_Renderer;

class Declarative_Renderer_Test extends TestCase {

	private const FIXTURES = __DIR__ . '/../fixtures/embed/';

	/**
	 * Fixtures the server draws.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function served(): array {
		$out = array();
		foreach ( (array) glob( self::FIXTURES . '*.server.html' ) as $file ) {
			$name         = basename( $file, '.server.html' );
			$out[ $name ] = array( $name );
		}
		return $out;
	}

	/**
	 * Reads a fixture.
	 *
	 * @param string $name Fixture name.
	 * @return array{attrs:array<string,string>,gallery:array<string,mixed>}
	 */
	private function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( self::FIXTURES . $name . '.json' ), true );
	}

	/**
	 * The shadow tree in the one-element-per-line form of the golden files:
	 * escaped text and attributes, two spaces per level, no closing tags, no stylesheet.
	 *
	 * @param string $html Output of the renderer.
	 */
	private function tree( string $html ): string {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		$template = $doc->getElementsByTagName( 'template' )->item( 0 );
		$lines    = array();
		foreach ( $template->childNodes as $node ) {
			if ( $node instanceof \DOMElement && 'style' === $node->localName ) {
				continue;
			}
			$this->walk( $node, 0, $lines );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Adds the lines of one node and its children.
	 *
	 * @param \DOMNode        $node  Node.
	 * @param int             $depth Depth.
	 * @param array<int,string> $lines Lines so far.
	 */
	private function walk( \DOMNode $node, int $depth, array &$lines ): void {
		$pad = str_repeat( '  ', $depth );
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = (string) $node->textContent;
			if ( '' !== trim( $text ) ) {
				$lines[] = $pad . $this->text( $text );
			}
			return;
		}
		if ( ! $node instanceof \DOMElement ) {
			return;
		}
		$attrs = '';
		foreach ( $node->attributes as $attr ) {
			$attrs .= ' ' . $attr->name . '="' . str_replace( array( '"', "\n" ), array( '&quot;', '&#10;' ), $this->text( $attr->value ) ) . '"';
		}
		$lines[] = $pad . '<' . $node->localName . $attrs . '>';
		foreach ( $node->childNodes as $child ) {
			$this->walk( $child, $depth + 1, $lines );
		}
	}

	/**
	 * Escapes text the way the golden files do.
	 *
	 * @param string $text Text.
	 */
	private function text( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * Every served fixture draws the golden tree.
	 *
	 * @dataProvider served
	 * @param string $name Fixture name.
	 */
	public function test_a_served_fixture_draws_the_golden_tree( string $name ): void {
		$fixture = $this->fixture( $name );
		$html    = Declarative_Renderer::render( $fixture['gallery'], $fixture['attrs'] );
		$this->assertNotNull( $html );
		$this->assertSame( file_get_contents( self::FIXTURES . $name . '.server.html' ), $this->tree( (string) $html ) );
	}

	public function test_the_output_is_a_declarative_shadow_root_with_the_shared_stylesheet(): void {
		$fixture = $this->fixture( 'one-photo' );
		$html    = (string) Declarative_Renderer::render( $fixture['gallery'], $fixture['attrs'] );
		$css     = (string) file_get_contents( self::FIXTURES . 'stylesheet.css' );
		$this->assertStringStartsWith( '<template shadowrootmode="open"><style>', $html );
		$this->assertStringContainsString( $css, $html );
		$this->assertStringEndsWith( '</template>', $html );
	}

	public function test_the_plugin_stylesheet_is_the_package_stylesheet(): void {
		$this->assertSame( file_get_contents( self::FIXTURES . 'stylesheet.css' ), file_get_contents( dirname( __DIR__, 2 ) . '/includes/embed-shadow.css' ) );
	}

	public function test_markup_characters_are_escaped(): void {
		$fixture = $this->fixture( 'captions-html-unsafe' );
		$html    = (string) Declarative_Renderer::render( $fixture['gallery'], $fixture['attrs'] );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	public function test_the_client_draws_what_the_server_does_not(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-layout' => 'slideshow' ) ) );
		$this->assertNull( Declarative_Renderer::render( $this->fixture( 'empty-gallery' )['gallery'], array() ) );
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-duotone' => '#000000,#ffffff' ) ) );
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-per-page' => '2' ) ) );
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-lightbox' => 'off' ) ) );
	}

	public function test_the_slideshow_fixture_is_not_served(): void {
		$fixture = $this->fixture( 'slideshow' );
		$this->assertNull( Declarative_Renderer::render( $fixture['gallery'], $fixture['attrs'] ) );
	}

	public function test_a_platform_layout_name_maps_to_the_drawn_layout(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$this->assertStringContainsString( 'gallery grid', (string) Declarative_Renderer::render( $gallery, array( 'data-layout' => 'instagram' ) ) );
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-layout' => 'parallax' ) ) );
	}

	public function test_a_look_value_the_script_rejects_is_drawn_as_the_default(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$html    = (string) Declarative_Renderer::render(
			$gallery,
			array(
				'data-layout'  => 'grid',
				'data-columns' => '99',
				'data-gap'     => 'wide',
				'data-hover'   => 'spin',
				'data-shadow'  => 'huge',
				'data-radius'  => '-4',
			)
		);
		$this->assertStringContainsString( '<div class="gallery grid"><div class="photos">', $html );
		$this->assertStringNotContainsString( 'style="', $html );
	}

	public function test_the_grow_hover_marks_the_tile_and_the_figure(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$this->assertStringContainsString( 'class="tile u"', (string) Declarative_Renderer::render( $gallery, array( 'data-hover' => 'grow' ) ) );
		$this->assertStringContainsString( 'class="item u"', (string) Declarative_Renderer::render( $this->fixture( 'captions-html-unsafe' )['gallery'], array( 'data-hover' => 'grow', 'data-captions' => 'below' ) ) );
	}

	public function test_the_badge_follows_the_gallery_and_the_attribute(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$this->assertStringContainsString( 'class="badge"', (string) Declarative_Renderer::render( $gallery, array() ) );
		$this->assertStringNotContainsString( 'class="badge"', (string) Declarative_Renderer::render( $gallery, array( 'data-badge' => 'off' ) ) );
	}
}
