<?php
/**
 * Admin notice tests: the three states, per user dismissal and recovery.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Admin_Notices;
use Profotograaf\Plugin;

/**
 * Admin_Notices with the queue, the screen and the redirect replaced.
 */
class Stubbed_Admin_Notices extends Admin_Notices {

	public int $failed = 0;

	public string $screen = '';

	public int $finished = 0;

	protected function failed_leads(): int {
		return $this->failed;
	}

	protected function screen_id(): string {
		return $this->screen;
	}

	protected function finish(): void {
		++$this->finished;
	}

	protected function now(): int {
		return 5000;
	}
}

class Admin_Notices_Test extends Wp_Test_Case {

	private Stubbed_Admin_Notices $notices;

	private Connection $connection;

	/**
	 * User meta by user id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	private int $user = 7;

	private bool $can = true;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = new Connection();
		$plugin           = new Plugin( $this->connection, new Api_Client( $this->connection, new Fake_Transport(), $this->clock() ), $this->clock() );
		$this->notices    = new Stubbed_Admin_Notices();
		$this->notices->register( $plugin );

		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url . '&_wpnonce=abc' );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user );
		Functions\when( 'current_user_can' )->alias( fn() => $this->can );
		Functions\when( 'get_user_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( '_n' )->alias( fn( $one, $many, $count ) => 1 === $count ? $one : $many );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => (string) $value );
		Functions\when( 'wp_unslash' )->alias( fn( $value ) => $value );
	}

	private function html(): string {
		ob_start();
		$this->notices->render();
		return (string) ob_get_clean();
	}

	private function revoke(): void {
		$this->connection->clear( 'The connection was ended.' );
		$this->notices->on_disconnected( 'revoked' );
	}

	public function test_a_revoked_connection_shows_one_notice_with_a_fix_link(): void {
		$this->revoke();

		$html = $this->html();

		$this->assertSame( 1, substr_count( $html, 'profotograaf-notice--revoked' ) );
		$this->assertStringContainsString( 'options-general.php?page=profotograaf"', $html );
		$this->assertStringContainsString( 'Connect this site again', $html );
	}

	public function test_a_user_disconnect_shows_nothing(): void {
		$this->connect();
		$this->connection->clear();
		$this->notices->on_disconnected( 'user' );

		$this->assertSame( '', $this->html() );
	}

	public function test_a_connected_site_without_problems_shows_nothing(): void {
		$this->connect();

		$this->assertSame( '', $this->html() );
	}

	public function test_the_revoked_notice_clears_after_connecting_again(): void {
		$this->revoke();
		$this->connect();
		$this->notices->on_connected();

		$this->assertSame( '', $this->html() );
	}

	public function test_a_failed_refresh_shows_a_notice_until_a_refresh_succeeds(): void {
		$this->connect();
		$this->notices->on_refresh_failed();

		$this->assertStringContainsString( 'profotograaf-notice--refresh', $this->html() );

		$this->connection->save_tokens( array( 'access_token' => 'a2', 'refresh_token' => 'r2' ), $this->now + 3600 );

		$this->assertSame( '', $this->html() );
	}

	public function test_repeated_refresh_failures_keep_the_first_episode(): void {
		$this->connect();
		$this->notices->on_refresh_failed();
		$first = $this->options[ Admin_Notices::EPISODES_OPTION ];
		$this->notices->on_refresh_failed();

		$this->assertSame( $first, $this->options[ Admin_Notices::EPISODES_OPTION ] );
		$this->assertFalse( $this->autoload[ Admin_Notices::EPISODES_OPTION ] );
	}

	public function test_failed_leads_show_a_notice_with_the_count_and_clear_at_zero(): void {
		$this->notices->failed = 2;

		$html = $this->html();

		$this->assertStringContainsString( '2 enquiries could not be delivered', $html );
		$this->assertStringContainsString( 'page=profotograaf-leads', $html );

		$this->notices->failed = 0;
		$this->assertSame( '', $this->html() );
	}

	public function test_a_notice_is_left_out_on_the_screen_that_fixes_it(): void {
		$this->notices->failed = 1;
		$this->notices->screen = 'settings_page_profotograaf-leads';

		$this->assertSame( '', $this->html() );
	}

	public function test_users_without_the_capability_see_nothing(): void {
		$this->notices->failed = 1;
		$this->can             = false;

		$this->assertSame( '', $this->html() );
	}

	public function test_dismissal_is_per_user(): void {
		$this->notices->failed = 1;
		$this->notices->dismiss( Admin_Notices::LEADS, 'leads:1' );

		$this->assertSame( '', $this->html() );

		$this->user = 8;
		$this->assertStringContainsString( 'profotograaf-notice--leads', $this->html() );
	}

	public function test_dismissal_resets_when_the_state_changes(): void {
		$this->notices->failed = 1;
		$this->notices->dismiss( Admin_Notices::LEADS, 'leads:1' );
		$this->notices->failed = 2;

		$this->assertStringContainsString( 'profotograaf-notice--leads', $this->html() );
	}

	public function test_a_second_revocation_shows_the_notice_again(): void {
		$this->revoke();
		$this->notices->dismiss( Admin_Notices::REVOKED, 'revoked:5000' );
		$this->assertSame( '', $this->html() );

		$this->connect();
		$this->notices->on_connected();
		$this->options[ Admin_Notices::EPISODES_OPTION ] = array( 'revoked' => array( 'since' => 9000 ) );
		$this->connection->clear( 'Ended again.' );

		$this->assertStringContainsString( 'profotograaf-notice--revoked', $this->html() );
	}

	public function test_the_dismiss_handler_stores_the_signature_and_redirects(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$_GET = array(
			'kind'      => 'leads',
			'signature' => 'leads:3',
		);

		$this->notices->handle_dismiss();

		$this->assertSame( array( 'leads' => 'leads:3' ), $this->meta[7][ Admin_Notices::DISMISS_META ] );
		$this->assertSame( 1, $this->notices->finished );
		$_GET = array();
	}

	public function test_the_dismiss_handler_ignores_an_unknown_kind(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$_GET = array(
			'kind'      => 'bogus',
			'signature' => 'x',
		);

		$this->notices->handle_dismiss();

		$this->assertArrayNotHasKey( 7, $this->meta );
		$_GET = array();
	}

	public function test_the_dismiss_handler_refuses_a_user_without_the_capability(): void {
		$this->can = false;
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->expectExceptionMessage( 'wp_die' );
		$this->notices->handle_dismiss();
	}
}
