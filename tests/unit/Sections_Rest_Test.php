<?php
/**
 * Editor preview route of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Sections_Rest;

require_once __DIR__ . '/Gallery_Rest_Test.php';

class Sections_Rest_Test extends Gallery_Test_Case {

	private Sections_Rest $rest;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		$this->rest = new Sections_Rest( $this->api );
		$this->connect();
	}

	/**
	 * A payload of photos p-0 … p-{count-1}.
	 *
	 * @param int $count Photos.
	 * @return array<string,mixed>
	 */
	private static function payload( int $count ): array {
		$photos = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$photos[] = array(
				'id'     => 'p-' . $i,
				'width'  => 3000,
				'height' => 2000,
				'alt'    => '<b>Bride</b>',
				'images' => array(
					array(
						'variant' => 'web',
						'url'     => 'https://profotograaf.nl/share/img/a/web-' . $i . '.jpg',
						'width'   => 1600,
					),
					array(
						'variant' => 'thumb',
						'url'     => 'https://profotograaf.nl/share/img/a/thumb-' . $i . '.jpg',
						'width'   => 400,
					),
				),
			);
		}
		return array( 'photos' => $photos );
	}

	public function test_the_route_needs_the_edit_posts_capability(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $space, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = array_merge( array( 'namespace' => $space ), $args );
			}
		);

		$this->rest->register_routes();

		$route = $routes['/galleries/(?P<id>[A-Za-z0-9_-]{1,64})/sections'];
		$this->assertSame( 'profotograaf/v1', $route['namespace'] );
		$this->assertSame( 'GET', $route['methods'] );
		$this->assertSame( array( $this->rest, 'can_edit' ), $route['permission_callback'] );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_posts' )->andReturn( false );
		$this->assertFalse( $this->rest->can_edit() );
	}

	public function test_it_sequences_the_public_payload_with_the_default_preset(): void {
		$this->http->reply( 200, self::payload( 19 ) );

		$result = $this->rest->sections( new Rest_Request_Stub( array( 'id' => 'g-1' ) ) );

		$this->assertSame( 'editorial', $result['preset'] );
		$this->assertSame( array( 'hero', 'grid', 'pair', 'grid', 'feature' ), array_column( $result['sections'], 'type' ) );
		$this->assertSame(
			array(
				'id'     => 'p-0',
				'thumb'  => 'https://profotograaf.nl/share/img/a/thumb-0.jpg',
				'width'  => 3000,
				'height' => 2000,
				'alt'    => 'Bride',
			),
			$result['sections'][0]['photos'][0]
		);
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g-1', $this->http->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->requests[0]['headers'] );
	}

	public function test_it_reads_preset_sections_picks_and_exclude_from_the_query(): void {
		$this->http->reply( 200, self::payload( 4 ) );
		$custom = (string) json_encode( array( array( 'type' => 'hero', 'photoIds' => array( 'p-3' ) ), array( 'type' => 'grid', 'columns' => 2, 'rows' => 1 ) ) );

		$result = $this->rest->sections(
			new Rest_Request_Stub(
				array(
					'id'       => 'g-1',
					'preset'   => 'custom',
					'sections' => $custom,
					'exclude'  => 'p-0, junk',
				)
			)
		);

		$this->assertSame( 'custom', $result['preset'] );
		$this->assertSame( array( 'p-3' ), array_column( $result['sections'][0]['photos'], 'id' ) );
		$this->assertSame( array( 'p-1', 'p-2' ), array_column( $result['sections'][1]['photos'], 'id' ) );

		$this->http->reply( 200, self::payload( 8 ) );
		$picked = $this->rest->sections(
			new Rest_Request_Stub(
				array(
					'id'     => 'g-1',
					'preset' => 'calm',
					'picks'  => array( array( 'slot' => '0.1.0', 'photoId' => 'p-0' ) ),
				)
			)
		);
		$this->assertSame( 'p-0', $picked['sections'][1]['photos'][0]['id'] );
	}

	public function test_an_empty_payload_gives_no_sections(): void {
		$this->http->reply( 200, array( 'id' => 'g-1' ) );

		$this->assertSame( array(), $this->rest->sections( new Rest_Request_Stub( array( 'id' => 'g-1', 'sections' => '{bad json' ) ) )['sections'] );
	}

	public function test_it_passes_a_platform_error_on_and_refuses_a_bad_id(): void {
		$this->http->reply( 404, array( 'error' => 'not found' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest->sections( new Rest_Request_Stub( array( 'id' => 'g-1' ) ) ) );

		$bad = $this->rest->sections( new Rest_Request_Stub( array( 'id' => '../x' ) ) );
		$this->assertSame( 400, $bad->data['status'] );
	}
}
