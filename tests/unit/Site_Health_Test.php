<?php
/**
 * Site Health tests and debug information.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Connection;
use Profotograaf\Embed_Script;
use Profotograaf\Logger;
use Profotograaf\Modules\Site_Health;
use Profotograaf\Plugin;
use Profotograaf\Tests\Leads\Leads_Test_Case;

class Site_Health_Test extends Leads_Test_Case {

	/**
	 * What the embed.js request returns: a status code or a WP_Error.
	 *
	 * @var int|\WP_Error
	 */
	private $embed_response = 200;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'rest_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-json/' . $path );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Logger::configure( false );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	private function module(): Site_Health {
		$module = new Site_Health(
			$this->queue,
			new Embed_Script(),
			fn() => $this->embed_response,
			$this->clock()
		);
		$module->register( new Plugin( new Connection(), null, $this->clock() ) );
		return $module;
	}

	private function pair( int $expires_in = 900, string $access = 'access-1', string $refresh = 'refresh-1' ): void {
		$this->connect( $expires_in, $access, $refresh );
		$this->options['profotograaf_connection']['scope_revision'] = \Profotograaf\Config::SCOPE_REVISION;
	}

	private function fail_a_lead(): void {
		$this->queue->enqueue( 'a', array( 'name' => 'Anna' ) );
		$this->queue->fail( $this->store->jobs['a'], 'Platform said no', 500 );
	}

	public function test_it_adds_the_tests(): void {
		$tests = $this->module()->add_tests(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		$this->assertArrayHasKey( Site_Health::CONNECTION_TEST, $tests['direct'] );
		$this->assertArrayHasKey( Site_Health::TOKEN_TEST, $tests['direct'] );
		$this->assertArrayHasKey( Site_Health::BACKLOG_TEST, $tests['direct'] );
		$this->assertArrayHasKey( Site_Health::FAILED_TEST, $tests['direct'] );
		$this->assertArrayHasKey( Site_Health::EMBED_TEST, $tests['async'] );
		$this->assertTrue( $tests['async'][ Site_Health::EMBED_TEST ]['has_rest'] );
		$this->assertStringContainsString( 'profotograaf/v1/health/embed', $tests['async'][ Site_Health::EMBED_TEST ]['test'] );
	}

	public function test_it_leaves_a_foreign_value_alone(): void {
		$this->assertSame( 'text', $this->module()->add_tests( 'text' ) );
		$this->assertSame( 'text', $this->module()->add_debug_information( 'text' ) );
	}

	public function test_a_connected_site_is_good(): void {
		$this->pair();

		$result = $this->module()->connection_result();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( Site_Health::CONNECTION_TEST, $result['test'] );
	}

	public function test_a_site_that_never_connected_gets_a_recommendation_with_an_action(): void {
		$result = $this->module()->connection_result();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'options-general.php?page=profotograaf', $result['actions'] );
	}

	public function test_a_revoked_connection_is_critical(): void {
		$this->options['profotograaf_connection'] = array( 'last_error' => 'revoked' );

		$this->assertSame( 'critical', $this->module()->connection_result()['status'] );
	}

	public function test_a_connection_that_needs_reconnecting_is_recommended(): void {
		$this->pair();
		$this->options['profotograaf_connection']['embed_denied'] = true;

		$this->assertSame( 'recommended', $this->module()->connection_result()['status'] );
	}

	public function test_a_fresh_or_recently_expired_token_is_good(): void {
		$this->pair( -600 );

		$this->assertSame( 'good', $this->module()->token_result()['status'] );
	}

	public function test_a_token_expired_for_hours_means_the_refresh_does_not_run(): void {
		$this->pair( -4 * 3600 );

		$result = $this->module()->token_result();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'WP-Cron', $result['description'] );
	}

	public function test_the_token_test_is_good_without_a_connection(): void {
		$this->assertSame( 'good', $this->module()->token_result()['status'] );
	}

	public function test_embed_js_reachable_is_good(): void {
		$this->assertSame( 'good', $this->module()->embed_result()['status'] );
	}

	public function test_embed_js_error_status_is_critical_with_the_address(): void {
		$this->embed_response = 503;

		$result = $this->module()->embed_result();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( '/share/embed/embed.js', $result['description'] );
	}

	public function test_embed_js_network_failure_is_critical(): void {
		$this->embed_response = new \WP_Error( 'http_request_failed', 'timeout' );

		$this->assertSame( 'critical', $this->module()->embed_result()['status'] );
	}

	public function test_an_empty_queue_has_no_backlog_or_failures(): void {
		$module = $this->module();

		$this->assertSame( 'good', $module->backlog_result()['status'] );
		$this->assertSame( 'good', $module->failed_result()['status'] );
	}

	public function test_a_lead_waiting_for_a_while_is_good_and_an_overdue_one_is_recommended(): void {
		$this->queue->enqueue( 'a', array( 'name' => 'Anna' ) );
		$module = $this->module();
		$this->assertSame( 'good', $module->backlog_result()['status'] );

		$this->now += 2 * 3600;

		$result = $module->backlog_result();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '1 lead', $result['description'] );
	}

	public function test_failed_leads_are_critical_and_point_to_the_leads_screen(): void {
		$this->fail_a_lead();

		$result = $this->module()->failed_result();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'page=profotograaf-leads', $result['actions'] );
		$this->assertStringNotContainsString( 'Anna', $result['description'] );
	}

	public function test_the_rest_route_runs_the_embed_test(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $ns . $route ] = $args;
			}
		);
		$module = $this->module();

		$module->register_routes();

		$args = $routes['profotograaf/v1/health/embed'];
		$this->assertSame( array( $module, 'embed_result' ), $args['callback'] );
		$this->assertTrue( $args['permission_callback']() );
	}

	public function test_debug_information_lists_the_facts_for_support(): void {
		$this->pair();
		$this->fail_a_lead();
		$this->queue->enqueue( 'b', array( 'name' => 'Bea' ) );

		$info   = $this->module()->add_debug_information( array() );
		$fields = $info['profotograaf']['fields'];

		$this->assertSame( 'Profotograaf', $info['profotograaf']['label'] );
		foreach ( array( 'plugin_version', 'wp_version', 'php_version', 'connection', 'modules', 'leads_pending', 'leads_failed', 'embed_version', 'wp_cron', 'recent_errors' ) as $key ) {
			$this->assertArrayHasKey( $key, $fields );
		}
		$this->assertSame( '0.0.0-test', $fields['plugin_version']['value'] );
		$this->assertSame( PHP_VERSION, $fields['php_version']['value'] );
		$this->assertSame( 'connected', $fields['connection']['value'] );
		$this->assertSame( 1, $fields['leads_pending']['value'] );
		$this->assertSame( 1, $fields['leads_failed']['value'] );
		$this->assertStringContainsString( 'Site_Health', $fields['modules']['value'] );
	}

	public function test_debug_information_holds_no_tokens_or_personal_data(): void {
		$this->pair( 900, 'secret-access-token-value', 'secret-refresh-token-value' );
		$this->fail_a_lead();
		Logger::error( 'Delivery failed for anna@example.com', array( 'status' => 500 ) );

		$info = $this->module()->add_debug_information( array() );
		$text = (string) json_encode( $info );

		$this->assertStringNotContainsString( 'secret-access', $text );
		$this->assertStringNotContainsString( 'secret-refresh', $text );
		$this->assertStringNotContainsString( 'anna@example.com', $text );
		$this->assertStringNotContainsString( 'Anna', $text );
		$this->assertStringNotContainsString( 'device-1', $text );
		$this->assertStringContainsString( 'Delivery failed', $info['profotograaf']['fields']['recent_errors']['value'] );
	}

	public function test_debug_information_keeps_other_sections(): void {
		$info = $this->module()->add_debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertArrayHasKey( 'wp-core', $info );
	}
}
