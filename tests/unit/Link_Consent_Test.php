<?php
/**
 * The consent to the Free plan link.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Connection;
use Profotograaf\Link_Consent;
use Profotograaf\Platform_Status;

class Link_Consent_Test extends Gallery_Test_Case {

	private Link_Consent $consent;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->consent = $this->plugin->link_consent();
	}

	/**
	 * Stores the plan the platform last reported.
	 *
	 * @param string $slug      Plan slug.
	 * @param bool   $badge     Whether the link applies.
	 * @param bool   $prominent Free tier treatment.
	 */
	private function plan( string $slug, bool $badge, bool $prominent = false ): void {
		$this->options[ Platform_Status::OPTION ] = array(
			'plan' => array(
				'slug'      => $slug,
				'name'      => ucfirst( $slug ),
				'badge'     => $badge,
				'prominent' => $prominent,
			),
		);
	}

	public function test_a_site_that_is_not_connected_asks_for_nothing(): void {
		$this->assertFalse( $this->consent->required() );
		$this->assertTrue( $this->consent->allows_render() );
		$this->assertFalse( $this->consent->pending() );
	}

	public function test_a_connected_free_site_needs_the_consent_before_it_renders(): void {
		$this->connect();
		$this->plan( 'free', true, true );

		$this->assertTrue( $this->consent->required() );
		$this->assertFalse( $this->consent->given() );
		$this->assertFalse( $this->consent->allows_render() );
		$this->assertTrue( $this->consent->pending() );
	}

	public function test_a_site_that_does_not_know_its_plan_yet_keeps_rendering(): void {
		$this->connect();

		$this->assertFalse( $this->consent->required() );
		$this->assertFalse( $this->consent->pending() );
		$this->assertTrue( $this->consent->allows_render() );
		$this->assertTrue( $this->consent->plan_unknown() );
	}

	public function test_a_paid_plan_without_the_link_needs_no_consent(): void {
		$this->connect();
		$this->plan( 'pro', false );

		$this->assertFalse( $this->consent->required() );
		$this->assertTrue( $this->consent->allows_render() );
	}

	public function test_a_paid_plan_that_still_carries_the_link_needs_the_consent(): void {
		$this->connect();
		$this->plan( 'coffee', true );

		$this->assertTrue( $this->consent->required() );
		$this->assertFalse( $this->consent->allows_render() );
	}

	public function test_accepting_stores_the_value_the_time_and_the_plan(): void {
		$this->connect();
		$this->plan( 'free', true, true );

		$this->consent->accept();

		$this->assertTrue( $this->consent->given() );
		$this->assertTrue( $this->consent->allows_render() );
		$this->assertFalse( $this->consent->pending() );
		$stored = $this->options[ Link_Consent::OPTION ];
		$this->assertTrue( $stored['given'] );
		$this->assertSame( 'free', $stored['plan'] );
		$this->assertGreaterThan( 0, $stored['at'] );
		$this->assertSame( $stored['at'], $this->consent->given_at() );
	}

	public function test_the_consent_is_kept_while_the_link_still_applies(): void {
		$this->connect();
		$this->plan( 'free', true, true );
		$this->consent->accept();

		$this->consent->sync();

		$this->assertTrue( $this->consent->given() );
	}

	public function test_a_paid_plan_drops_the_consent_and_a_downgrade_asks_again(): void {
		$this->connect();
		$this->plan( 'free', true, true );
		$this->consent->accept();

		$this->plan( 'pro', false );
		$this->consent->sync();
		$this->assertFalse( $this->consent->given(), 'the consent is dropped while no link applies' );
		$this->assertTrue( $this->consent->allows_render() );

		$this->plan( 'free', true, true );
		$this->assertTrue( $this->consent->pending(), 'a downgrade asks again' );
		$this->assertFalse( $this->consent->allows_render() );
	}

	public function test_an_unknown_plan_does_not_drop_the_consent(): void {
		$this->connect();
		$this->plan( 'free', true, true );
		$this->consent->accept();
		unset( $this->options[ Platform_Status::OPTION ] );

		$this->consent->sync();

		$this->assertTrue( $this->consent->given() );
	}

	public function test_forgetting_removes_the_consent(): void {
		$this->connect();
		$this->plan( 'free', true, true );
		$this->consent->accept();

		$this->consent->forget();

		$this->assertArrayNotHasKey( Link_Consent::OPTION, $this->options );
		$this->assertFalse( $this->consent->given() );
	}

	public function test_the_refusal_explains_itself_to_a_manager_and_is_empty_for_visitors(): void {
		$html = $this->consent->refusal( 'https://example.com/wp-admin/options-general.php?page=profotograaf' );
		$this->assertStringContainsString( 'Powered by Profotograaf', $html );
		$this->assertStringContainsString( 'options-general.php?page=profotograaf', $html );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( '', $this->consent->refusal( 'https://example.com/' ) );
	}

	public function test_the_plan_block_must_say_whether_the_link_applies(): void {
		$this->assertNull( Platform_Status::plan( null ) );
		$this->assertNull( Platform_Status::plan( array( 'slug' => 'free' ) ) );
		$this->assertNull( Platform_Status::plan( array( 'badge' => 'yes' ) ) );
		$this->assertSame(
			array(
				'slug'      => 'free',
				'name'      => 'Free',
				'badge'     => true,
				'prominent' => true,
			),
			Platform_Status::plan(
				array(
					'slug'      => ' free ',
					'name'      => 'Free',
					'badge'     => true,
					'prominent' => true,
				)
			)
		);
	}

	public function test_it_is_a_connection_aware_service(): void {
		$this->assertInstanceOf( Connection::class, $this->plugin->connection() );
		$this->assertSame( $this->consent, $this->plugin->link_consent() );
	}
}
