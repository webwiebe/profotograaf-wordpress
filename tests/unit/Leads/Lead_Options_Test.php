<?php
/**
 * Tests for the lead alert options in the settings schema.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Profotograaf\Settings_Schema;

class Lead_Options_Test extends Leads_Test_Case {

	public function test_the_email_options_accept_an_address_or_nothing(): void {
		$this->assertSame( 'owner@example.com', Settings_Schema::parse( 'leads_alert_email', ' owner@example.com ' ) );
		$this->assertSame( '', Settings_Schema::parse( 'leads_fallback_email', '  ' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_alert_email', 'not an address' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_fallback_email', array( 'x' ) ) );
	}

	public function test_the_retention_is_one_of_the_offered_periods(): void {
		$this->assertSame( '90', Settings_Schema::parse( 'leads_failed_retention', '90' ) );
		$this->assertNull( Settings_Schema::parse( 'leads_failed_retention', '1' ) );
		$this->assertSame( '30', Settings_Schema::entry( 'leads_failed_retention' )['default'] );
	}

	public function test_a_saved_junk_address_falls_back_to_the_admin_email_for_alerts(): void {
		$this->options['profotograaf_settings']['leads_alert_email'] = 'junk';
		$this->queue->enqueue( 'a', array( 'email' => 'a@example.com' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad', 400 );

		$this->assertSame( 'admin@example.com', $this->mails[0][0] );
	}
}
