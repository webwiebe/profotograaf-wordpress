<?php
/**
 * Gallery renderer tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Gallery_Index;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Reserved_Space;

class Gallery_Renderer_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	private const NOSCRIPT = '<noscript>This gallery needs JavaScript to be shown here.</noscript>';

	private function as_editor( bool $can ): void {
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $can && 'edit_posts' === $cap );
	}

	public function test_an_invalid_id_shows_editors_a_notice_and_visitors_nothing(): void {
		$this->as_editor( true );
		$html = $this->renderer->render( array( 'id' => 'with space' ) );
		$this->assertStringContainsString( 'class="profotograaf-gallery-notice"', $html );
		$this->assertStringContainsString( 'gallery ID is missing or not valid', $html );
		$this->assertStringNotContainsString( 'data-profotograaf-gallery', $html );
		$this->assertSame( array(), $this->enqueued );

		$this->as_editor( false );
		$this->assertSame( '', $this->renderer->render( array( 'id' => '' ) ) );
	}

	public function test_editors_are_told_when_the_gallery_is_gone_from_the_platform_list(): void {
		$this->options['profotograaf_gallery_index'] = array(
			'g-other' => array(
				'title' => 'Other',
				'url'   => 'https://profotograaf.nl/share/g/other',
			),
		);
		$args                                        = array(
			'id'  => 'g-gone',
			'url' => 'https://profotograaf.nl/share/g/gone',
		);

		$this->as_editor( true );
		$editor = $this->renderer->render( $args );
		$this->as_editor( false );
		$visitor = $this->renderer->render( $args );

		$this->assertStringStartsWith( '<p class="profotograaf-gallery-notice"', $editor );
		$this->assertStringContainsString( 'may have been deleted', $editor );
		$this->assertStringStartsWith( Reserved_Space::rule() . '<div class="profotograaf-gallery"', $visitor );
		$this->assertStringContainsString( '<a href="https://profotograaf.nl/share/g/gone"', $visitor );
	}

	public function test_a_listed_gallery_or_an_empty_list_gives_no_notice(): void {
		$this->as_editor( true );
		$this->options['profotograaf_gallery_index'] = array();
		$this->assertStringNotContainsString( 'notice', $this->renderer->render( array( 'id' => 'g-1' ) ) );

		$this->options['profotograaf_gallery_index'] = array(
			'g-1' => array(
				'title' => 'A',
				'url'   => 'https://profotograaf.nl/share/g/a',
			),
		);
		$this->assertStringNotContainsString( 'notice', $this->renderer->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_every_gallery_has_noscript_text_and_reserved_space(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( 'style="--pf-ar:4/3;', $html );
		$this->assertStringContainsString( self::NOSCRIPT, $html );
	}

	public function test_the_platform_host_gets_a_preconnect_hint_once_per_page(): void {
		$first  = $this->renderer->render( array( 'id' => 'g-1' ) );
		$second = $this->renderer->render( array( 'id' => 'g-2' ) );

		$this->assertStringStartsWith( self::HINT, $first );
		$this->assertStringNotContainsString( 'preconnect', $second );
	}

	public function test_a_platform_url_with_a_port_keeps_it_in_the_hint(): void {
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => 'profotograaf_platform_url' === $hook ? 'http://localhost:8090/base' : $value );

		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringStartsWith( '<link rel="preconnect" href="http://localhost:8090">', $html );
	}

	public function test_no_gallery_means_no_hint(): void {
		$this->assertSame( '', $this->renderer->render( array( 'id' => '' ) ) );
	}

	public function test_the_photo_shape_sets_the_reserved_ratio_on_the_host(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'ratio' => '4-3' ) );

		$this->assertStringContainsString( 'style="--pf-ar:16/9;--pf-ar-t:12/9;--pf-ar-m:8/9"', $html );
	}

	public function test_the_reservation_is_released_for_a_gallery_that_does_not_draw(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( Reserved_Space::rule(), $html );
		$this->assertStringContainsString( '@keyframes pf-release{to{aspect-ratio:auto}}', $html );
	}

	public function test_the_wrapper_callable_gets_the_reserved_ratios(): void {
		$seen = '';
		$this->renderer->render(
			array(
				'id'      => 'g-1',
				'ratio'   => '16-9',
				'wrapper' => function ( array $attributes ) use ( &$seen ) {
					$seen = $attributes['style'];
					return 'class="custom"';
				},
			)
		);

		$this->assertSame( '--pf-ar:64/27;--pf-ar-t:48/27;--pf-ar-m:32/27', $seen );
	}

	public function test_masonry_sends_no_ratio_from_the_block(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'masonry', 'ratio' => '1-1' ) );

		$this->assertStringNotContainsString( 'data-ratio', $html );
		$this->assertStringContainsString( 'style="--pf-ar:12/9;--pf-ar-t:8/9;--pf-ar-m:4/9"', $html );
	}

	public function test_masonry_sends_no_ratio_from_the_site_setting_or_the_shortcode(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_ratio' => '1-1', 'default_layout' => 'masonry' );

		$site      = $this->renderer->render( array( 'id' => 'g-1' ) );
		$shortcode = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'masonry' ), array( 'ratio' => '3-2' ) );

		$this->assertStringContainsString( 'data-layout="masonry"', $site );
		$this->assertStringNotContainsString( 'data-ratio', $site );
		$this->assertStringNotContainsString( 'data-ratio', $shortcode );
	}

	public function test_a_grid_keeps_the_site_ratio(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_ratio' => '1-1', 'default_layout' => 'masonry' );

		$html = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'grid' ) );

		$this->assertStringContainsString( 'data-ratio="1:1"', $html );
	}

	public function test_an_original_ratio_reserves_a_three_by_two_tile(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'ratio' => 'original' ) );

		$this->assertStringContainsString( 'style="--pf-ar:4/3;--pf-ar-t:3/3;--pf-ar-m:2/3"', $html );
	}

	public function test_it_writes_the_embed_div_with_the_fallback_link(): void {
		$html = $this->renderer->render(
			array(
				'id'     => 'g-1',
				'layout' => 'masonry',
				'title'  => 'Spring wedding',
				'url'    => 'https://profotograaf.nl/share/g/spring-wedding',
			)
		);

		$this->assertSame(
			self::HINT . Reserved_Space::rule() . '<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="masonry" style="--pf-ar:12/9;--pf-ar-t:8/9;--pf-ar-m:4/9">'
			. '<a href="https://profotograaf.nl/share/g/spring-wedding" style="display:inline-block;padding:.5em 0">Spring wedding</a>'
			. '<noscript>This gallery needs JavaScript to be shown here.</noscript></div>',
			$html
		);
		$this->assertCount( 0, $this->http->requests, 'A known link needs no lookup.' );
	}

	public function test_an_unknown_layout_falls_back_to_the_site_default(): void {
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'slideshow' );

		$html = $this->renderer->render(
			array(
				'id'     => 'g-1',
				'layout' => 'carousel',
				'url'    => 'https://profotograaf.nl/share/g/x',
			)
		);

		$this->assertStringContainsString( 'data-layout="slideshow"', $html );
	}

	public function test_the_site_link_text_is_used_for_a_gallery_without_a_title(): void {
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => (string) $value );
		$this->options['profotograaf_settings'] = array( 'fallback_link_label' => 'See the photos' );
		$args                                   = array(
			'id'  => 'g-1',
			'url' => 'https://profotograaf.nl/share/g/x',
		);

		$this->assertStringContainsString( '>See the photos</a>', $this->renderer->render( $args ) );
		$this->assertStringContainsString( '>Own title</a>', $this->renderer->render( $args + array( 'title' => 'Own title' ) ) );
	}

	public function test_a_missing_layout_uses_the_grid_without_settings(): void {
		$html = $this->renderer->render(
			array(
				'id'  => 'g-1',
				'url' => 'https://profotograaf.nl/share/g/x',
			)
		);

		$this->assertStringContainsString( 'data-layout="grid"', $html );
	}

	public function test_the_link_text_is_generic_without_a_title(): void {
		$html = $this->renderer->render(
			array(
				'id'  => 'g-1',
				'url' => 'https://profotograaf.nl/share/g/x',
			)
		);

		$this->assertStringContainsString( '>View this gallery on Profotograaf</a>', $html );
	}

	public function test_an_invalid_id_renders_nothing_and_loads_no_script(): void {
		$this->as_editor( false );
		foreach ( array( '', '   ', 'a"><script>', 'with space', str_repeat( 'a', 65 ) ) as $id ) {
			$this->assertSame( '', $this->renderer->render( array( 'id' => $id ) ), 'id: ' . $id );
		}
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_title_and_url_are_escaped_and_only_http_links_are_kept(): void {
		$html = $this->renderer->render(
			array(
				'id'    => 'g-1',
				'title' => '<b>Bride & "groom"</b>',
				'url'   => 'https://profotograaf.nl/share/g/x?a=1&b="2"',
			)
		);
		$this->assertStringContainsString( 'Bride &amp; &quot;groom&quot;</a>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'href="https://profotograaf.nl/share/g/x?a=1&amp;b=&quot;2&quot;"', $html );

		$this->connect();
		$this->http->reply( 200, array() );
		$html = $this->renderer->render(
			array(
				'id'  => 'g-2',
				'url' => 'javascript:alert(1)',
			)
		);
		$this->assertStringNotContainsString( 'javascript', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_the_script_is_queued_once_per_page_in_the_footer_with_async(): void {
		$this->renderer->render( array( 'id' => 'g-1' ) );
		$this->renderer->render( array( 'id' => 'g-2' ) );

		$this->assertCount( 1, $this->enqueued );
		$script = $this->enqueued['profotograaf-embed'];
		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.js', $script['src'] );
		$this->assertNull( $script['ver'] );
		$this->assertSame(
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			),
			$script['args']
		);
	}

	public function test_the_versioned_script_url_is_used_once_the_version_is_known(): void {
		$this->options['profotograaf_embed_version'] = array(
			'version'    => '0123456789ab',
			'checked_at' => time(),
		);

		$this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.0123456789ab.js', $this->enqueued['profotograaf-embed']['src'] );
	}

	public function test_a_stored_version_that_is_not_12_hex_characters_is_ignored(): void {
		$this->options['profotograaf_embed_version'] = array( 'version' => '../evil.js' );

		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.js', $this->script->url() );
	}

	/**
	 * Records the lookup events that get scheduled, as hook names.
	 *
	 * @return \ArrayObject<int,string>
	 */
	private function record_scheduled(): \ArrayObject {
		$scheduled = new \ArrayObject();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook ) use ( $scheduled ) {
				if ( Gallery_Index::LOOKUP_HOOK === $hook ) {
					$scheduled[] = $hook;
				}
				return true;
			}
		);
		return $scheduled;
	}

	public function test_a_missing_link_renders_without_a_request_and_schedules_a_lookup(): void {
		$this->connect();
		$scheduled = $this->record_scheduled();

		$html = $this->renderer->render( array( 'id' => 'g-2' ) );
		$this->renderer->render( array( 'id' => 'g-2' ) );

		$this->assertSame( self::HINT . Reserved_Space::rule() . '<div class="profotograaf-gallery" data-profotograaf-gallery="g-2" data-layout="grid" style="--pf-ar:4/3;--pf-ar-t:3/3;--pf-ar-m:2/3">' . self::NOSCRIPT . '</div>', $html );
		$this->assertCount( 0, $this->http->requests, 'A render path must not call the platform.' );
		$this->assertSame( array( Gallery_Index::LOOKUP_HOOK ), $scheduled->getArrayCopy() );
	}

	public function test_the_link_and_title_appear_after_the_background_lookup(): void {
		$this->connect();
		$this->http->reply( 200, array( $this->row( 'g-1', 'Spring wedding' ), $this->row( 'g-2', 'Autumn portraits' ) ) );
		$index = new Gallery_Index( $this->api );

		$before = $this->renderer->render( array( 'id' => 'g-2' ) );
		$index->lookup();
		$first  = $this->renderer->render( array( 'id' => 'g-2' ) );
		$second = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringNotContainsString( '<a ', $before );
		$this->assertStringContainsString( 'href="https://profotograaf.nl/share/g/spring-wedding" style="display:inline-block;padding:.5em 0">Autumn portraits</a>', $first );
		$this->assertStringContainsString( '>Spring wedding</a>', $second );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries', $this->http->requests[0]['url'] );
		$this->assertFalse( $this->autoload['profotograaf_gallery_index'], 'The index must not autoload.' );
	}

	public function test_a_failed_lookup_leaves_the_div_without_a_link_and_is_not_rescheduled_at_once(): void {
		$this->connect();
		$this->http->reply( 500, array( 'error' => 'boom' ) );
		$scheduled = $this->record_scheduled();

		$first = $this->renderer->render( array( 'id' => 'g-1' ) );
		( new Gallery_Index( $this->api ) )->lookup();
		$second = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertSame( self::HINT . Reserved_Space::rule() . '<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="grid" style="--pf-ar:4/3;--pf-ar-t:3/3;--pf-ar-m:2/3">' . self::NOSCRIPT . '</div>', $first );
		$this->assertSame( substr( $first, strlen( self::HINT ) ), $second );
		$this->assertCount( 1, $scheduled );
	}

	public function test_a_disconnected_site_still_renders_without_a_request(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( 'data-profotograaf-gallery="g-1"', $html );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_extra_classes_are_added_to_the_div(): void {
		$html = $this->renderer->render(
			array(
				'id'    => 'g-1',
				'class' => 'wp-block-profotograaf-gallery alignwide',
			)
		);

		$this->assertStringContainsString( 'class="profotograaf-gallery wp-block-profotograaf-gallery alignwide"', $html );
	}

	public function test_a_wrapper_callable_builds_the_div_attributes(): void {
		$seen = array();
		$html = $this->renderer->render(
			array(
				'id'      => 'g-1',
				'layout'  => 'grid',
				'class'   => 'ignored',
				'wrapper' => function ( array $attributes ) use ( &$seen ) {
					$seen = $attributes;
					return 'class="custom"';
				},
			)
		);

		$this->assertStringStartsWith( self::HINT . Reserved_Space::rule() . '<div class="custom">', $html );
		$this->assertSame( 'g-1', $seen['data-profotograaf-gallery'] );
		$this->assertSame( 'profotograaf-gallery', $seen['class'] );
	}

	/**
	 * The audit for #41 found no triangle in the plugin markup. These elements
	 * draw one in a browser or a theme (a disclosure marker or a list bullet),
	 * so the markup must never contain them.
	 */
	public function test_the_markup_has_no_element_that_could_draw_a_marker(): void {
		$this->as_editor( true );
		$html = $this->renderer->render(
			array(
				'id'    => 'g-1',
				'title' => 'One photo',
				'url'   => 'https://profotograaf.nl/share/g/one',
				'class' => 'wp-block-profotograaf-gallery alignfull',
			)
		);

		foreach ( array( '<details', '<summary', '<ul', '<ol', '<li>', '<li ', '<img', '<svg', 'list-style' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	public function test_the_fallback_content_is_one_link_and_one_noscript_inside_the_host(): void {
		$html = $this->renderer->render(
			array(
				'id'  => 'g-1',
				'url' => 'https://profotograaf.nl/share/g/x',
			)
		);

		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertSame( 1, substr_count( substr( $html, (int) strpos( $html, '<div ' ) ), '<noscript>' ) );
		$this->assertSame( 1, substr_count( $html, '<div ' ) );
		$this->assertStringStartsWith( self::HINT . Reserved_Space::rule() . '<div ', $html );
		$this->assertStringEndsWith( '</noscript></div>', $html );
	}

	public function test_alignment_classes_reach_the_host_div_for_wide_and_full_galleries(): void {
		foreach ( array( 'alignwide', 'alignfull' ) as $align ) {
			$html = $this->renderer->render(
				array(
					'id'    => 'g-1',
					'class' => 'wp-block-profotograaf-gallery ' . $align,
				)
			);
			$this->assertStringContainsString( 'class="profotograaf-gallery wp-block-profotograaf-gallery ' . $align . '"', $html );
		}
	}

	public function test_without_any_setting_no_display_attribute_is_sent(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringNotContainsString( 'data-columns', $html );
		$this->assertStringNotContainsString( 'data-lightbox', $html );
		$this->assertStringContainsString( 'data-layout="grid" style="--pf-ar:4/3;--pf-ar-t:3/3;--pf-ar-m:2/3"', $html );
	}

	public function test_the_site_default_reaches_embed_js_as_data_attributes(): void {
		$this->options['profotograaf_settings'] = array(
			'gallery_columns'        => 4,
			'gallery_columns_tablet' => 3,
			'gallery_columns_mobile' => 1,
			'gallery_gap'            => 0,
			'gallery_ratio'          => '4-3',
			'gallery_captions'       => 'overlay',
			'gallery_sort'           => 'newest',
			'gallery_per_page'       => 24,
			'gallery_load_more'      => 'on',
			'gallery_lightbox'       => 'off',
		);

		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		foreach ( array(
			'data-columns="4"',
			'data-columns-tablet="3"',
			'data-columns-mobile="1"',
			'data-gap="0"',
			'data-ratio="4:3"',
			'data-captions="overlay"',
			'data-sort="newest"',
			'data-per-page="24"',
			'data-load-more="on"',
			'data-lightbox="off"',
		) as $attribute ) {
			$this->assertStringContainsString( ' ' . $attribute, $html, $attribute );
		}
	}

	public function test_block_wins_over_shortcode_and_shortcode_over_site_default(): void {
		$this->options['profotograaf_settings'] = array(
			'gallery_columns' => 5,
			'gallery_gap'     => 10,
			'gallery_sort'    => 'oldest',
		);

		$html = $this->renderer->render(
			array(
				'id'      => 'g-1',
				'columns' => '2',
				'gap'     => '',
			),
			array(
				'columns' => '6',
				'gap'     => '20',
				'sort'    => 'random',
			)
		);

		$this->assertStringContainsString( ' data-columns="2"', $html );
		$this->assertStringContainsString( ' data-gap="20"', $html );
		$this->assertStringContainsString( ' data-sort="random"', $html );
	}

	public function test_columns_alone_step_down_for_a_grid(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'columns' => '5' ) );

		$this->assertStringContainsString( ' data-columns="5"', $html );
		$this->assertStringContainsString( ' data-columns-tablet="3"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="2"', $html );
		$this->assertStringContainsString( '--pf-ar:5/3;--pf-ar-t:3/3;--pf-ar-m:2/3', $html );
	}

	public function test_columns_alone_step_down_to_one_on_a_phone_for_masonry(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'masonry', 'columns' => '4' ) );

		$this->assertStringContainsString( ' data-columns-tablet="3"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="1"', $html );
	}

	public function test_small_column_counts_do_not_step_up(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'columns' => '1' ) );

		$this->assertStringContainsString( ' data-columns-tablet="1"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="1"', $html );
	}

	public function test_explicit_tablet_and_phone_values_win_over_the_step_down(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'columns' => '5', 'columns_tablet' => '5', 'columns_mobile' => '4' ) );

		$this->assertStringContainsString( ' data-columns-tablet="5"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="4"', $html );
	}

	public function test_a_site_tablet_value_wins_and_the_phone_steps_down_from_it(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_columns_tablet' => 1 );

		$html = $this->renderer->render( array( 'id' => 'g-1', 'columns' => '5' ) );

		$this->assertStringContainsString( ' data-columns-tablet="1"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="1"', $html );
	}

	public function test_site_default_columns_step_down_too(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_columns' => 6 );

		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( ' data-columns-tablet="3"', $html );
		$this->assertStringContainsString( ' data-columns-mobile="2"', $html );
	}

	public function test_a_slideshow_gets_no_step_down(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'slideshow', 'columns' => '5' ) );

		$this->assertStringNotContainsString( 'data-columns-tablet', $html );
		$this->assertStringNotContainsString( 'data-columns-mobile', $html );
	}

	public function test_grid_with_original_ratio_still_renders(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'grid', 'ratio' => 'original' ) );

		$this->assertStringContainsString( ' data-ratio="original"', $html );
		$this->assertStringContainsString( '--pf-ar:4/3;--pf-ar-t:3/3;--pf-ar-m:2/3', $html );
	}

	public function test_a_block_can_turn_off_what_the_site_default_turns_on(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_lightbox' => 'on' );

		$html = $this->renderer->render(
			array(
				'id'       => 'g-1',
				'lightbox' => 'off',
			)
		);

		$this->assertStringContainsString( ' data-lightbox="off"', $html );
	}

	public function test_invalid_values_are_dropped_and_numbers_are_kept_in_range(): void {
		$html = $this->renderer->render(
			array(
				'id'       => 'g-1',
				'columns'  => '99',
				'gap'      => 'wide',
				'captions' => '"><script>',
				'ratio'    => '5-7',
			)
		);

		$this->assertStringContainsString( ' data-columns="8"', $html );
		$this->assertStringNotContainsString( 'data-gap', $html );
		$this->assertStringNotContainsString( 'data-captions', $html );
		$this->assertStringNotContainsString( 'data-ratio', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_block_attributes_map_to_render_arguments(): void {
		$args = Gallery_Renderer::options_from_block(
			array(
				'columnsTablet' => '3',
				'perPage'       => '12',
				'unknown'       => 'x',
			)
		);

		$this->assertSame( '3', $args['columns_tablet'] );
		$this->assertSame( '12', $args['per_page'] );
		$this->assertSame( '', $args['lightbox'] );
		$this->assertSame( array_keys( Gallery_Renderer::OPTIONS ), array_keys( $args ) );
	}

	public function test_excluded_photos_reach_embed_js_as_a_comma_separated_list(): void {
		$html = $this->renderer->render(
			array(
				'id'      => 'g-1',
				'exclude' => array( 'p-1', 'p_2', 'p-1', 'bad id', '"><script>', '' ),
			)
		);

		$this->assertStringContainsString( ' data-exclude="p-1,p_2"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_no_excluded_photos_send_no_attribute(): void {
		$this->assertStringNotContainsString( 'data-exclude', $this->renderer->render( array( 'id' => 'g-1' ) ) );
		$this->assertStringNotContainsString( 'data-exclude', $this->renderer->render( array( 'id' => 'g-1', 'exclude' => array() ), array( 'exclude' => ' , ' ) ) );
	}

	public function test_the_block_list_wins_over_the_shortcode_list(): void {
		$block = $this->renderer->render( array( 'id' => 'g-1', 'exclude' => array( 'a' ) ), array( 'exclude' => 'b,c' ) );
		$code  = $this->renderer->render( array( 'id' => 'g-1', 'exclude' => '' ), array( 'exclude' => 'b, c' ) );

		$this->assertStringContainsString( ' data-exclude="a"', $block );
		$this->assertStringContainsString( ' data-exclude="b,c"', $code );
	}

	public function test_the_excluded_list_is_capped(): void {
		$ids = array();
		for ( $i = 0; $i < Gallery_Renderer::MAX_EXCLUDED + 10; $i++ ) {
			$ids[] = 'p' . $i;
		}

		$this->assertCount( Gallery_Renderer::MAX_EXCLUDED, Gallery_Renderer::clean_ids( $ids ) );
		$this->assertSame( array(), Gallery_Renderer::clean_ids( 42 ) );
	}

	public function test_the_excluded_block_attribute_maps_to_the_exclude_argument(): void {
		$args = Gallery_Renderer::options_from_block( array( 'excludedPhotoIds' => array( 'p-1' ) ) );

		$this->assertSame( array( 'p-1' ), $args['exclude'] );
	}

	public function test_duotone_and_link_to_follow_the_same_layers_as_the_other_options(): void {
		$this->options['profotograaf_settings'] = array(
			'gallery_duotone' => '#111111,#EEEEEE',
			'gallery_link_to' => 'file',
		);

		$site = $this->renderer->render( array( 'id' => 'g-1' ) );
		$this->assertStringContainsString( ' data-duotone="#111111,#eeeeee"', $site );
		$this->assertStringContainsString( ' data-link-to="file"', $site );

		$shortcode = $this->renderer->render(
			array( 'id' => 'g-1' ),
			array(
				'duotone' => '#000,#fff',
				'link_to' => 'none',
			)
		);
		$this->assertStringContainsString( ' data-duotone="#000,#fff"', $shortcode );
		$this->assertStringContainsString( ' data-link-to="none"', $shortcode );
	}

	public function test_every_link_target_reaches_embed_js(): void {
		foreach ( array( 'none', 'page', 'site', 'file' ) as $target ) {
			$html = $this->renderer->render( array( 'id' => 'g-1' ), array( 'link_to' => $target ) );
			$this->assertStringContainsString( ' data-link-to="' . $target . '"', $html, $target );
		}
	}

	public function test_a_saved_link_target_stays_valid(): void {
		foreach ( array( 'none', 'page', 'file' ) as $saved ) {
			$this->options['profotograaf_settings'] = array( 'gallery_link_to' => $saved );
			$html                                   = $this->renderer->render( array( 'id' => 'g-1' ) );
			$this->assertStringContainsString( ' data-link-to="' . $saved . '"', $html, $saved );
		}
	}

	public function test_link_new_tab_follows_the_block_shortcode_and_site_layers(): void {
		$this->assertStringNotContainsString( 'data-link-new-tab', $this->renderer->render( array( 'id' => 'g-1' ) ) );

		$this->options['profotograaf_settings'] = array( 'gallery_link_new_tab' => 'off' );
		$this->assertStringContainsString( ' data-link-new-tab="off"', $this->renderer->render( array( 'id' => 'g-1' ) ) );

		$shortcode = $this->renderer->render( array( 'id' => 'g-1' ), array( 'link_new_tab' => 'on' ) );
		$this->assertStringContainsString( ' data-link-new-tab="on"', $shortcode );

		$block = $this->renderer->render(
			array( 'id' => 'g-1' ) + Gallery_Renderer::options_from_block( array( 'linkNewTab' => 'on' ) ),
			array( 'link_new_tab' => 'off' )
		);
		$this->assertStringContainsString( ' data-link-new-tab="on"', $block );
	}

	public function test_an_invalid_link_new_tab_value_is_dropped(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1' ), array( 'link_new_tab' => 'yes' ) );

		$this->assertStringNotContainsString( 'data-link-new-tab', $html );
	}

	public function test_an_invalid_duotone_or_link_target_is_dropped(): void {
		$html = $this->renderer->render(
			array(
				'id'      => 'g-1',
				'duotone' => 'red,blue',
				'link_to' => 'elsewhere',
			)
		);

		$this->assertStringNotContainsString( 'data-duotone', $html );
		$this->assertStringNotContainsString( 'data-link-to', $html );
	}

	public function test_per_image_text_reaches_embed_js_as_clean_json(): void {
		$items = array(
			array(
				'id'      => 'p-1',
				'caption' => 'Sunrise',
				'alt'     => 'Sun over the sea',
			),
			array(
				'id'      => 'bad id',
				'caption' => 'Dropped',
			),
			array( 'id' => 'p-2' ),
			array(
				'id'      => 'p-3',
				'caption' => 'Dunes at dusk',
			),
		);
		$args  = Gallery_Renderer::options_from_block( array( 'imageText' => $items ) );
		$html  = $this->renderer->render( array( 'id' => 'g-1' ) + $args );

		$this->assertStringContainsString(
			' data-image-text="[{&quot;id&quot;:&quot;p-1&quot;,&quot;caption&quot;:&quot;Sunrise&quot;,&quot;alt&quot;:&quot;Sun over the sea&quot;},{&quot;id&quot;:&quot;p-3&quot;,&quot;caption&quot;:&quot;Dunes at dusk&quot;}]"',
			$html
		);
	}

	public function test_per_image_text_from_the_shortcode_is_json_and_garbage_is_ignored(): void {
		$json = '[{"id":"p-1","alt":"Boat"}]';
		$html = $this->renderer->render( array( 'id' => 'g-1' ), array( 'image_text' => $json ) );
		$this->assertStringContainsString( ' data-image-text="[{&quot;id&quot;:&quot;p-1&quot;,&quot;alt&quot;:&quot;Boat&quot;}]"', $html );

		foreach ( array( 'not json', '{"id":"p-1"}', '[1,2]', '' ) as $garbage ) {
			$this->assertStringNotContainsString( 'data-image-text', $this->renderer->render( array( 'id' => 'g-1' ), array( 'image_text' => $garbage ) ), $garbage );
		}
	}

	public function test_per_image_text_is_limited_in_count_and_length(): void {
		$items = array();
		for ( $i = 0; $i < Gallery_Renderer::IMAGE_TEXT_LIMIT + 5; $i++ ) {
			$items[] = array(
				'id'      => 'p' . $i,
				'caption' => str_repeat( 'x', Gallery_Renderer::IMAGE_TEXT_LENGTH + 50 ),
			);
		}
		$decoded = json_decode( Gallery_Renderer::clean_image_text( $items ), true );

		$this->assertCount( Gallery_Renderer::IMAGE_TEXT_LIMIT, $decoded );
		$this->assertSame( Gallery_Renderer::IMAGE_TEXT_LENGTH, strlen( $decoded[0]['caption'] ) );
	}

	public function test_crop_and_radius_have_no_option_of_their_own(): void {
		$this->assertArrayHasKey( 'ratio', Gallery_Renderer::OPTIONS );
		$this->assertArrayNotHasKey( 'crop', Gallery_Renderer::OPTIONS );
		$this->assertArrayNotHasKey( 'radius', Gallery_Renderer::OPTIONS );
	}

	/**
	 * Index entry for one gallery.
	 *
	 * @param int $count Photo count.
	 * @return array<string,array<string,mixed>>
	 */
	private function indexed( int $count ): array {
		return array(
			'g-1' => array(
				'title' => 'A',
				'url'   => 'https://profotograaf.nl/share/g/a',
				'count' => $count,
			),
		);
	}

	public function test_a_gallery_listed_with_no_photos_gets_the_empty_state_without_space_or_link(): void {
		$this->as_editor( false );
		( new Gallery_Index( $this->api ) )->remember( array( array_merge( $this->row( 'g-1' ), array( 'photo_count' => 0 ) ) ) );

		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertSame( self::HINT . Reserved_Space::rule() . '<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="grid" data-pf-empty=""></div>', $html );
	}

	public function test_the_empty_state_keeps_the_hint_for_editors_only(): void {
		$this->options['profotograaf_gallery_index'] = $this->indexed( 0 );

		$this->as_editor( true );
		$editor = $this->renderer->render( array( 'id' => 'g-1' ) );
		$this->as_editor( false );
		$visitor = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( 'This gallery has no photos that can be shown on your site.', $editor );
		$this->assertStringContainsString( 'data-pf-hint style=', $editor );
		$this->assertStringNotContainsString( ' hidden', $editor );
		$this->assertStringNotContainsString( '<a ', $editor );
		$this->assertStringNotContainsString( 'has no photos', $visitor );
	}

	public function test_the_hint_follows_the_right_to_edit_the_current_post(): void {
		$this->options['profotograaf_gallery_index'] = $this->indexed( 0 );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'current_user_can' )->alias( fn( $cap, $id = 0 ) => 'edit_post' === $cap && 42 === $id );

		$this->assertStringContainsString( 'has no photos', $this->renderer->render( array( 'id' => 'g-1' ) ) );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertStringNotContainsString( 'has no photos', $this->renderer->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_a_gallery_with_photos_keeps_its_space_and_link_and_a_hidden_hint_for_editors(): void {
		$this->options['profotograaf_gallery_index'] = $this->indexed( 5 );

		$this->as_editor( true );
		$editor = $this->renderer->render( array( 'id' => 'g-1' ) );
		$this->as_editor( false );
		$visitor = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringNotContainsString( 'data-pf-empty=', $editor );
		$this->assertStringContainsString( ' style="--pf-ar:', $editor );
		$this->assertStringContainsString( '<a href="https://profotograaf.nl/share/g/a"', $editor );
		$this->assertStringContainsString( '<p class="profotograaf-gallery-empty-hint" data-pf-hint hidden', $editor );
		$this->assertStringNotContainsString( 'data-pf-hint', $visitor );
	}

	public function test_a_gallery_with_an_unknown_count_is_not_called_empty(): void {
		$this->options['profotograaf_gallery_index'] = array(
			'g-1' => array(
				'title' => 'A',
				'url'   => 'https://profotograaf.nl/share/g/a',
			),
		);
		$this->as_editor( false );

		$this->assertStringNotContainsString( 'data-pf-empty=', $this->renderer->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_the_empty_state_works_with_the_block_wrapper(): void {
		$this->as_editor( false );
		$this->options['profotograaf_gallery_index'] = $this->indexed( 0 );
		$wrapper                                     = static function ( array $attributes ): string {
			$html = '';
			foreach ( $attributes as $name => $value ) {
				$html .= $name . '="' . $value . '" ';
			}
			return trim( $html );
		};

		$html = $this->renderer->render(
			array(
				'id'      => 'g-1',
				'wrapper' => $wrapper,
			)
		);

		$this->assertStringContainsString( 'data-pf-empty=""', $html );
		$this->assertStringNotContainsString( 'style=', substr( $html, (int) strpos( $html, '<div ' ) ) );
	}

	public function test_the_stylesheet_releases_the_space_at_once_for_an_empty_or_failed_state(): void {
		$rule = Reserved_Space::rule();

		$this->assertStringContainsString( '[data-pf-state=error]{aspect-ratio:auto!important;animation:none}', $rule );
		$this->assertStringNotContainsString( 'data-pf-state=drawn', $rule );
		// The timer stays for an embed.js that sets no state.
		$this->assertStringContainsString( 'animation:pf-release 1ms ' . Reserved_Space::RELEASE_AFTER . 's forwards', $rule );
	}

	public function test_the_stylesheet_collapses_empty_and_failed_galleries(): void {
		$rule = Reserved_Space::rule();

		$this->assertStringContainsString( '[data-pf-empty],[data-pf-state=empty]{display:block;aspect-ratio:auto!important;animation:none}', $rule );
		$this->assertStringContainsString( '[data-pf-empty],[data-pf-state=empty],[data-profotograaf-failed],[data-pf-state=error]{overflow:visible', $rule );
		$this->assertStringContainsString( '[data-pf-empty]>a,[data-pf-empty]>noscript,[data-pf-state=empty]>a,[data-pf-state=empty]>noscript{display:none!important}', $rule );
		$this->assertStringContainsString( '[data-profotograaf-gallery]>a{max-width:100%', $rule );
	}
}
