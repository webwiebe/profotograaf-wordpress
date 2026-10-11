<?php
/**
 * Site Health tests of the backup: permission, cron and the queue.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Backup_Health;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Backup_Worker;
use Profotograaf\Connection;
use Profotograaf\Settings;

class Backup_Health_Test extends Wp_Test_Case {

	/**
	 * Counts per state.
	 *
	 * @var array<string,int>
	 */
	private array $found = array();

	private bool $cron_disabled = false;

	protected function setUp(): void {
		parent::setUp();
		$this->now                         = 1790000000;
		$this->options[ Settings::OPTION ] = array(
			'backup'       => true,
			'backup_scope' => 'all',
		);
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		$this->options[ Backup_Status::RUN_OPTION ] = array( 'at' => $this->now - 60 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'site_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/' . $path );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( '_n' )->alias( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
	}

	private function health(): Backup_Health {
		$status = new Backup_Status( new Settings(), new Connection(), new Backup_State(), $this->clock(), fn() => $this->found, fn() => $this->cron_disabled );
		return new Backup_Health( new Settings(), $status );
	}

	public function test_the_three_tests_are_added_only_while_the_backup_is_on(): void {
		$tests = $this->health()->add_tests( array( 'direct' => array() ) );
		$this->assertSame( array( Backup_Health::PERMISSION_TEST, Backup_Health::CRON_TEST, Backup_Health::QUEUE_TEST ), array_keys( $tests['direct'] ) );

		$this->options[ Settings::OPTION ]['backup'] = false;
		$this->assertSame( array( 'direct' => array() ), $this->health()->add_tests( array( 'direct' => array() ) ) );
		$this->assertSame( 'junk', $this->health()->add_tests( 'junk' ) );
	}

	public function test_permission_is_good_with_galleries_write(): void {
		$result = $this->health()->permission_result();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( Backup_Health::PERMISSION_TEST, $result['test'] );
		$this->assertSame( 'blue', $result['badge']['color'] );
	}

	public function test_a_missing_permission_is_critical_and_says_to_connect_again(): void {
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed' );

		$result = $this->health()->permission_result();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'connect this site again', $result['description'] );
		$this->assertStringContainsString( 'page=profotograaf', $result['actions'] );
	}

	public function test_without_a_connection_the_backup_waits(): void {
		unset( $this->options['profotograaf_connection'] );

		$result = $this->health()->permission_result();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'The Profotograaf backup waits for a connection', $result['label'] );
	}

	public function test_cron_is_good_after_a_recent_run_or_while_inactive(): void {
		$this->assertSame( 'good', $this->health()->cron_result()['status'] );

		$this->options[ Backup_Status::RUN_OPTION ] = array( 'at' => 1 );
		unset( $this->options['profotograaf_connection'] );
		$this->assertSame( 'good', $this->health()->cron_result()['status'] );
	}

	public function test_a_stale_run_is_recommended_and_critical_while_files_wait(): void {
		$this->options[ Backup_Status::RUN_OPTION ] = array( 'at' => $this->now - Backup_Status::STALE_AFTER - 1 );

		$result = $this->health()->cron_result();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'has not run for more than an hour', $result['description'] );
		$this->assertStringContainsString( '*/5 * * * * curl', $result['actions'] );
		$this->assertStringContainsString( 'wp profotograaf backup run', $result['actions'] );

		$this->found = array( 'queued' => 2 );
		$this->assertSame( 'critical', $this->health()->cron_result()['status'] );
	}

	public function test_disabled_wp_cron_is_named(): void {
		unset( $this->options[ Backup_Status::RUN_OPTION ] );
		$this->cron_disabled = true;

		$this->assertStringContainsString( 'DISABLE_WP_CRON', $this->health()->cron_result()['description'] );
	}

	public function test_a_full_storage_is_critical(): void {
		$this->options[ Backup_Worker::PAUSE ] = array(
			'reason' => 'storage_full',
			'until'  => $this->now + 600,
		);

		$result = $this->health()->queue_result();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'Your Profotograaf storage is full', $result['label'] );
		$this->assertStringContainsString( 'tab=backup', $result['actions'] );
	}

	public function test_a_stuck_queue_is_recommended(): void {
		$this->found                                = array( 'queued' => 4 );
		$this->options[ Backup_Status::RUN_OPTION ] = array(
			'at'         => $this->now - 60,
			'idle_since' => $this->now - Backup_Status::STUCK_AFTER - 1,
		);

		$result = $this->health()->queue_result();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'The Profotograaf backup queue is stuck', $result['label'] );
		$this->assertStringContainsString( '4 files wait', $result['description'] );
	}

	public function test_files_that_wait_for_the_photographer_are_recommended(): void {
		$this->found = array(
			'failed'      => 1,
			'needs_check' => 1,
		);

		$result = $this->health()->queue_result();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '2 files could not be backed up', $result['description'] );
		$this->assertStringContainsString( 'Retry failed', $result['actions'] );
	}

	public function test_a_moving_queue_is_good(): void {
		$this->found = array( 'queued' => 4 );

		$this->assertSame( 'good', $this->health()->queue_result()['status'] );
	}
}
