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
use Profotograaf\Plugin;

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
}
