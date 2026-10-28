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
	 * The camel-case SVG names the HTML parser of DOMDocument lowercases.
	 */
	private const SVG_CASE = array(
		'fecolormatrix'       => 'feColorMatrix',
		'fecomponenttransfer' => 'feComponentTransfer',
		'fefuncr'             => 'feFuncR',
		'fefuncg'             => 'feFuncG',
		'fefuncb'             => 'feFuncB',
		'tablevalues'         => 'tableValues',
	);

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
	 * Fixtures with a client tree.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function with_client_tree(): array {
		$out = array();
		foreach ( (array) glob( self::FIXTURES . '*.expected.html' ) as $file ) {
			$name         = basename( $file, '.expected.html' );
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
			$attrs .= ' ' . strtr( $attr->name, self::SVG_CASE ) . '="' . str_replace( array( '"', "\n" ), array( '&quot;', '&#10;' ), $this->text( $attr->value ) ) . '"';
		}
		$lines[] = $pad . '<' . strtr( $node->localName, self::SVG_CASE ) . $attrs . '>';
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
	 * Skips a fixture with a title order on a host without intl: the server cannot collate there and leaves it to the script.
	 *
	 * @param array{attrs:array<string,string>,gallery:array<string,mixed>} $fixture Fixture.
	 */
	private function skip_without_intl( array $fixture ): void {
		if ( in_array( strtolower( trim( $fixture['attrs']['data-sort'] ?? '' ) ), array( 'title', 'name' ), true ) && ! class_exists( '\Collator' ) ) {
			$this->markTestSkipped( 'The title order needs the intl extension.' );
		}
	}

	/**
	 * Every served fixture draws the golden tree.
	 *
	 * @dataProvider served
	 * @param string $name Fixture name.
	 */
	public function test_a_served_fixture_draws_the_golden_tree( string $name ): void {
		$fixture = $this->fixture( $name );
		$this->skip_without_intl( $fixture );
		$html = Declarative_Renderer::render( $fixture['gallery'], $fixture['attrs'] );
		$this->assertNotNull( $html );
		$this->assertSame( file_get_contents( self::FIXTURES . $name . '.server.html' ), $this->tree( (string) $html ) );
	}

	/**
	 * The server draws what the script draws, or leaves the fixture to the script. Masonry is the one
	 * difference: the server writes one ordered list and the script places the columns on adoption.
	 *
	 * @dataProvider with_client_tree
	 * @param string $name Fixture name.
	 */
	public function test_the_server_tree_is_the_client_tree_or_the_fixture_is_left_to_the_script( string $name ): void {
		$fixture = $this->fixture( $name );
		$this->skip_without_intl( $fixture );
		$expected = (string) file_get_contents( self::FIXTURES . $name . '.expected.html' );
		$html     = Declarative_Renderer::render( $fixture['gallery'], $fixture['attrs'] );
		if ( null === $html ) {
			$this->assertFileDoesNotExist( self::FIXTURES . $name . '.server.html', $name . ' has a server file but the renderer draws nothing' );
			return;
		}
		$this->assertFileExists( self::FIXTURES . $name . '.server.html', $name . ' is drawn on the server and has no server file' );
		if ( false === strpos( $expected, 'class="mcol"' ) ) {
			$this->assertSame( $expected, $this->tree( $html ) );
		}
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
	}

	public function test_a_paged_gallery_draws_the_first_page_and_the_show_more_button(): void {
		$gallery = $this->fixture( 'many-photos' )['gallery'];
		$html    = (string) Declarative_Renderer::render( $gallery, array( 'data-per-page' => '2' ) );
		$this->assertSame( 2, substr_count( $html, 'class="tile"' ) );
		$this->assertStringContainsString( '<button class="more" type="button">Show more</button></div></div>', $html );
		$dutch = (string) Declarative_Renderer::render( $gallery, array( 'data-per-page' => '2' ), 'Gemaakt met Profotograaf', 'Toon meer' );
		$this->assertStringContainsString( '>Toon meer</button>', $dutch );
	}

	public function test_the_show_more_button_is_hidden_when_the_first_page_holds_every_photo(): void {
		$gallery = $this->fixture( 'many-photos' )['gallery'];
		$html    = (string) Declarative_Renderer::render( $gallery, array( 'data-per-page' => '500' ) );
		$this->assertStringContainsString( '<button class="more" type="button" hidden>', $html );
		$this->assertSame( count( $gallery['photos'] ), substr_count( $html, 'class="tile"' ) );
	}

	public function test_a_paged_gallery_without_the_button_draws_only_the_first_page(): void {
		$gallery = $this->fixture( 'many-photos' )['gallery'];
		$html    = (string) Declarative_Renderer::render( $gallery, array( 'data-per-page' => '2', 'data-load-more' => 'off' ) );
		$this->assertSame( 2, substr_count( $html, 'class="tile"' ) );
		$this->assertStringNotContainsString( 'class="more"', $html );
	}

	public function test_a_page_size_the_script_rejects_draws_every_photo(): void {
		$gallery = $this->fixture( 'many-photos' )['gallery'];
		foreach ( array( '0', '501', 'abc', '2.5' ) as $size ) {
			$html = (string) Declarative_Renderer::render( $gallery, array( 'data-per-page' => $size ) );
			$this->assertSame( count( $gallery['photos'] ), substr_count( $html, 'class="tile"' ), $size );
			$this->assertStringNotContainsString( 'class="more"', $html, $size );
		}
	}

	public function test_the_random_sort_is_left_to_the_client(): void {
		$gallery = $this->fixture( 'sort-reverse' )['gallery'];
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-sort' => ' random ' ) ) );
		foreach ( array( 'title', 'Name' ) as $sort ) {
			// A host without intl cannot order by the locale's collation, so the script draws it.
			$drawn = Declarative_Renderer::render( $gallery, array( 'data-sort' => $sort ) );
			class_exists( '\\Collator' ) ? $this->assertNotNull( $drawn, $sort ) : $this->assertNull( $drawn, $sort );
		}
		foreach ( array( 'reverse', 'newest', 'oldest', 'default', 'sideways', '' ) as $sort ) {
			$this->assertNotNull( Declarative_Renderer::render( $gallery, array( 'data-sort' => $sort ) ), $sort );
		}
	}

	public function test_excluding_every_photo_leaves_nothing_to_draw(): void {
		$gallery = $this->fixture( 'exclude-ids' )['gallery'];
		$ids     = implode( ',', array_column( $gallery['photos'], 'id' ) );
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-exclude' => $ids ) ) );
		$this->assertNotNull( Declarative_Renderer::render( $gallery, array( 'data-exclude' => 'p1' ) ) );
	}

	public function test_an_image_text_cut_inside_a_surrogate_pair_is_written_as_the_replacement_character(): void {
		$gallery = $this->fixture( 'image-text-caption' )['gallery'];
		// 499 units, then an emoji of two: the script keeps the first half only.
		$caption = str_repeat( 'a', 499 ) . "\u{1F600}tail";
		$attrs   = array(
			'data-captions'   => 'below',
			'data-image-text' => (string) json_encode( array( array( 'id' => 'p1', 'caption' => $caption ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the unit test needs no WordPress.
		);
		$html    = (string) Declarative_Renderer::render( $gallery, $attrs );
		$this->assertStringContainsString( str_repeat( 'a', 499 ) . "\u{FFFD}</figcaption>", $html );
		$this->assertStringNotContainsString( 'tail', $html );
	}

	public function test_a_lightbox_off_tile_is_a_link_or_an_inert_box(): void {
		$gallery = $this->fixture( 'link-file' )['gallery'];
		$tiles   = static function ( array $attrs, array $payload ) use ( $gallery ): array {
			preg_match_all( '/<(?:a|button|div) class="tile[^"]*"[^>]*>/', (string) Declarative_Renderer::render( ( array() !== $payload ? $payload : $gallery ), $attrs ), $m );
			return $m[0];
		};
		$off     = array( 'data-lightbox' => 'off' );
		$this->assertCount( count( $gallery['photos'] ), $tiles( $off + array( 'data-link-to' => 'file' ), array() ) );
		$this->assertMatchesRegularExpression( '/^<a class="tile" href="https:[^"]+" target="_blank" rel="noopener noreferrer" aria-label=/', $tiles( $off + array( 'data-link-to' => 'FILE ' ), array() )[0] );
		$this->assertStringNotContainsString( 'target=', $tiles( $off + array( 'data-link-to' => 'page', 'data-link-new-tab' => 'off' ), array() )[0] );
		$this->assertSame( '<div class="tile static">', $tiles( $off + array( 'data-link-to' => 'none' ), array() )[0] );
		// A value the script does not know is no link target: the plain static button stays.
		$this->assertStringStartsWith( '<button class="tile static" type="button"', $tiles( $off + array( 'data-link-to' => 'bogus' ), array() )[0] );
		// The lightbox wins while it is on.
		$this->assertStringStartsWith( '<button class="tile" type="button"', $tiles( array( 'data-link-to' => 'file' ), array() )[0] );
	}

	public function test_only_web_and_relative_urls_reach_an_href(): void {
		$gallery                     = $this->fixture( 'link-file' )['gallery'];
		$gallery['photos']           = array_slice( $gallery['photos'], 0, 4 );
		$gallery['url']              = 'javascript:alert(1)';
		$gallery['site_url']         = 'data:text/html,x';
		$gallery['photos'][0]['url'] = '/share/photo/1';
		$gallery['photos'][1]['url'] = 'JavaScript:alert(2)';
		$gallery['photos'][2]['url'] = " \tjavascript:alert(3)";
		$gallery['photos'][3]['url'] = 'http://[bad';
		$attrs                       = array( 'data-lightbox' => 'off', 'data-link-to' => 'page' );
		$html                        = (string) Declarative_Renderer::render( $gallery, $attrs );
		$this->assertStringNotContainsString( 'alert', $html );
		$this->assertStringContainsString( 'href="/share/photo/1"', $html );
		$this->assertSame( 3, substr_count( $html, '<div class="tile static">' ) );
		$this->assertStringNotContainsString( 'data:text', (string) Declarative_Renderer::render( $gallery, array( 'data-lightbox' => 'off', 'data-link-to' => 'site' ) ) );
		// A quote in a URL cannot leave the attribute.
		$gallery['photos'][0]['url'] = 'https://example.test/a"onmouseover="x';
		$this->assertStringNotContainsString( '"onmouseover', (string) Declarative_Renderer::render( $gallery, $attrs ) );
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

	public function test_the_original_ratio_is_left_to_the_client(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-ratio' => 'original' ) ) );
		$this->assertNull( Declarative_Renderer::render( $gallery, array( 'data-ratio' => ' Original ' ) ) );
	}

	public function test_frame_and_ratio_are_written_as_the_script_writes_them(): void {
		$gallery = $this->fixture( 'one-photo' )['gallery'];
		$style   = static function ( array $attrs ) use ( $gallery ): string {
			$html = (string) Declarative_Renderer::render( $gallery, array_merge( array( 'data-layout' => 'grid' ), $attrs ) );
			return 1 === preg_match( '/<div class="gallery[^"]*" style="([^"]*)"/', $html, $m ) ? $m[1] : '';
		};
		$this->assertSame( '--frame-w:12px;--frame-c:#aabbcc', $style( array( 'data-frame' => ' AABBCC 12px ' ) ) );
		$this->assertSame( '--frame-w:6px;--frame-c:#112233', $style( array( 'data-frame' => '#123,6' ) ) );
		$this->assertSame( '--ratio:1.5/1', $style( array( 'data-ratio' => '1.5' ) ) );
		$this->assertSame( '--ratio:4/3', $style( array( 'data-ratio' => '4/3' ) ) );
		$this->assertSame( '--ratio:16/9', $style( array( 'data-ratio' => ' 16 x 9 ' ) ) );
		// Rejected by the script: width 0, width over 24, a bad colour, a ratio over 10:1 or over 100.
		foreach ( array( '#fff,0', '#fff,25', 'red,4', '#fff', '#fff,4,5' ) as $frame ) {
			$this->assertSame( '', $style( array( 'data-frame' => $frame ) ), $frame );
		}
		foreach ( array( '11:1', '1:11', '101:1', '0:4', 'wide', '4:' ) as $ratio ) {
			$this->assertSame( '', $style( array( 'data-ratio' => $ratio ) ), $ratio );
		}
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
