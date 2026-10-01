<?php
/**
 * Telemetry consent tests: the sender stays silent without opt-in, a revocation
 * clears the queue and the prompt follows the connection.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Telemetry_Consent;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Settings_Schema;
use Profotograaf\Telemetry_Sender;

/**
 * Sender that counts dispatches and can pretend delivery works.
 */
class Counting_Telemetry_Sender extends Telemetry_Sender {

	public int $dispatched = 0;

	public bool $deliver = false;

	protected function dispatch( array $batch ): bool {
		++$this->dispatched;
		return $this->deliver;
	}
}

/**
 * Consent module with the clock and the redirect replaced.
 */
class Stubbed_Telemetry_Consent extends Telemetry_Consent {

	public int $time = 1000000;

	public int $finished = 0;

	protected function now(): int {
		return $this->time;
	}

	protected function finish(): void {
		++$this->finished;
	}
}

class Telemetry_Test extends Wp_Test_Case {

	private Settings $settings;

	private Stubbed_Telemetry_Consent $consent;

	/**
	 * Posted form values.
	 *
	 * @var array<string,mixed>
	 */
	private array $post = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->alias( fn() => print '<input type="hidden" name="_wpnonce" value="abc" />' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->alias( fn( $value ) => $value );

		$connection     = new Connection();
		$plugin         = new Plugin( $connection, new Api_Client( $connection, new Fake_Transport(), $this->clock() ), $this->clock() );
		$this->settings = $plugin->settings();
		$this->consent  = new Stubbed_Telemetry_Consent();
		$this->consent->register( $plugin );
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function opt_in(): void {
		$this->options[ Settings::OPTION ] = array( 'telemetry_enabled' => true );
	}

	private function sender(): Counting_Telemetry_Sender {
		return new Counting_Telemetry_Sender( $this->settings );
	}

	private function html(): string {
		ob_start();
		$this->consent->render();
		return (string) ob_get_clean();
	}

	private function choose( string $choice ): void {
		$_POST = array( 'choice' => $choice );
		$this->consent->handle_choice();
	}

	public function test_telemetry_is_off_by_default_and_the_entry_links_the_design_doc(): void {
		$entry = Settings_Schema::entry( 'telemetry_enabled' );

		$this->assertFalse( $entry['default'] );
		$this->assertSame( 'bool', $entry['type'] );
		$this->assertSame( Settings_Schema::TELEMETRY_DOC_URL, $entry['link']['url'] );
		$this->assertStringEndsWith( 'docs/telemetry.md', $entry['link']['url'] );
		$this->assertFalse( $this->settings->get( 'telemetry_enabled' ) );
	}

	public function test_nothing_is_queued_or_sent_before_the_owner_opts_in(): void {
		$sender          = $this->sender();
		$sender->deliver = true;

		$this->options[ Telemetry_Sender::QUEUE_OPTION ] = array( array( 'type' => 'usage' ) );

		$this->assertFalse( $sender->is_enabled() );
		$this->assertFalse( $sender->enqueue( array( 'type' => 'usage' ) ) );
		$this->assertSame( 0, $sender->send() );
		$this->assertSame( 0, $sender->dispatched );
		$this->assertCount( 1, $sender->queue() );
	}

	public function test_after_opt_in_batches_are_queued_and_dispatch_is_attempted(): void {
		$this->opt_in();
		$sender = $this->sender();

		$this->assertTrue( $sender->enqueue( array( 'type' => 'usage' ) ) );
		$this->assertCount( 1, $sender->queue() );
		$this->assertSame( 0, $sender->send() );
		$this->assertSame( 1, $sender->dispatched );
		$this->assertCount( 1, $sender->queue() );
	}

	public function test_the_skeleton_sender_sends_nothing_even_with_consent(): void {
		$this->opt_in();
		$sender = new Telemetry_Sender( $this->settings );
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->assertSame( 0, $sender->send() );
	}

	public function test_a_host_can_force_telemetry_off(): void {
		$this->opt_in();
		Filters\expectApplied( 'profotograaf_telemetry_enabled' )->andReturn( false );

		$this->assertFalse( $this->sender()->is_enabled() );
	}

	public function test_a_host_cannot_force_telemetry_on(): void {
		Filters\expectApplied( 'profotograaf_telemetry_enabled' )->andReturn( true );

		$this->assertFalse( $this->sender()->is_enabled() );
	}

	public function test_revoking_clears_the_queue(): void {
		$this->opt_in();
		$sender = $this->sender();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->consent->on_settings_updated( array( 'telemetry_enabled' => true ), array( 'telemetry_enabled' => false ) );

		$this->assertSame( array(), $sender->queue() );
		$this->assertArrayNotHasKey( Telemetry_Sender::QUEUE_OPTION, $this->options );
	}

	public function test_a_save_that_keeps_telemetry_on_keeps_the_queue(): void {
		$this->opt_in();
		$sender = $this->sender();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->consent->on_settings_updated( array( 'telemetry_enabled' => true ), array( 'telemetry_enabled' => true, 'default_layout' => 'masonry' ) );

		$this->assertCount( 1, $sender->queue() );
	}

	public function test_disconnecting_clears_the_queue(): void {
		$this->opt_in();
		$sender = $this->sender();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->consent->on_disconnected();

		$this->assertSame( array(), $sender->queue() );
	}

	public function test_no_prompt_on_a_site_that_has_not_connected(): void {
		$this->assertSame( '', $this->html() );
	}

	public function test_a_connected_site_sees_the_prompt_with_both_buttons_and_the_doc_link(): void {
		$this->connect();

		$html = $this->html();

		$this->assertStringContainsString( 'profotograaf-notice--telemetry', $html );
		$this->assertStringContainsString( 'Enable telemetry', $html );
		$this->assertStringContainsString( 'Not now', $html );
		$this->assertStringContainsString( Settings_Schema::TELEMETRY_DOC_URL, $html );
	}

	public function test_the_prompt_needs_the_manage_options_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->connect();

		$this->assertSame( '', $this->html() );
	}

	public function test_enable_opts_in_keeps_other_settings_and_ends_the_prompt(): void {
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'default_layout' => 'masonry' );

		$this->choose( 'enable' );

		$this->assertTrue( $this->settings->get( 'telemetry_enabled' ) );
		$this->assertSame( 'masonry', $this->settings->get( 'default_layout' ) );
		$this->assertSame( '', $this->html() );
		$this->assertSame( 1, $this->consent->finished );
	}

	public function test_not_now_hides_the_prompt_for_a_week_and_leaves_telemetry_off(): void {
		$this->connect();

		$this->choose( 'later' );

		$this->assertFalse( $this->settings->get( 'telemetry_enabled' ) );
		$this->assertSame( '', $this->html() );

		$this->consent->time += Telemetry_Consent::SNOOZE - 1;
		$this->assertSame( '', $this->html() );

		$this->consent->time += 1;
		$this->assertStringContainsString( 'profotograaf-notice--telemetry', $this->html() );
	}

	public function test_opting_in_on_the_settings_screen_answers_the_prompt(): void {
		$this->connect();

		$this->consent->on_settings_updated( array(), array( 'telemetry_enabled' => true ) );
		$this->opt_in();

		$this->assertTrue( $this->options[ Telemetry_Consent::PROMPT_OPTION ]['answered'] );
		$this->assertSame( '', $this->html() );
	}

	public function test_opting_out_again_does_not_bring_the_prompt_back(): void {
		$this->connect();
		$this->choose( 'enable' );
		$this->options[ Settings::OPTION ] = array( 'telemetry_enabled' => false );

		$this->assertSame( '', $this->html() );
	}

	public function test_a_host_that_forces_telemetry_off_hides_the_prompt(): void {
		$this->connect();
		Filters\expectApplied( 'profotograaf_telemetry_enabled' )->andReturn( false );

		$this->assertSame( '', $this->html() );
	}

	public function test_an_unknown_choice_changes_nothing(): void {
		$this->connect();

		$this->choose( 'bogus' );

		$this->assertArrayNotHasKey( Telemetry_Consent::PROMPT_OPTION, $this->options );
		$this->assertSame( 1, $this->consent->finished );
	}

	public function test_a_user_without_the_capability_cannot_answer(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'died' );
			}
		);
		$this->connect();

		$this->expectException( \RuntimeException::class );
		try {
			$this->choose( 'enable' );
		} finally {
			$this->assertFalse( $this->settings->get( 'telemetry_enabled' ) );
		}
	}

	public function test_the_consent_module_hooks_in_on_register(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( $this->consent, 'render' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Telemetry_Consent::ACTION, array( $this->consent, 'handle_choice' ) ) );
		$this->assertNotFalse( has_action( 'update_option_' . Settings::OPTION, array( $this->consent, 'on_settings_updated' ) ) );
		$this->assertNotFalse( has_action( 'profotograaf_disconnected', array( $this->consent, 'on_disconnected' ) ) );
	}
}
