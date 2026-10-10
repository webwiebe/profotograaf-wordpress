<?php
/**
 * Review notice tests: eligibility, later and dismissed states, event queueing.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Modules\Platform_Status_Sync;
use Profotograaf\Modules\Review_Notice;
use Profotograaf\Platform_Status;
use Profotograaf\Review_Prompt;

/**
 * Review_Notice with the screen and the redirect replaced.
 */
class Stubbed_Review_Notice extends Review_Notice {

	public string $screen = 'dashboard';

	/**
	 * Redirects made: true for the review page.
	 *
	 * @var bool[]
	 */
	public array $finished = array();

	protected function screen_id(): string {
		return $this->screen;
	}

	protected function finish( bool $to_review ): void {
		$this->finished[] = $to_review;
	}
}

class Review_Notice_Test extends Gallery_Test_Case {

	private Stubbed_Review_Notice $notice;

	private bool $can = true;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->notice = new Stubbed_Review_Notice();
		$this->notice->register( $this->plugin );

		Functions\when( 'current_user_can' )->alias( fn() => $this->can );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url . '&_wpnonce=abc' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->alias( fn( $value ) => (string) $value );
		Functions\when( 'wp_unslash' )->alias( fn( $value ) => $value );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		unset( $_GET['choice'] );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		unset( $_GET['choice'] );
		parent::tearDown();
	}

	private function eligible( string $reason = 'first_client_download' ): void {
		$this->options[ Platform_Status::OPTION ] = array(
			'fetched_at'    => $this->now,
			'review_prompt' => array(
				'eligible' => true,
				'reason'   => $reason,
				'at'       => '2026-10-09T10:00:00Z',
			),
		);
	}

	private function html(): string {
		ob_start();
		$this->notice->render();
		return (string) ob_get_clean();
	}

	private function choose( string $choice ): void {
		$_GET['choice'] = $choice;
		$this->notice->handle_choice();
	}

	public function test_nothing_shows_while_the_platform_has_not_made_the_site_eligible(): void {
		$this->assertSame( '', $this->html() );

		$this->options[ Platform_Status::OPTION ] = array( 'review_prompt' => array( 'eligible' => false ) );
		$this->assertSame( '', $this->html() );
		$this->assertSame( array(), $this->options[ Review_Prompt::OPTION ] ?? array() );
	}

	public function test_an_eligible_site_sees_the_notice_with_the_reason_and_three_choices(): void {
		$this->eligible();

		$html = $this->html();

		$this->assertSame( 1, substr_count( $html, 'profotograaf-notice--review' ) );
		$this->assertStringContainsString( 'downloaded photos', $html );
		$this->assertStringContainsString( 'choice=clicked', $html );
		$this->assertStringContainsString( 'choice=later', $html );
		$this->assertStringContainsString( 'choice=dismissed', $html );
		$this->assertStringContainsString( 'Leave a review', $html );
		$this->assertStringContainsString( 'Later', $html );
		$this->assertStringContainsString( 'Don&#039;t ask again', $html );
	}

	public function test_the_copy_names_the_embed_views_reason(): void {
		$this->eligible( 'embed_views_10' );

		$this->assertStringContainsString( 'viewed on your site at least 10 times', $this->html() );
	}

	public function test_an_unknown_reason_gets_a_general_text(): void {
		$this->eligible( 'something_new' );

		$this->assertStringContainsString( 'works well for you', $this->html() );
	}

	public function test_it_shows_on_the_dashboard_and_the_settings_page_only(): void {
		$this->eligible();

		$this->notice->screen = 'settings_page_profotograaf';
		$this->assertNotSame( '', $this->html() );

		$this->notice->screen = 'settings_page_profotograaf-leads';
		$this->assertSame( '', $this->html() );
		$this->notice->screen = 'plugins';
		$this->assertSame( '', $this->html() );
		$this->notice->screen = '';
		$this->assertSame( '', $this->html() );
	}

	public function test_users_who_cannot_manage_options_see_nothing(): void {
		$this->eligible();
		$this->can = false;

		$this->assertSame( '', $this->html() );
	}

	public function test_a_disconnected_site_sees_nothing(): void {
		$this->eligible();
		unset( $this->options['profotograaf_connection'] );

		$this->assertSame( '', $this->html() );
	}

	public function test_shown_is_queued_once_and_asks_for_a_status_call(): void {
		$this->eligible();
		Actions\expectDone( 'profotograaf_review_event_queued' )->once();

		$this->html();
		$this->html();

		$this->assertSame( array( 'shown' ), $this->options[ Review_Prompt::OPTION ]['queue'] );
		$this->assertFalse( $this->autoload[ Review_Prompt::OPTION ] );
	}

	public function test_later_hides_the_notice_for_fourteen_days_and_shows_it_again_after(): void {
		$this->eligible();
		$this->html();

		$this->choose( 'later' );

		$this->assertSame( array( false ), $this->notice->finished );
		$this->assertSame( array( 'shown', 'later' ), $this->options[ Review_Prompt::OPTION ]['queue'] );
		$this->assertSame( '', $this->html() );

		$this->now += 14 * DAY_IN_SECONDS - 1;
		$this->assertSame( '', $this->html() );

		$this->now += 1;
		$this->assertNotSame( '', $this->html() );
		$this->assertSame( array( 'shown', 'later', 'shown' ), $this->options[ Review_Prompt::OPTION ]['queue'] );
	}

	public function test_dont_ask_again_hides_the_notice_for_good(): void {
		$this->eligible();
		$this->html();

		$this->choose( 'dismissed' );

		$this->assertSame( array( false ), $this->notice->finished );
		$this->assertSame( array( 'shown', 'dismissed' ), $this->options[ Review_Prompt::OPTION ]['queue'] );
		$this->now += 365 * DAY_IN_SECONDS;
		$this->assertSame( '', $this->html() );
	}

	public function test_leave_a_review_records_clicked_goes_to_the_review_page_and_stops_asking(): void {
		$this->eligible();
		$this->html();

		$this->choose( 'clicked' );

		$this->assertSame( array( true ), $this->notice->finished );
		$this->assertSame( array( 'shown', 'clicked' ), $this->options[ Review_Prompt::OPTION ]['queue'] );
		$this->assertSame( '', $this->html() );
		$this->assertStringStartsWith( 'https://wordpress.org/support/plugin/profotograaf/reviews/', Review_Notice::REVIEW_URL );
	}

	public function test_an_unknown_choice_changes_nothing(): void {
		$this->eligible();

		$this->choose( 'bogus' );

		$this->assertArrayNotHasKey( Review_Prompt::OPTION, $this->options );
		$this->assertSame( array( false ), $this->notice->finished );
	}

	public function test_a_choice_needs_the_capability(): void {
		$this->can = false;
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->choose( 'dismissed' );
	}

	public function test_a_status_call_reports_one_queued_event_and_keeps_it_when_the_call_fails(): void {
		$this->eligible();
		$this->html();
		$this->choose( 'later' );
		$sync = new Platform_Status_Sync();
		$sync->register( $this->plugin );

		$this->http->reply( 503, array() );
		$sync->run();
		$this->assertSame( 'shown', $this->http->body( 0 )['review_event'] );
		$this->assertSame( array( 'shown', 'later' ), $this->options[ Review_Prompt::OPTION ]['queue'] );

		$this->http->reply( 200, array( 'review_prompt' => array( 'eligible' => true ) ) );
		$sync->run();
		$this->assertSame( 'shown', $this->http->body( 1 )['review_event'] );
		$this->assertSame( array( 'later' ), $this->options[ Review_Prompt::OPTION ]['queue'] );

		$this->http->reply( 200, array( 'review_prompt' => array( 'eligible' => true ) ) );
		$sync->run();
		$this->assertSame( 'later', $this->http->body( 2 )['review_event'] );
		$this->assertSame( array(), $this->options[ Review_Prompt::OPTION ]['queue'] );

		$this->http->reply( 200, array( 'review_prompt' => array( 'eligible' => true ) ) );
		$sync->run();
		$this->assertArrayNotHasKey( 'review_event', $this->http->body( 3 ) );
	}

	public function test_the_sync_asks_for_another_call_while_events_wait(): void {
		$queued = new \ArrayObject();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) use ( $queued ) {
				$queued[] = $hook;
				return true;
			}
		);
		$this->eligible();
		$this->html();
		$this->choose( 'dismissed' );
		$sync = new Platform_Status_Sync();
		$sync->register( $this->plugin );

		$this->http->reply( 200, array( 'review_prompt' => array( 'eligible' => true ) ) );
		$sync->run();

		$this->assertSame( array( Platform_Status_Sync::SOON_HOOK ), $queued->getArrayCopy() );
	}

	public function test_the_queue_keeps_the_newest_ten_events(): void {
		$prompt = new Review_Prompt( $this->clock() );
		for ( $i = 0; $i < 12; $i++ ) {
			$prompt->later();
		}

		$this->assertSame( 10, $prompt->pending() );
	}

	public function test_stored_state_with_junk_falls_back_to_defaults(): void {
		$this->options[ Review_Prompt::OPTION ] = array(
			'queue'     => array( 'bogus', 'shown', 7 ),
			'dismissed' => 'yes',
		);
		$prompt                                 = new Review_Prompt( $this->clock() );

		$this->assertSame( array( 'shown' ), $prompt->state()['queue'] );
		$this->assertFalse( $prompt->state()['dismissed'] );
	}
}
