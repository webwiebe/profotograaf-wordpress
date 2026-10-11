<?php
/**
 * Virtual attachments on deactivation and uninstall: the bulk conversion,
 * the Plugins screen row and the WP-CLI command (issue #158).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Cli\Virtual_Command;
use Profotograaf\Logger;
use Profotograaf\Modules\Virtual_Convert;
use Profotograaf\Modules\Virtual_Removal;
use Profotograaf\Multisite;
use Profotograaf\Virtual_Attachments;
use Profotograaf\Virtual_Bulk_Convert;

class Virtual_Removal_Test extends Gallery_Test_Case {

	/**
	 * Capabilities the current user has.
	 *
	 * @var array<int,string>
	 */
	public array $caps = array( 'upload_files', 'edit_post', 'activate_plugins' );

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $meta = array();

	/**
	 * Single events scheduled, as hook names.
	 *
	 * @var array<int,string>
	 */
	private array $scheduled = array();

	/**
	 * Users set as current, in order.
	 *
	 * @var array<int,int>
	 */
	private array $users = array();

	private Fake_Virtual_Convert $convert;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $at, $hook ) {
				$this->scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_set_current_user' )->alias(
			function ( $id ) {
				$this->users[] = (int) $id;
				return null;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'plugin_basename' )->justReturn( 'profotograaf/profotograaf.php' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		$this->stub_count();
		$this->convert = new Fake_Virtual_Convert( $this );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Answers the count query from the marked attachments in $meta.
	 */
	private function stub_count(): void {
		$test            = $this;
		$GLOBALS['wpdb'] = new class( $test ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the count reads the global.
			public string $postmeta = 'wp_postmeta';

			/**
			 * Queries asked.
			 *
			 * @var array<int,string>
			 */
			public array $queries = array();

			private Virtual_Removal_Test $test;

			public function __construct( Virtual_Removal_Test $test ) {
				$this->test = $test;
			}

			public function prepare( string $query, ...$args ): string {
				return $query . '|' . implode( '|', $args );
			}

			public function get_var( string $query ): int {
				$this->queries[] = $query;
				return count( $this->test->marked_ids() );
			}
		};
	}

	/**
	 * Ids of attachments with the virtual mark.
	 *
	 * @return array<int,int>
	 */
	public function marked_ids(): array {
		return array_keys( array_filter( $this->meta, fn( $meta ) => '1' === ( $meta[ Virtual_Attachments::META_VIRTUAL ] ?? '' ) ) );
	}

	/**
	 * Marks attachments as virtual.
	 *
	 * @param int ...$ids Attachment ids.
	 */
	private function mark( int ...$ids ): void {
		foreach ( $ids as $id ) {
			$this->meta[ $id ] = array( Virtual_Attachments::META_VIRTUAL => '1' );
		}
	}

	private function job(): Virtual_Bulk_Convert {
		return new Virtual_Bulk_Convert( $this->convert );
	}

	/**
	 * What a callback printed.
	 *
	 * @param callable $callback Callback.
	 */
	private function printed( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	public function test_the_count_reads_the_virtual_mark_with_one_query(): void {
		$this->mark( 1, 2 );
		$this->meta[3] = array( '_profotograaf_photo_id' => 'p-3' );

		$this->assertSame( 2, Virtual_Bulk_Convert::count() );
		$this->assertSame( array( 'SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = %s AND meta_value = %s|_profotograaf_virtual|1' ), $GLOBALS['wpdb']->queries );
	}

	public function test_start_stores_the_job_and_schedules_one_batch(): void {
		$job = $this->job();

		$this->assertTrue( $job->start( 7 ) );

		$this->assertSame( array( Virtual_Bulk_Convert::HOOK ), $this->scheduled );
		$this->assertSame( 'running', $job->state()['status'] );
		$this->assertSame( 7, $job->state()['user'] );
		$this->assertFalse( $this->autoload[ Virtual_Bulk_Convert::OPTION ] );
		$this->assertStringStartsWith( 'profotograaf_', Virtual_Bulk_Convert::OPTION, 'Uninstall removes it with the prefix.' );
		$this->assertStringStartsWith( 'profotograaf_', Virtual_Bulk_Convert::HOOK, 'Deactivation and uninstall clear it with the prefix.' );

		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		$this->assertFalse( $job->start( 8 ), 'A running job keeps going.' );
		$this->assertSame( 7, $job->state()['user'] );
		$this->assertCount( 1, $this->scheduled );
	}

	public function test_each_run_converts_one_batch_as_the_user_who_started_it_until_none_is_left(): void {
		$this->mark( ...range( 1, 25 ) );
		$this->convert->failing = array( 3, 14 );
		$job                    = $this->job();
		$job->start( 7 );

		$runs = 0;
		while ( $job->running() && $runs < 10 ) {
			$job->run();
			++$runs;
		}

		$this->assertSame( 4, $runs, 'Three batches of at most ten, then one run that finds nothing left.' );
		$this->assertSame( array( 10, 10, 5 ), $this->convert->batches );
		$this->assertSame( array( 7, 7, 7, 7 ), $this->users );
		$this->assertSame( array( 3, 14 ), $this->marked_ids(), 'Failed attachments stay virtual.' );
		$state = $job->state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 23, $state['converted'] );
		$this->assertSame( array( 3, 14 ), $state['failed_ids'] );
		$this->assertSame( 'Photo 3', $state['failures'][0]['title'] );
		$this->assertCount( 4, $this->scheduled, 'The start plus one per batch.' );

		$job->run();
		$this->assertCount( 3, $this->convert->batches, 'A finished job does nothing.' );
	}

	public function test_a_run_stops_when_the_user_may_no_longer_upload_files(): void {
		$this->mark( 1 );
		$job = $this->job();
		$job->start( 7 );
		$this->caps = array();

		$job->run();

		$this->assertSame( 'stopped', $job->state()['status'] );
		$this->assertSame( array(), $this->convert->batches );
		$this->assertSame( array( 1 ), $this->marked_ids() );
	}

	public function test_a_running_job_that_lost_its_event_is_scheduled_again(): void {
		$job = $this->job();
		$job->resume();
		$this->assertSame( array(), $this->scheduled, 'No job, nothing to resume.' );

		$job->start( 7 );
		$this->scheduled = array();
		$job->resume();
		$this->assertSame( array( Virtual_Bulk_Convert::HOOK ), $this->scheduled );

		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		$job->resume();
		$this->assertCount( 1, $this->scheduled, 'An event that is still there is left alone.' );
	}

	public function test_deactivation_clears_the_batch_event_and_keeps_the_virtual_attachments(): void {
		$this->mark( 1 );
		$this->job()->start( 7 );
		$cleared = array();
		Functions\when( '_get_cron_array' )->justReturn( array( 1 => array( Virtual_Bulk_Convert::HOOK => array() ) ) );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return 1;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );

		( new Multisite() )->deactivate( false );

		$this->assertContains( Virtual_Bulk_Convert::HOOK, $cleared );
		$this->assertSame( array( 1 ), $this->marked_ids(), 'Deactivation changes no attachment.' );
		$this->assertSame( 'running', $this->job()->state()['status'], 'The job resumes after the plugin is activated again.' );
	}

	public function test_the_module_registers_the_row_the_start_action_the_batch_and_the_resume(): void {
		$module = new Virtual_Removal( $this->job() );
		$module->register( $this->plugin );

		$this->assertNotFalse( has_action( 'after_plugin_row_profotograaf/profotograaf.php', array( $module, 'plugin_row' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_convert_all', array( $module, 'handle_start' ) ) );
		$this->assertNotFalse( has_action( Virtual_Bulk_Convert::HOOK, array( $module, 'run' ) ) );
		$this->assertNotFalse( has_action( 'load-plugins.php', array( $module, 'resume' ) ) );
	}

	public function test_the_module_runs_and_resumes_the_job(): void {
		$this->mark( 1 );
		$module = new Virtual_Removal( $this->job() );
		$this->job()->start( 7 );
		$this->scheduled = array();

		$module->resume();
		$module->run();

		$this->assertSame( array( Virtual_Bulk_Convert::HOOK, Virtual_Bulk_Convert::HOOK ), $this->scheduled );
		$this->assertSame( array(), $this->marked_ids() );
	}

	public function test_the_plugin_row_offers_the_conversion_while_virtual_attachments_exist(): void {
		$module = new Virtual_Removal( $this->job() );
		$this->assertSame( '', $this->printed( array( $module, 'plugin_row' ) ), 'Nothing kept, no row.' );

		$this->mark( 1, 2 );
		$html = $this->printed( array( $module, 'plugin_row' ) );

		$this->assertStringContainsString( 'profotograaf-virtual-row', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( '2 photos are kept on Profotograaf and have no copy on this site. They show no image while this plugin is deactivated or after you delete it. Convert them to local first.', $html );
		$this->assertStringContainsString( '<a class="button button-primary profotograaf-convert-all" href="https://site.test/wp-admin/admin-post.php?action=profotograaf_convert_all&amp;_wpnonce=profotograaf_convert_all">Convert all to local</a>', $html );
		$this->assertStringContainsString( 'run wp profotograaf virtual convert instead.', $html );
		$this->assertStringContainsString( 'colspan="4"', $html );
	}

	public function test_the_plugin_row_shows_progress_failures_and_the_result(): void {
		$job    = $this->job();
		$module = new Virtual_Removal( $job );
		$this->mark( 1, 2, 3 );
		$this->convert->failing = array( 2 );
		$job->start( 7 );

		$this->assertStringContainsString( '0 converted, 0 could not be converted, 3 left.', $this->printed( array( $module, 'plugin_row' ) ) );

		$job->run();
		$this->assertStringContainsString( '2 converted, 1 could not be converted, 0 left.', $this->printed( array( $module, 'plugin_row' ) ) );

		$job->run();
		$html = $this->printed( array( $module, 'plugin_row' ) );
		$this->assertStringContainsString( '1 photo is kept on Profotograaf', $html );
		$this->assertStringContainsString( '1 photo could not be converted in the last run:', $html );
		$this->assertStringContainsString( '<li><strong>Photo 2</strong>: Profotograaf no longer serves this photo.</li>', $html );
		$this->assertStringContainsString( 'Convert all to local', $html, 'The admin can try again.' );

		$this->meta = array();
		$this->assertStringContainsString( 'Every photo kept on Profotograaf is converted to local.', $this->printed( array( $module, 'plugin_row' ) ) );
	}

	public function test_the_plugin_row_says_why_a_job_stopped(): void {
		$job    = $this->job();
		$module = new Virtual_Removal( $job );
		$this->mark( 1 );
		$job->start( 7 );
		$this->caps = array();
		$job->run();
		$this->caps = array( 'upload_files', 'activate_plugins' );

		$this->assertStringContainsString( 'The last conversion stopped because the user who started it may no longer upload files.', $this->printed( array( $module, 'plugin_row' ) ) );
	}

	public function test_the_plugin_row_is_hidden_in_the_network_admin_and_from_users_who_may_not_convert(): void {
		$module = new Virtual_Removal( $this->job() );
		$this->mark( 1 );

		$this->caps = array( 'activate_plugins' );
		$this->assertSame( '', $this->printed( array( $module, 'plugin_row' ) ) );
		$this->caps = array( 'upload_files' );
		$this->assertSame( '', $this->printed( array( $module, 'plugin_row' ) ) );
		$this->caps = array( 'upload_files', 'activate_plugins' );
		Functions\when( 'is_network_admin' )->justReturn( true );
		$this->assertSame( '', $this->printed( array( $module, 'plugin_row' ) ) );
	}

	public function test_the_start_action_checks_the_nonce_starts_the_job_and_goes_back(): void {
		$job    = $this->job();
		$module = new Recording_Virtual_Removal( $job );
		Functions\expect( 'check_admin_referer' )->once()->with( 'profotograaf_convert_all' )->andReturn( 1 );

		$module->handle_start();

		$this->assertSame( array( 'https://site.test/wp-admin/plugins.php' ), $module->redirects );
		$this->assertSame( 7, $job->state()['user'] );
		$this->assertTrue( $job->running() );
	}

	public function test_the_start_action_refuses_users_who_may_not_convert(): void {
		$job    = $this->job();
		$module = new Recording_Virtual_Removal( $job );
		Functions\expect( 'check_admin_referer' )->never();
		$dies = array();
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title, $args ) use ( &$dies ) {
				$dies[] = $args['response'];
			}
		);

		$this->caps = array( 'upload_files' );
		$module->handle_start();

		$this->assertSame( array( 403 ), $dies );
		$this->assertSame( array(), $module->redirects );
		$this->assertFalse( $job->running() );
	}

	public function test_the_cli_command_is_registered_under_wp_cli(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
		\WP_CLI::reset();

		( new Virtual_Removal( $this->job() ) )->register( $this->plugin );

		$this->assertInstanceOf( Virtual_Command::class, \WP_CLI::$commands['profotograaf virtual'] );
	}

	public function test_the_cli_command_converts_in_batches_and_reports_failures(): void {
		\WP_CLI::reset();
		$this->mark( 1, 2, 3, 4, 5 );
		$this->convert->failing = array( 4 );

		( new Virtual_Command( $this->job() ) )->convert( array(), array( 'batch-size' => '2' ) );

		$this->assertSame( array( 2, 2, 1 ), $this->convert->batches );
		$this->assertSame( array( 4 ), $this->marked_ids() );
		$this->assertSame(
			array(
				array( 'log', '5 photos are kept on Profotograaf.' ),
				array( 'log', 'Converted 2, failed 0.' ),
				array( 'warning', 'Photo 4: Profotograaf no longer serves this photo.' ),
				array( 'log', 'Converted 3, failed 1.' ),
				array( 'log', 'Converted 4, failed 1.' ),
				array( 'warning', '4 converted, 1 could not be converted and stay kept on Profotograaf.' ),
			),
			\WP_CLI::$output
		);
	}

	public function test_the_cli_command_reports_success_when_everything_converted(): void {
		\WP_CLI::reset();
		$this->mark( 1 );

		( new Virtual_Command( $this->job() ) )->convert();

		$this->assertSame( array( 'success', '1 photo converted to local.' ), end( \WP_CLI::$output ) );
	}

	public function test_the_cli_dry_run_and_an_empty_library_only_count(): void {
		\WP_CLI::reset();
		$command = new Virtual_Command( $this->job() );
		$command->convert();
		$this->mark( 1 );
		$command->convert( array(), array( 'dry-run' => true ) );

		$this->assertSame(
			array(
				array( 'log', '0 photos are kept on Profotograaf.' ),
				array( 'log', '1 photo is kept on Profotograaf.' ),
			),
			\WP_CLI::$output
		);
		$this->assertSame( array(), $this->convert->batches );
	}

	public function test_the_cli_command_needs_a_user_who_may_upload_files(): void {
		\WP_CLI::reset();
		$this->mark( 1 );
		$this->caps = array();

		( new Virtual_Command( $this->job() ) )->convert();

		$this->assertSame( array( 'error', 'Run this command as a user who may upload files, for example with --user=admin.' ), end( \WP_CLI::$output ) );
		$this->assertSame( array(), $this->convert->batches );
	}
}

/**
 * Stands in for the conversion of issue #152, which Virtual_Attachments_Test
 * covers: removes the virtual mark unless the id is in $failing.
 */
class Fake_Virtual_Convert extends Virtual_Convert {

	/**
	 * Ids whose conversion fails.
	 *
	 * @var array<int,int>
	 */
	public array $failing = array();

	/**
	 * Size of every batch converted.
	 *
	 * @var array<int,int>
	 */
	public array $batches = array();

	private Virtual_Removal_Test $test;

	public function __construct( Virtual_Removal_Test $test ) {
		$this->test = $test;
	}

	public function marked_ids( int $limit, bool $gone ): array {
		return array_slice( $this->test->marked_ids(), 0, $limit );
	}

	public function convert_many( array $ids ): array {
		$this->batches[] = count( $ids );
		$result          = array(
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => array(),
		);
		foreach ( $ids as $id ) {
			if ( in_array( $id, $this->failing, true ) ) {
				$result['failed'][] = array(
					'title'   => 'Photo ' . $id,
					'message' => 'Profotograaf no longer serves this photo.',
				);
				continue;
			}
			unset( $this->test->meta[ $id ][ Virtual_Attachments::META_VIRTUAL ] );
			++$result['converted'];
		}
		return $result;
	}
}

/**
 * Records the redirect instead of exiting.
 */
class Recording_Virtual_Removal extends Virtual_Removal {

	/**
	 * Where the module redirected to.
	 *
	 * @var array<int,string>
	 */
	public array $redirects = array();

	protected function finish( string $url ): void {
		$this->redirects[] = $url;
	}
}
