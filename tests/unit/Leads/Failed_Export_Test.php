<?php
/**
 * Failed lead export tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use PHPUnit\Framework\TestCase;
use Profotograaf\Leads\Failed_Export;

class Failed_Export_Test extends TestCase {

	public function test_it_writes_a_header_and_one_line_per_job(): void {
		$csv = Failed_Export::build(
			array(
				'a' => array(
					'created_at'  => 1000000,
					'failed_at'   => 1000600,
					'last_error'  => 'bad input',
					'last_status' => 400,
					'attempts'    => 1,
					'payload'     => array(
						'name'         => 'Anna "A" Jansen',
						'email'        => 'anna@example.com',
						'message'      => "Line one\nLine two",
						'source_form'  => 'CF7: Wedding',
						'extra_fields' => array( array( 'label' => 'Venue', 'value' => 'Barn' ) ),
					),
				),
			)
		);

		$this->assertStringStartsWith( "\xEF\xBB\xBF\"received\",\"failed\",\"form\"", $csv );
		$this->assertStringContainsString( '"1970-01-12 13:46:40 UTC","1970-01-12 13:56:40 UTC","CF7: Wedding","Anna ""A"" Jansen","anna@example.com","","","Line one' . "\n" . 'Line two","Venue: Barn","bad input","400","1"', $csv );
	}

	public function test_a_value_that_looks_like_a_formula_is_neutralised(): void {
		$csv = Failed_Export::build( array( array( 'payload' => array( 'name' => '=HYPERLINK("http://evil")', 'phone' => '+31 6 1234' ) ) ) );

		$this->assertStringContainsString( '"\'=HYPERLINK(""http://evil"")"', $csv );
		$this->assertStringContainsString( '"\'+31 6 1234"', $csv );
	}

	public function test_no_jobs_give_only_the_header(): void {
		$this->assertSame( "\xEF\xBB\xBF" . '"' . implode( '","', Failed_Export::columns() ) . "\"\r\n", Failed_Export::build( array() ) );
	}
}
