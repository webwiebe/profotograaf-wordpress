<?php
/**
 * Mapping tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Leads\Mapping;
use Profotograaf\Leads\Submission;
use Profotograaf\Tests\Wp_Test_Case;

class Mapping_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
	}

	/**
	 * Field helper.
	 *
	 * @param string $id    Id.
	 * @param string $label Label.
	 * @param string $type  Type.
	 * @param string $value Value.
	 * @return array{id:string,label:string,type:string,value:string}
	 */
	private function field( string $id, string $label, string $type, string $value ): array {
		return compact( 'id', 'label', 'type', 'value' );
	}

	private function wedding_form(): array {
		return array(
			$this->field( 'your-name', 'Your name', 'text', 'Anna de Vries' ),
			$this->field( 'your-email', 'Your email', 'email', 'anna@example.com' ),
			$this->field( 'your-tel', 'Your tel', 'phone', '0612345678' ),
			$this->field( 'wedding-date', 'Wedding date', 'date', '2027-06-12' ),
			$this->field( 'location', 'Location', 'text', 'Utrecht' ),
			$this->field( 'your-message', 'Your message', 'textarea', 'We would love photos.' ),
		);
	}

	public function test_detects_the_five_targets_by_type_and_name(): void {
		$this->assertSame(
			array(
				'email'      => 'your-email',
				'phone'      => 'your-tel',
				'event_date' => 'wedding-date',
				'name'       => 'your-name',
				'message'    => 'your-message',
			),
			Mapping::detect( $this->wedding_form() )
		);
	}

	public function test_email_field_is_never_taken_for_a_name(): void {
		$fields = array(
			$this->field( 'email-address', 'E-mail name', 'text', 'a@example.com' ),
			$this->field( 'who', 'Who are you', 'text', 'Anna' ),
		);
		$found  = Mapping::detect( $fields );

		$this->assertSame( 'email-address', $found['email'] );
		$this->assertArrayNotHasKey( 'name', $found );
	}

	public function test_dutch_labels_are_detected(): void {
		$fields = array(
			$this->field( 'a', 'Naam', 'text', 'Anna' ),
			$this->field( 'b', 'E-mailadres', 'text', 'a@example.com' ),
			$this->field( 'c', 'Telefoonnummer', 'text', '06' ),
			$this->field( 'd', 'Datum bruiloft', 'text', '12-06-2027' ),
			$this->field( 'e', 'Bericht', 'text', 'Hoi' ),
		);

		$this->assertSame(
			array(
				'email'      => 'b',
				'phone'      => 'c',
				'event_date' => 'd',
				'name'       => 'a',
				'message'    => 'e',
			),
			Mapping::detect( $fields )
		);
	}

	public function test_explicit_choice_wins_and_is_not_reused_by_detection(): void {
		$map      = array( 'message' => 'location' );
		$resolved = Mapping::resolve( $this->wedding_form(), $map );

		$this->assertSame( 'location', $resolved['message'] );
		$this->assertSame( 'your-name', $resolved['name'] );
	}

	public function test_none_switches_a_target_off(): void {
		$resolved = Mapping::resolve( $this->wedding_form(), array( 'phone' => Mapping::NONE ) );

		$this->assertArrayNotHasKey( 'phone', $resolved );
	}

	public function test_a_choice_for_a_field_the_form_lost_falls_back_to_detection(): void {
		$resolved = Mapping::resolve( $this->wedding_form(), array( 'phone' => 'removed-field' ) );

		$this->assertSame( 'your-tel', $resolved['phone'] );
	}

	public function test_payload_carries_the_mapped_fields_and_the_rest_as_pairs(): void {
		$submission = new Submission( 'cf7:1', 'Contact Form 7: Wedding inquiry', $this->wedding_form(), 'https://photos.example.com/contact/' );

		$this->assertSame(
			array(
				'name'         => 'Anna de Vries',
				'email'        => 'anna@example.com',
				'phone'        => '0612345678',
				'event_date'   => '2027-06-12',
				'message'      => 'We would love photos.',
				'source'       => 'wordpress',
				'source_form'  => 'Contact Form 7: Wedding inquiry',
				'page_url'     => 'https://photos.example.com/contact/',
				'extra_fields' => array(
					array(
						'label' => 'Location',
						'value' => 'Utrecht',
					),
				),
			),
			Mapping::payload( $submission, array() )
		);
	}

	public function test_a_mapped_field_is_not_repeated_as_an_extra(): void {
		$submission = new Submission( 'cf7:1', 'Form', $this->wedding_form() );
		$payload    = Mapping::payload( $submission, array( 'message' => 'location' ) );

		$this->assertSame( 'Utrecht', $payload['message'] );
		$labels = array_column( $payload['extra_fields'], 'label' );
		$this->assertNotContains( 'Location', $labels );
		$this->assertContains( 'Your message', $labels );
	}

	public function test_empty_fields_are_left_out(): void {
		$fields = array(
			$this->field( 'email', 'Email', 'email', 'a@example.com' ),
			$this->field( 'name', 'Name', 'name', 'Anna' ),
			$this->field( 'note', 'Note', 'text', '' ),
		);
		$result = Mapping::payload( new Submission( 'k', 'F', $fields ), array() );

		$this->assertArrayNotHasKey( 'extra_fields', $result );
		$this->assertArrayNotHasKey( 'phone', $result );
		$this->assertArrayNotHasKey( 'page_url', $result );
	}

	public function test_no_usable_email_means_no_lead(): void {
		$fields = array(
			$this->field( 'email', 'Email', 'email', 'not-an-address' ),
			$this->field( 'name', 'Name', 'name', 'Anna' ),
		);

		$this->assertNull( Mapping::payload( new Submission( 'k', 'F', $fields ), array() ) );
		$this->assertNull( Mapping::payload( new Submission( 'k', 'F', array() ), array() ) );
	}

	public function test_name_falls_back_to_the_start_of_the_email(): void {
		$fields = array( $this->field( 'email', 'Email', 'email', 'anna@example.com' ) );

		$this->assertSame( 'anna', Mapping::payload( new Submission( 'k', 'F', $fields ), array() )['name'] );
	}

	public function test_lengths_follow_the_platform_caps(): void {
		$fields  = array(
			$this->field( 'email', 'Email', 'email', 'a@example.com' ),
			$this->field( 'name', 'Name', 'name', str_repeat( 'é', 300 ) ),
			$this->field( 'message', 'Message', 'textarea', str_repeat( 'm', 9000 ) ),
			$this->field( 'long', str_repeat( 'l', 300 ), 'text', str_repeat( 'v', 5000 ) ),
		);
		$payload = Mapping::payload( new Submission( 'k', str_repeat( 'f', 300 ), $fields, 'https://x.example/' . str_repeat( 'p', 2100 ) ), array() );

		$this->assertSame( 200, mb_strlen( $payload['name'] ) );
		$this->assertSame( 8000, mb_strlen( $payload['message'] ) );
		$this->assertSame( 200, mb_strlen( $payload['source_form'] ) );
		$this->assertArrayNotHasKey( 'page_url', $payload );
		$this->assertSame( 100, mb_strlen( $payload['extra_fields'][0]['label'] ) );
		$this->assertSame( 2000, mb_strlen( $payload['extra_fields'][0]['value'] ) );
	}

	public function test_at_most_thirty_extras_and_the_body_stays_under_the_cap(): void {
		$fields = array( $this->field( 'email', 'Email', 'email', 'a@example.com' ) );
		for ( $i = 0; $i < 40; $i++ ) {
			$fields[] = $this->field( 'f' . $i, 'Field ' . $i, 'text', str_repeat( 'x', 2000 ) );
		}
		$payload = Mapping::payload( new Submission( 'k', 'F', $fields ), array() );

		$this->assertLessThanOrEqual( 30, count( $payload['extra_fields'] ) );
		$this->assertLessThanOrEqual( 30000, strlen( json_encode( $payload ) ) );
	}

	public function test_page_url_must_be_http_or_https(): void {
		$fields = array( $this->field( 'email', 'Email', 'email', 'a@example.com' ) );

		$this->assertArrayNotHasKey( 'page_url', Mapping::payload( new Submission( 'k', 'F', $fields, 'javascript:alert(1)' ), array() ) );
		$this->assertSame( 'http://x.example/a', Mapping::payload( new Submission( 'k', 'F', $fields, 'http://x.example/a' ), array() )['page_url'] );
	}

	public function test_lists_are_joined_and_control_characters_removed(): void {
		$this->assertSame( 'a, b, c', Submission::text( array( 'a', array( 'b', 'c' ), '' ) ) );
		$this->assertSame( 'ab', Submission::text( "a\x00b " ) );
		$this->assertSame( '', Submission::text( new \stdClass() ) );
	}
}
