<?php
/**
 * The gallery block and shortcode draw the gallery on the server when they can.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Full_Page_Payload;
use Profotograaf\Gallery_Index;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Reserved_Space;
use Profotograaf\Settings;

class Gallery_Declarative_Test extends Gallery_Test_Case {

	private Gallery_Renderer $server;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		$this->server = new Gallery_Renderer( $this->script, new Gallery_Index( $this->api ), new Settings(), new Full_Page_Payload( $this->api ) );
	}

	/**
	 * A payload the platform could send.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(): array {
		return array(
			'id'     => 'g-1',
			'title'  => 'Spring wedding',
			'layout' => 'masonry',
			'url'    => 'https://profotograaf.nl/share/g/spring-wedding',
			'badge'  => array(
				'show'      => true,
				'prominent' => false,
				'url'       => 'https://profotograaf.nl/made-with?ref=embed-badge',
			),
			'photos' => array(
				array(
					'id'      => 'p1',
					'width'   => 3000,
					'height'  => 2000,
					'alt'     => 'Photo 1',
					'title'   => '',
					'caption' => '',
					'images'  => array(
						array( 'variant' => 'web', 'url' => 'https://profotograaf.nl/share/img/a1/web-v1.jpg', 'width' => 1600, 'height' => 1067 ),
					),
				),
			),
		);
	}

	public function test_a_default_gallery_carries_its_markup_and_keeps_its_noscript_text(): void {
		$this->http->reply( 200, $this->payload() );

		$html = $this->server->render( array( 'id' => 'g-1', 'layout' => 'masonry' ) );

		$this->assertStringContainsString( '<template shadowrootmode="open"><style>', $html );
		$this->assertStringContainsString( 'aria-label="Photo 1"', $html );
		$this->assertStringContainsString( 'data-profotograaf-gallery="g-1"', $html );
		$this->assertStringContainsString( 'This gallery needs JavaScript', $html );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_gallery_the_server_drew_holds_no_reserved_space(): void {
		$this->http->reply( 200, $this->payload() );

		$html = $this->server->render( array( 'id' => 'g-1', 'layout' => 'masonry' ) );

		$this->assertStringContainsString( '<template shadowrootmode="open">', $html );
		$this->assertStringNotContainsString( '--pf-ar', str_replace( Reserved_Space::rule(), '', $html ) );
	}

	public function test_a_gallery_the_server_cannot_draw_still_reserves_its_space(): void {
		$this->http->reply( 200, $this->payload() );

		$html = $this->server->render( array( 'id' => 'g-1', 'layout' => 'slideshow' ) );

		$this->assertStringContainsString( 'style="--pf-ar:', $html );
	}

	public function test_the_badge_is_written_in_the_site_language(): void {
		Functions\when( 'get_locale' )->justReturn( 'nl_NL' );
		$this->http->reply( 200, $this->payload() );

		$this->assertStringContainsString( 'Gemaakt met Profotograaf', $this->server->render( array( 'id' => 'g-1', 'layout' => 'masonry' ) ) );
	}

	public function test_a_gallery_the_server_cannot_draw_keeps_only_the_host_div(): void {
		$this->http->reply( 200, $this->payload() );

		$html = $this->server->render( array( 'id' => 'g-1', 'layout' => 'slideshow' ) );

		$this->assertStringNotContainsString( '<template', $html );
		$this->assertStringContainsString( 'This gallery needs JavaScript', $html );
	}

	public function test_a_failed_payload_request_keeps_the_host_div_and_is_not_repeated_for_a_minute(): void {
		$this->http->reply( 500, array( 'error' => 'down' ) );

		$first  = $this->server->render( array( 'id' => 'g-1', 'layout' => 'masonry' ) );
		$second = $this->server->render( array( 'id' => 'g-1', 'layout' => 'masonry' ) );

		$this->assertStringNotContainsString( '<template', $first );
		$this->assertStringNotContainsString( '<template', $second );
		$this->assertStringContainsString( 'This gallery needs JavaScript', $second );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_without_a_payload_source_nothing_is_fetched(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'layout' => 'masonry' ) );

		$this->assertStringNotContainsString( '<template', $html );
		$this->assertCount( 0, $this->http->requests );
	}
}
