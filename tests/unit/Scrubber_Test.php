<?php
/**
 * Scrubber tests: tokens, emails, URLs and paths never survive.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Scrubber;

class Scrubber_Test extends TestCase {

	public function test_bearer_tokens_and_long_keys_become_token(): void {
		$this->assertSame( 'failed with [TOKEN]', Scrubber::string( 'failed with Bearer abc.def-123' ) );
		$this->assertSame( 'key [TOKEN] rejected', Scrubber::string( 'key pft_9f8a7b6c5d4e3f2a1b0c9d8e7f rejected' ) );
		$this->assertSame( '[TOKEN]', Scrubber::string( 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r' ) );
		$this->assertSame( 'token=[TOKEN]', Scrubber::string( 'token=short1' ) );
	}

	public function test_emails_become_email(): void {
		$this->assertSame( 'sent to [EMAIL] and [EMAIL].', Scrubber::string( 'sent to jane.doe+x@mail.example.com and bob@example.nl.' ) );
	}

	public function test_urls_and_domains_become_url(): void {
		$this->assertSame( 'GET [URL] failed', Scrubber::string( 'GET https://photos.example.com/gallery/1?token=abc failed' ) );
		$this->assertSame( 'see [URL] and [URL]', Scrubber::string( 'see www.example.org/x and shop.example.nl' ) );
		$this->assertSame( '[URL]', Scrubber::string( 'http://localhost:8080/wp-json' ) );
	}

	public function test_a_url_holding_an_email_becomes_one_url(): void {
		$this->assertSame( '[URL]', Scrubber::string( 'https://example.com/unsubscribe/jane@example.com' ) );
	}

	public function test_paths_outside_the_plugin_become_path_and_plugin_paths_turn_relative(): void {
		$this->assertSame( 'in [PATH] and [PATH]', Scrubber::string( 'in /var/www/html/wp-content/themes/x/functions.php and C:\\inetpub\\site\\index.php' ) );
		$this->assertSame( 'includes/class-api-client.php:142', Scrubber::string( PROFOTOGRAAF_DIR . 'includes/class-api-client.php:142' ) );
	}

	public function test_plain_text_and_file_names_are_kept(): void {
		$this->assertSame( 'includes/class-api-client.php:142 api_timeout 429', Scrubber::string( 'includes/class-api-client.php:142 api_timeout 429' ) );
	}

	public function test_event_scrubs_nested_strings_and_drops_other_values(): void {
		$event = Scrubber::event(
			array(
				'error_code'  => 'bad mail@example.com',
				'http_status' => 500,
				'nested'      => array(
					'url' => 'https://example.com',
					'n'   => 1,
				),
				'object'      => new \stdClass(),
			)
		);

		$this->assertSame( 'bad [EMAIL]', $event['error_code'] );
		$this->assertSame( 500, $event['http_status'] );
		$this->assertSame(
			array(
				'url' => '[URL]',
				'n'   => 1,
			),
			$event['nested']
		);
		$this->assertArrayNotHasKey( 'object', $event );
	}

	public function test_code_keeps_only_slug_characters(): void {
		$this->assertSame( 'profotograaf_api_timeout', Scrubber::code( 'profotograaf_api_timeout' ) );
		$this->assertSame( 'unknown', Scrubber::code( '' ) );
		$this->assertSame( 'a_b_c_d', Scrubber::code( 'a b/c<d' ) );
		$this->assertSame( 'bad_EMAIL_URL', Scrubber::code( 'bad jane@example.com https://x.example.com' ) );
		$this->assertSame( 64, strlen( Scrubber::code( str_repeat( 'a', 100 ) ) ) );
	}
}
