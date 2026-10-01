<?php
/**
 * Embed script version tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the tests assert on script markup.

use Brain\Monkey\Functions;
use Profotograaf\Embed_Script;

class Embed_Script_Test extends Gallery_Test_Case {

	public function test_the_first_use_schedules_a_version_lookup(): void {
		$scheduled = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);

		$this->script->enqueue();

		$this->assertSame( array( Embed_Script::REFRESH_HOOK ), $scheduled );
	}

	public function test_a_fresh_version_needs_no_lookup(): void {
		$this->options['profotograaf_embed_version'] = array(
			'version'    => '0123456789ab',
			'checked_at' => time(),
		);
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->script->enqueue();
	}

	public function test_refresh_stores_the_etag_as_the_version(): void {
		Functions\expect( 'wp_remote_head' )->once()->with( 'https://profotograaf.nl/share/embed/embed.js', \Mockery::type( 'array' ) )->andReturn( array( 'etag' => '"0123456789ab"' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->alias( fn( $response, $name ) => $response[ $name ] ?? '' );

		$this->script->refresh();

		$this->assertSame( '0123456789ab', $this->options['profotograaf_embed_version']['version'] );
		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.0123456789ab.js', $this->script->url() );
	}

	public function test_a_failed_refresh_keeps_the_known_version(): void {
		$this->options['profotograaf_embed_version'] = array(
			'version'    => '0123456789ab',
			'checked_at' => 1,
		);
		Functions\when( 'wp_remote_head' )->justReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );

		$this->script->refresh();

		$this->assertSame( '0123456789ab', $this->options['profotograaf_embed_version']['version'] );
		$this->assertGreaterThan( 1, $this->options['profotograaf_embed_version']['checked_at'], 'The attempt is recorded so it is not retried on every page view.' );
	}

	public function test_an_etag_that_is_not_a_version_is_ignored(): void {
		Functions\when( 'wp_remote_head' )->justReturn( array( 'etag' => '"not-a-hash"' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->alias( fn( $response, $name ) => $response[ $name ] ?? '' );

		$this->script->refresh();

		$this->assertSame( '', $this->options['profotograaf_embed_version']['version'] );
		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.js', $this->script->url() );
	}

	public function test_the_footer_that_already_ran_gets_the_tag_once_by_hand(): void {
		Functions\when( 'did_action' )->justReturn( 1 );

		ob_start();
		$this->script->enqueue();
		$this->script->enqueue();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, '<script ' ) );
		$this->assertStringStartsWith( '<script async src="https://profotograaf.nl/share/embed/embed.js" onerror=', $output );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_version_filter_can_pin_a_version(): void {
		\Brain\Monkey\Filters\expectApplied( 'profotograaf_embed_script_version' )->andReturn( 'aaaaaaaaaaaa' );

		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.aaaaaaaaaaaa.js', $this->script->url() );
	}

	public function test_the_failure_handler_logs_an_actionable_console_message(): void {
		Functions\when( 'wp_json_encode' )->alias( fn( $value, $flags = 0 ) => json_encode( $value, $flags ) );

		$js = $this->script->error_handler();

		$this->assertStringContainsString( 'console.error(', $js );
		$this->assertStringContainsString( 'could not be loaded from https://profotograaf.nl/share/embed/embed.js', $js );
		$this->assertStringContainsString( 'Content-Security-Policy', $js );
		$this->assertStringContainsString( 'data-profotograaf-failed', $js );
		$this->assertStringNotContainsString( '<', $js );
	}

	public function test_the_queued_tag_gets_the_failure_handler_once(): void {
		$tag = '<script src="https://profotograaf.nl/share/embed/embed.js" id="profotograaf-embed-js" async></script>';
		$out = $this->script->add_error_handler( $tag, Embed_Script::HANDLE );

		$this->assertStringStartsWith( '<script onerror="console.error(', $out );
		$this->assertSame( $out, $this->script->add_error_handler( $out, Embed_Script::HANDLE ) );
		$this->assertSame( $tag, $this->script->add_error_handler( $tag, 'other' ) );
	}

	public function test_a_late_printed_tag_carries_the_failure_handler(): void {
		Functions\when( 'did_action' )->justReturn( 1 );

		ob_start();
		$this->script->enqueue();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<script async src="https://profotograaf.nl/share/embed/embed.js" onerror="console.error(', $html );
	}
}
