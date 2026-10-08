<?php
/**
 * Privacy policy text, exporter and eraser.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Connection;
use Profotograaf\Modules\Privacy;
use Profotograaf\Plugin;
use Profotograaf\Tests\Leads\Leads_Test_Case;

class Privacy_Test extends Leads_Test_Case {

	private function module(): Privacy {
		return new Privacy( $this->store );
	}

	private function lead( string $id, string $email, string $status = 'pending' ): void {
		$this->store->put(
			$id,
			array(
				'id'         => $id,
				'status'     => $status,
				'created_at' => 1000,
				'payload'    => array(
					'name'         => 'Ada',
					'email'        => $email,
					'phone'        => '0612345678',
					'message'      => 'Hello',
					'source_form'  => 'Contact',
					'page_url'     => 'https://photos.example.com/contact',
					'extra_fields' => array(
						array(
							'label' => 'Venue',
							'value' => 'Barn',
						),
					),
				),
			)
		);
	}

	public function test_register_adds_the_hooks(): void {
		$module = $this->module();
		$module->register( new Plugin( new Connection() ) );

		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters', array( $module, 'add_exporter' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( $module, 'add_eraser' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $module, 'add_policy_content' ) ) );
	}

	public function test_exporter_and_eraser_are_listed(): void {
		$module = $this->module();

		$exporters = $module->add_exporter( array( 'other' => 1 ) );
		$this->assertSame( array( $module, 'export' ), $exporters[ Privacy::EXPORTER ]['callback'] );
		$this->assertSame( 1, $exporters['other'] );

		$erasers = $module->add_eraser( array() );
		$this->assertSame( array( $module, 'erase' ), $erasers[ Privacy::ERASER ]['callback'] );
		$this->assertSame( 'x', $module->add_eraser( 'x' ) );
		$this->assertSame( 'x', $module->add_exporter( 'x' ) );
	}

	public function test_export_matches_email_case_insensitively_and_includes_failed_jobs(): void {
		$this->lead( 'a', 'Ada@Example.com' );
		$this->lead( 'b', 'ada@example.com', 'failed' );
		$this->lead( 'c', 'other@example.com' );

		$result = $this->module()->export( 'ADA@example.COM' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 2, $result['data'] );
		$item = $result['data'][0];
		$this->assertSame( Privacy::EXPORTER, $item['group_id'] );
		$flat = array();
		foreach ( $item['data'] as $row ) {
			$flat[ $row['name'] ] = $row['value'];
		}
		$this->assertSame( 'Ada', $flat['Name'] );
		$this->assertSame( 'Ada@Example.com', $flat['Email'] );
		$this->assertSame( 'Barn', $flat['Venue'] );
		$this->assertSame( 'Waiting', $flat['Delivery status'] );
	}

	public function test_export_paginates(): void {
		for ( $i = 0; $i < Privacy::PER_PAGE + 5; $i++ ) {
			$this->lead( sprintf( 'job%03d', $i ), 'ada@example.com' );
		}

		$first = $this->module()->export( 'ada@example.com', 1 );
		$this->assertFalse( $first['done'] );
		$this->assertCount( Privacy::PER_PAGE, $first['data'] );

		$second = $this->module()->export( 'ada@example.com', 2 );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 5, $second['data'] );
	}

	public function test_export_with_no_match_is_done_and_empty(): void {
		$this->lead( 'c', 'other@example.com' );
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->module()->export( 'ada@example.com' )
		);
		$this->assertTrue( $this->module()->export( '' )['done'] );
	}

	public function test_erase_removes_pending_and_failed_jobs_of_the_email_only(): void {
		$this->lead( 'a', 'Ada@example.com' );
		$this->lead( 'b', 'ada@example.com', 'failed' );
		$this->lead( 'c', 'other@example.com' );

		$result = $this->module()->erase( 'ada@EXAMPLE.com' );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertSame( array( 'c' ), array_keys( $this->store->jobs ) );
	}

	public function test_erase_works_in_batches(): void {
		for ( $i = 0; $i < Privacy::PER_PAGE + 5; $i++ ) {
			$this->lead( sprintf( 'job%03d', $i ), 'ada@example.com' );
		}

		$first = $this->module()->erase( 'ada@example.com', 1 );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 5, $this->store->jobs );

		$second = $this->module()->erase( 'ada@example.com', 2 );
		$this->assertTrue( $second['done'] );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_erase_without_a_match_removes_nothing(): void {
		$result = $this->module()->erase( 'ada@example.com' );
		$this->assertTrue( $result['done'] );
		$this->assertFalse( $result['items_removed'] );
	}

	public function test_policy_content_is_registered_with_the_core_function(): void {
		$captured = array();
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			function ( $name, $content ) use ( &$captured ) {
				$captured = array( $name, $content );
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();

		$this->module()->add_policy_content();

		$this->assertSame( 'Profotograaf', $captured[0] );
		$this->assertStringContainsString( 'profotograaf.nl', $captured[1] );
		$this->assertStringContainsString( 'Enquiries', $captured[1] );
		$this->assertStringContainsString( 'Photo library in the editor', $captured[1] );
		$this->assertStringContainsString( 'stored in the Media Library', $captured[1] );
		$this->assertStringContainsString( 'opts in to anonymous usage data', $captured[1] );
		$this->assertStringNotContainsString( 'sends no usage statistics or error reports', $captured[1] );
	}
}
