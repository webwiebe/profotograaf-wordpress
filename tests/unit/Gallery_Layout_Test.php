<?php
/**
 * Platform default layout: the index keeps it and the render uses it.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Gallery_Index;

class Gallery_Layout_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Stores a gallery in the index the way a gallery list does.
	 *
	 * @param string $id           Gallery id.
	 * @param string $layout       Layout on the platform.
	 * @param string $embed_layout Layout the platform says the embed draws.
	 */
	private function remember( string $id, string $layout, string $embed_layout = '' ): void {
		( new Gallery_Index( $this->api ) )->remember(
			array( array_merge( $this->row( $id ), array( 'layout' => $layout, 'embed_layout' => $embed_layout ) ) )
		);
	}

	/**
	 * Gallery ids with the lookup hook scheduled.
	 *
	 * @return \ArrayObject<int,string>
	 */
	private function scheduled(): \ArrayObject {
		$scheduled = new \ArrayObject();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) use ( $scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);
		return $scheduled;
	}

	public function test_the_index_keeps_the_layout_of_each_gallery(): void {
		$this->remember( 'g-1', 'parallax', 'slideshow' );

		$entry = $this->options['profotograaf_gallery_index']['g-1'];
		$this->assertSame( 'parallax', $entry['layout'] );
		$this->assertSame( 'slideshow', $entry['embed_layout'] );
	}

	public function test_a_list_row_without_a_layout_stores_an_empty_one(): void {
		( new Gallery_Index( $this->api ) )->remember( array( $this->row( 'g-1' ) ) );

		$this->assertSame( '', $this->options['profotograaf_gallery_index']['g-1']['layout'] );
	}

	public function test_the_api_client_passes_the_layout_on(): void {
		$this->connect();
		$this->http->reply( 200, array( array_merge( $this->row( 'g-1' ), array( 'layout' => 'Parallax' ) ) ) );

		$rows = $this->api->list_galleries();

		$this->assertSame( 'parallax', $rows[0]['layout'] );
		$this->assertSame( '', $rows[0]['embed_layout'] );
	}

	public function test_a_block_on_the_platform_default_draws_the_mapped_layout(): void {
		$this->remember( 'g-1', 'parallax' );

		$html = $this->renderer->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( 'data-layout="slideshow"', $html );
		$this->assertCount( 0, $this->http->requests, 'A page view must not call the platform.' );
	}

	public function test_justified_is_drawn_as_masonry_and_instagram_as_a_grid(): void {
		$this->remember( 'g-1', 'justified' );
		$this->assertStringContainsString( 'data-layout="masonry"', $this->renderer->render( array( 'id' => 'g-1' ) ) );

		$this->remember( 'g-2', 'instagram' );
		$this->assertStringContainsString( 'data-layout="grid"', $this->renderer->render( array( 'id' => 'g-2' ) ) );
	}

	public function test_an_unknown_platform_layout_is_drawn_as_a_grid(): void {
		$this->remember( 'g-1', 'carousel' );

		$this->assertStringContainsString( 'data-layout="grid"', $this->renderer->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_a_reported_drawn_layout_wins_over_the_table(): void {
		$this->remember( 'g-1', 'parallax', 'masonry' );

		$this->assertStringContainsString( 'data-layout="masonry"', $this->renderer->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_a_layout_chosen_in_the_block_wins_over_the_platform(): void {
		$this->remember( 'g-1', 'parallax' );

		$html = $this->renderer->render(
			array(
				'id'     => 'g-1',
				'layout' => 'masonry',
			)
		);

		$this->assertStringContainsString( 'data-layout="masonry"', $html );
	}

	public function test_a_layout_chosen_in_the_site_settings_wins_over_the_platform(): void {
		$this->remember( 'g-1', 'parallax' );
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'grid' );

		$this->assertStringContainsString( 'data-layout="grid"', $this->renderer->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_a_gallery_drawn_as_masonry_sends_no_ratio(): void {
		$this->remember( 'g-1', 'justified' );
		$this->options['profotograaf_settings'] = array( 'gallery_ratio' => '1-1' );

		$html = $this->renderer->render( array( 'id' => 'g-1', 'ratio' => '3-2' ) );

		$this->assertStringContainsString( 'data-layout="masonry"', $html );
		$this->assertStringNotContainsString( 'data-ratio', $html );
	}

	public function test_a_gallery_drawn_as_a_slideshow_still_sends_the_ratio(): void {
		$this->remember( 'g-1', 'parallax' );

		$html = $this->renderer->render( array( 'id' => 'g-1', 'ratio' => '3-2' ) );

		$this->assertStringContainsString( 'data-layout="slideshow"', $html );
		$this->assertStringContainsString( 'data-ratio="3:2"', $html );
	}

	public function test_a_gallery_missing_from_the_index_is_a_grid_and_asks_for_a_lookup(): void {
		$scheduled = $this->scheduled();

		$html = $this->renderer->render( array( 'id' => 'g-9' ) );

		$this->assertStringContainsString( 'data-layout="grid"', $html );
		$this->assertContains( Gallery_Index::LOOKUP_HOOK, $scheduled->getArrayCopy() );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_an_entry_from_before_layouts_were_kept_asks_for_a_lookup(): void {
		$this->options['profotograaf_gallery_index'] = array(
			'g-1' => array(
				'title' => 'Spring wedding',
				'url'   => 'https://profotograaf.nl/share/g/spring-wedding',
			),
		);
		$scheduled                                   = $this->scheduled();

		$this->assertSame( 'grid', ( new Gallery_Index( $this->api ) )->drawn_layout( 'g-1' ) );
		$this->assertSame( array( Gallery_Index::LOOKUP_HOOK ), $scheduled->getArrayCopy() );
	}

	public function test_a_known_entry_asks_for_no_lookup(): void {
		$this->remember( 'g-1', '' );
		$scheduled = $this->scheduled();

		$this->assertSame( 'grid', ( new Gallery_Index( $this->api ) )->drawn_layout( 'g-1' ) );
		$this->assertSame( array(), $scheduled->getArrayCopy() );
	}
}
