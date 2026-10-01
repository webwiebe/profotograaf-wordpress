<?php
/**
 * Cron health tests: DISABLE_WP_CRON, the heartbeat, Site Health and the notice.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Cron_Health;
use Profotograaf\Leads\Delivery;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Tests\Leads\Leads_Test_Case;

class Cron_Health_Test extends Leads_Test_Case {

	private function health( bool $disabled ): Cron_Health {
		return new Cron_Health( $this->queue, fn() => $disabled );
	}

	private function queue_lead(): void {
		$this->queue->enqueue( 'a', array( 'name' => 'Anna' ) );
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'site_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/' . $path );
	}

	public function test_a_site_with_working_cron_is_healthy(): void {
		$health = $this->health( false );
		$health->beat();

		$this->assertSame( 'good', $health->status()['level'] );
		$this->assertSame( 'good', $health->site_health_result()['status'] );
	}

	public function test_a_new_site_without_a_heartbeat_is_healthy(): void {
		$status = $this->health( false )->status();

		$this->assertFalse( $status['problem'] );
		$this->assertNull( $status['last_run'] );
	}

	public function test_disabled_cron_without_a_heartbeat_and_pending_leads_is_critical(): void {
		$this->queue_lead();

		$status = $this->health( true )->status();

		$this->assertTrue( $status['disabled'] );
		$this->assertSame( 1, $status['pending'] );
		$this->assertSame( 'critical', $status['level'] );
	}

	public function test_disabled_cron_without_pending_leads_is_a_recommendation(): void {
		$this->assertSame( 'recommended', $this->health( true )->status()['level'] );
	}

	public function test_disabled_cron_with_a_fresh_heartbeat_means_a_system_cron_runs(): void {
		$this->queue_lead();
		$health = $this->health( true );
		$health->beat();
		$this->now += 600;

		$this->assertSame( 'good', $health->status()['level'] );
	}

	public function test_an_old_heartbeat_is_stale(): void {
		$this->queue_lead();
		$health = $this->health( false );
		$health->beat();
		$this->now += Cron_Health::STALE_AFTER + 1;

		$status = $health->status();

		$this->assertTrue( $status['stale'] );
		$this->assertSame( 'critical', $status['level'] );
	}

	public function test_an_overdue_sweep_without_a_heartbeat_is_stale(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( $this->now - Cron_Health::STALE_AFTER - 1 );

		$this->assertTrue( $this->health( false )->status()['stale'] );
	}

	public function test_a_sweep_that_is_only_a_little_late_is_not_stale(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( $this->now - 600 );

		$this->assertFalse( $this->health( false )->status()['stale'] );
	}

	public function test_the_heartbeat_does_not_autoload(): void {
		$this->health( false )->beat();

		$this->assertSame( $this->now, $this->options[ Cron_Health::OPTION ] );
		$this->assertFalse( $this->autoload[ Cron_Health::OPTION ] );
	}

	public function test_register_records_the_heartbeat_on_delivery_events_and_adds_the_test(): void {
		$health = $this->health( false );
		$health->register();

		$this->assertNotFalse( has_action( Delivery::SWEEP, array( $health, 'beat' ) ) );
		$this->assertNotFalse( has_action( Delivery::HOOK, array( $health, 'beat' ) ) );
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $health, 'add_test' ) ) );
	}

	public function test_the_site_health_test_merges_with_other_tests(): void {
		$tests = $this->health( false )->add_test( array( 'direct' => array( 'other' => array( 'label' => 'Other' ) ) ) );

		$this->assertArrayHasKey( 'other', $tests['direct'] );
		$this->assertArrayHasKey( Cron_Health::TEST, $tests['direct'] );
		$this->assertSame( 'a string', $this->health( false )->add_test( 'a string' ) );
	}

	public function test_site_health_warns_with_the_crontab_line_when_leads_wait(): void {
		$this->queue_lead();

		$result = $this->health( true )->site_health_result();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'red', $result['badge']['color'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $result['description'] );
		$this->assertStringContainsString( '1 lead is waiting', $result['description'] );
		$this->assertStringContainsString( '*/5 * * * * curl', $result['actions'] );
		$this->assertStringContainsString( 'https://photos.example.com/wp-cron.php', $result['actions'] );
	}

	public function test_site_health_names_a_stopped_cron_when_cron_is_not_disabled(): void {
		$health = $this->health( false );
		$health->beat();
		$this->now += Cron_Health::STALE_AFTER + 1;

		$result = $health->site_health_result();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'more than three hours', $result['description'] );
	}

	public function test_the_notice_shows_only_with_a_problem_and_pending_leads(): void {
		ob_start();
		$this->health( true )->render_notice();
		$this->assertSame( '', (string) ob_get_clean() );

		$this->queue_lead();
		ob_start();
		$this->health( false )->render_notice();
		$this->assertSame( '', (string) ob_get_clean() );

		ob_start();
		$this->health( true )->render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'wp-cron.php', $html );
	}

	public function test_the_settings_page_shows_the_notice_on_its_own_screen_for_admins_only(): void {
		$this->queue_lead();
		$health = $this->health( true );
		$page   = new class( $health ) extends Settings_Page {

			private Cron_Health $health;

			public string $screen = '';

			public function __construct( Cron_Health $health ) {
				$this->health = $health;
			}

			protected function cron_health(): Cron_Health {
				return $this->health;
			}

			protected function screen_id(): string {
				return $this->screen;
			}
		};
		$notice = function ( string $screen, bool $capable ) use ( $page ): string {
			Functions\when( 'current_user_can' )->justReturn( $capable );
			$page->screen = $screen;
			ob_start();
			$page->cron_notice();
			return (string) ob_get_clean();
		};

		$this->assertSame( '', $notice( 'dashboard', true ) );
		$this->assertSame( '', $notice( 'settings_page_profotograaf', false ) );
		$this->assertStringContainsString( 'notice-warning', $notice( 'settings_page_profotograaf', true ) );
	}
}
