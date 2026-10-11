<?php
/**
 * Backup_Admin: the Media Library column, the attachment details line, the
 * bulk actions and the Site Health hook.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Backup_Health;
use Profotograaf\Backup_State;
use Profotograaf\Connection;
use Profotograaf\Modules\Backup_Admin;
use Profotograaf\Plugin;

class Backup_Admin_Test extends Wp_Test_Case {

	use Backup_Fixtures;

	private Backup_Admin $module;

	/**
	 * Capabilities the current user has.
	 *
	 * @var array<int,string>
	 */
	private array $caps = array( 'upload_files', 'edit_post', 'manage_options' );

	protected function setUp(): void {
		parent::setUp();
		$this->now = 1790000000;
		$this->set_up_library();
		$this->backup( 'marked' );
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			fn( $args, $value = null, $url = null ) => is_array( $args ) ? $value . '?' . http_build_query( $args ) : $url . '&' . $args . '=' . $value
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_date' )->alias( fn( $format, $time ) => gmdate( 'Y-m-d', $time ) );
		Functions\when( '_n' )->alias( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		$connection   = new Connection();
		$plugin       = new Plugin( $connection, new Api_Client( $connection, new Fake_Transport(), $this->clock() ), $this->clock() );
		$this->module = new Backup_Admin();
		$this->module->register( $plugin );
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		$this->tear_down_library();
		parent::tearDown();
	}

	private function column( int $id ): string {
		ob_start();
		$this->module->render_column( Backup_Admin::COLUMN, $id );
		return (string) ob_get_clean();
	}

	public function test_it_adds_its_hooks(): void {
		$this->assertNotFalse( has_filter( 'manage_media_columns', array( $this->module, 'add_column' ) ) );
		$this->assertNotFalse( has_filter( 'bulk_actions-upload', array( $this->module, 'bulk_actions' ) ) );
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $this->module, 'add_tests' ) ) );
	}

	public function test_the_column_shows_while_the_backup_is_on_for_users_who_upload(): void {
		$this->assertSame(
			array(
				'title'              => 'File',
				Backup_Admin::COLUMN => 'Backup',
			),
			$this->module->add_column( array( 'title' => 'File' ) )
		);

		$this->caps = array();
		$this->assertSame( array(), $this->module->add_column( null ) );
		$this->caps = array( 'upload_files' );
		$this->backup( 'marked', false );
		$this->assertSame( array(), $this->module->add_column( array() ) );
	}

	public function test_the_column_prints_the_state_and_the_reason(): void {
		$this->meta[1] = array(
			Backup_State::META_STATE  => 'skipped',
			Backup_State::META_REASON => 'too_large',
		);
		$this->meta[2] = array( Backup_State::META_STATE => 'done' );

		$this->assertSame( 'Not backed up<br /><span class="description">The file is larger than 100 MB, the most Profotograaf accepts.</span>', $this->column( 1 ) );
		$this->assertSame( 'Backed up', $this->column( 2 ) );
		$this->assertSame( '', $this->column( 3 ) );

		ob_start();
		$this->module->render_column( 'other', 1 );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_details_line_shows_the_state_of_a_supported_file(): void {
		$this->attachment( 1 );
		$this->attachment( 2 );
		$this->meta[2] = array(
			Backup_State::META_STATE => 'done',
			Backup_State::META_AT    => $this->now,
		);

		$fields = $this->module->details_field( array(), $this->posts[1] );
		$this->assertSame( 'Backup', $fields[ Backup_Admin::COLUMN ]['label'] );
		$this->assertSame( 'html', $fields[ Backup_Admin::COLUMN ]['input'] );
		$this->assertStringContainsString( 'Not in the backup', $fields[ Backup_Admin::COLUMN ]['html'] );

		$fields = $this->module->details_field( null, $this->posts[2] );
		$this->assertStringContainsString( 'Backed up to Profotograaf on 2026-09-21.', $fields[ Backup_Admin::COLUMN ]['html'] );
	}

	public function test_a_failed_file_links_to_the_backup_tab(): void {
		$this->attachment( 1 );
		$this->meta[1] = array(
			Backup_State::META_STATE  => 'needs_check',
			Backup_State::META_REASON => 'no_answer',
		);

		$html = $this->module->details_field( array(), $this->posts[1] )[ Backup_Admin::COLUMN ]['html'];

		$this->assertStringContainsString( 'Backup not confirmed<br /><span class="description">The file was sent', $html );
		$this->assertStringContainsString( 'tab=backup">Retry it on the Backup tab</a>', $html );

		$this->caps = array( 'upload_files' );
		$html       = $this->module->details_field( array(), $this->posts[1] )[ Backup_Admin::COLUMN ]['html'];
		$this->assertStringNotContainsString( 'Retry it', $html );
	}

	public function test_no_details_line_for_unsupported_files_or_while_off(): void {
		$this->attachment( 1, '', '2026-10-03 09:00:00', 'application/pdf' );
		$this->assertSame( array( 'x' => 1 ), $this->module->details_field( array( 'x' => 1 ), $this->posts[1] ) );

		$this->attachment( 2 );
		$this->backup( 'marked', false );
		$this->assertSame( array(), $this->module->details_field( array(), $this->posts[2] ) );
	}

	public function test_the_bulk_actions_appear_for_the_scopes_that_read_marks(): void {
		$this->assertSame(
			array(
				'delete'             => 'Delete',
				Backup_Admin::MARK   => 'Back up to Profotograaf',
				Backup_Admin::UNMARK => 'Do not back up',
			),
			$this->module->bulk_actions( array( 'delete' => 'Delete' ) )
		);

		$this->backup( 'used' );
		$this->assertArrayHasKey( Backup_Admin::MARK, $this->module->bulk_actions( null ) );

		$this->backup( 'all' );
		$this->assertSame( array(), $this->module->bulk_actions( array() ) );
	}

	public function test_marking_queues_the_files_without_sending(): void {
		$this->attachment( 1 );
		$this->attachment( 2 );

		$url = $this->module->handle_bulk( 'upload.php', Backup_Admin::MARK, array( '1', 2, 0 ) );

		$this->assertSame( 'upload.php?profotograaf_backup_marked=2', $url );
		$this->assertSame( 'queued', $this->state_of( 1 ) );
		$this->assertSame( 'queued', $this->state_of( 2 ) );
		$this->assertSame( '1', $this->meta[1][ Backup_State::META_MARKED ] );
	}

	public function test_unmarking_takes_a_queued_file_out_and_keeps_a_done_one(): void {
		$this->queued( 1 );
		$this->meta[1][ Backup_State::META_MARKED ] = '1';
		$this->attachment( 2 );
		$this->meta[2] = array(
			Backup_State::META_STATE  => 'done',
			Backup_State::META_MARKED => '1',
		);

		$url = $this->module->handle_bulk( 'upload.php', Backup_Admin::UNMARK, array( 1, 2 ) );

		$this->assertSame( 'upload.php?profotograaf_backup_marked=-2', $url );
		$this->assertSame( '', $this->state_of( 1 ) );
		$this->assertSame( 'done', $this->state_of( 2 ) );
		$this->assertArrayNotHasKey( Backup_State::META_MARKED, $this->meta[2] );
	}

	public function test_other_actions_and_files_the_user_may_not_edit_are_left_alone(): void {
		$this->attachment( 1 );

		$this->assertSame( 'upload.php', $this->module->handle_bulk( 'upload.php', 'delete', array( 1 ) ) );
		$this->assertSame( '?profotograaf_backup_marked=0', $this->module->handle_bulk( null, Backup_Admin::MARK, null ) );

		$this->caps = array( 'upload_files' );
		$this->assertSame( 'upload.php?profotograaf_backup_marked=0', $this->module->handle_bulk( 'upload.php', Backup_Admin::MARK, array( 1 ) ) );
		$this->assertSame( '', $this->state_of( 1 ) );
	}

	public function test_the_bulk_notice_tells_the_count(): void {
		ob_start();
		$this->module->bulk_notice();
		$this->assertSame( '', ob_get_clean() );

		$_GET[ Backup_Admin::NOTICE_ARG ] = '3';
		ob_start();
		$this->module->bulk_notice();
		$this->assertStringContainsString( '3 files are marked for the backup. They are copied to Profotograaf in the background.', (string) ob_get_clean() );

		$_GET[ Backup_Admin::NOTICE_ARG ] = '-1';
		ob_start();
		$this->module->bulk_notice();
		$this->assertStringContainsString( '1 file is no longer marked for the backup.', (string) ob_get_clean() );
	}

	public function test_site_health_gets_the_backup_tests(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$tests = $this->module->add_tests( array( 'direct' => array() ) );

		$this->assertArrayHasKey( Backup_Health::QUEUE_TEST, $tests['direct'] );
	}
}
