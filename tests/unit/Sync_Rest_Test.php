<?php
/**
 * Check now route, the background check and the sync panel.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Change_Poller;
use Profotograaf\Modules\Sync_Check;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Settings;
use Profotograaf\Sync_Panel;
use Profotograaf\Sync_Rest;

class Sync_Rest_Test extends Gallery_Test_Case {

	private Sync_Rest $rest;

	protected function setUp(): void {
		parent::setUp();
		$this->connect();
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'human_time_diff' )->alias( fn( $from, $to ) => max( 1, (int) round( ( $to - $from ) / 60 ) ) . ' minutes' );
		$poller     = new Change_Poller( $this->api, new Photo_Catalogue( $this->api, new Settings(), $this->clock() ) );
		$this->rest = new Sync_Rest( $poller, $this->plugin->connection() );
	}

	/**
	 * A request carrying the given params.
	 *
	 * @param array<string,mixed> $params Params.
	 */
	private function request( array $params = array() ): object {
		return new class( $params ) {

			/**
			 * @param array<string,mixed> $params Params.
			 */
			public function __construct( private array $params ) {}

			/**
			 * @param string $name Name.
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}
		};
	}

	private function feed(): void {
		$this->http->reply(
			200,
			array(
				'cursor'    => 'c-2',
				'has_more'  => false,
				'resync'    => false,
				'galleries' => array(
					'created' => array(),
					'updated' => array(),
					'deleted' => array(),
				),
				'photos'    => array(
					'created' => array(),
					'updated' => array(),
					'deleted' => array(),
				),
			)
		);
	}

	public function test_check_now_runs_one_poll_and_reports_the_last_check(): void {
		$this->feed();

		$answer = $this->rest->run( $this->request() );

		$this->assertCount( 1, $this->http->requests );
		$this->assertTrue( $answer['ran'] );
		$this->assertTrue( $answer['ok'] );
		$this->assertGreaterThan( 0, $answer['lastChecked'] );
		$this->assertSame( 'Photo changes last checked 1 minutes ago.', $answer['lastCheckedText'] );
	}

	public function test_check_now_reports_a_failed_poll(): void {
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$answer = $this->rest->run( $this->request() );

		$this->assertTrue( $answer['ran'] );
		$this->assertFalse( $answer['ok'] );
		$this->assertNotSame( '', $answer['error'] );
		$this->assertSame( 0, $answer['lastChecked'] );
		$this->assertSame( 'Photo changes have not been checked yet.', $answer['lastCheckedText'] );
	}

	public function test_check_now_reports_a_run_that_is_already_busy(): void {
		$this->options[ Change_Poller::OPTION_LOCK ] = time();

		$answer = $this->rest->run( $this->request() );

		$this->assertTrue( $answer['busy'] );
		$this->assertFalse( $answer['ok'] );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_check_now_needs_a_connection(): void {
		unset( $this->options['profotograaf_connection'] );

		$answer = $this->rest->run( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( 'profotograaf_not_connected', $answer->code );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_a_background_check_runs_when_the_last_success_is_old(): void {
		$this->options[ Change_Poller::OPTION_LAST_OK ] = time() - Sync_Rest::BACKGROUND_AFTER - 60;
		$this->feed();

		$answer = $this->rest->run( $this->request( array( 'background' => true ) ) );

		$this->assertTrue( $answer['ran'] );
		$this->assertCount( 1, $this->http->requests );
		$this->assertArrayHasKey( Sync_Rest::THROTTLE, $this->transients );
	}

	public function test_a_background_check_is_skipped_when_the_last_success_is_recent(): void {
		$this->options[ Change_Poller::OPTION_LAST_OK ] = time() - 600;

		$answer = $this->rest->run( $this->request( array( 'background' => true ) ) );

		$this->assertFalse( $answer['ran'] );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_background_checks_are_throttled(): void {
		$this->feed();
		$this->rest->run( $this->request( array( 'background' => true ) ) );
		$this->options[ Change_Poller::OPTION_LAST_OK ] = time() - Sync_Rest::BACKGROUND_AFTER - 60;

		$answer = $this->rest->run( $this->request( array( 'background' => true ) ) );

		$this->assertFalse( $answer['ran'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_manual_check_ignores_the_throttle_and_the_age(): void {
		$this->options[ Change_Poller::OPTION_LAST_OK ] = time() - 60;
		$this->transients[ Sync_Rest::THROTTLE ]        = 1;
		$this->feed();

		$this->assertTrue( $this->rest->run( $this->request() )['ran'] );
	}

	public function test_the_route_needs_upload_files(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'upload_files' )->andReturn( true );

		$this->assertTrue( $this->rest->can_run() );
	}

	public function test_the_route_is_a_post_on_sync_run(): void {
		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route, $args ) use ( &$registered ) {
				$registered[ $ns . $route ] = $args;
				return true;
			}
		);

		$this->rest->register_routes();

		$this->assertSame( 'POST', $registered['profotograaf/v1/sync/run']['methods'] );
		$this->assertSame( array( $this->rest, 'can_run' ), $registered['profotograaf/v1/sync/run']['permission_callback'] );
	}

	public function test_background_due_needs_a_connection_and_an_old_or_missing_success(): void {
		$now = time();

		$this->assertTrue( Sync_Rest::background_due( true, $now ) );
		$this->assertFalse( Sync_Rest::background_due( false, $now ) );

		$this->options[ Change_Poller::OPTION_LAST_OK ] = $now - 60;
		$this->assertFalse( Sync_Rest::background_due( true, $now ) );
	}

	public function test_the_panel_shows_the_last_check_and_the_button_only_while_connected(): void {
		$this->options[ Change_Poller::OPTION_LAST_OK ] = time() - 300;

		ob_start();
		Sync_Panel::render( $this->plugin->connection() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Photo changes last checked 5 minutes ago.', $html );
		$this->assertStringContainsString( 'profotograaf-sync__button', $html );
		$this->assertStringContainsString( 'Check now', $html );

		unset( $this->options['profotograaf_connection'] );
		ob_start();
		Sync_Panel::render( $this->plugin->connection() );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_script_loads_on_the_admin_screens_only_and_asks_for_a_background_run_when_stale(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $name ] = $data;
				return true;
			}
		);
		$module = new Sync_Check();
		$module->register( $this->plugin );

		$module->enqueue( 'dashboard' );
		$this->assertArrayNotHasKey( 'profotograaf-sync', $this->enqueued );

		foreach ( array( 'upload.php', 'media_page_profotograaf-import', 'settings_page_profotograaf' ) as $screen ) {
			$this->enqueued = array();
			$module->enqueue( $screen );
			$this->assertArrayHasKey( 'profotograaf-sync', $this->enqueued, $screen );
		}
		$this->assertSame( array( 'background' => true ), $localized['profotograafSync'] );

		$this->options[ Change_Poller::OPTION_LAST_OK ] = time() - 60;
		$module->enqueue( 'upload.php' );
		$this->assertSame( array( 'background' => false ), $localized['profotograafSync'] );
	}

	public function test_the_script_does_not_load_without_upload_files(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$module = new Sync_Check();
		$module->register( $this->plugin );

		$module->enqueue( 'upload.php' );

		$this->assertArrayNotHasKey( 'profotograaf-sync', $this->enqueued );
	}
}
