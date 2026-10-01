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
use Profotograaf\Settings;

class Client_Galleries_Test extends Wp_Test_Case {

	private Client_Galleries $block;

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
		$this->block    = new Client_Galleries( $this->settings );
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
		$this->assertStringContainsString( '<h3 class="wp-block-profotograaf-client-galleries__heading">Find your gallery</h3>', $html );
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

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function paths(): array {
		return array(
			'slug with path'    => array( 'studio', '/portal', 'https://profotograaf.nl/studio/portal' ),
			'host with path'    => array( 'photos.example.com', '/clients', 'https://photos.example.com/clients' ),
			'url without path'  => array( 'https://photos.example.com', '/clients', 'https://photos.example.com/clients' ),
			'address path wins' => array( 'photos.example.com/own', '/clients', 'https://photos.example.com/own' ),
		);
	}

	/**
	 * @dataProvider paths
	 */
	public function test_portal_url_uses_the_given_path( string $address, string $path, string $expected ): void {
		$this->assertSame( $expected, Client_Galleries::portal_url( $address, $path ) );
	}

	public function test_site_setting_sets_the_portal_path(): void {
		$this->options['profotograaf_settings'] = array( 'client_portal_path' => '/portal' );

		$html = $this->block->render( array( 'portal' => 'studio' ) );

		$this->assertStringContainsString( 'href="https://profotograaf.nl/studio/portal"', $html );
	}

	public function test_block_path_wins_over_the_site_setting(): void {
		$this->options['profotograaf_settings'] = array( 'client_portal_path' => '/portal' );

		$html = $this->block->render(
			array(
				'portal'     => 'studio',
				'portalPath' => 'clients',
			)
		);

		$this->assertStringContainsString( 'href="https://profotograaf.nl/studio/clients"', $html );
	}

	public function test_invalid_block_path_falls_back_to_the_default(): void {
		$html = $this->block->render(
			array(
				'portal'     => 'studio',
				'portalPath' => '/a b?x',
			)
		);

		$this->assertStringContainsString( 'href="https://profotograaf.nl/studio/client"', $html );
	}

	/**
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function path_inputs(): array {
		return array(
			'plain'        => array( '/client', '/client' ),
			'no slash'     => array( 'portal', '/portal' ),
			'nested'       => array( '/my/portal/', '/my/portal' ),
			'surrounding'  => array( '  /portal ', '/portal' ),
			'root'         => array( '/', null ),
			'space inside' => array( '/a b', null ),
			'query'        => array( '/a?b=1', null ),
			'fragment'     => array( '/a#b', null ),
			'host'         => array( '//evil.example', null ),
			'scheme'       => array( 'https://evil.example/x', null ),
			'traversal'    => array( '/a/../b', null ),
		);
	}

	/**
	 * @dataProvider path_inputs
	 */
	public function test_portal_path_sanitising( string $input, ?string $expected ): void {
		$this->assertSame( $expected, Client_Galleries::sanitize_path( $input ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function heading_levels(): array {
		return array(
			'default is h3' => array( array(), 'h3' ),
			'h2'            => array( array( 'headingLevel' => 2 ), 'h2' ),
			'h6'            => array( array( 'headingLevel' => 6 ), 'h6' ),
			'string level'  => array( array( 'headingLevel' => '4' ), 'h4' ),
			'paragraph'     => array( array( 'headingLevel' => 0 ), 'p' ),
			'h1 is refused' => array( array( 'headingLevel' => 1 ), 'h3' ),
			'out of range'  => array( array( 'headingLevel' => 9 ), 'h3' ),
			'junk'          => array( array( 'headingLevel' => 'x' ), 'h3' ),
		);
	}

	/**
	 * @dataProvider heading_levels
	 * @param array<string,mixed> $attributes Extra attributes.
	 */
	public function test_heading_level( array $attributes, string $tag ): void {
		$html = $this->block->render( array( 'portal' => 'studio' ) + $attributes );

		$this->assertStringContainsString( '<' . $tag . ' class="wp-block-profotograaf-client-galleries__heading">Find your gallery</' . $tag . '>', $html );
	}

	public function test_button_carries_the_core_button_classes(): void {
		$html = $this->block->render( array( 'portal' => 'studio' ) );

		$this->assertStringContainsString( '<div class="wp-block-button">', $html );
		$this->assertMatchesRegularExpression( '/<a class="wp-block-profotograaf-client-galleries__button wp-block-button__link wp-element-button"/', $html );
	}

	public function test_schema_has_the_portal_path_with_a_default(): void {
		$entry = \Profotograaf\Settings_Schema::entry( 'client_portal_path' );

		$this->assertNotNull( $entry );
		$this->assertSame( '/client', $entry['default'] );
		$this->assertSame( '/client', $this->settings->resolve( 'client_portal_path' ) );
	}

	public function test_render_callback_is_added_to_this_block_only(): void {
		$args = $this->block->add_render_callback( array( 'title' => 'x' ), Client_Galleries::BLOCK );
		$this->assertSame( array( $this->block, 'render' ), $args['render_callback'] );

		$other = $this->block->add_render_callback( array( 'title' => 'x' ), 'core/paragraph' );
		$this->assertArrayNotHasKey( 'render_callback', $other );
	}

	public function test_editor_translations_use_the_standard_api(): void {
		Functions\when( 'generate_block_asset_handle' )->justReturn( 'profotograaf-client-galleries-editor-script' );
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'profotograaf-client-galleries-editor-script', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );

		$this->block->load_script_translations();
	}

	public function test_it_does_not_remap_the_script_path_for_translations(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook ) use ( &$filters ) {
				$filters[] = $hook;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		$this->block->register( new \Profotograaf\Plugin() );

		$this->assertNotContains( 'load_script_textdomain_relative_path', $filters );
	}

	public function test_block_json_matches_the_render_attributes(): void {
		$json = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/client-galleries/block.json' ), true );

		$this->assertSame( Client_Galleries::BLOCK, $json['name'] );
		$this->assertSame( 'profotograaf', $json['textdomain'] );
		foreach ( array( 'portal', 'portalPath', 'heading', 'headingLevel', 'description', 'buttonLabel', 'openInNewTab' ) as $attribute ) {
			$this->assertArrayHasKey( $attribute, $json['attributes'] );
		}
		$this->assertArrayNotHasKey( 'render', $json, 'The render callback is attached in PHP.' );
	}
}
