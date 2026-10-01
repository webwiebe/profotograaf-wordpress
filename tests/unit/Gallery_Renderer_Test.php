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

class Gallery_Renderer_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
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
		$this->assertStringStartsWith( '<div class="profotograaf-gallery"', $visitor );
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

		$this->assertStringContainsString( 'style="min-height:8em"', $html );
		$this->assertStringContainsString( self::NOSCRIPT, $html );
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
			'<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="masonry" style="min-height:8em">'
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

		$this->assertSame( '<div class="profotograaf-gallery" data-profotograaf-gallery="g-2" data-layout="grid" style="min-height:8em">' . self::NOSCRIPT . '</div>', $html );
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

		$this->assertSame( '<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="grid" style="min-height:8em">' . self::NOSCRIPT . '</div>', $first );
		$this->assertSame( $first, $second );
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

		foreach ( array( '<details', '<summary', '<ul', '<ol', '<li', '<img', '<svg', 'list-style' ) as $needle ) {
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
		$this->assertSame( 1, substr_count( $html, '<noscript>' ) );
		$this->assertSame( 1, substr_count( $html, '<div ' ) );
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
		$this->assertStringContainsString( 'data-layout="grid" style="min-height:8em"', $html );
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
}
