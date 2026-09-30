<?php
/**
 * Bridge tests: Contact Form 7, WPForms and Gravity Forms with faked hooks.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Leads\Contact_Form_7;
use Profotograaf\Leads\Gravity_Forms;
use Profotograaf\Leads\Wpforms;

require_once __DIR__ . '/wpcf7-stubs.php';

class Bridges_Test extends Leads_Test_Case {

	private function cf7_form( int $id = 12 ): \WPCF7_ContactForm {
		$form             = new \WPCF7_ContactForm();
		$form->form_id    = $id;
		$form->form_title = 'Wedding inquiry';
		$form->tags       = array(
			array( 'your-name', 'text' ),
			array( 'your-email', 'email' ),
			array( 'your-tel', 'tel' ),
			array( 'wedding-date', 'date' ),
			array( 'services', 'checkbox' ),
			array( 'your-message', 'textarea' ),
			array( '', 'submit' ),
		);
		return $form;
	}

	private function cf7_submit( \WPCF7_ContactForm $form, array $meta = array( 'url' => 'https://photos.example.com/contact/' ) ): void {
		$submission                 = new \WPCF7_Submission();
		$submission->posted         = array(
			'your-name'    => 'Anna de Vries',
			'your-email'   => 'anna@example.com',
			'your-tel'     => '0612345678',
			'wedding-date' => '2027-06-12',
			'services'     => array( 'Wedding', 'Engagement' ),
			'your-message' => 'We would love photos.',
			'_wpcf7'       => '12',
		);
		$submission->meta           = $meta;
		\WPCF7_Submission::$current = $submission;
		( new Contact_Form_7( $this->dispatcher ) )->on_mail_sent( $form );
	}

	public function test_contact_form_7_queues_a_mapped_lead(): void {
		$this->enable( 'cf7:12' );

		$this->cf7_submit( $this->cf7_form() );

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
						'label' => 'Services',
						'value' => 'Wedding, Engagement',
					),
				),
			),
			$this->only_payload()
		);
		$this->assertContains( array( $this->now, 'profotograaf_deliver_leads' ), $this->scheduled );
	}

	public function test_contact_form_7_prefers_the_page_that_holds_the_form(): void {
		$this->enable( 'cf7:12' );
		Functions\when( 'get_permalink' )->justReturn( 'https://photos.example.com/booking/' );

		$this->cf7_submit( $this->cf7_form(), array( 'container_post_id' => '55' ) );

		$this->assertSame( 'https://photos.example.com/booking/', $this->only_payload()['page_url'] );
	}

	public function test_contact_form_7_ignores_a_relative_url_and_uses_the_referer(): void {
		$this->enable( 'cf7:12' );
		Functions\when( 'wp_get_raw_referer' )->justReturn( 'https://photos.example.com/contact/' );

		$this->cf7_submit( $this->cf7_form(), array( 'url' => '/wp-json/contact-form-7/v1/contact-forms/12/feedback' ) );

		$this->assertSame( 'https://photos.example.com/contact/', $this->only_payload()['page_url'] );
	}

	public function test_contact_form_7_uses_the_saved_mapping(): void {
		$this->enable( 'cf7:12', array( 'message' => 'services' ) );

		$this->cf7_submit( $this->cf7_form() );

		$this->assertSame( 'Wedding, Engagement', $this->only_payload()['message'] );
	}

	public function test_a_form_that_is_off_queues_nothing(): void {
		$this->cf7_submit( $this->cf7_form() );

		$this->assertSame( array(), $this->store->jobs );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_only_the_switched_on_form_is_sent(): void {
		$this->enable( 'cf7:99' );

		$this->cf7_submit( $this->cf7_form( 12 ) );

		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_contact_form_7_lists_its_forms_and_fields(): void {
		\WPCF7_ContactForm::$all = array( $this->cf7_form() );

		$forms = ( new Contact_Form_7( $this->dispatcher ) )->forms();

		$this->assertSame( 'cf7:12', $forms[0]['key'] );
		$this->assertSame( 'Wedding inquiry', $forms[0]['title'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'your-tel',
					'label' => 'Your tel',
					'type'  => 'phone',
				),
			),
			array_values( array_filter( $forms[0]['fields'], fn( $f ) => 'your-tel' === $f['id'] ) )
		);
		$this->assertNotContains( '', array_column( $forms[0]['fields'], 'id' ), 'The submit button is not a field.' );
	}

	public function test_contact_form_7_without_a_submission_does_nothing(): void {
		$this->enable( 'cf7:12' );
		\WPCF7_Submission::$current = null;

		( new Contact_Form_7( $this->dispatcher ) )->on_mail_sent( $this->cf7_form() );

		$this->assertSame( array(), $this->store->jobs );
	}

	private function wpforms_fields(): array {
		return array(
			1 => array(
				'id'    => 1,
				'name'  => 'Name',
				'type'  => 'name',
				'value' => 'Anna de Vries',
			),
			2 => array(
				'id'    => 2,
				'name'  => 'Email',
				'type'  => 'email',
				'value' => 'anna@example.com',
			),
			3 => array(
				'id'    => 3,
				'name'  => 'Phone',
				'type'  => 'phone',
				'value' => '0612345678',
			),
			4 => array(
				'id'    => 4,
				'name'  => 'Event date',
				'type'  => 'date-time',
				'value' => '06/12/2027',
			),
			5 => array(
				'id'    => 5,
				'name'  => 'Comment or Message',
				'type'  => 'textarea',
				'value' => 'We would love photos.',
			),
			6 => array(
				'id'    => 6,
				'name'  => 'Package',
				'type'  => 'select',
				'value' => 'Full day',
			),
			7 => array(
				'id'    => 7,
				'name'  => '',
				'type'  => 'pagebreak',
				'value' => '',
			),
		);
	}

	public function test_wpforms_queues_a_mapped_lead(): void {
		$this->enable( 'wpforms:7' );
		Functions\when( 'wp_get_raw_referer' )->justReturn( 'https://photos.example.com/contact/' );

		( new Wpforms( $this->dispatcher ) )->on_complete(
			$this->wpforms_fields(),
			array(),
			array(
				'id'       => '7',
				'settings' => array( 'form_title' => 'Enquiry' ),
			),
			31
		);

		$this->assertSame(
			array(
				'name'         => 'Anna de Vries',
				'email'        => 'anna@example.com',
				'phone'        => '0612345678',
				'event_date'   => '06/12/2027',
				'message'      => 'We would love photos.',
				'source'       => 'wordpress',
				'source_form'  => 'WPForms: Enquiry',
				'page_url'     => 'https://photos.example.com/contact/',
				'extra_fields' => array(
					array(
						'label' => 'Package',
						'value' => 'Full day',
					),
				),
			),
			$this->only_payload()
		);
	}

	public function test_wpforms_hook_firing_twice_for_one_entry_queues_one_lead(): void {
		$this->enable( 'wpforms:7' );
		Functions\when( 'wp_get_raw_referer' )->justReturn( '' );
		$bridge = new Wpforms( $this->dispatcher );
		$form   = array(
			'id'       => 7,
			'settings' => array( 'form_title' => 'Enquiry' ),
		);

		$bridge->on_complete( $this->wpforms_fields(), array(), $form, 31 );
		$bridge->on_complete( $this->wpforms_fields(), array(), $form, 31 );

		$this->assertCount( 1, $this->store->jobs );
	}

	public function test_wpforms_uses_the_entry_post_for_the_page_url(): void {
		$this->enable( 'wpforms:7' );
		Functions\when( 'get_permalink' )->justReturn( 'https://photos.example.com/booking/' );

		( new Wpforms( $this->dispatcher ) )->on_complete( $this->wpforms_fields(), array( 'post_id' => 55 ), array( 'id' => 7 ), 0 );

		$this->assertSame( 'https://photos.example.com/booking/', $this->only_payload()['page_url'] );
	}

	public function test_wpforms_without_a_stored_entry_queues_every_submission(): void {
		$this->enable( 'wpforms:7' );
		Functions\when( 'wp_get_raw_referer' )->justReturn( '' );
		$bridge = new Wpforms( $this->dispatcher );

		$bridge->on_complete( $this->wpforms_fields(), array(), array( 'id' => 7 ), 0 );
		$bridge->on_complete( $this->wpforms_fields(), array(), array( 'id' => 7 ), 0 );

		$this->assertCount( 2, $this->store->jobs );
	}

	private function gf_field( int $id, string $type, string $label, array $inputs = array() ): object {
		return (object) array(
			'id'     => $id,
			'type'   => $type,
			'label'  => $label,
			'inputs' => $inputs,
		);
	}

	public function test_gravity_forms_queues_a_mapped_lead(): void {
		$this->enable( 'gf:3' );
		$form  = array(
			'id'     => 3,
			'title'  => 'Contact',
			'fields' => array(
				$this->gf_field(
					1,
					'name',
					'Name',
					array(
						array(
							'id'    => '1.3',
							'label' => 'First',
						),
						array(
							'id'       => '1.4',
							'label'    => 'Middle',
							'isHidden' => true,
						),
						array(
							'id'    => '1.6',
							'label' => 'Last',
						),
					)
				),
				$this->gf_field( 2, 'email', 'Email' ),
				$this->gf_field( 3, 'phone', 'Phone' ),
				$this->gf_field( 4, 'date', 'Event date' ),
				$this->gf_field( 5, 'textarea', 'Message' ),
				$this->gf_field(
					6,
					'checkbox',
					'Interested in',
					array(
						array(
							'id'    => '6.1',
							'label' => 'Wedding',
						),
						array(
							'id'    => '6.2',
							'label' => 'Portrait',
						),
					)
				),
				$this->gf_field( 7, 'section', 'Extra' ),
			),
		);
		$entry = array(
			'id'         => '88',
			'source_url' => 'https://photos.example.com/contact/',
			'1.3'        => 'Anna',
			'1.4'        => 'Maria',
			'1.6'        => 'de Vries',
			'2'          => 'anna@example.com',
			'3'          => '0612345678',
			'4'          => '2027-06-12',
			'5'          => 'We would love photos.',
			'6.1'        => 'Wedding',
			'6.2'        => 'Portrait',
		);

		( new Gravity_Forms( $this->dispatcher ) )->on_submission( $entry, $form );

		$this->assertSame(
			array(
				'name'         => 'Anna de Vries',
				'email'        => 'anna@example.com',
				'phone'        => '0612345678',
				'event_date'   => '2027-06-12',
				'message'      => 'We would love photos.',
				'source'       => 'wordpress',
				'source_form'  => 'Gravity Forms: Contact',
				'page_url'     => 'https://photos.example.com/contact/',
				'extra_fields' => array(
					array(
						'label' => 'Interested in',
						'value' => 'Wedding, Portrait',
					),
				),
			),
			$this->only_payload()
		);

		( new Gravity_Forms( $this->dispatcher ) )->on_submission( $entry, $form );
		$this->assertCount( 1, $this->store->jobs, 'The same entry is queued once.' );
	}

	public function test_every_bridge_hooks_its_plugins_public_action(): void {
		$cf7 = new Contact_Form_7( $this->dispatcher );
		$wpf = new Wpforms( $this->dispatcher );
		$gf  = new Gravity_Forms( $this->dispatcher );
		$cf7->register();
		$wpf->register();
		$gf->register();

		$this->assertNotFalse( has_action( 'wpcf7_mail_sent', array( $cf7, 'on_mail_sent' ) ) );
		$this->assertNotFalse( has_action( 'wpforms_process_complete', array( $wpf, 'on_complete' ) ) );
		$this->assertNotFalse( has_action( 'gform_after_submission', array( $gf, 'on_submission' ) ) );
	}

	public function test_a_broken_queue_never_breaks_the_form(): void {
		$this->enable( 'cf7:12' );
		$this->enable( 'wpforms:7' );
		$this->enable( 'gf:3' );
		$this->store->throw = true;

		$this->cf7_submit( $this->cf7_form() );
		( new Wpforms( $this->dispatcher ) )->on_complete( $this->wpforms_fields(), array(), array( 'id' => 7 ), 1 );
		( new Gravity_Forms( $this->dispatcher ) )->on_submission(
			array( '2' => 'anna@example.com' ),
			array(
				'id'     => 3,
				'fields' => array( $this->gf_field( 2, 'email', 'Email' ) ),
			)
		);

		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_malformed_hook_arguments_are_ignored(): void {
		( new Wpforms( $this->dispatcher ) )->on_complete( 'nope', null, null, null );
		( new Gravity_Forms( $this->dispatcher ) )->on_submission( 'nope', null );
		( new Contact_Form_7( $this->dispatcher ) )->on_mail_sent( 'nope' );

		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_a_submission_without_a_usable_email_is_not_queued(): void {
		$this->enable( 'wpforms:7' );
		Functions\when( 'wp_get_raw_referer' )->justReturn( '' );
		$fields             = $this->wpforms_fields();
		$fields[2]['value'] = 'not an address';

		( new Wpforms( $this->dispatcher ) )->on_complete( $fields, array(), array( 'id' => 7 ), 5 );

		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_the_payload_filter_can_change_or_drop_a_lead(): void {
		$this->enable( 'cf7:12' );
		\Brain\Monkey\Filters\expectApplied( 'profotograaf_lead_payload' )->once()->andReturn( null );

		$this->cf7_submit( $this->cf7_form() );

		$this->assertSame( array(), $this->store->jobs );
	}
}
