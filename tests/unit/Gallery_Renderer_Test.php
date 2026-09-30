<?php
/**
 * Gallery renderer tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

class Gallery_Renderer_Test extends Gallery_Test_Case {

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
			'<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="masonry">'
			. '<a href="https://profotograaf.nl/share/g/spring-wedding">Spring wedding</a></div>',
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

		$this->assertStringContainsString( '>View the gallery</a>', $html );
	}

	public function test_an_invalid_id_renders_nothing_and_loads_no_script(): void {
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

	public function test_a_missing_link_is_looked_up_once_and_remembered(): void {
		$this->connect();
		$this->http->reply( 200, array( $this->row( 'g-1', 'Spring wedding' ), $this->row( 'g-2', 'Autumn portraits' ) ) );

		$first  = $this->renderer->render( array( 'id' => 'g-2' ) );
		$second = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( '<a href="https://profotograaf.nl/share/g/spring-wedding">Autumn portraits</a>', $first );
		$this->assertStringContainsString( '>Spring wedding</a>', $second );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries', $this->http->requests[0]['url'] );
		$this->assertFalse( $this->autoload['profotograaf_gallery_index'], 'The index must not autoload.' );
	}

	public function test_a_failed_lookup_is_not_repeated_and_the_div_stays_without_a_link(): void {
		$this->connect();
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$first  = $this->renderer->render( array( 'id' => 'g-1' ) );
		$second = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertSame( '<div class="profotograaf-gallery" data-profotograaf-gallery="g-1" data-layout="grid"></div>', $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->http->requests );
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
}
