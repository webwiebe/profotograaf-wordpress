<?php
/**
 * Uninstall tests: the data of every subsite is removed.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;

/**
 * Runs uninstall.php in its own process, because the script defines
 * WP_UNINSTALL_PLUGIN and declares a function.
 */
class Uninstall_Test extends Wp_Test_Case {

	/**
	 * Options per site id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sites = array();

	private int $current = 1;

	/**
	 * Arguments the get_sites call received.
	 *
	 * @var array<string,mixed>
	 */
	private array $query = array();

	/**
	 * Cron hooks cleared, as "site:hook".
	 *
	 * @var array<int,string>
	 */
	private array $cleared = array();

	private function stub_database(): void {
		global $wpdb;
		$test = $this;
		$wpdb = new class( $test ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- uninstall.php reads the global.
			public string $options = 'wp_options';

			private object $test;

			public function __construct( object $test ) {
				$this->test = $test;
			}

			public function prepare( string $query, ...$args ): string {
				return $query . '|' . implode( '|', $args );
			}

			public function esc_like( string $text ): string {
				return $text;
			}

			public function get_var( string $query ): int {
				return 0;
			}

			public function get_col( string $query ): array {
				return $this->test->names( $query );
			}
		};
	}

	/**
	 * Option names of the current site that the prepared query asks for.
	 *
	 * @param string $query Prepared query.
	 * @return array<int,string>
	 */
	public function names( string $query ): array {
		$prefix = substr( $query, strrpos( $query, '|' ) + 1 );
		$prefix = rtrim( $prefix, '%' );
		return array_values( array_filter( array_keys( $this->sites[ $this->current ] ), fn( $name ) => 0 === strpos( $name, $prefix ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_removes_the_data_of_every_subsite_including_beyond_the_first_hundred(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'profotograaf/profotograaf.php' );
		$this->stub_database();
		$this->sites = array();
		for ( $id = 1; $id <= 150; $id++ ) {
			$this->sites[ $id ] = array(
				'profotograaf_settings'         => array( 'media_source' => true ),
				'profotograaf_connection'       => array( 'access_token' => 'x' ),
				'profotograaf_lead_job_abc'     => '{"status":"queued"}',
				'profotograaf_import_lock_0123' => 1000000,
				'blogname'                      => 'Site ' . $id,
			);
		}

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			function ( $args ) {
				$this->query = $args;
				$limit       = ! empty( $args['number'] ) ? (int) $args['number'] : PHP_INT_MAX;
				return array_slice( array_keys( $this->sites ), 0, $limit );
			}
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) {
				$this->current = (int) $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->justReturn( true );
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->sites[ $this->current ][ $name ] ?? $fallback );
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->sites[ $this->current ][ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( '_get_cron_array' )->justReturn( array( 1 => array( 'profotograaf_refresh_tokens' => array() ) ) );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = $this->current . ':' . $hook;
				return 1;
			}
		);

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( 0, $this->query['number'] );
		foreach ( $this->sites as $id => $options ) {
			$this->assertSame( array( 'blogname' ), array_keys( $options ), "site $id keeps only WordPress options" );
		}
		$this->assertContains( '150:profotograaf_refresh_tokens', $this->cleared );
	}
}
