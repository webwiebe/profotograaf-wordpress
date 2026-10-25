<?php
/**
 * The enquiry block: render, spam protection, hand-off to the lead queue.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Leads\Enquiry_Form;
use Profotograaf\Logger;

class Enquiry_Form_Test extends Leads_Test_Case {

	private Enquiry_Form $form;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://photos.example.com/contact/' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'wp_json_encode' )->alias( fn( $value ) => json_encode( $value ) );
		Logger::configure( true, static function (): void {} );
		Logger::clear();
		$this->form = new Enquiry_Form( $this->dispatcher );
	}

	/**
	 * A valid post, made one minute after the form was drawn.
	 *
	 * @param array<string,string> $override Fields to replace.
	 * @return array<string,string>
	 */
	private function post( array $override = array() ): array {
		return $override + array(
			'pf_name'    => 'Anna de Vries',
			'pf_email'   => 'anna@example.com',
			'pf_message' => 'We would love photos of our wedding.',
			'pf_date'    => '2027-06-12',
			'pf_website' => '',
			'pf_token'   => $this->form->token( $this->now - 60 ),
			'pf_page'    => 'https://photos.example.com/contact/',
		);
	}

	public function test_a_valid_enquiry_is_queued_as_a_lead(): void {
		$result = $this->form->handle( $this->post(), '203.0.113.9', $this->now );

		$this->assertSame( 'sent', $result );
		$payload = $this->only_payload();
		$this->assertSame( 'Anna de Vries', $payload['name'] );
		$this->assertSame( 'anna@example.com', $payload['email'] );
		$this->assertSame( 'We would love photos of our wedding.', $payload['message'] );
		$this->assertSame( '2027-06-12', $payload['event_date'] );
		$this->assertSame( 'wordpress', $payload['source'] );
		$this->assertSame( 'https://photos.example.com/contact/', $payload['page_url'] );
		$this->assertNotEmpty( $this->scheduled );
	}

	public function test_the_native_form_is_on_without_a_settings_entry(): void {
		$this->assertSame( 'sent', $this->form->handle( $this->post(), '203.0.113.9', $this->now ) );
	}

	public function test_the_photographer_can_switch_the_native_form_off(): void {
		$this->options[ \Profotograaf\Leads\Form_Settings::OPTION ]['forms'][ Enquiry_Form::FORM_KEY ] = array( 'enabled' => false );

		$this->assertSame( 'error', $this->form->handle( $this->post(), '203.0.113.9', $this->now ) );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_a_filled_honeypot_shows_sent_but_queues_nothing(): void {
		$result = $this->form->handle( $this->post( array( 'pf_website' => 'https://spam.example' ) ), '203.0.113.9', $this->now );

		$this->assertSame( 'sent', $result );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_a_post_without_a_valid_token_is_refused_with_a_message(): void {
		$this->assertSame( 'invalid', $this->form->handle( $this->post( array( 'pf_token' => '' ) ), '203.0.113.9', $this->now ) );
		$this->assertSame( 'invalid', $this->form->handle( $this->post( array( 'pf_token' => $this->now . '.forged' ) ), '203.0.113.9', $this->now ) );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_a_post_faster_than_three_seconds_is_refused(): void {
		$post = $this->post( array( 'pf_token' => $this->form->token( $this->now - 1 ) ) );

		$this->assertSame( 'invalid', $this->form->handle( $post, '203.0.113.9', $this->now ) );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_a_token_older_than_a_day_is_refused(): void {
		$post = $this->post( array( 'pf_token' => $this->form->token( $this->now - 90000 ) ) );

		$this->assertSame( 'invalid', $this->form->handle( $post, '203.0.113.9', $this->now ) );
	}

	public function test_a_missing_or_bad_email_is_refused_with_a_message(): void {
		$this->assertSame( 'invalid', $this->form->handle( $this->post( array( 'pf_email' => 'not-an-email' ) ), '203.0.113.9', $this->now ) );
		$this->assertSame( 'invalid', $this->form->handle( $this->post( array( 'pf_name' => '' ) ), '203.0.113.9', $this->now ) );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_the_sixth_enquiry_in_an_hour_from_one_address_is_limited(): void {
		for ( $i = 0; $i < Enquiry_Form::LIMIT; $i++ ) {
			$this->assertSame( 'sent', $this->form->handle( $this->post(), '203.0.113.9', $this->now ) );
		}

		$this->assertSame( 'limited', $this->form->handle( $this->post(), '203.0.113.9', $this->now ) );
		$this->assertSame( 'sent', $this->form->handle( $this->post(), '203.0.113.10', $this->now ) );
	}

	public function test_the_rate_limit_stores_a_salted_hash_and_never_the_address(): void {
		$this->form->handle( $this->post(), '203.0.113.9', $this->now );

		$keys = array_keys( $this->transients );
		$this->assertCount( 1, $keys );
		$this->assertStringNotContainsString( '203.0.113.9', json_encode( $this->transients ) );
		$this->assertStringNotContainsString( '203.0.113.9', $keys[0] );
		$this->assertStringStartsWith( 'pf_enq_', $keys[0] );
	}

	public function test_a_full_queue_tells_the_visitor_it_was_not_received(): void {
		for ( $i = 0; $i < \Profotograaf\Leads\Queue::MAX_PENDING; $i++ ) {
			$this->store->jobs[ 'j' . $i ] = array( 'status' => 'pending' );
		}

		$this->assertSame( 'error', $this->form->handle( $this->post(), '203.0.113.9', $this->now ) );
	}

	public function test_nothing_personal_reaches_the_log(): void {
		$this->form->handle( $this->post(), '203.0.113.9', $this->now );
		$this->form->handle( $this->post( array( 'pf_email' => 'bad' ) ), '203.0.113.9', $this->now );
		$this->form->handle( $this->post( array( 'pf_website' => 'x' ) ), '203.0.113.9', $this->now );

		$logged = json_encode( Logger::entries() );
		foreach ( array( 'anna@example.com', 'Anna de Vries', 'wedding', '203.0.113.9', 'bad' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, (string) $logged );
		}
	}

	public function test_the_form_draws_the_four_fields_a_honeypot_and_a_token(): void {
		$html = $this->form->render( array(), $this->now );

		foreach ( array( 'name="pf_name"', 'name="pf_email"', 'name="pf_message"', 'name="pf_date"', 'name="pf_website"', 'name="pf_token"', 'name="action" value="profotograaf_enquiry"' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
		$this->assertStringContainsString( 'action="https://photos.example.com/wp-admin/admin-post.php"', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'autocomplete="off"', $html );
	}

	public function test_the_form_shows_a_clear_message_for_every_outcome(): void {
		$sent    = $this->form->render( array(), $this->now, 'sent' );
		$failed  = $this->form->render( array(), $this->now, 'error' );
		$limited = $this->form->render( array(), $this->now, 'limited' );
		$invalid = $this->form->render( array(), $this->now, 'invalid' );

		$this->assertStringContainsString( 'Thank you', $sent );
		$this->assertStringContainsString( 'role="status"', $sent );
		$this->assertStringContainsString( 'was not received', $failed );
		$this->assertStringContainsString( 'role="alert"', $failed );
		$this->assertStringContainsString( 'Too many', $limited );
		$this->assertStringContainsString( 'Check', $invalid );
		$this->assertStringNotContainsString( 'Thank you', $failed );
	}

	public function test_the_block_json_is_a_server_rendered_block(): void {
		$meta = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/blocks/enquiry/block.json' ), true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( 'profotograaf/enquiry', $meta['name'] );
		$this->assertTrue( $meta['supports']['autoRegister'] );
		$this->assertArrayNotHasKey( 'editorScript', $meta );
	}

	public function test_the_render_callback_is_attached_to_the_block(): void {
		$args = $this->form->add_render_callback( array(), 'profotograaf/enquiry' );
		$this->assertIsCallable( $args['render_callback'] );
		$this->assertSame( array(), $this->form->add_render_callback( array(), 'core/paragraph' ) );
	}
}
