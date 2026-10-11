<?php
/**
 * Payload cache of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Full_Page_Payload;

class Full_Page_Payload_Test extends Gallery_Test_Case {

	private Full_Page_Payload $payload;

	/**
	 * Expiry of every transient written, by name.
	 *
	 * @var array<string,int>
	 */
	private array $expiry = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $expiration = 0 ) {
				$this->transients[ $name ] = $value;
				$this->expiry[ $name ]     = $expiration;
				return true;
			}
		);
		$this->payload = new Full_Page_Payload( $this->api );
	}

	public function test_it_reads_the_public_route_without_a_token_and_caches_the_body_with_its_version(): void {
		$this->connect();
		$this->http->reply( 200, array( 'id' => 'g-1', 'version' => 'abc123', 'photos' => array() ) );

		$body = $this->payload->get( 'g-1' );

		$this->assertSame( array( 'id' => 'g-1', 'version' => 'abc123', 'photos' => array() ), $body );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g-1', $this->http->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->requests[0]['headers'] );
		$this->assertSame( 'abc123', $this->transients[ Full_Page_Payload::PREFIX . 'g-1' ]['version'] );
		$this->assertSame( 300, $this->expiry[ Full_Page_Payload::PREFIX . 'g-1' ] );
	}

	public function test_a_fresh_entry_is_reused_without_a_request(): void {
		$this->http->reply( 200, array( 'id' => 'g-1', 'version' => 'v1' ) );
		$this->payload->get( 'g-1' );

		$again = $this->payload->get( 'g-1' );

		$this->assertSame( 'v1', $again['version'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_the_cache_is_per_gallery(): void {
		$this->http->reply( 200, array( 'id' => 'g-1' ) )->reply( 200, array( 'id' => 'g-2' ) );

		$this->payload->get( 'g-1' );
		$second = $this->payload->get( 'g-2' );

		$this->assertSame( 'g-2', $second['id'] );
		$this->assertCount( 2, $this->http->requests );
	}

	public function test_a_platform_error_is_returned_and_not_cached(): void {
		$this->http->reply( 404, array( 'error' => 'not found' ) );

		$result = $this->payload->get( 'g-1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->transients );
	}

	public function test_a_network_failure_is_returned_and_not_cached(): void {
		$this->http->fail( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->payload->get( 'g-1' ) );
		$this->assertSame( array(), $this->transients );
	}

	public function test_the_gallery_id_is_encoded_in_the_path(): void {
		$this->http->reply( 200, array() );

		$this->payload->get( 'g_1-A' );

		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g_1-A', $this->http->requests[0]['url'] );
	}
}
