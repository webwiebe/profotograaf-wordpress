<?php
/**
 * Lead settings screen tests: capability, nonce and saving.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Leads_Settings;
use Profotograaf\Leads\Bridge;
use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\Site_Tools;
use Profotograaf\Plugin;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Tests\Fake_Transport;

/**
 * Leads_Settings with the redirect-and-exit replaced by a recorder.
 */
class Recording_Leads_Settings extends Leads_Settings {

	/**
	 * Notices redirected with.
	 *
	 * @var string[]
	 */
	public array $finished = array();

	/**
	 * CSV files sent.
	 *
	 * @var string[]
	 */
	public array $csv = array();

	protected function finish( string $done ): void {
		$this->finished[] = $done;
	}

	protected function send_csv( string $csv ): void {
		$this->csv[] = $csv;
	}
}

/**
 * A bridge with one form.
 */
class Stub_Bridge implements Bridge {

	public function id(): string {
		return 'stub';
	}

	public function label(): string {
		return 'Stub Forms';
	}

	public function register(): void {}

	public function is_active(): bool {
		return true;
	}

	public function forms(): array {
		return array(
			array(
				'key'    => 'stub:1',
				'title'  => 'Enquiry',
				'fields' => array(
					array(
						'id'    => 'mail',
						'label' => 'Email',
						'type'  => 'email',
					),
				),
			),
		);
	}
}

class Leads_Settings_Test extends Leads_Test_Case {

	private Recording_Leads_Settings $page;

	protected function setUp(): void {
		parent::setUp();
		$this->page = new Recording_Leads_Settings( new Form_Settings(), $this->queue, array( new Stub_Bridge() ), new Plugin() );
		$this->page->register();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function allow( bool $capable, bool $nonce_ok = true ): void {
		Functions\when( 'current_user_can' )->justReturn( $capable );
		Functions\when( 'check_admin_referer' )->alias(
			function () use ( $nonce_ok ) {
				if ( ! $nonce_ok ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'forbidden' );
			}
		);
	}

	public function test_it_adds_a_settings_page_and_the_two_actions(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->page, 'add_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_leads_save', array( $this->page, 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_leads_retry', array( $this->page, 'handle_retry' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_leads_export', array( $this->page, 'handle_export' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_leads_dismiss', array( $this->page, 'handle_dismiss' ) ) );
	}

	public function test_saving_stores_the_switch_and_mapping_of_known_forms_only(): void {
		$this->allow( true );
		$_POST = array(
			'forms' => array(
				'stub:1'  => array(
					'enabled' => '1',
					'map'     => array(
						'email'   => 'mail',
						'phone'   => '-',
						'message' => '<script>',
						'other'   => 'mail',
					),
				),
				'stub:99' => array( 'enabled' => '1' ),
			),
		);

		$this->page->handle_save();

		$this->assertSame(
			array(
				'forms' => array(
					'stub:1' => array(
						'enabled' => true,
						'map'     => array(
							'email' => 'mail',
							'phone' => '-',
						),
					),
				),
			),
			$this->options[ Form_Settings::OPTION ]
		);
		$this->assertSame( array( 'saved' ), $this->page->finished );
		$this->assertFalse( $this->autoload[ Form_Settings::OPTION ] );
	}

	public function test_saving_with_the_switch_left_off_turns_the_form_off(): void {
		$this->allow( true );
		$this->enable( 'stub:1' );
		$_POST = array( 'forms' => array( 'stub:1' => array( 'map' => array() ) ) );

		$this->page->handle_save();

		$this->assertFalse( $this->options[ Form_Settings::OPTION ]['forms']['stub:1']['enabled'] );
	}

	public function test_saving_needs_the_capability(): void {
		$this->allow( false );

		$this->expectExceptionMessage( 'forbidden' );
		try {
			$this->page->handle_save();
		} finally {
			$this->assertArrayNotHasKey( Form_Settings::OPTION, $this->options );
		}
	}

	public function test_saving_needs_a_valid_nonce(): void {
		$this->allow( true, false );
		$_POST = array( 'forms' => array( 'stub:1' => array( 'enabled' => '1' ) ) );

		$this->expectExceptionMessage( 'bad nonce' );
		try {
			$this->page->handle_save();
		} finally {
			$this->assertArrayNotHasKey( Form_Settings::OPTION, $this->options );
		}
	}

	public function test_retry_requeues_failed_leads_and_schedules_delivery(): void {
		$this->allow( true );
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );

		$this->page->handle_retry();

		$this->assertSame( 'pending', $this->store->jobs['a']['status'] );
		$this->assertContains( array( $this->now, 'profotograaf_deliver_leads' ), $this->scheduled );
		$this->assertSame( array( 'retried' ), $this->page->finished );
	}

	public function test_retry_needs_a_valid_nonce(): void {
		$this->allow( true, false );
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );

		$this->expectExceptionMessage( 'bad nonce' );
		try {
			$this->page->handle_retry();
		} finally {
			$this->assertSame( 'failed', $this->store->jobs['a']['status'] );
		}
	}

	public function test_export_sends_the_failed_leads_and_marks_them_exported(): void {
		$this->allow( true );
		$this->queue->enqueue( 'a', array( 'name' => 'Anna', 'email' => 'anna@example.com', 'source_form' => 'CF7: Wedding' ) );
		$this->queue->enqueue( 'b', array( 'email' => 'pending@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );

		$this->page->handle_export();

		$this->assertCount( 1, $this->page->csv );
		$this->assertStringContainsString( 'anna@example.com', $this->page->csv[0] );
		$this->assertStringNotContainsString( 'pending@example.com', $this->page->csv[0] );
		$this->assertArrayHasKey( 'exported_at', $this->store->jobs['a'] );
		$this->assertArrayNotHasKey( 'exported_at', $this->store->jobs['b'] );
	}

	public function test_export_needs_the_capability_and_a_nonce(): void {
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );

		$this->allow( false );
		try {
			$this->page->handle_export();
			$this->fail( 'Expected the capability check to stop the export.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'forbidden', $e->getMessage() );
		}

		$this->allow( true, false );
		try {
			$this->page->handle_export();
			$this->fail( 'Expected the nonce check to stop the export.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		}
		$this->assertSame( array(), $this->page->csv );
	}

	public function test_dismiss_removes_only_exported_leads(): void {
		$this->allow( true );
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->enqueue( 'b', array( 'email' => 'b@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );
		$this->queue->fail( $this->store->jobs['b'], 'bad', 400 );
		$this->queue->mark_exported( array( 'a' ) );

		$this->page->handle_dismiss();

		$this->assertSame( array( 'b' ), array_keys( $this->store->jobs ) );
		$this->assertSame( array( 'dismissed' ), $this->page->finished );
	}

	public function test_dismiss_needs_a_valid_nonce(): void {
		$this->allow( true, false );
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );
		$this->queue->mark_exported( array( 'a' ) );

		try {
			$this->page->handle_dismiss();
			$this->fail( 'Expected the nonce check to stop the dismissal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertCount( 1, $this->store->jobs );
		}
	}

	public function test_saving_stores_a_cleaned_source_tag_and_leaves_it_out_when_empty(): void {
		$this->allow( true );
		$_POST = array(
			'forms' => array(
				'stub:1' => array(
					'enabled'    => '1',
					'source_tag' => '  <b>Wedding</b> page ',
				),
			),
		);

		$this->page->handle_save();

		$this->assertSame( 'Wedding page', $this->options[ Form_Settings::OPTION ]['forms']['stub:1']['source_tag'] );
		$this->assertSame( 'Wedding page', ( new Form_Settings() )->get( 'stub:1' )['source_tag'] );

		$_POST['forms']['stub:1']['source_tag'] = '   ';
		$this->page->handle_save();

		$this->assertArrayNotHasKey( 'source_tag', $this->options[ Form_Settings::OPTION ]['forms']['stub:1'] );
		$this->assertSame( '', ( new Form_Settings() )->get( 'stub:1' )['source_tag'] );
	}

	public function test_a_long_source_tag_is_cut(): void {
		$this->allow( true );
		$_POST = array( 'forms' => array( 'stub:1' => array( 'source_tag' => str_repeat( 'a', 150 ) ) ) );

		$this->page->handle_save();

		$this->assertSame( Form_Settings::MAX_TAG, strlen( $this->options[ Form_Settings::OPTION ]['forms']['stub:1']['source_tag'] ) );
	}

	public function test_the_tool_buttons_need_the_capability_and_a_nonce(): void {
		foreach ( array( 'handle_sync_galleries', 'handle_clear_gallery_index', 'handle_check_embed_version' ) as $handler ) {
			$this->allow( false );
			try {
				$this->page->$handler();
				$this->fail( $handler . ' ran without the capability.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'forbidden', $e->getMessage() );
			}

			$this->allow( true, false );
			try {
				$this->page->$handler();
				$this->fail( $handler . ' ran without a valid nonce.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'bad nonce', $e->getMessage() );
			}
		}
	}

	public function test_clearing_the_gallery_index_leaves_a_result_and_returns_to_the_page(): void {
		$this->allow( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$this->options['profotograaf_gallery_index'] = array( 'g1' => array() );

		$this->page->handle_clear_gallery_index();

		$this->assertArrayNotHasKey( 'profotograaf_gallery_index', $this->options );
		$this->assertSame( array( 'tools' ), $this->page->finished );
		$this->assertSame( 'success', Site_Tools::take()['type'] );
	}

	private function page_with_api( Fake_Transport $http ): Recording_Leads_Settings {
		$plugin = new Plugin( new Connection(), new Api_Client( new Connection(), $http, $this->clock() ), $this->clock() );
		return new Recording_Leads_Settings( new Form_Settings(), $this->queue, array( new Stub_Bridge() ), $plugin );
	}

	public function test_syncing_galleries_stores_them_and_leaves_a_result(): void {
		$this->allow( true );
		$this->connect( 100000 );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'number_format_i18n' )->returnArg();
		$http = ( new Fake_Transport() )->reply( 200, array( array( 'id' => 'g1', 'title' => 'Wedding', 'url' => 'https://profotograaf.nl/g/1' ) ) );

		$page = $this->page_with_api( $http );
		$page->handle_sync_galleries();

		$this->assertSame( array( 'g1' ), array_keys( $this->options['profotograaf_gallery_index'] ) );
		$this->assertSame( array( 'tools' ), $page->finished );
		$this->assertSame( 'Synced 1 gallery.', Site_Tools::take()['message'] );
	}

	public function test_rechecking_the_embed_version_leaves_a_result(): void {
		$this->allow( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\expect( 'wp_remote_request' )->once()->with( 'https://profotograaf.nl/api/v1/embed/script', \Mockery::type( 'array' ) )->andReturn( array( 'code' => 200, 'body' => '{"script_url":"/share/embed/embed.0123456789ab.js","version":"0123456789ab"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $response ) => $response['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $response ) => $response['body'] );

		$page = $this->page_with_api( new Fake_Transport() );
		$page->handle_check_embed_version();

		$this->assertSame( array( 'tools' ), $page->finished );
		$this->assertStringContainsString( '0123456789ab', Site_Tools::take()['message'] );
	}

	public function test_the_page_shows_the_defaults_the_forms_and_the_tool_buttons(): void {
		$this->allow( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias( fn( $label = '' ) => print( '<button>' . $label . '</button>' ) );
		$_GET['done'] = 'tools';
		Site_Tools::remember( array( 'type' => 'error', 'message' => 'Nope.' ) );
		$this->options[ Form_Settings::OPTION ]['forms']['stub:1'] = array( 'enabled' => true, 'map' => array(), 'source_tag' => 'Wedding page' );
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );
		$this->queue->mark_exported( array( 'a' ) );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['done'] );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Nope.', $html );
		$this->assertStringContainsString( '<button>Save defaults</button>', $html );
		$this->assertStringContainsString( 'name="profotograaf_settings[leads_failed_retention]"', $html );
		$this->assertStringContainsString( 'name="forms[stub:1][source_tag]" value="Wedding page"', $html );
		foreach ( array( 'profotograaf_sync_galleries', 'profotograaf_clear_gallery_index', 'profotograaf_check_embed_version', 'profotograaf_leads_dismiss' ) as $action ) {
			$this->assertStringContainsString( 'value="' . $action . '"', $html );
		}
	}

	public function test_a_tool_notice_is_not_shown_twice(): void {
		$this->allow( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		$_GET['done'] = 'tools';

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['done'] );

		$this->assertStringNotContainsString( 'is-dismissible', $html );
	}

	public function test_a_source_tag_that_is_not_text_is_ignored(): void {
		$this->assertSame( '', Form_Settings::clean_tag( array( 'x' ) ) );
	}
}
