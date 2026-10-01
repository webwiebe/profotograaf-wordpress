<?php
/**
 * Config, settings, autoloader and origin tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Autoloader;
use Profotograaf\Config;
use Profotograaf\Modules\Origin_Sync;

class Config_And_Settings_Test extends Wp_Test_Case {

	public function test_the_platform_defaults_to_production(): void {
		$this->assertSame( 'https://profotograaf.nl', Config::platform_url() );
		$this->assertSame( 'https://profotograaf.nl/api/v1/leads', Config::platform_endpoint( '/api/v1/leads' ) );
	}

	public function test_a_broken_override_falls_back_to_production(): void {
		Filters\expectApplied( 'profotograaf_platform_url' )->andReturn( 'javascript:alert(1)' );

		$this->assertSame( 'https://profotograaf.nl', Config::platform_url() );
	}

	public function test_the_timeout_is_clamped(): void {
		Filters\expectApplied( 'profotograaf_http_timeout' )->andReturn( 900 );

		$this->assertSame( 30, Config::http_timeout() );
	}

	public function test_the_site_origin_has_scheme_host_and_port_only(): void {
		Functions\when( 'home_url' )->justReturn( 'https://Photos.Example.com:8443/blog' );

		$this->assertSame( 'https://photos.example.com:8443', Config::site_origin() );
	}

	public function test_the_autoloader_maps_names_to_wordpress_file_names(): void {
		$this->assertSame( 'api-client', Autoloader::slug( 'Api_Client' ) );
		$this->assertTrue( class_exists( \Profotograaf\Api_Client::class ) );
		$this->assertTrue( interface_exists( \Profotograaf\Transport::class ) );
	}

	public function test_origin_sync_asks_the_photographer_when_the_platform_has_no_write(): void {
		( new Origin_Sync() )->sync();

		$this->assertSame(
			array(
				'state'   => 'manual',
				'origin'  => 'https://photos.example.com',
				'message' => '',
			),
			Origin_Sync::status()
		);
		$this->assertFalse( $this->autoload['profotograaf_origin_sync'] );
	}

	public function test_origin_sync_refuses_an_insecure_site(): void {
		Functions\when( 'home_url' )->justReturn( 'http://photos.example.com' );

		( new Origin_Sync() )->sync();

		$this->assertSame( 'insecure', Origin_Sync::status()['state'] );
	}

	public function test_origin_sync_adds_the_origin_once_a_write_endpoint_exists(): void {
		$http       = new Fake_Transport();
		$connection = new \Profotograaf\Connection();
		$this->connect();
		$plugin = new \Profotograaf\Plugin( $connection, new \Profotograaf\Api_Client( $connection, $http, $this->clock() ), $this->clock() );
		Filters\expectApplied( 'profotograaf_origin_sync_endpoint' )->andReturn( '/api/v1/account/embed-origins' );
		$http->reply( 200, array( 'origins' => array( 'https://other.example.com' ) ) );
		$http->reply( 200, array( 'origins' => array( 'https://other.example.com', 'https://photos.example.com' ) ) );

		$module = new Origin_Sync();
		$module->register( $plugin );
		$module->sync();

		$this->assertSame( 'synced', Origin_Sync::status()['state'] );
		$this->assertSame( 'PUT', $http->requests[1]['method'] );
		$this->assertSame(
			array( 'origins' => array( 'https://other.example.com', 'https://photos.example.com' ) ),
			$http->body( 1 )
		);
	}

	public function test_origin_sync_records_a_platform_refusal(): void {
		$http       = new Fake_Transport();
		$connection = new \Profotograaf\Connection();
		$this->connect();
		$plugin = new \Profotograaf\Plugin( $connection, new \Profotograaf\Api_Client( $connection, $http, $this->clock() ), $this->clock() );
		Filters\expectApplied( 'profotograaf_origin_sync_endpoint' )->andReturn( '/api/v1/account/embed-origins' );
		$http->reply( 403, array( 'error' => 'this app is not allowed to use this endpoint' ) );

		$module = new Origin_Sync();
		$module->register( $plugin );
		$module->sync();

		$this->assertSame( 'error', Origin_Sync::status()['state'] );
	}

	/**
	 * Builds a connected plugin on a fake transport.
	 *
	 * @param Fake_Transport $http Transport.
	 */
	private function plugin_with( Fake_Transport $http ): \Profotograaf\Plugin {
		$connection = new \Profotograaf\Connection();
		$this->connect();
		return new \Profotograaf\Plugin( $connection, new \Profotograaf\Api_Client( $connection, $http, $this->clock() ), $this->clock() );
	}

	private function gallery_row(): array {
		return array(
			array(
				'id'         => 'g1',
				'url'        => 'https://profotograaf.nl/studio/bruiloft',
				'embeddable' => true,
			),
		);
	}

	public function test_origin_sync_verifies_the_frame_ancestors_when_there_is_no_write_endpoint(): void {
		$http = new Fake_Transport();
		$http->reply( 200, $this->gallery_row() );
		$http->reply( 200, null, array( 'content-security-policy' => "default-src 'self'; frame-ancestors 'self' https://photos.example.com" ) );

		$module = new Origin_Sync();
		$module->register( $this->plugin_with( $http ) );
		$module->sync();

		$this->assertSame( 'synced', Origin_Sync::status()['state'] );
		$this->assertSame( 'https://profotograaf.nl/studio/bruiloft', $http->requests[1]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $http->requests[1]['headers'] );
	}

	public function test_origin_sync_stays_manual_when_the_origin_is_not_listed(): void {
		$http = new Fake_Transport();
		$http->reply( 200, $this->gallery_row() );
		$http->reply( 200, null, array( 'content-security-policy' => "frame-ancestors 'self' https://other.example.com" ) );

		$module = new Origin_Sync();
		$module->register( $this->plugin_with( $http ) );
		$module->sync();

		$this->assertSame( 'manual', Origin_Sync::status()['state'] );
	}

	public function test_origin_sync_stays_manual_when_the_page_cannot_be_checked(): void {
		$http = new Fake_Transport();
		$http->reply( 200, array() );

		$module = new Origin_Sync();
		$module->register( $this->plugin_with( $http ) );
		$module->sync();

		$this->assertSame( 'manual', Origin_Sync::status()['state'] );
		$this->assertCount( 1, $http->requests );
	}

	public function test_frame_ancestors_matching(): void {
		$origin = 'https://photos.example.com';
		$this->assertTrue( \Profotograaf\Frame_Ancestors::allow( 'frame-ancestors *', $origin ) );
		$this->assertTrue( \Profotograaf\Frame_Ancestors::allow( 'frame-ancestors https:', $origin ) );
		$this->assertTrue( \Profotograaf\Frame_Ancestors::allow( 'FRAME-ANCESTORS HTTPS://PHOTOS.EXAMPLE.COM', $origin ) );
		$this->assertFalse( \Profotograaf\Frame_Ancestors::allow( "frame-ancestors 'none'", $origin ) );
		$this->assertFalse( \Profotograaf\Frame_Ancestors::allow( "default-src 'self'", $origin ) );
		$this->assertFalse( \Profotograaf\Frame_Ancestors::allow( '', $origin ) );
		$this->assertFalse( \Profotograaf\Frame_Ancestors::allow( "frame-ancestors {$origin}, frame-ancestors 'self'", $origin ) );
	}

	public function test_origin_sync_records_why_the_check_failed(): void {
		$http = new Fake_Transport();
		$http->reply( 500, array( 'error' => 'boom' ) );
		$module = new Origin_Sync();
		$module->register( $this->plugin_with( $http ) );
		$module->sync();
		$this->assertSame( 'manual', Origin_Sync::status()['state'] );
		$this->assertSame( 'boom', Origin_Sync::status()['message'] );

		$http = new Fake_Transport();
		$http->reply( 200, $this->gallery_row() );
		$http->reply( 404 );
		$module = new Origin_Sync();
		$module->register( $this->plugin_with( $http ) );
		$module->sync();
		$this->assertSame( 'manual', Origin_Sync::status()['state'] );
		$this->assertNotSame( '', Origin_Sync::status()['message'] );
	}

	public function test_framing_check_refuses_other_hosts_and_reports_network_failures(): void {
		$http = new Fake_Transport();
		$api  = $this->plugin_with( $http )->api();

		$this->assertWPError( $api->framing_allows( 'https://evil.example.net/x', 'https://photos.example.com' ) );
		$this->assertCount( 0, $http->requests );

		$http->fail( new \RuntimeException( 'down' ) );
		$this->assertWPError( $api->framing_allows( 'https://profotograaf.nl/x', 'https://photos.example.com' ) );
		$http->fail( new \WP_Error( 'http_request_failed' ) );
		$this->assertWPError( $api->framing_allows( 'https://profotograaf.nl/x', 'https://photos.example.com' ) );
		$http->reply( 503 );
		$this->assertWPError( $api->framing_allows( 'https://profotograaf.nl/x', 'https://photos.example.com' ) );
	}

	private function assertWPError( $value ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
