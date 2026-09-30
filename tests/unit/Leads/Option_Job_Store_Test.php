<?php
/**
 * Option job store tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Tests\Wp_Test_Case;

class Option_Job_Store_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		$options = &$this->options;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fake database for this test.
		$GLOBALS['wpdb'] = new class( $options ) {
			public $options = 'wp_options';

			private $rows;

			public $last_sql = '';

			public function __construct( array &$rows ) {
				$this->rows = &$rows;
			}

			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( $sql, ...$args ) {
				$this->last_sql = $sql;
				return array( $sql, $args );
			}

			public function get_results( $query, $output ) {
				$prefix = stripslashes( rtrim( $query[1][0], '%' ) );
				$found  = array();
				foreach ( $this->rows as $name => $value ) {
					if ( 0 === strpos( $name, $prefix ) ) {
						$found[] = array(
							'option_name'  => $name,
							'option_value' => $value,
						);
					}
				}
				return $found;
			}
		};
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_jobs_are_options_that_are_not_autoloaded(): void {
		$store = new Option_Job_Store();

		$this->assertTrue( $store->add( 'abc', array( 'status' => 'pending' ) ) );

		$this->assertFalse( $this->autoload['profotograaf_lead_job_abc'] ?? null );
		$this->assertSame( array( 'abc' => array( 'status' => 'pending' ) ), $store->all() );
	}

	public function test_adding_an_existing_id_changes_nothing(): void {
		$store = new Option_Job_Store();
		$store->add( 'abc', array( 'status' => 'pending' ) );

		$this->assertFalse( $store->add( 'abc', array( 'status' => 'other' ) ) );
		$this->assertSame( 'pending', $store->all()['abc']['status'] );
	}

	public function test_put_and_delete(): void {
		$store = new Option_Job_Store();
		$store->add( 'abc', array( 'status' => 'pending' ) );

		$store->put( 'abc', array( 'status' => 'failed' ) );
		$this->assertSame( 'failed', $store->all()['abc']['status'] );

		$store->delete( 'abc' );
		$this->assertSame( array(), $store->all() );
	}

	public function test_the_lock_is_exclusive_and_expires(): void {
		$store = new Option_Job_Store();

		$this->assertTrue( $store->acquire( 1000, 300 ) );
		$this->assertFalse( $store->acquire( 1100, 300 ) );
		$this->assertTrue( $store->acquire( 1300, 300 ), 'A crashed run does not block delivery forever.' );

		$store->release();
		$this->assertTrue( $store->acquire( 1301, 300 ) );
	}
}
