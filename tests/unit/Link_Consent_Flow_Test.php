<?php
/**
 * The Free plan link consent across the status call, the prompt and the
 * places that render galleries.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Link_Consent;
use Profotograaf\Logger;
use Profotograaf\Modules\Full_Page_Gallery;
use Profotograaf\Modules\Gallery_Embed;
use Profotograaf\Modules\Link_Consent_Prompt;
use Profotograaf\Modules\Platform_Status_Sync;
use Profotograaf\Platform_Status;
use Profotograaf\Shortcode;

class Link_Consent_Flow_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'get_bloginfo' )->alias( fn( ...$args ) => array( 'version' ) === $args ? '6.8.1' : 'Example Photography' );
		Functions\when( 'sanitize_html_class' )->alias( fn( $name ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $name ) );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'add_shortcode' )->justReturn( true );
		$this->connect();
		$this->options[ Platform_Status::OPTION ] = array( 'plan' => $this->answer( 'free', true )['plan'] );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * A status answer with a plan.
	 *
	 * @param string $slug  Plan slug.
	 * @param bool   $badge Whether the link applies.
	 * @return array<string,mixed>
	 */
	private function answer( string $slug, bool $badge ): array {
		return array(
			'device_id' => 'device-1',
			'client_id' => 'wordpress',
			'plan'      => array(
				'slug'      => $slug,
				'name'      => ucfirst( $slug ),
				'badge'     => $badge,
				'prominent' => 'free' === $slug,
			),
		);
	}

	public function test_the_status_call_keeps_the_plan_and_an_answer_without_one_leaves_it(): void {
		$status = new Platform_Status( $this->api, $this->clock() );

		$this->http->reply( 200, $this->answer( 'free', true ) );
		$status->refresh();
		$this->assertSame( 'free', $status->stored()['plan']['slug'] );
		$this->assertTrue( $status->stored()['plan']['badge'] );

		$this->http->reply( 200, array( 'device_id' => 'device-1' ) );
		$status->refresh();
		$this->assertSame( 'free', $status->stored()['plan']['slug'], 'an answer without a plan keeps the known one' );

		$this->http->reply( 200, $this->answer( 'pro', false ) );
		$status->refresh();
		$this->assertSame( 'pro', $status->stored()['plan']['slug'] );
		$this->assertFalse( $status->stored()['plan']['badge'] );
	}

	public function test_the_daily_call_drops_the_consent_of_an_account_that_moved_to_a_paid_plan(): void {
		$this->plugin->link_consent()->accept();
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );
		$this->http->reply( 200, $this->answer( 'pro', false ) );

		$module->run();

		$this->assertFalse( $this->plugin->link_consent()->given() );
		$this->assertTrue( $this->plugin->link_consent()->allows_render() );
	}

	public function test_the_daily_call_keeps_the_consent_of_a_free_account(): void {
		$this->plugin->link_consent()->accept();
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );
		$this->http->reply( 200, $this->answer( 'free', true ) );

		$module->run();

		$this->assertTrue( $this->plugin->link_consent()->given() );
	}

	public function test_a_disconnect_forgets_the_consent(): void {
		$this->plugin->link_consent()->accept();
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );

		$module->on_disconnected();

		$this->assertArrayNotHasKey( Link_Consent::OPTION, $this->options );
	}

	public function test_connecting_queues_a_status_call_so_the_plan_arrives_at_once(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[] = array( $hook, is_array( $callback ) ? $callback[1] : '' );
			}
		);
		( new Platform_Status_Sync() )->register( $this->plugin );

		$this->assertContains( array( 'profotograaf_connected', 'queue_soon' ), $hooks );
	}

	public function test_the_shortcode_renders_nothing_for_visitors_until_the_consent_is_given(): void {
		$consent   = $this->plugin->link_consent();
		$shortcode = new Shortcode( $this->renderer, $consent );

		$this->assertSame( '', $shortcode->render( array( 'id' => 'g-1' ) ) );

		$consent->accept();
		$this->assertStringContainsString( 'data-profotograaf-gallery="g-1"', $shortcode->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_the_shortcode_tells_an_administrator_why_the_gallery_is_hidden(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$shortcode = new Shortcode( $this->renderer, $this->plugin->link_consent() );

		$html = $shortcode->render( array( 'id' => 'g-1' ) );

		$this->assertStringContainsString( 'Powered by Profotograaf', $html );
		$this->assertStringNotContainsString( 'data-profotograaf-gallery', $html );
	}

	public function test_a_status_call_without_a_plan_field_never_blanks_the_galleries(): void {
		unset( $this->options[ Platform_Status::OPTION ] );
		$this->http->reply( 200, array( 'device_id' => 'device-1' ) );
		( new Platform_Status( $this->api, $this->clock() ) )->refresh();
		$shortcode = new Shortcode( $this->renderer, $this->plugin->link_consent() );

		$this->assertStringContainsString( 'data-profotograaf-gallery="g-1"', $shortcode->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_the_prompt_for_an_unknown_plan_is_a_notice_without_a_checkbox(): void {
		unset( $this->options[ Platform_Status::OPTION ] );
		Functions\when( 'current_user_can' )->justReturn( true );
		$module = new Link_Consent_Prompt();
		$module->register( $this->plugin );

		ob_start();
		$module->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'has not reported your plan yet', $html );
		$this->assertStringNotContainsString( 'name="accept"', $html );
	}

	public function test_a_paid_plan_renders_the_shortcode_without_asking(): void {
		$status = new Platform_Status( $this->api, $this->clock() );
		$this->http->reply( 200, $this->answer( 'pro', false ) );
		$status->refresh();
		$shortcode = new Shortcode( $this->renderer, $this->plugin->link_consent() );

		$this->assertStringContainsString( 'data-profotograaf-gallery="g-1"', $shortcode->render( array( 'id' => 'g-1' ) ) );
	}

	public function test_the_gallery_block_follows_the_consent(): void {
		Functions\when( 'get_block_wrapper_attributes' )->alias( fn( $extra ) => 'class="' . $extra['class'] . '"' );
		$module = new Gallery_Embed();
		$module->register( $this->plugin );

		$this->assertSame( '', $module->render_block( array( 'galleryId' => 'g-1' ) ) );

		$this->plugin->link_consent()->accept();
		$this->assertStringContainsString( 'This gallery needs JavaScript', $module->render_block( array( 'galleryId' => 'g-1' ) ) );
	}

	public function test_the_full_page_block_follows_the_consent(): void {
		$module = new Full_Page_Gallery();
		$module->register( $this->plugin );

		$this->assertSame( '', $module->render_block( array( 'galleryId' => 'g-1' ) ) );
		$this->assertCount( 0, $this->http->requests, 'a refused block asks the platform for nothing' );
	}

	public function test_the_prompt_shows_the_required_checkbox_until_the_consent_is_given(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->alias( fn() => print( '<input type="hidden" name="_wpnonce" />' ) );
		$module = new Link_Consent_Prompt();
		$module->register( $this->plugin );

		ob_start();
		$module->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="accept"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'link appears under galleries', $html );
		$this->assertStringContainsString( Link_Consent::PRICING_URL, $html );

		$this->plugin->link_consent()->accept();
		ob_start();
		$module->render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_prompt_shows_nothing_to_people_who_cannot_manage_options(): void {
		$module = new Link_Consent_Prompt();
		$module->register( $this->plugin );

		ob_start();
		$module->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_answering_without_the_tick_stores_nothing(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$module = new class() extends Link_Consent_Prompt {
			protected function finish(): void {}
		};
		$module->register( $this->plugin );

		$_POST = array();
		$module->handle();
		$this->assertFalse( $this->plugin->link_consent()->given() );

		$_POST = array( 'accept' => '1' );
		$module->handle();
		$this->assertTrue( $this->plugin->link_consent()->given() );
	}
}
