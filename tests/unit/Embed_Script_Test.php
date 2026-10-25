<?php
/**
 * Embed script tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the tests assert on script markup.

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Embed_Script;
use Profotograaf\Empty_Gallery;

class Embed_Script_Test extends Gallery_Test_Case {

	public function test_the_script_comes_from_the_plugin_not_from_the_platform(): void {
		$this->assertSame( PROFOTOGRAAF_URL . 'build/embed/embed.js', $this->script->url() );
		$this->assertStringNotContainsString( 'profotograaf.nl', $this->script->url() );
	}

	public function test_the_script_is_queued_with_the_plugin_version(): void {
		$this->script->enqueue();

		$this->assertSame( PROFOTOGRAAF_VERSION, $this->enqueued[ Embed_Script::HANDLE ]['ver'] ?? null );
	}

	public function test_the_footer_that_already_ran_gets_the_tag_once_by_hand(): void {
		Functions\when( 'did_action' )->justReturn( 1 );

		ob_start();
		$this->script->enqueue();
		$this->script->enqueue();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, '<script ' ) );
		$this->assertStringStartsWith( '<script async src="https://example.test/wp-content/plugins/profotograaf/build/embed/embed.js?ver=0.0.0-test" data-platform="https://profotograaf.nl" onerror=', $output );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_failure_handler_logs_an_actionable_console_message(): void {
		Functions\when( 'wp_json_encode' )->alias( fn( $value, $flags = 0 ) => json_encode( $value, $flags ) );

		$js = $this->script->error_handler();

		$this->assertStringContainsString( 'console.error(', $js );
		$this->assertStringContainsString( 'could not be loaded from https://example.test/wp-content/plugins/profotograaf/build/embed/embed.js', $js );
		$this->assertStringContainsString( 'Content-Security-Policy', $js );
		$this->assertStringContainsString( 'data-profotograaf-failed', $js );
		$this->assertStringNotContainsString( '<', $js );
	}

	public function test_the_queued_tag_gets_the_failure_handler_once(): void {
		$tag = '<script src="https://example.test/wp-content/plugins/profotograaf/build/embed/embed.js" id="profotograaf-embed-js" async></script>';
		$out = $this->script->add_error_handler( $tag, Embed_Script::HANDLE );

		$this->assertStringStartsWith( '<script data-platform="https://profotograaf.nl" onerror="console.error(', $out );
		$this->assertSame( $out, $this->script->add_error_handler( $out, Embed_Script::HANDLE ) );
		$this->assertSame( $tag, $this->script->add_error_handler( $tag, 'other' ) );
	}

	public function test_a_late_printed_tag_carries_the_failure_handler(): void {
		Functions\when( 'did_action' )->justReturn( 1 );

		ob_start();
		$this->script->enqueue();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<script async src="https://example.test/wp-content/plugins/profotograaf/build/embed/embed.js?ver=0.0.0-test" data-platform="https://profotograaf.nl" onerror="console.error(', $html );
	}

	public function test_the_queued_script_keeps_async_and_the_watcher_goes_to_the_footer_once(): void {
		Actions\expectAdded( 'wp_footer' )->once()->with( array( $this->script, 'print_watcher' ), 100 );

		$this->script->enqueue();
		$this->script->enqueue();

		ob_start();
		$this->script->print_watcher();
		$this->assertSame( '<script>' . Empty_Gallery::script() . '</script>', ob_get_clean() );
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

	public function test_the_watcher_listens_for_the_state_event_and_reads_the_state_attribute(): void {
		$js = Empty_Gallery::script();

		foreach ( array( 'addEventListener("profotograaf:state"', 'getAttribute("data-pf-state")', 'setAttribute("data-profotograaf-failed","")' ) as $needle ) {
			$this->assertStringContainsString( $needle, $js );
		}
	}
}
