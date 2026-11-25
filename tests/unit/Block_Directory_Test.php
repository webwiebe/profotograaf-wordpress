<?php
/**
 * Single-block plugin tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/block-directory/includes/gallery-source.php';
require_once dirname( __DIR__, 2 ) . '/block-directory/includes/render.php';

if ( ! defined( 'PROFOTOGRAAF_GALLERY_BLOCK_URL' ) ) {
	define( 'PROFOTOGRAAF_GALLERY_BLOCK_URL', 'https://example.test/wp-content/plugins/profotograaf-gallery-block/' );
	define( 'PROFOTOGRAAF_GALLERY_BLOCK_VERSION', '0.0.0-test' );
}

class Block_Directory_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	public function test_it_accepts_share_links_on_profotograaf_hosts_only(): void {
		$this->assertTrue( \profotograaf_gallery_block_is_share_url( 'https://profotograaf.nl/share/g/spring-wedding' ) );
		$this->assertTrue( \profotograaf_gallery_block_is_share_url( 'https://anna.profotograaf.nl/share/g/spring-wedding/' ) );
		$this->assertFalse( \profotograaf_gallery_block_is_share_url( 'http://profotograaf.nl/share/g/a' ) );
		$this->assertFalse( \profotograaf_gallery_block_is_share_url( 'https://profotograaf.nl.evil.test/share/g/a' ) );
		$this->assertFalse( \profotograaf_gallery_block_is_share_url( 'https://profotograaf.nl/share/g/a/photo/b' ) );
	}

	public function test_a_bare_embed_id_is_its_own_id(): void {
		$this->assertSame( 'gallery-id-1234', \profotograaf_gallery_block_resolve( '  gallery-id-1234 ' ) );
		$this->assertSame( '', \profotograaf_gallery_block_resolve( 'a b' ) );
		$this->assertSame( '', \profotograaf_gallery_block_resolve( 'short' ) );
	}

	public function test_a_share_link_is_resolved_through_oembed_and_cached(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_safe_remote_get' )->justReturn( array( 'body' => '{"html":"<div data-profotograaf-gallery=\"abc12345\"></div>"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $response ) => $response['body'] );
		$stored = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$stored ) {
				$stored = array( $value, $ttl );
				return true;
			}
		);

		$this->assertSame( 'abc12345', \profotograaf_gallery_block_resolve( 'https://profotograaf.nl/share/g/spring' ) );
		$this->assertSame( array( 'abc12345', 12 * HOUR_IN_SECONDS ), $stored );
	}

	public function test_a_failed_lookup_is_kept_for_five_minutes(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url );
		Functions\when( 'wp_safe_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		$stored = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$stored ) {
				$stored = array( $value, $ttl );
				return true;
			}
		);

		$this->assertSame( '', \profotograaf_gallery_block_resolve( 'https://profotograaf.nl/share/g/missing' ) );
		$this->assertSame( array( '', 5 * MINUTE_IN_SECONDS ), $stored );
	}

	public function test_a_cached_id_skips_the_request(): void {
		Functions\when( 'get_transient' )->justReturn( 'cached-id-1' );
		Functions\expect( 'wp_safe_remote_get' )->never();

		$this->assertSame( 'cached-id-1', \profotograaf_gallery_block_resolve( 'https://profotograaf.nl/share/g/spring' ) );
	}

	public function test_render_outputs_the_embed_host_and_enqueues_the_script(): void {
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( 'class="wp-block-x profotograaf-gallery"' );
		Functions\expect( 'wp_enqueue_script' )->once();

		$html = \profotograaf_gallery_block_render( array( 'source' => 'gallery-id-1234', 'layout' => 'masonry' ) );

		$this->assertStringContainsString( 'data-profotograaf-gallery="gallery-id-1234"', $html );
		$this->assertStringContainsString( 'data-layout="masonry"', $html );
	}

	public function test_render_falls_back_to_auto_for_an_unknown_layout_and_links_the_share_page(): void {
		Functions\when( 'get_transient' )->justReturn( 'cached-id-1' );
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( 'class="x"' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$html = \profotograaf_gallery_block_render( array( 'source' => 'https://profotograaf.nl/share/g/spring', 'layout' => '<b>' ) );

		$this->assertStringContainsString( 'data-layout="auto"', $html );
		$this->assertStringContainsString( '<a href="https://profotograaf.nl/share/g/spring">View the gallery</a>', $html );
	}

	public function test_visitors_see_nothing_when_the_gallery_cannot_be_shown(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', \profotograaf_gallery_block_render( array() ) );
		$this->assertSame( '', \profotograaf_gallery_block_render( array( 'source' => 'a b' ) ) );
	}

	public function test_editors_get_a_note(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertStringContainsString( 'Paste a Profotograaf gallery link', \profotograaf_gallery_block_render( array() ) );
		$this->assertStringContainsString( 'cannot be shown', \profotograaf_gallery_block_render( array( 'source' => 'a b' ) ) );
	}
}
