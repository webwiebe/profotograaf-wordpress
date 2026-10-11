<?php
/**
 * Server render of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Full_Page_Payload;
use Profotograaf\Full_Page_Renderer;
use Profotograaf\Gallery_Index;
use Profotograaf\Modules\Full_Page_Gallery;
use Profotograaf\Settings;

class Full_Page_Renderer_Test extends Gallery_Test_Case {

	private Full_Page_Renderer $full;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias( fn( $value, $flags = 0 ) => json_encode( $value, $flags ) );
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( '' );
		$this->full = new Full_Page_Renderer( new Full_Page_Payload( $this->api ), new Gallery_Index( $this->api ), new Settings() );
	}

	/**
	 * A payload of photos p-0 … p-{count-1}, 3000 × 2000.
	 *
	 * @param int                 $count  Photos.
	 * @param array<string,mixed> $extra  Payload keys to add or replace.
	 * @param bool                $marked Whether the gallery is watermarked (only `web` listed).
	 * @return array<string,mixed>
	 */
	private static function payload( int $count, array $extra = array(), bool $marked = false ): array {
		$photos = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$images   = array(
				array( 'variant' => 'thumb', 'url' => 'https://profotograaf.nl/i/thumb-' . $i . '.jpg', 'width' => 400, 'height' => 400 ),
				array( 'variant' => 'web', 'url' => 'https://profotograaf.nl/i/web-' . $i . '.jpg', 'width' => 1600, 'height' => 1067 ),
				array( 'variant' => 'large', 'url' => 'https://profotograaf.nl/i/large-' . $i . '.jpg', 'width' => 2560, 'height' => 1707 ),
			);
			$photos[] = array(
				'id'      => 'p-' . $i,
				'width'   => 3000,
				'height'  => 2000,
				'alt'     => '',
				'title'   => 'Photo ' . $i,
				'caption' => '',
				'url'     => 'https://profotograaf.nl/share/p/' . $i,
				'images'  => $marked ? array( $images[1] ) : $images,
			);
		}
		return $extra + array(
			'id'       => 'g-1',
			'title'    => 'Spring wedding',
			'url'      => 'https://profotograaf.nl/share/g/spring-wedding',
			'site_url' => 'https://anna.profotograaf.nl',
			'version'  => 'v1',
			'badge'    => array( 'show' => false, 'prominent' => false, 'url' => '' ),
			'photos'   => $photos,
		);
	}

	/**
	 * Renders with a payload of $count photos.
	 *
	 * @param int                 $count      Photos.
	 * @param array<string,mixed> $attributes Block attributes besides the gallery id.
	 * @param array<string,mixed> $extra      Payload keys.
	 */
	private function render( int $count, array $attributes = array(), array $extra = array() ): string {
		$this->http->reply( 200, self::payload( $count, $extra ) );
		return $this->full->render( array( 'galleryId' => 'g-1' ) + $attributes );
	}

	/**
	 * The JSON in script.pf-fp-data.
	 *
	 * @param string $html Rendered block.
	 * @return array<string,mixed>
	 */
	private static function data( string $html ): array {
		preg_match( '#<script type="application/json" class="pf-fp-data">(.*?)</script>#s', $html, $found );
		return json_decode( $found[1] ?? '', true );
	}

	/**
	 * The opening tags of every section, in order.
	 *
	 * @param string $html Rendered block.
	 * @return string[]
	 */
	private static function sections( string $html ): array {
		preg_match_all( '#<section[^>]*>#', $html, $found );
		return $found[0];
	}

	public function test_the_default_preset_renders_the_contract_wrapper_sections_and_data(): void {
		$html = $this->render( 19 );

		$this->assertStringStartsWith( '<div class="pf-fp alignfull" data-pf-full-page="g-1" data-preset="editorial">', $html );
		$this->assertSame(
			array( 'hero', 'grid', 'pair', 'grid', 'feature' ),
			array_map( fn( $tag ) => preg_replace( '/.*data-type="([a-z]+)".*/', '$1', $tag ), self::sections( $html ) )
		);
		$data = self::data( $html );
		$this->assertTrue( $data['lightbox'] );
		$this->assertCount( 19, $data['photos'] );
		$this->assertSame(
			array(
				'src'     => 'https://profotograaf.nl/i/web-0.jpg',
				'srcset'  => 'https://profotograaf.nl/i/web-0.jpg 1600w, https://profotograaf.nl/i/large-0.jpg 2560w',
				'w'       => 3000,
				'h'       => 2000,
				'alt'     => 'Photo 0',
				'caption' => 'Photo 0',
			),
			$data['photos'][0]
		);
		$this->assertSame( array( 'url' => 'https://profotograaf.nl/share/embed/view', 'gallery' => 'g-1' ), $data['beacon'] );
	}

	public function test_indexes_run_flat_across_sections_in_render_order(): void {
		$html = $this->render( 19 );

		preg_match_all( '#data-pf-index="(\d+)"#', $html, $found );
		$this->assertSame( array_map( 'strval', range( 0, 18 ) ), $found[1] );
		preg_match_all( '#<img src="https://profotograaf.nl/i/web-(\d+)\.jpg"#', $html, $sources );
		$data = self::data( $html );
		foreach ( $sources[1] as $index => $photo ) {
			$this->assertSame( 'https://profotograaf.nl/i/web-' . $photo . '.jpg', $data['photos'][ $index ]['src'] );
		}
		$this->assertStringContainsString( '<a class="pf-fp-open" href="https://profotograaf.nl/share/p/0" data-pf-index="0">', $html );
	}

	public function test_every_section_type_writes_its_contract_markup(): void {
		$custom = array(
			array( 'type' => 'text', 'heading' => 'Ceremony', 'text' => 'At the old church.', 'align' => 'left' ),
			array( 'type' => 'hero', 'title' => 'Anna & Tom' ),
			array( 'type' => 'grid', 'columns' => 2, 'rows' => 1, 'ratio' => '1-1', 'gap' => 4 ),
			array( 'type' => 'masonry', 'columns' => 2, 'count' => 2 ),
			array( 'type' => 'feature', 'count' => 2 ),
			array( 'type' => 'pair' ),
			array( 'type' => 'filmstrip', 'count' => 2, 'rowHeight' => 300 ),
		);

		$html = $this->render( 12, array( 'preset' => 'custom', 'sections' => $custom ) );
		$tags = self::sections( $html );

		$this->assertStringContainsString( 'data-preset="custom"', $html );
		$this->assertSame( '<section class="pf-fp-s pf-fp-text" data-type="text" style="--pf-align:left">', $tags[0] );
		$this->assertStringContainsString( '<h2>Ceremony</h2><p>At the old church.</p></section>', $html );
		$this->assertSame( '<section class="pf-fp-s pf-fp-hero" data-type="hero" style="--pf-height:85vh;--pf-height-phone:70vh" data-hover="zoom" data-hover-strength="subtle" data-title="Anna &amp; Tom">', $tags[1] );
		$this->assertStringContainsString( '<h2 class="pf-fp-title">Anna &amp; Tom</h2></section>', $html );
		$this->assertSame( '<section class="pf-fp-s pf-fp-grid" data-type="grid" style="--pf-cols:2;--pf-cols-phone:2;--pf-ratio:1/1;--pf-gap:4px" data-hover="zoom" data-hover-strength="subtle">', $tags[2] );
		$this->assertSame( '<section class="pf-fp-s pf-fp-masonry" data-type="masonry" style="--pf-cols:2;--pf-cols-phone:2;--pf-gap:8px" data-hover="zoom" data-hover-strength="subtle">', $tags[3] );
		$this->assertSame( '<section class="pf-fp-s pf-fp-feature" data-type="feature" style="--pf-gap:8px" data-hover="zoom" data-hover-strength="subtle">', $tags[4] );
		$this->assertSame( '<section class="pf-fp-s pf-fp-pair" data-type="pair" style="--pf-gap:8px" data-hover="zoom" data-hover-strength="subtle" data-side="left">', $tags[5] );
		$this->assertSame( '<section class="pf-fp-s pf-fp-filmstrip" data-type="filmstrip" style="--pf-row:300px;--pf-row-phone:240px;--pf-gap:8px" data-hover="zoom" data-hover-strength="subtle" tabindex="0" role="region" aria-label="Photo strip. Scroll sideways for more photos.">', $tags[6] );
		$this->assertMatchesRegularExpression( '#pf-fp-filmstrip[^>]*><div class="pf-fp-track"><figure#', $html );
		$this->assertStringContainsString( '<figure class="pf-fp-photo" style="--pf-ar:1.5">', $html );
		$this->assertCount( 12, self::data( $html )['photos'] );
	}

	public function test_a_grid_gets_its_phone_columns_and_an_original_ratio_is_auto(): void {
		$custom = array( array( 'type' => 'grid', 'columns' => 4, 'rows' => 1, 'ratio' => 'original' ), array( 'type' => 'grid', 'columns' => 1, 'rows' => 1 ) );

		$tags = self::sections( $this->render( 5, array( 'preset' => 'custom', 'sections' => $custom ) ) );

		$this->assertStringContainsString( 'style="--pf-cols:4;--pf-cols-phone:2;--pf-ratio:auto;--pf-gap:8px"', $tags[0] );
		$this->assertStringContainsString( '--pf-cols:1;--pf-cols-phone:1;', $tags[1] );
	}

	public function test_mirrored_pairs_alternate_their_side(): void {
		$html = $this->render( 32, array( 'preset' => 'playful' ) );

		preg_match_all( '#data-side="([a-z]+)"#', $html, $found );
		$this->assertSame( array( 'left', 'right' ), $found[1] );
	}

	public function test_the_first_section_loads_eagerly_and_a_first_hero_gets_high_priority(): void {
		$html = $this->render( 19 );
		preg_match_all( '#<img [^>]*>#', $html, $images );

		$this->assertStringContainsString( 'loading="eager" fetchpriority="high" decoding="async"', $images[0][0] );
		$this->assertCount( 18, array_filter( $images[0], fn( $img ) => str_contains( $img, 'loading="lazy"' ) ) );
		$this->assertSame( 1, substr_count( $html, 'fetchpriority' ) );

		$calm = $this->full->render( array( 'galleryId' => 'g-1', 'preset' => 'calm' ) );
		preg_match_all( '#<img [^>]*>#', $calm, $grid );
		$this->assertCount( 6, array_filter( $grid[0], fn( $img ) => str_contains( $img, 'loading="eager"' ) ) );
		$this->assertStringNotContainsString( 'fetchpriority', $calm );
	}

	public function test_images_carry_their_size_srcset_and_sizes_per_section(): void {
		$html = $this->render( 19 );
		preg_match_all( '#<img [^>]*>#', $html, $images );

		$this->assertSame(
			'<img src="https://profotograaf.nl/i/web-0.jpg" srcset="https://profotograaf.nl/i/web-0.jpg 1600w, https://profotograaf.nl/i/large-0.jpg 2560w" sizes="100vw" width="3000" height="2000" alt="Photo 0" loading="eager" fetchpriority="high" decoding="async">',
			$images[0][0]
		);
		$this->assertStringContainsString( 'sizes="(max-width: 599px) 50vw, 34vw"', $images[0][1] );
		$this->assertStringContainsString( 'sizes="(max-width: 599px) 100vw, 67vw"', $images[0][7] );
		$this->assertStringContainsString( 'sizes="(max-width: 599px) 50vw, 34vw"', $images[0][8] );
		$this->assertStringContainsString( 'sizes="(max-width: 599px) 100vw, 42vw"', $images[0][16] );
		$this->assertStringNotContainsString( 'thumb-', $html );
	}

	public function test_a_square_grid_adds_the_thumb_to_its_srcset(): void {
		$custom = array( array( 'type' => 'grid', 'columns' => 2, 'rows' => 1, 'ratio' => '1-1' ) );

		$html = $this->render( 2, array( 'preset' => 'custom', 'sections' => $custom ) );

		$this->assertStringContainsString( 'srcset="https://profotograaf.nl/i/thumb-0.jpg 400w, https://profotograaf.nl/i/web-0.jpg 1600w, https://profotograaf.nl/i/large-0.jpg 2560w"', $html );
		$this->assertStringNotContainsString( 'thumb-', (string) wp_json_encode( self::data( $html )['photos'] ) );
	}

	public function test_a_watermarked_gallery_uses_only_web(): void {
		$this->http->reply( 200, self::payload( 3, array(), true ) );

		$html = $this->full->render( array( 'galleryId' => 'g-1' ) );

		$this->assertStringContainsString( 'srcset="https://profotograaf.nl/i/web-0.jpg 1600w"', $html );
		$this->assertStringNotContainsString( 'large-', $html );
		$this->assertStringNotContainsString( 'thumb-', $html );
	}

	public function test_with_the_lightbox_off_photos_follow_the_link_setting(): void {
		$page = $this->render( 2, array( 'lightbox' => 'off', 'linkTo' => 'page' ) );
		$this->assertStringContainsString( '<a class="pf-fp-open" href="https://profotograaf.nl/share/p/0" target="_blank" rel="noopener noreferrer"><img', $page );
		$this->assertStringNotContainsString( 'data-pf-index', $page );
		$this->assertFalse( self::data( $page )['lightbox'] );

		$same_tab = $this->full->render( array( 'galleryId' => 'g-1', 'lightbox' => 'off', 'linkTo' => 'site', 'linkNewTab' => 'off' ) );
		$this->assertStringContainsString( '<a class="pf-fp-open" href="https://anna.profotograaf.nl"><img', $same_tab );

		$file = $this->full->render( array( 'galleryId' => 'g-1', 'lightbox' => 'off', 'linkTo' => 'file' ) );
		$this->assertStringContainsString( '<a class="pf-fp-open" href="https://profotograaf.nl/i/web-0.jpg" target="_blank"', $file );

		$none = $this->full->render( array( 'galleryId' => 'g-1', 'lightbox' => 'off' ) );
		$this->assertStringContainsString( '<span class="pf-fp-open"><img', $none );
		$this->assertStringNotContainsString( '<a class="pf-fp-open"', $none );
	}

	public function test_the_site_lightbox_setting_applies_when_the_block_leaves_it_empty(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_lightbox' => 'off' );

		$html = $this->render( 2 );

		$this->assertFalse( self::data( $html )['lightbox'] );
		$this->assertTrue( self::data( $this->full->render( array( 'galleryId' => 'g-1', 'lightbox' => 'on' ) ) )['lightbox'] );
	}

	public function test_the_badge_follows_the_payload(): void {
		$this->assertStringNotContainsString( 'pf-fp-badge', $this->render( 2 ) );

		$this->transients = array();
		$shown            = $this->render( 2, array(), array( 'badge' => array( 'show' => true, 'prominent' => false, 'url' => 'https://profotograaf.nl/?ref=embed' ) ) );
		$this->assertStringContainsString( '<a class="pf-fp-badge" href="https://profotograaf.nl/?ref=embed" target="_blank" rel="noopener">Made with Profotograaf</a><script', $shown );

		$this->transients = array();
		$prominent        = $this->render( 2, array(), array( 'badge' => array( 'show' => true, 'prominent' => true, 'url' => 'javascript:alert(1)' ) ) );
		$this->assertStringContainsString( '<a class="pf-fp-badge prominent" href="https://profotograaf.nl"', $prominent );
	}

	public function test_captions_and_alt_texts_are_escaped(): void {
		$this->http->reply(
			200,
			array(
				'id'     => 'g-1',
				'photos' => array(
					array(
						'id'      => 'p-0',
						'width'   => 1000,
						'height'  => 1000,
						'title'   => '</script><img src=x onerror=alert(1)>',
						'caption' => 'Bride & "groom"',
						'images'  => array( array( 'variant' => 'web', 'url' => 'https://profotograaf.nl/i/a.jpg', 'width' => 1000, 'height' => 1000 ) ),
					),
				),
			)
		);

		$html = $this->full->render( array( 'galleryId' => 'g-1', 'captions' => 'below' ) );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertStringContainsString( '<figcaption>Bride &amp; &quot;groom&quot;</figcaption>', $html );
		$this->assertStringContainsString( 'alt="Bride &amp; &quot;groom&quot;"', $html );
		$this->assertStringContainsString( 'data-captions="below"', $html );
		$this->assertSame( 'Bride & "groom"', self::data( $html )['photos'][0]['caption'] );
	}

	public function test_the_block_image_text_overrides_caption_and_alt(): void {
		$text = array( array( 'id' => 'p-0', 'caption' => 'First dance', 'alt' => 'Couple dancing' ) );

		$html = $this->render( 2, array( 'imageText' => $text, 'captions' => 'overlay' ) );

		$this->assertStringContainsString( 'alt="Couple dancing"', $html );
		$this->assertStringContainsString( '<figcaption>First dance</figcaption>', $html );
		$this->assertStringContainsString( 'data-captions="overlay"', $html );
		$this->assertSame( 'Couple dancing', self::data( $html )['photos'][0]['alt'] );
	}

	public function test_a_hero_photo_with_a_crop_box_gets_its_focus(): void {
		$payload                      = self::payload( 1 );
		$payload['photos'][0]['crop'] = array( 'x' => 0.5, 'y' => 0.1, 'w' => 0.4, 'h' => 0.2 );
		$this->http->reply( 200, $payload );

		$html = $this->full->render( array( 'galleryId' => 'g-1' ) );

		$this->assertStringContainsString( '<section class="pf-fp-s pf-fp-hero"', $html );
		$this->assertStringContainsString( 'style="--pf-ar:1.5;--pf-focus:70% 20%"', $html );
	}

	public function test_no_photos_prints_the_fallback_link(): void {
		$html = $this->render( 0, array( 'galleryUrl' => 'https://profotograaf.nl/share/g/spring-wedding', 'galleryTitle' => 'Spring <b>wedding</b>' ) );

		$this->assertSame( '<div class="pf-fp alignfull" data-pf-full-page="g-1"><a href="https://profotograaf.nl/share/g/spring-wedding" style="display:inline-block;padding:.5em 0">Spring wedding</a></div>', $html );
	}

	public function test_all_photos_excluded_falls_back_to_the_payload_link(): void {
		$html = $this->render( 2, array( 'excludedPhotoIds' => array( 'p-0', 'p-1' ), 'align' => 'wide' ) );

		$this->assertStringStartsWith( '<div class="pf-fp alignwide" data-pf-full-page="g-1">', $html );
		$this->assertStringContainsString( '<a href="https://profotograaf.nl/share/g/spring-wedding"', $html );
		$this->assertStringContainsString( '>Spring wedding</a>', $html );
		$this->assertStringNotContainsString( '<section', $html );
	}

	public function test_a_payload_error_prints_the_fallback_link(): void {
		$this->http->reply( 503, array() );

		$html = $this->full->render( array( 'galleryId' => 'g-1', 'galleryUrl' => 'https://profotograaf.nl/share/g/a', 'galleryTitle' => 'A' ) );

		$this->assertStringContainsString( '<a href="https://profotograaf.nl/share/g/a"', $html );
		$this->assertStringNotContainsString( 'pf-fp-data', $html );
	}

	public function test_an_invalid_gallery_id_renders_nothing_and_asks_nothing(): void {
		$this->assertSame( '', $this->full->render( array( 'galleryId' => 'bad id' ) ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_second_render_reads_the_cache(): void {
		$first  = $this->render( 8 );
		$second = $this->full->render( array( 'galleryId' => 'g-1' ) );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_inside_a_block_render_wordpress_builds_the_wrapper(): void {
		Functions\when( 'get_block_wrapper_attributes' )->alias(
			function ( $extra ) {
				return sprintf( 'class="wp-block-profotograaf-full-page-gallery alignfull %1$s" data-pf-full-page="%2$s" data-preset="%3$s"', $extra['class'], $extra['data-pf-full-page'], $extra['data-preset'] );
			}
		);

		$this->assertStringStartsWith( '<div class="wp-block-profotograaf-full-page-gallery alignfull pf-fp" data-pf-full-page="g-1" data-preset="editorial">', $this->render( 3 ) );
	}

	public function test_the_module_gives_the_block_its_render_callback(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		$module = new Full_Page_Gallery();
		$module->register( $this->plugin );

		$args = $module->block_args( array(), Full_Page_Gallery::BLOCK );

		$this->assertSame( array( $module, 'render_block' ), $args['render_callback'] );
		$this->assertSame( array(), $module->block_args( array(), 'core/image' ) );
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/blocks/full-page-gallery/block.json' ), true );
		$this->assertSame( Full_Page_Gallery::BLOCK, $json['name'] );
		$this->http->reply( 200, self::payload( 1 ) );
		$this->assertStringContainsString( 'pf-fp-hero', $module->render_block( array( 'galleryId' => 'g-1' ) ) );
	}

	public function test_the_editor_and_front_end_scripts_load_their_translations(): void {
		Functions\when( 'generate_block_asset_handle' )->alias(
			static fn( string $block, string $field ): string => 'editorScript' === $field ? 'profotograaf-full-page-gallery-editor-script' : 'profotograaf-full-page-gallery-view-script'
		);
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'profotograaf-full-page-gallery-editor-script', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'profotograaf-full-page-gallery-view-script', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );

		( new Full_Page_Gallery() )->load_script_translations();
	}

	public function test_a_script_that_is_not_registered_gets_no_translations(): void {
		Functions\when( 'generate_block_asset_handle' )->alias(
			static fn( string $block, string $field ): string => $block . '-' . $field
		);
		Functions\when( 'wp_script_is' )->alias(
			static fn( string $handle ): bool => Full_Page_Gallery::BLOCK . '-editorScript' === $handle
		);
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( Full_Page_Gallery::BLOCK . '-editorScript', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );

		( new Full_Page_Gallery() )->load_script_translations();
	}
}
