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
use Profotograaf\Empty_Gallery;

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

	public function test_refresh_reads_the_version_from_the_script_route_without_a_token(): void {
		$this->connect();
		$http   = new Fake_Transport();
		$script = new Embed_Script( $http );
		$http->reply(
			200,
			array(
				'script_url' => '/share/embed/embed.9ea0aff359ae.js',
				'version'    => '9ea0aff359ae',
			)
		);

		$script->refresh();

		$this->assertCount( 1, $http->requests );
		$this->assertSame( 'GET', $http->requests[0]['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/script', $http->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $http->requests[0]['headers'] );
		$this->assertSame( '9ea0aff359ae', $this->options['profotograaf_embed_version']['version'] );
		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.9ea0aff359ae.js', $script->url() );
	}

	public function test_refresh_takes_the_version_from_the_script_url_when_the_field_is_missing(): void {
		$http   = new Fake_Transport();
		$script = new Embed_Script( $http );
		$http->reply( 200, array( 'script_url' => '/share/embed/embed.0123456789ab.js' ) );

		$script->refresh();

		$this->assertSame( '0123456789ab', $this->options['profotograaf_embed_version']['version'] );
	}

	public function test_a_failed_refresh_keeps_the_known_version(): void {
		foreach ( array( 'network', 'status', 'body', 'throw' ) as $failure ) {
			$this->options['profotograaf_embed_version'] = array(
				'version'    => '0123456789ab',
				'checked_at' => 1,
			);

			$http = new Fake_Transport();
			if ( 'network' === $failure ) {
				$http->fail( new \WP_Error( 'http_request_failed', 'timeout' ) );
			} elseif ( 'status' === $failure ) {
				$http->reply( 503, array( 'error' => 'unavailable' ) );
			} elseif ( 'body' === $failure ) {
				$http->reply( 200, array( 'version' => 'not-a-hash' ) );
			} else {
				$http->fail( new \RuntimeException( 'boom' ) );
			}

			( new Embed_Script( $http ) )->refresh();

			$this->assertSame( '0123456789ab', $this->options['profotograaf_embed_version']['version'], $failure );
			$this->assertGreaterThan( 1, $this->options['profotograaf_embed_version']['checked_at'], 'The attempt is recorded so it is not retried on every page view.' );
		}
	}

	public function test_a_value_that_is_not_a_version_is_ignored(): void {
		$http   = new Fake_Transport();
		$script = new Embed_Script( $http );
		$http->reply(
			200,
			array(
				'script_url' => '/share/embed/embed.js',
				'version'    => 'not-a-hash',
			)
		);

		$script->refresh();

		$this->assertSame( '', $this->options['profotograaf_embed_version']['version'] );
		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.js', $script->url() );
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

	public function test_the_queued_script_gets_the_empty_gallery_watcher_after_it(): void {
		$this->script->enqueue();

		$this->assertSame( array( Empty_Gallery::script() ), $this->inline_scripts[ Embed_Script::HANDLE ] );
	}

	public function test_a_hand_printed_tag_is_followed_by_the_empty_gallery_watcher(): void {
		Functions\when( 'did_action' )->justReturn( 1 );

		ob_start();
		$this->script->enqueue();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '</script><script>(function(){var S="[data-profotograaf-gallery]"', $output );
	}

	public function test_the_watcher_marks_a_host_that_embed_js_left_without_photos(): void {
		$js = Empty_Gallery::script();

		$this->assertStringContainsString( 'data-pf-ready', $js );
		$this->assertStringContainsString( 'shadowRoot', $js );
		$this->assertStringContainsString( '/api/v1/embed/galleries/', $js );
		$this->assertStringContainsString( 'setAttribute("data-pf-empty","")', $js );
		$this->assertStringContainsString( 'removeAttribute("data-profotograaf-failed")', $js );
	}
}
