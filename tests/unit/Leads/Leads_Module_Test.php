<?php
/**
 * Leads module: the bridges register on init, so nothing translates earlier.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Modules\Leads;
use Profotograaf\Plugin;
use Profotograaf\Settings;

class Leads_Module_Test extends Leads_Test_Case {

	/**
	 * Strings translated so far.
	 *
	 * @var string[]
	 */
	private array $translated = array();

	/**
	 * The callback the module added to init.
	 *
	 * @var callable|null
	 */
	private $on_init = null;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( '__' )->alias(
			function ( $text ) {
				$this->translated[] = $text;
				return $text;
			}
		);
		Actions\expectAdded( 'init' )->whenHappen(
			function ( $callback ) {
				$this->on_init = $callback;
			}
		);
	}

	public function test_register_translates_nothing_and_defers_the_bridges_to_init(): void {
		( new Leads() )->register( new Plugin() );

		$this->assertSame( array(), $this->translated );
		$this->assertFalse( has_action( 'wpcf7_mail_sent' ) );
		$this->assertIsCallable( $this->on_init );

		( $this->on_init )();

		$this->assertNotFalse( has_action( 'wpcf7_mail_sent' ) );
		$this->assertNotFalse( has_action( 'wpforms_process_complete' ) );
		$this->assertNotFalse( has_action( 'gform_after_submission' ) );
	}

	public function test_the_bridges_stay_off_when_enquiries_are_off(): void {
		$this->options[ Settings::OPTION ] = array( 'leads_enabled' => false );

		( new Leads() )->register( new Plugin() );
		$this->assertIsCallable( $this->on_init );
		( $this->on_init )();

		$this->assertFalse( has_action( 'wpcf7_mail_sent' ) );
		$this->assertFalse( has_action( 'wpforms_process_complete' ) );
		$this->assertFalse( has_action( 'gform_after_submission' ) );
	}
}
