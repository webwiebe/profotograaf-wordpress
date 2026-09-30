<?php
/**
 * Client galleries block tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Modules\Client_Galleries;

class Client_Galleries_Test extends Wp_Test_Case {

	private Client_Galleries $block;

	protected function setUp(): void {
		parent::setUp();
		$this->block = new Client_Galleries();
		Functions\when( 'esc_url' )->alias( fn( $url ) => (string) $url );
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( 'class="wp-block-profotograaf-client-galleries"' );
		Functions\when( 'current_user_can' )->justReturn( false );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function addresses(): array {
		return array(
			'slug'                    => array( 'studio', 'https://profotograaf.nl/studio/client' ),
			'slug is lowercased'      => array( 'Studio-One', 'https://profotograaf.nl/studio-one/client' ),
			'subdomain'               => array( 'studio.profotograaf.nl', 'https://studio.profotograaf.nl/client' ),
			'custom domain'           => array( 'photos.example.com', 'https://photos.example.com/client' ),
			'custom domain with path' => array( 'photos.example.com/portal', 'https://photos.example.com/portal' ),
			'url without path'        => array( 'https://photos.example.com/', 'https://photos.example.com/client' ),
			'url with path'           => array( 'https://profotograaf.nl/studio/client', 'https://profotograaf.nl/studio/client' ),
			'surrounding space'       => array( '  studio  ', 'https://profotograaf.nl/studio/client' ),
			'empty'                   => array( '', '' ),
			'javascript url'          => array( 'javascript://example.com/%0Aalert(1)', '' ),
			'ftp url'                 => array( 'ftp://example.com', '' ),
			'space inside'            => array( 'my studio', '' ),
			'markup'                  => array( '<script>', '' ),
			'trailing dash slug'      => array( 'studio-', '' ),
		);
	}

	/**
	 * @dataProvider addresses
	 */
	public function test_portal_url( string $address, string $expected ): void {
		$this->assertSame( $expected, Client_Galleries::portal_url( $address ) );
	}

	public function test_portal_url_follows_the_platform_url_filter(): void {
		Filters\expectApplied( 'profotograaf_platform_url' )->andReturn( 'http://mock-platform:8090/' );
		$this->assertSame( 'http://mock-platform:8090/studio/client', Client_Galleries::portal_url( 'studio' ) );
	}

	public function test_render_outputs_a_link_with_default_text(): void {
		$html = $this->block->render( array( 'portal' => 'studio' ) );

		$this->assertStringContainsString( 'href="https://profotograaf.nl/studio/client"', $html );
		$this->assertStringContainsString( '>Find your gallery</h3>', $html );
		$this->assertStringContainsString( '>Open my gallery</a>', $html );
		$this->assertStringNotContainsString( 'target=', $html );
	}

	public function test_render_uses_custom_text_and_escapes_it(): void {
		Functions\when( 'esc_html' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		$html = $this->block->render(
			array(
				'portal'      => 'studio',
				'heading'     => 'Hi <b>there</b>',
				'description' => 'Your photos',
				'buttonLabel' => 'Go',
			)
		);

		$this->assertStringContainsString( 'Hi &lt;b&gt;there&lt;/b&gt;', $html );
		$this->assertStringContainsString( '>Go</a>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_render_new_tab_sets_rel(): void {
		$html = $this->block->render(
			array(
				'portal'       => 'studio',
				'openInNewTab' => true,
			)
		);

		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
	}

	public function test_render_works_when_the_site_is_not_connected(): void {
		// Nothing is stored in the options table: no tokens, no settings.
		$this->assertSame( array(), $this->options );
		$html = $this->block->render( array( 'portal' => 'studio' ) );

		$this->assertStringContainsString( 'studio/client', $html );
	}

	public function test_render_without_an_address_is_empty_for_visitors(): void {
		$this->assertSame( '', $this->block->render( array() ) );
		$this->assertSame( '', $this->block->render( array( 'portal' => 'not a portal' ) ) );
	}

	public function test_render_without_an_address_shows_editors_a_note(): void {
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_posts' === $cap );
		$html = $this->block->render( array() );

		$this->assertStringContainsString( 'Only editors see this note', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_site_default_address_from_the_filter(): void {
		Filters\expectApplied( 'profotograaf_client_portal_address' )->once()->andReturn( 'photos.example.com' );
		$html = $this->block->render( array() );

		$this->assertStringContainsString( 'href="https://photos.example.com/client"', $html );
	}

	public function test_block_address_wins_over_the_filter(): void {
		Filters\expectApplied( 'profotograaf_client_portal_address' )->never();
		$html = $this->block->render( array( 'portal' => 'studio' ) );

		$this->assertStringContainsString( 'profotograaf.nl/studio/client', $html );
	}

	public function test_render_callback_is_added_to_this_block_only(): void {
		$args = $this->block->add_render_callback( array( 'title' => 'x' ), Client_Galleries::BLOCK );
		$this->assertSame( array( $this->block, 'render' ), $args['render_callback'] );

		$other = $this->block->add_render_callback( array( 'title' => 'x' ), 'core/paragraph' );
		$this->assertArrayNotHasKey( 'render_callback', $other );
	}

	public function test_editor_translations_are_found_under_the_source_path(): void {
		$this->assertSame( 'blocks/client-galleries/index.js', $this->block->source_script_path( 'build/client-galleries/index.js', '' ) );
		$this->assertSame( 'build/other/index.js', $this->block->source_script_path( 'build/other/index.js', '' ) );
		$this->assertFalse( $this->block->source_script_path( false, '' ) );
	}

	public function test_block_json_matches_the_render_attributes(): void {
		$json = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/client-galleries/block.json' ), true );

		$this->assertSame( Client_Galleries::BLOCK, $json['name'] );
		$this->assertSame( 'profotograaf', $json['textdomain'] );
		foreach ( array( 'portal', 'heading', 'description', 'buttonLabel', 'openInNewTab' ) as $attribute ) {
			$this->assertArrayHasKey( $attribute, $json['attributes'] );
		}
		$this->assertArrayNotHasKey( 'render', $json, 'The render callback is attached in PHP.' );
	}
}
