<?php
/**
 * Picker REST route tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Gallery_Index;
use Profotograaf\Gallery_Rest;

/**
 * Just enough of a REST request.
 */
class Rest_Request_Stub {

	/**
	 * Params.
	 *
	 * @var array<string,mixed>
	 */
	private array $params;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $params Params.
	 */
	public function __construct( array $params ) {
		$this->params = $params;
	}

	/**
	 * Param.
	 *
	 * @param string $name Name.
	 */
	public function get_param( $name ) {
		return $this->params[ $name ] ?? null;
	}
}

if ( ! class_exists( '\\WP_REST_Response' ) ) {
	/**
	 * Just enough of a REST response.
	 */
	class Rest_Response_Stub {

		/**
		 * Constructor.
		 *
		 * @param mixed                $data    Data.
		 * @param int                  $status  Status.
		 * @param array<string,string> $headers Headers.
		 */
		public function __construct( public $data = null, public int $status = 200, public array $headers = array() ) {}
	}
	class_alias( Rest_Response_Stub::class, 'WP_REST_Response' );
}

class Gallery_Rest_Test extends Gallery_Test_Case {

	private Gallery_Rest $rest;

	protected function setUp(): void {
		parent::setUp();
		$this->rest = new Gallery_Rest( $this->api, new Gallery_Index( $this->api ) );
		$this->connect();
	}

	public function test_both_routes_need_the_edit_posts_capability(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $space, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = array_merge( array( 'namespace' => $space ), $args );
			}
		);

		$this->rest->register_routes();

		$this->assertCount( 2, $routes );
		foreach ( $routes as $route ) {
			$this->assertSame( 'profotograaf/v1', $route['namespace'] );
			$this->assertSame( array( $this->rest, 'can_edit' ), $route['permission_callback'] );
		}
		$this->assertSame( 'GET', $routes['/galleries']['methods'] );
		$this->assertSame( 'POST', $routes['/galleries/(?P<id>[A-Za-z0-9_-]{1,64})/embeddable']['methods'] );

		Functions\expect( 'current_user_can' )->once()->with( 'edit_posts' )->andReturn( false );
		$this->assertFalse( $this->rest->can_edit() );
	}

	public function test_it_lists_the_galleries_and_remembers_them(): void {
		$this->http->reply( 200, array( $this->row( 'g-1' ) ) );

		$rows = $this->rest->list_galleries();

		$this->assertSame( 'g-1', $rows[0]['id'] );
		$this->assertSame( 12, $rows[0]['photo_count'] );
		$this->assertSame( 'https://cdn.example/cover.jpg', $rows[0]['cover_url'] );
		$this->assertSame( 'Spring wedding', $this->options['profotograaf_gallery_index']['g-1']['title'] );
	}

	public function test_a_disconnected_site_gets_a_409_with_the_api_message(): void {
		$this->options = array();

		$error = $this->rest->list_galleries();

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'profotograaf_not_connected', $error->get_error_code() );
		$this->assertSame( 409, $error->data['status'] );
	}

	public function test_a_platform_failure_is_a_502(): void {
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$error = $this->rest->list_galleries();

		$this->assertSame( 502, $error->data['status'] );
	}

	public function test_a_platform_rate_limit_is_a_429_with_a_retry_after_header(): void {
		$this->http->reply( 429, array( 'error' => 'slow down' ), array( 'retry-after' => '42' ) );

		$response = $this->rest->list_galleries();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 429, $response->status );
		$this->assertSame( array( 'Retry-After' => '42' ), $response->headers );
		$this->assertSame( 'profotograaf_http', $response->data['code'] );
		$this->assertSame( 42, $response->data['data']['retry_after'] );
		$this->assertTrue( $response->data['data']['retryable'] );
	}

	public function test_a_rate_limit_without_a_retry_after_gets_a_default_wait(): void {
		$this->http->reply( 429, array( 'error' => 'slow down' ) );

		$response = $this->rest->list_galleries();

		$this->assertSame( 429, $response->status );
		$this->assertSame( array( 'Retry-After' => '60' ), $response->headers );
	}

	public function test_marking_passes_a_rate_limit_on_too(): void {
		$this->http->reply( 429, null, array( 'retry-after' => '5' ) );

		$response = $this->rest->mark_embeddable( new Rest_Request_Stub( array( 'id' => 'g-1' ) ) );

		$this->assertSame( 429, $response->status );
		$this->assertSame( '5', $response->headers['Retry-After'] );
	}

	public function test_a_network_failure_is_a_504(): void {
		$this->http->fail( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$error = $this->rest->list_galleries();

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'profotograaf_network', $error->get_error_code() );
		$this->assertSame( 504, $error->data['status'] );
		$this->assertTrue( $error->data['retryable'] );
	}

	public function test_a_platform_gateway_timeout_is_a_504(): void {
		$this->http->reply( 504, null );

		$error = $this->rest->list_galleries();

		$this->assertSame( 504, $error->data['status'] );
	}

	public function test_a_platform_request_timeout_is_a_504(): void {
		$this->http->reply( 408, null );

		$error = $this->rest->list_galleries();

		$this->assertSame( 504, $error->data['status'] );
	}

	public function test_marking_a_gallery_returns_the_platform_answer(): void {
		$this->http->reply(
			200,
			array(
				'id'         => 'g-1',
				'embeddable' => true,
				'available'  => false,
			)
		);

		$result = $this->rest->mark_embeddable( new Rest_Request_Stub( array( 'id' => 'g-1' ) ) );

		$this->assertSame(
			array(
				'id'         => 'g-1',
				'embeddable' => true,
				'available'  => false,
			),
			$result
		);
		$this->assertSame( 'PUT', $this->http->requests[0]['method'] );
		$this->assertSame( array( 'embeddable' => true ), $this->http->body( 0 ) );
	}

	public function test_marking_is_a_403_that_asks_for_a_reconnect_when_the_scope_is_missing(): void {
		$this->http->reply( 403, array( 'error' => 'forbidden' ) );

		$error = $this->rest->mark_embeddable( new Rest_Request_Stub( array( 'id' => 'g-1' ) ) );

		$this->assertSame( 'profotograaf_reconnect', $error->get_error_code() );
		$this->assertSame( 403, $error->data['status'] );
		$this->assertStringContainsString( 'Connect this site again', $error->get_error_message() );
	}

	public function test_a_bad_id_is_a_400_without_a_request(): void {
		$error = $this->rest->mark_embeddable( new Rest_Request_Stub( array( 'id' => '../x' ) ) );

		$this->assertSame( 400, $error->data['status'] );
		$this->assertCount( 0, $this->http->requests );
	}
}
