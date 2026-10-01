<?php
/**
 * Tests for the lead alert options in the settings schema.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\Submission;
use Profotograaf\Settings_Schema;

class Lead_Options_Test extends Leads_Test_Case {

	public function test_the_email_options_accept_an_address_or_nothing(): void {
		$this->assertSame( 'owner@example.com', Settings_Schema::parse( 'leads_alert_email', ' owner@example.com ' ) );
		$this->assertSame( '', Settings_Schema::parse( 'leads_fallback_email', '  ' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_alert_email', 'not an address' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_fallback_email', array( 'x' ) ) );
	}

	public function test_the_retention_is_a_number_of_days_from_one_to_ninety(): void {
		$this->assertSame( 90, Settings_Schema::parse( 'leads_failed_retention', '90' ) );
		$this->assertSame( 1, Settings_Schema::parse( 'leads_failed_retention', 1 ) );
		$this->assertSame( 45, Settings_Schema::parse( 'leads_failed_retention', ' 45 ' ) );
		$this->assertSame( 90, Settings_Schema::parse( 'leads_failed_retention', '365' ) );
		$this->assertSame( 1, Settings_Schema::parse( 'leads_failed_retention', '0' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_failed_retention', 'soon' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_failed_retention', array( 5 ) ) );
		$this->assertSame( 7, Settings_Schema::entry( 'leads_failed_retention' )['default'] );
	}

	public function test_the_retention_is_an_integer_with_bounds_in_the_rest_schema(): void {
		$property = Settings_Schema::rest_schema()['schema']['properties']['leads_failed_retention'];

		$this->assertSame( 'integer', $property['type'] );
		$this->assertSame( 1, $property['minimum'] );
		$this->assertSame( 90, $property['maximum'] );
	}

	public function test_a_saved_junk_address_falls_back_to_the_admin_email_for_alerts(): void {
		$this->options['profotograaf_settings']['leads_alert_email'] = 'junk';
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );

		$this->assertSame( 'admin@example.com', $this->mails[0][0] );
	}

	public function test_a_source_tag_replaces_the_form_label_in_the_lead(): void {
		$fields = array(
			array(
				'id'    => 'email',
				'label' => 'Email',
				'type'  => 'email',
				'value' => 'anna@example.com',
			),
		);
		$this->options[ Form_Settings::OPTION ]['forms']['cf7:12'] = array(
			'enabled'    => true,
			'map'        => array(),
			'source_tag' => 'Wedding page',
		);
		$this->options[ Form_Settings::OPTION ]['forms']['cf7:13'] = array(
			'enabled' => true,
			'map'     => array(),
		);

		$this->dispatcher->submit( new Submission( 'cf7:12', 'Contact Form 7: Wedding', $fields, '', 'e1' ) );
		$this->assertSame( 'Wedding page', $this->only_payload()['source_form'] );

		$this->store->jobs = array();
		$this->dispatcher->submit( new Submission( 'cf7:13', 'Contact Form 7: Family', $fields, '', 'e2' ) );
		$this->assertSame( 'Contact Form 7: Family', $this->only_payload()['source_form'] );
	}
}
