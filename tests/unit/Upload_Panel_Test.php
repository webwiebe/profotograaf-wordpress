<?php
/**
 * Upload_Panel tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Upload_Panel;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Plugin;
use Profotograaf\Settings;

class Recording_Upload_Panel extends Upload_Panel {

	/**
	 * Times the panel redirected.
	 *
	 * @var int
	 */
	public int $finished = 0;

	protected function finish(): void {
		++$this->finished;
	}
}

class Upload_Panel_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Plugin $plugin;

	private Recording_Upload_Panel $panel;

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION ] = array( 'media_upload' => true );
		$this->connect( 900, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		$this->http   = new Fake_Transport();
		$connection   = new Connection();
		$this->plugin = new Plugin( $connection, new Api_Client( $connection, $this->http, $this->clock() ), $this->clock() );
		$this->panel  = new Recording_Upload_Panel( $this->plugin );

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			function ( $text ) {
				echo '<button>' . htmlspecialchars( (string) $text ) . '</button>';
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function die_on_wp_die(): void {
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	private function notice(): array {
		return $this->transients['profotograaf_notice_7'] ?? array();
	}

	private function render(): string {
		ob_start();
		$this->panel->render();
		return (string) ob_get_clean();
	}

	public function test_it_registers_the_four_handlers(): void {
		$this->panel->register();

		foreach ( array( 'create', 'link', 'unlink', 'auto' ) as $name ) {
			$this->assertNotFalse( has_action( 'admin_post_profotograaf_upload_' . $name ), $name );
		}
	}

	public function test_creating_a_gallery_creates_it_on_the_platform_and_links_it(): void {
		$_POST['title'] = 'Spring wedding';
		$this->http->reply( 201, array( 'id' => 'g-9', 'slug' => 'spring', 'title' => 'Spring wedding' ) );

		$this->panel->handle_create();

		$link = $this->plugin->upload_links()->find( 'g-9' );
		$this->assertSame( 'Spring wedding', $link['title'] );
		$this->assertSame( 7, $link['created_by'] );
		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertSame( 1, $this->panel->finished );
		$this->assertSame( array( 'title' => 'Spring wedding' ), $this->http->body( 0 ) );
	}

	public function test_a_failed_creation_links_nothing_and_says_why(): void {
		$_POST['title'] = 'Spring wedding';
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$this->panel->handle_create();

		$this->assertSame( array(), $this->plugin->upload_links()->all() );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertSame( 1, $this->panel->finished );
	}

	public function test_linking_needs_the_confirmation_box(): void {
		$_POST = array( 'gallery_id' => 'g-1' );

		$this->panel->handle_link();

		$this->assertSame( array(), $this->plugin->upload_links()->all() );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_linking_an_existing_gallery_uses_the_title_from_the_account(): void {
		$_POST = array( 'gallery_id' => 'g-1', 'confirm' => '1' );
		$this->http->reply( 200, array( array( 'id' => 'g-1', 'title' => 'Client gallery' ) ) );

		$this->panel->handle_link();

		$this->assertSame( 'Client gallery', $this->plugin->upload_links()->find( 'g-1' )['title'] );
		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertArrayHasKey( Upload_Panel::CHOICES, $this->transients );
	}

	public function test_a_gallery_that_is_not_in_the_account_is_not_linked(): void {
		$_POST = array( 'gallery_id' => 'g-other', 'confirm' => '1' );
		$this->http->reply( 200, array( array( 'id' => 'g-1', 'title' => 'Client gallery' ) ) );

		$this->panel->handle_link();

		$this->assertSame( array(), $this->plugin->upload_links()->all() );
		$this->assertSame( 'error', $this->notice()['type'] );
	}

	public function test_unlinking_removes_the_link_only(): void {
		$this->plugin->upload_links()->add( 'g-1', 'A', 7, 1 );
		$_POST = array( 'gallery_id' => 'g-1' );

		$this->panel->handle_unlink();

		$this->assertSame( array(), $this->plugin->upload_links()->all() );
		$this->assertCount( 0, $this->http->requests );
		$this->assertSame( 1, $this->panel->finished );
	}

	public function test_automatic_sending_switches_on_and_off(): void {
		$this->plugin->upload_links()->add( 'g-1', 'A', 7, 1 );

		$_POST = array( 'gallery_id' => 'g-1', 'auto' => '1' );
		$this->panel->handle_auto();
		$this->assertTrue( $this->plugin->upload_links()->find( 'g-1' )['auto'] );

		$_POST = array( 'gallery_id' => 'g-1', 'auto' => '0' );
		$this->panel->handle_auto();
		$this->assertFalse( $this->plugin->upload_links()->find( 'g-1' )['auto'] );
	}

	public function test_every_handler_stops_for_a_user_without_manage_options(): void {
		$this->die_on_wp_die();
		Functions\when( 'current_user_can' )->justReturn( false );

		foreach ( array( 'handle_create', 'handle_link', 'handle_unlink', 'handle_auto' ) as $handler ) {
			try {
				$this->panel->$handler();
				$this->fail( $handler . ' ran.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'wp_die', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_every_handler_stops_on_a_bad_nonce(): void {
		Functions\when( 'check_admin_referer' )->alias(
			function () {
				throw new \RuntimeException( 'bad nonce' );
			}
		);

		foreach ( array( 'handle_create', 'handle_link', 'handle_unlink', 'handle_auto' ) as $handler ) {
			try {
				$this->panel->$handler();
				$this->fail( $handler . ' ran.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'bad nonce', $e->getMessage() );
			}
		}
	}

	public function test_every_handler_stops_while_the_setting_is_off(): void {
		$this->die_on_wp_die();
		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );

		foreach ( array( 'handle_create', 'handle_link', 'handle_unlink', 'handle_auto' ) as $handler ) {
			try {
				$this->panel->$handler();
				$this->fail( $handler . ' ran.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'wp_die', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_nothing_renders_while_the_setting_is_off_or_the_site_is_not_connected(): void {
		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );
		$this->assertSame( '', $this->render() );

		$this->options[ Settings::OPTION ] = array( 'media_upload' => true );
		unset( $this->options['profotograaf_connection'] );
		$this->assertSame( '', $this->render() );
	}

	public function test_the_panel_lists_links_and_offers_the_forms(): void {
		$this->plugin->upload_links()->add( 'g-1', 'Spring <b>wedding</b>', 7, 1 );
		$this->plugin->upload_links()->add( 'g-2', 'Gone', 7, 1 );
		$this->plugin->upload_links()->disable( 'g-2' );
		$this->plugin->upload_links()->set_auto( 'g-1', true );
		$this->http->reply( 200, array( array( 'id' => 'g-3', 'title' => 'Autumn' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Send files to Profotograaf', $html );
		$this->assertStringContainsString( 'Spring &lt;b&gt;wedding&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'Sent automatically.', $html );
		$this->assertStringContainsString( 'no longer knows this gallery', $html );
		$this->assertStringContainsString( 'name="action" value="profotograaf_upload_create"', $html );
		$this->assertStringContainsString( '<option value="g-3">Autumn</option>', $html );
		$this->assertStringContainsString( 'name="confirm"', $html );
	}

	public function test_the_panel_reports_the_queue_and_a_missing_write_permission(): void {
		$this->connect();
		Functions\when( 'get_posts' )->justReturn( array( 4 ) );
		$this->http->reply( 200, array() );

		$html = $this->render();

		$this->assertStringContainsString( 'needs a new permission', $html );
		$this->assertStringContainsString( '2 waiting to be sent, 1 failed.', $html );
		$this->assertStringContainsString( 'No galleries were found', $html );
	}
}
