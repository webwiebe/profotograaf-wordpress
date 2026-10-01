<?php
/**
 * Gallery module tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Gallery_Embed;

class Gallery_Embed_Test extends Gallery_Test_Case {

	private Gallery_Embed $module;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'sanitize_html_class' )->alias( fn( $name ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $name ) );
		$this->module = new Gallery_Embed();
	}

	public function test_it_hooks_the_shortcode_the_provider_the_routes_and_the_block(): void {
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook ) use ( &$actions ) {
				$actions[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook ) use ( &$filters ) {
				$filters[] = $hook;
			}
		);
		Functions\expect( 'add_shortcode' )->once()->with( 'profotograaf_gallery', \Mockery::type( 'array' ) );

		$this->module->register( $this->plugin );

		$this->assertContains( 'init', $actions );
		$this->assertContains( 'rest_api_init', $actions );
		$this->assertContains( 'profotograaf_refresh_embed_version', $actions );
		$this->assertContains( 'embed_oembed_html', $filters );
		$this->assertContains( 'register_block_type_args', $filters );
	}

	public function test_only_the_gallery_block_gets_the_render_callback(): void {
		$this->assertArrayHasKey( 'render_callback', $this->module->block_args( array(), 'profotograaf/gallery' ) );
		$this->assertSame( array(), $this->module->block_args( array(), 'core/paragraph' ) );
	}

	public function test_the_block_renders_from_its_attributes(): void {
		Functions\when( 'add_shortcode' )->justReturn( true );
		$this->module->register( $this->plugin );

		$html = $this->module->render_block(
			array(
				'galleryId'    => 'g-1',
				'galleryTitle' => 'Spring wedding',
				'galleryUrl'   => 'https://profotograaf.nl/share/g/spring-wedding',
				'layout'       => 'masonry',
				'align'        => 'wide',
			)
		);

		$this->assertSame(
			'<div class="profotograaf-gallery wp-block-profotograaf-gallery alignwide" data-profotograaf-gallery="g-1" data-layout="masonry">'
			. '<a href="https://profotograaf.nl/share/g/spring-wedding">Spring wedding</a></div>',
			$html
		);
	}

	public function test_a_block_without_a_gallery_renders_nothing(): void {
		Functions\when( 'add_shortcode' )->justReturn( true );
		$this->module->register( $this->plugin );

		$this->assertSame( '', $this->module->render_block( array( 'galleryId' => '' ) ) );
	}

	public function test_the_block_and_the_shortcode_produce_the_same_markup(): void {
		Functions\when( 'add_shortcode' )->justReturn( true );
		$this->module->register( $this->plugin );
		$atts = array(
			'id'     => 'g-1',
			'layout' => 'grid',
			'title'  => 'Spring wedding',
			'url'    => 'https://profotograaf.nl/share/g/spring-wedding',
		);

		$block = $this->module->render_block(
			array(
				'galleryId'    => 'g-1',
				'layout'       => 'grid',
				'galleryTitle' => 'Spring wedding',
				'galleryUrl'   => 'https://profotograaf.nl/share/g/spring-wedding',
			)
		);
		$this->assertSame( $this->renderer->render( $atts + array( 'class' => 'wp-block-profotograaf-gallery' ) ), $block );
	}

	public function test_the_block_definition_matches_the_renderer(): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/blocks/gallery/block.json' ), true );

		$this->assertSame( 'profotograaf/gallery', $json['name'] );
		$this->assertSame( Gallery_Embed::BLOCK, $json['name'] );
		$this->assertSame( 'profotograaf', $json['textdomain'] );
		$this->assertSame( array( '', 'grid', 'masonry', 'slideshow' ), $json['attributes']['layout']['enum'] );
		$this->assertSame( array( 'grid', 'masonry', 'slideshow' ), \Profotograaf\Settings::LAYOUTS );
	}

	public function test_the_editor_script_translations_use_the_standard_api(): void {
		Functions\when( 'generate_block_asset_handle' )->justReturn( 'profotograaf-gallery-editor-script' );
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'profotograaf-gallery-editor-script', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );

		$this->module->load_script_translations();
	}

	public function test_nothing_is_attached_while_the_editor_script_is_not_registered(): void {
		Functions\when( 'generate_block_asset_handle' )->justReturn( 'profotograaf-gallery-editor-script' );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\expect( 'wp_set_script_translations' )->never();

		$this->module->load_script_translations();
	}

	public function test_it_hooks_the_translations_and_adds_no_translation_file_filter(): void {
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook ) use ( &$actions ) {
				$actions[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook ) use ( &$filters ) {
				$filters[] = $hook;
			}
		);
		Functions\when( 'add_shortcode' )->justReturn( true );

		$this->module->register( $this->plugin );

		$this->assertContains( 'init', $actions );
		$this->assertNotContains( 'load_script_translation_file', $filters );
		$this->assertNotContains( 'load_script_textdomain_relative_path', $filters );
	}
}
