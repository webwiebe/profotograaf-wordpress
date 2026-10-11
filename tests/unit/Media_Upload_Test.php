<?php
/**
 * Media_Upload module tests: field, bulk action, column, hooks.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Media_Upload;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Upload_Queue;

class Recording_Media_Upload extends Media_Upload {

	/**
	 * Where the module redirected to.
	 *
	 * @var string[]
	 */
	public array $redirects = array();

	protected function finish( string $url ): void {
		$this->redirects[] = $url;
	}
}

class Media_Upload_Test extends Wp_Test_Case {

	private Media_Upload $module;

	private Plugin $plugin;

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Capabilities the current user has.
	 *
	 * @var array<int,string>
	 */
	private array $caps = array( 'upload_files', 'edit_post' );

	private string $mime = 'image/jpeg';

	private Fake_Transport $http;

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION ] = array( 'media_upload' => true );
		$this->connect( 900, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'get_post_mime_type' )->alias( fn() => $this->mime );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_date' )->alias( fn( $format, $time ) => gmdate( 'Y-m-d', $time ) );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_attr' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$this->http   = new Fake_Transport();
		$connection   = new Connection();
		$this->plugin = new Plugin( $connection, new Api_Client( $connection, $this->http, $this->clock() ), $this->clock() );
		$this->plugin->upload_links()->add( 'g-1', 'Spring wedding', 7, $this->now );
		$this->plugin->upload_links()->add( 'g-2', 'Client "A" & B', 7, $this->now );
		$this->module = new Media_Upload();
		$this->module->register( $this->plugin );
	}

	private function post( int $id ): object {
		return (object) array( 'ID' => $id );
	}

	public function test_it_registers_its_hooks(): void {
		$this->assertNotFalse( has_action( Upload_Queue::HOOK, array( $this->module, 'run_queue' ) ) );
		$this->assertNotFalse( has_action( 'profotograaf_connected', array( $this->module, 'resume_queue' ) ) );
		$this->assertNotFalse( has_action( 'add_attachment', array( $this->module, 'maybe_send_automatically' ) ) );
		$this->assertNotFalse( has_action( 'edit_attachment', array( $this->module, 'note_edit' ) ) );
		$this->assertNotFalse( has_filter( 'attachment_fields_to_edit', array( $this->module, 'send_field' ) ) );
		$this->assertNotFalse( has_filter( 'attachment_fields_to_save', array( $this->module, 'save_field' ) ) );
		$this->assertNotFalse( has_filter( 'bulk_actions-upload', array( $this->module, 'bulk_actions' ) ) );
		$this->assertNotFalse( has_filter( 'handle_bulk_actions-upload', array( $this->module, 'handle_bulk' ) ) );
		$this->assertNotFalse( has_filter( 'manage_media_columns', array( $this->module, 'add_column' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_upload_create' ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_upload_again', array( $this->module, 'handle_again' ) ) );
	}

	public function test_the_field_offers_the_linked_galleries_and_escapes_their_titles(): void {
		$fields = $this->module->send_field( array(), $this->post( 40 ) );

		$html = $fields['profotograaf_send_to']['html'];
		$this->assertSame( 'Send to Profotograaf', $fields['profotograaf_send_to']['label'] );
		$this->assertSame( 'html', $fields['profotograaf_send_to']['input'] );
		$this->assertStringContainsString( 'name="attachments[40][profotograaf_send_to]"', $html );
		$this->assertStringContainsString( '<option value="">Do not send</option>', $html );
		$this->assertStringContainsString( '<option value="g-1">Spring wedding</option>', $html );
		$this->assertStringContainsString( 'Client &quot;A&quot; &amp; B', $html );
	}

	public function test_the_field_is_absent_while_upload_is_off_or_for_users_who_may_not_edit(): void {
		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );
		$this->assertSame( array(), $this->module->send_field( array(), $this->post( 40 ) ) );

		$this->options[ Settings::OPTION ] = array( 'media_upload' => true );
		$this->caps                        = array( 'upload_files' );
		$this->assertSame( array(), $this->module->send_field( array(), $this->post( 40 ) ) );

		$this->caps = array( 'edit_post' );
		$this->assertSame( array(), $this->module->send_field( array(), $this->post( 40 ) ) );
	}

	public function test_the_field_is_absent_for_other_file_types_and_imported_photos(): void {
		$this->mime = 'application/pdf';
		$this->assertSame( array(), $this->module->send_field( array(), $this->post( 40 ) ) );

		$this->mime                               = 'image/jpeg';
		$this->meta[41]['_profotograaf_photo_id'] = 'p-1';
		$this->assertSame( array(), $this->module->send_field( array(), $this->post( 41 ) ) );
	}

	public function test_the_field_says_to_link_a_gallery_when_none_is_linked(): void {
		$this->plugin->upload_links()->remove( 'g-1' );
		$this->plugin->upload_links()->remove( 'g-2' );

		$html = $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'];

		$this->assertStringContainsString( 'Link a gallery', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_the_field_shows_the_state_of_a_file_that_is_queued_sent_or_failed(): void {
		$this->meta[40]['_profotograaf_upload_state'] = 'queued';
		$this->assertStringContainsString( 'Waiting to be sent', $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'] );

		$this->meta[40]['_profotograaf_upload_state'] = 'uploaded';
		$this->meta[40]['_profotograaf_uploaded_at']  = 86400 * 365;
		$this->assertStringContainsString( 'Sent to Profotograaf on 1971-01-01', $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'] );

		$this->meta[40]['_profotograaf_upload_state'] = 'failed';
		$this->meta[40]['_profotograaf_upload_error'] = 'Your Profotograaf storage is full.';
		$html = $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'];
		$this->assertStringContainsString( 'storage is full', $html );
		$this->assertStringContainsString( '<select', $html, 'A failed file can be sent again.' );
	}

	public function test_a_queued_file_explains_a_missing_write_permission(): void {
		$this->connect();
		$this->meta[40]['_profotograaf_upload_state'] = 'queued';

		$html = $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'];

		$this->assertStringContainsString( 'needs a new permission', $html );
	}

	public function test_saving_the_field_queues_the_file(): void {
		$post = $this->module->save_field( array( 'ID' => 40 ), array( 'profotograaf_send_to' => 'g-1' ) );

		$this->assertSame( array( 'ID' => 40 ), $post );
		$this->assertSame( 'queued', $this->meta[40]['_profotograaf_upload_state'] );
		$this->assertSame( 'g-1', $this->meta[40]['_profotograaf_upload_gallery'] );
	}

	public function test_saving_without_a_choice_or_permission_queues_nothing(): void {
		$this->module->save_field( array( 'ID' => 40 ), array( 'profotograaf_send_to' => '' ) );
		$this->module->save_field( array( 'ID' => 40 ), array() );
		$this->caps = array( 'upload_files' );
		$this->module->save_field( array( 'ID' => 40 ), array( 'profotograaf_send_to' => 'g-1' ) );

		$this->assertArrayNotHasKey( 40, $this->meta );
	}

	public function test_saving_a_gallery_that_is_not_linked_reports_an_error(): void {
		$post = $this->module->save_field( array( 'ID' => 40 ), array( 'profotograaf_send_to' => 'g-other' ) );

		$this->assertSame( 'This gallery is not linked for sending files.', $post['errors']['profotograaf_send_to']['errors'][0] );
		$this->assertArrayNotHasKey( 40, $this->meta );
	}

	public function test_the_bulk_actions_name_each_linked_gallery(): void {
		$actions = $this->module->bulk_actions( array( 'delete' => 'Delete permanently' ) );

		$this->assertSame( 'Delete permanently', $actions['delete'] );
		$this->assertSame( 'Send to Profotograaf: Spring wedding', $actions['profotograaf_send:g-1'] );
		$this->assertCount( 3, $actions );

		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );
		$this->assertSame( array( 'delete' => 'x' ), $this->module->bulk_actions( array( 'delete' => 'x' ) ) );
	}

	public function test_the_bulk_action_queues_the_selected_files_and_counts_the_rest_as_skipped(): void {
		$this->meta[43]['_profotograaf_photo_id'] = 'p-1';

		$redirect = $this->module->handle_bulk( 'upload.php', 'profotograaf_send:g-1', array( 40, 41, 43 ) );

		$this->assertSame( 'queued', $this->meta[40]['_profotograaf_upload_state'] );
		$this->assertSame( 'queued', $this->meta[41]['_profotograaf_upload_state'] );
		$this->assertSame( 'upload.php?profotograaf_queued=2&profotograaf_skipped=1', $redirect );
	}

	public function test_other_bulk_actions_pass_through(): void {
		$this->assertSame( 'upload.php', $this->module->handle_bulk( 'upload.php', 'delete', array( 40 ) ) );
		$this->assertArrayNotHasKey( 40, $this->meta );
	}

	public function test_the_bulk_notice_reports_the_counts(): void {
		$_GET['profotograaf_queued']  = '2';
		$_GET['profotograaf_skipped'] = '1';

		ob_start();
		$this->module->bulk_notice();
		$html = (string) ob_get_clean();
		unset( $_GET['profotograaf_queued'], $_GET['profotograaf_skipped'] );

		$this->assertStringContainsString( '2 files will be sent to Profotograaf in the background.', $html );
		$this->assertStringContainsString( '1 file was skipped', $html );
	}

	public function test_no_bulk_notice_without_the_argument(): void {
		ob_start();
		$this->module->bulk_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_column_shows_a_short_status(): void {
		$this->assertArrayHasKey( 'profotograaf', $this->module->add_column( array() ) );

		$expect = array(
			'queued'    => 'Waiting to be sent',
			'uploading' => 'Sending',
			'uploaded'  => 'Sent',
			'failed'    => 'Not sent',
		);
		foreach ( $expect as $state => $label ) {
			$this->meta[40]['_profotograaf_upload_state'] = $state;
			ob_start();
			$this->module->render_column( 'profotograaf', 40 );
			$this->assertSame( $label, (string) ob_get_clean(), $state );
		}

		ob_start();
		$this->module->render_column( 'author', 40 );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_column_tells_imported_photos_from_empty_rows(): void {
		$this->meta[41]['_profotograaf_photo_id'] = 'p-1';
		ob_start();
		$this->module->render_column( 'profotograaf', 41 );
		$this->assertSame( 'Imported', (string) ob_get_clean() );

		ob_start();
		$this->module->render_column( 'profotograaf', 42 );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_no_column_while_upload_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );

		$this->assertSame( array(), $this->module->add_column( array() ) );
	}

	public function test_a_new_upload_goes_to_the_automatic_link_only(): void {
		$this->module->maybe_send_automatically( 40 );
		$this->assertArrayNotHasKey( 40, $this->meta );

		$this->plugin->upload_links()->set_auto( 'g-2', true );
		$this->module->maybe_send_automatically( '40' );

		$this->assertSame( 'g-2', $this->meta[40]['_profotograaf_upload_gallery'] );
	}

	public function test_the_cron_event_runs_the_queue_and_a_pairing_resumes_it(): void {
		$this->http->reply( 503, array( 'error' => 'down' ) );
		$this->module->save_field( array( 'ID' => 40 ), array( 'profotograaf_send_to' => 'g-1' ) );
		Functions\when( 'get_posts' )->alias( fn() => array( 40 ) );
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/nonexistent/file.jpg' );

		$this->module->run_queue();
		$this->assertSame( 'failed', $this->meta[40]['_profotograaf_upload_state'], 'The run reached the queue, which could not read the file.' );

		$this->module->resume_queue();
		$this->module->note_edit( 40 );
		$this->assertCount( 0, $this->http->requests );
	}

	/**
	 * Marks attachment 40 as failed after a lost answer that could not be checked.
	 */
	private function maybe_sent(): void {
		$this->meta[40]['_profotograaf_upload_state']      = 'failed';
		$this->meta[40]['_profotograaf_upload_gallery']    = 'g-1';
		$this->meta[40]['_profotograaf_upload_error']      = 'The file may already exist on Profotograaf.';
		$this->meta[40]['_profotograaf_upload_maybe_sent'] = '1';
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		Functions\when( 'esc_url' )->returnArg();
	}

	public function test_a_file_that_may_be_sent_offers_upload_again(): void {
		$this->maybe_sent();

		$html = $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'];
		$this->assertStringContainsString( 'may already exist', $html );
		$this->assertStringContainsString( '>Upload again</a>', $html );
		$this->assertStringContainsString( 'admin-post.php?action=profotograaf_upload_again&attachment=40&_wpnonce=profotograaf_upload_again_40', $html );

		ob_start();
		$this->module->render_column( 'profotograaf', 40 );
		$this->assertStringStartsWith( 'Not sent<br /><a', (string) ob_get_clean() );

		$this->caps = array( 'upload_files' );
		$this->assertStringNotContainsString( 'Upload again', (string) ( $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'] ?? '' ) );
	}

	public function test_other_failures_offer_no_upload_again(): void {
		$this->maybe_sent();
		unset( $this->meta[40]['_profotograaf_upload_maybe_sent'] );

		$this->assertStringNotContainsString( 'Upload again', $this->module->send_field( array(), $this->post( 40 ) )['profotograaf_send_to']['html'] );
		ob_start();
		$this->module->render_column( 'profotograaf', 40 );
		$this->assertSame( 'Not sent', (string) ob_get_clean() );
	}

	public function test_upload_again_queues_the_file_for_its_gallery_and_goes_back(): void {
		$this->maybe_sent();
		$module = new Recording_Media_Upload();
		$module->register( $this->plugin );
		Functions\expect( 'check_admin_referer' )->once()->with( 'profotograaf_upload_again_40' )->andReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'https://photos.example.com/wp-admin/upload.php?mode=list' );
		$_GET['attachment'] = '40';

		$module->handle_again();
		unset( $_GET['attachment'] );

		$this->assertSame( 'queued', $this->meta[40]['_profotograaf_upload_state'] );
		$this->assertSame( 'g-1', $this->meta[40]['_profotograaf_upload_gallery'] );
		$this->assertSame( array( 'https://photos.example.com/wp-admin/upload.php?mode=list?profotograaf_queued=1' ), $module->redirects );
	}

	public function test_upload_again_refuses_users_who_may_not_edit_and_files_that_did_not_fail(): void {
		$this->maybe_sent();
		$module = new Recording_Media_Upload();
		$module->register( $this->plugin );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( false );
		$dies = array();
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title, $args ) use ( &$dies ) {
				$dies[] = $args['response'];
			}
		);
		$_GET['attachment'] = '40';

		$this->caps = array( 'upload_files' );
		$module->handle_again();
		$this->caps                                   = array( 'upload_files', 'edit_post' );
		$this->meta[40]['_profotograaf_upload_state'] = 'uploaded';
		$module->handle_again();
		unset( $_GET['attachment'] );

		$this->assertSame( array( 403, 400 ), $dies );
		$this->assertSame( array(), $module->redirects );
	}
}
