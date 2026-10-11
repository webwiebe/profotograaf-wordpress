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

			public string $postmeta = 'wp_postmeta';

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
				return $this->test->count_rows( $query );
			}

			public function get_col( string $query ): array {
				return $this->test->names( $query );
			}

			public function query( string $query ): int {
				return $this->test->delete_meta( $query );
			}
		};
	}

	/**
	 * Post meta keys per site id.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $meta = array();

	/**
	 * Deletes the post meta of the current site whose key starts with the prefix in the prepared query.
	 *
	 * @param string $query Prepared query.
	 */
	public function delete_meta( string $query ): int {
		$this->assertStringStartsWith( 'DELETE FROM wp_postmeta WHERE meta_key LIKE %s|', $query );
		$prefix                       = rtrim( substr( $query, strrpos( $query, '|' ) + 1 ), '%' );
		$before                       = $this->meta[ $this->current ] ?? array();
		$this->meta[ $this->current ] = array_values( array_filter( $before, fn( $key ) => 0 !== strpos( $key, $prefix ) ) );
		return count( $before ) - count( $this->meta[ $this->current ] );
	}

	/**
	 * Virtual attachments per site id.
	 *
	 * @var array<int,int>
	 */
	private array $virtual = array();

	/**
	 * Mails sent, as [ to, subject, body ].
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $mails = array();

	/**
	 * Answers a COUNT query: the virtual attachments of the current site, 0 for anything else.
	 *
	 * @param string $query Prepared query.
	 */
	public function count_rows( string $query ): int {
		if ( str_contains( $query, 'FROM wp_postmeta WHERE meta_key = %s AND meta_value = %s|_profotograaf_virtual|1' ) ) {
			return $this->virtual[ $this->current ] ?? 0;
		}
		return 0;
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
				'profotograaf_upload_links'     => array( array( 'gallery_id' => 'g1' ) ),
				'profotograaf_upload_lock'      => 1000000,
				'blogname'                      => 'Site ' . $id,
			);
			$this->meta[ $id ]  = array( '_profotograaf_backup_state', '_profotograaf_backup_photo_id', '_profotograaf_photo_id', '_wp_attached_file' );
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
		Functions\when( '_get_cron_array' )->justReturn( array( 1 => array( 'profotograaf_refresh_tokens' => array(), 'profotograaf_upload_queue' => array() ) ) );
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
		foreach ( $this->meta as $id => $keys ) {
			$this->assertSame( array( '_profotograaf_photo_id', '_wp_attached_file' ), $keys, "site $id loses only the backup state" );
		}
		$this->assertContains( '150:profotograaf_refresh_tokens', $this->cleared );
		$this->assertContains( '150:profotograaf_upload_queue', $this->cleared );
	}

	/**
	 * Runs uninstall.php on one site with three photos kept on Profotograaf.
	 *
	 * @param bool $keep Whether the data is kept on uninstall.
	 */
	private function uninstall_with_virtual_attachments( bool $keep ): void {
		define( 'WP_UNINSTALL_PLUGIN', 'profotograaf/profotograaf.php' );
		$this->stub_database();
		$this->sites   = array(
			1 => array(
				'profotograaf_settings'     => array( 'keep_data_on_uninstall' => $keep ),
				'profotograaf_virtual_bulk' => array( 'status' => 'done' ),
				'admin_email'               => 'owner@example.com',
			),
		);
		$this->virtual = array( 1 => 3 );
		$this->meta    = array( 1 => array( '_profotograaf_virtual', '_profotograaf_virtual_urls', '_profotograaf_photo_id', '_profotograaf_backup_state' ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->sites[ $this->current ][ $name ] ?? $fallback );
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->sites[ $this->current ][ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( '_get_cron_array' )->justReturn( array( 1 => array( 'profotograaf_virtual_bulk_convert' => array() ) ) );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = $this->current . ':' . $hook;
				return 1;
			}
		);
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mails[] = array( $to, $subject, $body );
				return true;
			}
		);

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_keeps_virtual_attachments_and_tells_the_admin_they_lost_their_image(): void {
		$this->uninstall_with_virtual_attachments( false );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.com', $this->mails[0][0] );
		$this->assertSame( '[Example Photography] Photos kept on Profotograaf no longer show', $this->mails[0][1] );
		$this->assertStringContainsString( '3 photos in the Media Library were kept on Profotograaf and have no copy on this site', $this->mails[0][2] );
		$this->assertStringContainsString( 'install and connect the Profotograaf plugin again and use Convert to local', $this->mails[0][2] );
		$this->assertSame( array( '_profotograaf_virtual', '_profotograaf_virtual_urls', '_profotograaf_photo_id' ), $this->meta[1], 'The virtual marks stay, so a reinstall can convert them.' );
		$this->assertSame( array( 'admin_email' ), array_keys( $this->sites[1] ), 'The bulk job state goes with the other options.' );
		$this->assertContains( '1:profotograaf_virtual_bulk_convert', $this->cleared );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_that_keeps_the_data_still_tells_the_admin_about_virtual_attachments(): void {
		$this->uninstall_with_virtual_attachments( true );

		$this->assertCount( 1, $this->mails );
		$this->assertCount( 4, $this->meta[1] );
		$this->assertArrayHasKey( 'profotograaf_virtual_bulk', $this->sites[1] );
	}
}
