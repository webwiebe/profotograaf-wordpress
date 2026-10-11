<?php
/**
 * Backup_Panel: the status on the Backup tab.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Backup_Panel;
use Profotograaf\Api_Client;
use Profotograaf\Backup_Backfill;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Backup_Worker;
use Profotograaf\Connection;
use Profotograaf\Plugin;
use Profotograaf\Settings;

class Backup_Panel_Test extends Wp_Test_Case {

	use Backup_Fixtures;

	/**
	 * Counts per state.
	 *
	 * @var array<string,int>
	 */
	private array $found = array();

	private Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->now = 1790000000;
		$this->set_up_library();
		$this->backup( 'all' );
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 9,
			'complete' => true,
		);
		$connection                               = new Connection();
		$this->plugin                             = new Plugin( $connection, new Api_Client( $connection, new Fake_Transport(), $this->clock() ), $this->clock() );

		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'site_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/' . $path );
		Functions\when( '_n' )->alias( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => number_format( (float) $n ) );
		Functions\when( 'human_time_diff' )->alias( fn( $from, $to ) => (int) ( ( $to - $from ) / 60 ) . ' mins' );
		Functions\when( 'wp_date' )->alias( fn( $format, $time ) => gmdate( 'Y-m-d H:i', $time ) );
		Functions\when( 'size_format' )->alias( fn( $bytes ) => $bytes . ' B' );
		Functions\when( 'get_the_title' )->alias( fn( $id ) => 3 === $id ? '' : 'Photo ' . $id );
		Functions\when( 'get_edit_post_link' )->alias( fn( $id ) => 'https://photos.example.com/wp-admin/post.php?post=' . $id );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			function ( $text ) {
				echo '<button>' . htmlspecialchars( (string) $text ) . '</button>';
			}
		);
	}

	protected function tearDown(): void {
		$this->tear_down_library();
		parent::tearDown();
	}

	private function panel(): Backup_Panel {
		$status = new Backup_Status( new Settings(), new Connection(), new Backup_State( $this->clock() ), $this->clock(), fn() => $this->found, fn() => false );
		return new Backup_Panel( $this->plugin, $status );
	}

	private function render( string $tab = 'backup' ): string {
		ob_start();
		$this->panel()->render( $tab );
		return (string) ob_get_clean();
	}

	public function test_it_shows_only_on_the_backup_tab_while_the_backup_is_on(): void {
		$this->assertSame( '', $this->render( 'general' ) );
		$this->backup( 'all', false );
		$this->assertSame( '', $this->render() );
	}

	public function test_without_a_connection_it_says_nothing_is_copied(): void {
		unset( $this->options['profotograaf_connection'] );

		$html = $this->render();

		$this->assertStringContainsString( 'Nothing is copied while this site is not connected', $html );
		$this->assertStringNotContainsString( 'Copy now', $html );
	}

	public function test_the_status_line_counts_the_files_and_names_the_last_run(): void {
		$this->found                                = array(
			'done'        => 1240,
			'queued'      => 12,
			'uploading'   => 1,
			'failed'      => 2,
			'needs_check' => 1,
			'skipped'     => 5,
		);
		$this->options[ Backup_Status::RUN_OPTION ] = array( 'at' => $this->now - 240 );

		$this->assertSame(
			array(
				'1,240 of 1,256 files copied.',
				'13 files wait to be sent.',
				'3 files wait for you, see the list below.',
				'5 files are not copied. The Copy column in the Media Library list says why.',
				'Last run 4 mins ago.',
			),
			$this->panel()->summary()
		);
	}

	public function test_before_the_first_run_and_during_the_walk_it_says_so(): void {
		$this->found                              = array( 'queued' => 1 );
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 0,
			'complete' => false,
		);

		$this->assertSame(
			array(
				'Still looking through the Media Library for files to copy.',
				'0 of 1 file copied.',
				'1 file waits to be sent.',
				'The first run starts within a few minutes.',
			),
			$this->panel()->summary()
		);
	}

	public function test_the_buttons_bytes_and_failures_show(): void {
		$this->queued( 1, str_repeat( 'x', 2048 ) );
		foreach ( array( 2 => 'failed', 3 => 'needs_check' ) as $id => $state ) {
			$this->attachment( $id );
			$this->meta[ $id ][ Backup_State::META_STATE ]  = $state;
			$this->meta[ $id ][ Backup_State::META_REASON ] = 2 === $id ? 'http_500' : 'no_answer';
		}
		$this->found = array(
			'queued'      => 1,
			'failed'      => 1,
			'needs_check' => 1,
		);

		$html = $this->render();

		$this->assertStringContainsString( '<h2>Copy status</h2>', $html );
		$this->assertStringContainsString( '<button>Copy now</button>', $html );
		$this->assertStringContainsString( 'value="profotograaf_backup_now"', $html );
		$this->assertStringContainsString( '<button>Retry failed</button>', $html );
		$this->assertStringContainsString( '2048 B waits to be sent.', $html );
		$this->assertStringContainsString( '<a href="https://photos.example.com/wp-admin/post.php?post=2">Photo 2</a></td><td>Copy failed</td><td>Profotograaf answered with error 500.</td>', $html );
		$this->assertStringContainsString( '>#3</a></td><td>Copy not confirmed</td>', $html );
		$this->assertStringNotContainsString( 'more file', $html );
	}

	public function test_a_long_failure_list_says_how_many_more_wait(): void {
		$this->attachment( 2 );
		$this->meta[2][ Backup_State::META_STATE ] = 'failed';
		$this->found                               = array( 'failed' => Backup_Panel::FAILURES_SHOWN + 5 );

		$this->assertStringContainsString( 'And ' . ( Backup_Panel::FAILURES_SHOWN + 4 ) . ' more files.', $this->render() );
	}

	public function test_without_failures_there_is_no_retry_and_no_list(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'Retry failed', $html );
		$this->assertStringNotContainsString( 'Files that wait for you', $html );
		$this->assertStringNotContainsString( 'waits to be sent', $html );
	}

	public function test_partial_bytes_say_more_than(): void {
		$this->queued( 1, 'abcd' );
		$this->found = array( 'queued' => Backup_Status::BYTES_SCAN + 1 );

		$this->assertStringContainsString( 'More than 4 B waits to be sent.', $this->render() );
	}

	public function test_a_missing_permission_asks_to_connect_again(): void {
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read' );

		$this->assertStringContainsString( 'Connect this site again', $this->render() );
	}

	public function test_problems_name_a_full_storage_a_wait_no_cron_and_a_stuck_queue(): void {
		$this->options[ Backup_Worker::PAUSE ] = array(
			'reason' => 'storage_full',
			'until'  => $this->now + 3600,
		);
		$problems                              = $this->panel()->problems();
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'storage is full, so copying is paused until 2026-09-21', $problems[0] );

		$this->options[ Backup_Worker::PAUSE ]['reason'] = 'backoff';
		$this->assertStringContainsString( 'It tries again on its own at', $this->panel()->problems()[0] );

		unset( $this->options[ Backup_Worker::PAUSE ] );
		$this->options[ Backup_Status::RUN_OPTION ] = array( 'at' => $this->now - Backup_Status::STALE_AFTER - 1 );
		$problems                                   = $this->panel()->problems();
		$this->assertStringContainsString( 'WP-Cron does not seem to run', $problems[0] );
		$this->assertStringContainsString( '<code>*/5 * * * * curl', $problems[0] );

		$this->found                                = array( 'queued' => 3 );
		$this->options[ Backup_Status::RUN_OPTION ] = array(
			'at'         => $this->now - 60,
			'idle_since' => $this->now - Backup_Status::STUCK_AFTER - 1,
		);
		$this->assertStringContainsString( 'No file was sent for more than six hours', $this->render() );
	}

	public function test_the_url_opens_the_backup_tab(): void {
		$this->assertSame( 'https://photos.example.com/wp-admin/options-general.php?page=profotograaf&tab=backup', Backup_Panel::url() );
	}
}
