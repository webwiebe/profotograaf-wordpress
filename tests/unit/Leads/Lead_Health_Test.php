<?php
/**
 * Lead delivery health: the leads permission, the fallback address and the
 * warnings in the enquiry block.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Enquiry_Form;
use Profotograaf\Settings;
use Profotograaf\Tests\Fake_Transport;

class Lead_Health_Test extends Leads_Test_Case {

	private const PAYLOAD = array(
		'name'   => 'Anna',
		'email'  => 'anna@example.com',
		'source' => 'wordpress',
	);

	private Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = new Connection();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_permalink' )->justReturn( 'https://photos.example.com/contact/' );
	}

	public function test_a_connection_with_the_leads_permission_needs_no_reconnect(): void {
		$this->connect( 900, 'a', 'r', 'galleries:read leads:write' );

		$this->assertFalse( $this->connection->needs_leads_reconnect() );
	}

	public function test_a_known_grant_without_the_leads_permission_needs_a_reconnect(): void {
		$this->connect( 900, 'a', 'r', 'galleries:read galleries:embed' );

		$this->assertTrue( $this->connection->needs_leads_reconnect() );
	}

	public function test_an_unknown_grant_is_no_reason_to_ask(): void {
		$this->connect( 900, 'a', 'r', null );

		$this->assertFalse( $this->connection->needs_leads_reconnect() );
	}

	public function test_a_disconnected_site_has_no_leads_reconnect_state(): void {
		$this->assertFalse( $this->connection->needs_leads_reconnect() );
	}

	public function test_a_denied_leads_call_is_remembered_until_a_new_grant(): void {
		$this->connect( 900, 'a', 'r', null );
		$this->connection->flag_leads_denied();

		$this->assertTrue( $this->connection->needs_leads_reconnect() );

		$this->connection->save_tokens( array( 'access_token' => 'a2', 'refresh_token' => 'r2' ), $this->now );
		$this->assertTrue( $this->connection->needs_leads_reconnect(), 'a refresh keeps the flag' );

		$this->connection->record_grant( 1 );
		$this->assertFalse( $this->connection->needs_leads_reconnect() );
	}

	private function delivery( Fake_Transport $http ): Delivery {
		return new Delivery( $this->queue, new Api_Client( $this->connection, $http, $this->clock() ), $this->connection );
	}

	public function test_a_403_from_the_leads_route_flags_the_connection(): void {
		$this->connect( 100000, 'a', 'r', null );
		$http = new Fake_Transport();
		$http->reply( 403, array( 'error' => 'insufficient_scope' ) );
		$this->queue->enqueue( 'a', self::PAYLOAD );

		$result = $this->delivery( $http )->run();

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 403, $this->store->jobs['a']['last_status'] );
		$this->assertTrue( $this->connection->needs_leads_reconnect() );
	}

	public function test_a_delivered_lead_clears_the_flag(): void {
		$this->connect( 100000, 'a', 'r', null );
		$this->connection->flag_leads_denied();
		$http = new Fake_Transport();
		$http->reply( 201, array( 'id' => 'lead-1' ) );
		$this->queue->enqueue( 'a', self::PAYLOAD );

		$this->delivery( $http )->run();

		$this->assertFalse( $this->connection->needs_leads_reconnect() );
	}

	public function test_only_enquiries_the_platform_refused_for_the_permission_are_requeued(): void {
		$this->store->jobs = array(
			'denied' => array( 'status' => 'failed', 'last_status' => 403, 'attempts' => 1, 'payload' => self::PAYLOAD ),
			'broken' => array( 'status' => 'failed', 'last_status' => 422, 'attempts' => 1, 'payload' => self::PAYLOAD ),
		);

		$this->assertSame( 1, $this->queue->retry_failed( 403 ) );
		$this->assertSame( 'pending', $this->store->jobs['denied']['status'] );
		$this->assertSame( 'failed', $this->store->jobs['broken']['status'] );
	}

	public function test_retry_failed_without_a_status_still_requeues_everything(): void {
		$this->store->jobs = array(
			'a' => array( 'status' => 'failed', 'last_status' => 403, 'attempts' => 1, 'payload' => self::PAYLOAD ),
			'b' => array( 'status' => 'failed', 'last_status' => 422, 'attempts' => 1, 'payload' => self::PAYLOAD ),
		);

		$this->assertSame( 2, $this->queue->retry_failed() );
	}

	public function test_the_fallback_address_defaults_to_the_admin_email(): void {
		$settings = new Settings();

		$this->assertSame( 'admin@example.com', $settings->get( 'leads_fallback_email' ) );
	}

	public function test_a_saved_empty_fallback_address_stays_off(): void {
		$this->options[ Settings::OPTION ] = array( 'leads_fallback_email' => '' );

		$this->assertSame( '', ( new Settings() )->get( 'leads_fallback_email' ) );
	}

	public function test_a_saved_fallback_address_wins(): void {
		$this->options[ Settings::OPTION ] = array( 'leads_fallback_email' => 'studio@example.com' );

		$this->assertSame( 'studio@example.com', ( new Settings() )->get( 'leads_fallback_email' ) );
	}

	public function test_a_failed_enquiry_is_mailed_to_the_admin_email_without_any_setting(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$job = $this->store->jobs['a'];
		$this->queue->fail( $job, 'Forbidden', 403 );

		$with_lead = array_filter( $this->mails, fn( $mail ) => str_contains( $mail[2], 'anna@example.com' ) );
		$this->assertCount( 1, $with_lead );
		$this->assertSame( 'admin@example.com', array_values( $with_lead )[0][0] );
	}

	private function form( string $problem ): Enquiry_Form {
		return new Enquiry_Form( $this->dispatcher, null, fn(): string => $problem );
	}

	public function test_the_editor_sees_a_warning_and_the_form_when_the_site_is_disconnected(): void {
		$html = $this->form( 'disconnected' )->render( array(), $this->now, '', true );

		$this->assertStringContainsString( 'not connected to Profotograaf', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'name="pf_email"', $html );
	}

	public function test_the_editor_sees_a_warning_when_the_leads_permission_is_missing(): void {
		$html = $this->form( 'reconnect' )->render( array(), $this->now, '', true );

		$this->assertStringContainsString( 'needs a new permission', $html );
	}

	public function test_the_editor_sees_why_the_form_is_empty_when_enquiries_are_off(): void {
		$html = new Enquiry_Form( $this->dispatcher, fn(): bool => false, fn(): string => 'off' );
		$html = $html->render( array(), $this->now, '', true );

		$this->assertStringContainsString( 'switched off', $html );
		$this->assertStringNotContainsString( 'name="pf_email"', $html );
	}

	public function test_visitors_never_see_the_warning(): void {
		$html = $this->form( 'disconnected' )->render( array(), $this->now );

		$this->assertStringNotContainsString( 'Profotograaf', $html );
		$this->assertStringContainsString( 'name="pf_email"', $html );
	}

	public function test_visitors_see_nothing_when_enquiries_are_off(): void {
		$form = new Enquiry_Form( $this->dispatcher, fn(): bool => false, fn(): string => 'off' );

		$this->assertSame( '', $form->render( array(), $this->now ) );
	}

	public function test_a_healthy_site_shows_the_editor_no_warning(): void {
		$html = $this->form( '' )->render( array(), $this->now, '', true );

		$this->assertStringNotContainsString( 'role="alert"', $html );
	}
}
