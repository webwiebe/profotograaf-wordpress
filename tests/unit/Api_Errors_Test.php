<?php
/**
 * Api_Errors tests: platform error codes map to local translatable messages.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Api_Errors;
use Profotograaf\Logger;

class Api_Errors_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		Logger::clear();
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * Builds an http() response array.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $body   Body.
	 * @return array{status:int,retry_after:int,body:mixed}
	 */
	private function response( int $status, $body ): array {
		return array(
			'status'      => $status,
			'retry_after' => 0,
			'body'        => $body,
		);
	}

	public function test_every_known_code_has_a_translatable_message(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-api-errors.php' );

		$this->assertNotEmpty( Api_Errors::known_codes() );
		foreach ( Api_Errors::known_codes() as $code ) {
			$message = Api_Errors::message_for( $code );
			$this->assertNotSame( '', $message, $code );
			$this->assertStringContainsString( "__( '" . $message . "', 'profotograaf' )", $source, $code );
		}
	}

	public function test_every_known_code_has_a_distinct_message(): void {
		$messages = array_map( array( Api_Errors::class, 'message_for' ), Api_Errors::known_codes() );

		$this->assertSame( $messages, array_values( array_unique( $messages ) ) );
	}

	public function test_a_known_code_replaces_the_platform_text(): void {
		$error = Api_Errors::http( $this->response( 400, array( 'error' => 'polling too fast', 'code' => 'device.slow_down' ) ) );

		$this->assertSame( Api_Errors::message_for( 'device.slow_down' ), $error->get_error_message() );
		$this->assertSame( 'device.slow_down', $error->get_error_data()['code'] );
	}

	public function test_an_unknown_code_shows_a_generic_message_and_logs_the_raw_text(): void {
		$error = Api_Errors::http( $this->response( 422, array( 'error' => 'Quota ueberschritten', 'code' => 'billing.unheard_of' ) ) );

		$this->assertSame( 'The platform reported an error (HTTP 422). Try again later.', $error->get_error_message() );
		$entries = Logger::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Quota ueberschritten', $entries[0]['context']['reason'] );
		$this->assertSame( 'billing.unheard_of', $entries[0]['context']['code'] );
	}

	public function test_a_response_without_text_keeps_the_http_status_message(): void {
		$error = Api_Errors::http( $this->response( 503, array() ) );

		$this->assertSame( 'Profotograaf answered with HTTP 503.', $error->get_error_message() );
		$this->assertSame( array(), Logger::entries() );
	}
}
