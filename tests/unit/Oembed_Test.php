<?php
/**
 * oEmbed provider tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the tests assert on script markup.

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Oembed;

class Oembed_Test extends Gallery_Test_Case {

	private Oembed $oembed;

	protected function setUp(): void {
		parent::setUp();
		$this->oembed = new Oembed( $this->script );
	}

	/**
	 * Whether any registered pattern matches.
	 *
	 * @param string $url URL.
	 */
	private function is_matched( string $url ): bool {
		foreach ( $this->oembed->patterns() as $pattern ) {
			if ( 1 === preg_match( $pattern, $url ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_it_hooks_init_and_the_html_filter(): void {
		Functions\expect( 'add_action' )->once()->with( 'init', \Mockery::type( 'array' ) );
		Functions\expect( 'add_filter' )->once()->with( 'embed_oembed_html', \Mockery::type( 'array' ), 10, 2 );

		$this->oembed->register();
	}

	public function test_the_provider_is_registered_for_the_platform_oembed_endpoint(): void {
		$registered = array();
		Functions\when( 'wp_oembed_add_provider' )->alias(
			function ( $format, $provider, $regex ) use ( &$registered ) {
				$registered[] = compact( 'format', 'provider', 'regex' );
			}
		);

		$this->oembed->register_providers();

		$this->assertCount( 1, $registered );
		$this->assertSame( 'https://profotograaf.nl/oembed', $registered[0]['provider'] );
		$this->assertTrue( $registered[0]['regex'] );
	}

	public function test_share_links_on_the_platform_and_on_subdomains_match(): void {
		$this->assertTrue( $this->is_matched( 'https://profotograaf.nl/share/g/spring-wedding' ) );
		$this->assertTrue( $this->is_matched( 'https://profotograaf.nl/share/g/spring-wedding/' ) );
		$this->assertTrue( $this->is_matched( 'http://anna.profotograaf.nl/share/g/spring-wedding?x=1' ) );
		$this->assertTrue( $this->is_matched( 'https://ANNA.PROFOTOGRAAF.NL/share/g/spring-wedding' ) );
	}

	public function test_other_links_do_not_match(): void {
		$this->assertFalse( $this->is_matched( 'https://profotograaf.nl/anna' ) );
		$this->assertFalse( $this->is_matched( 'https://profotograaf.nl/share/g/' ) );
		$this->assertFalse( $this->is_matched( 'https://profotograaf.nl/share/g/a/photo/b' ) );
		$this->assertFalse( $this->is_matched( 'https://evilprofotograaf.nl/share/g/x' ) );
		$this->assertFalse( $this->is_matched( 'https://profotograaf.nl.evil.example/share/g/x' ) );
		$this->assertFalse( $this->is_matched( 'https://example.com/share/g/x' ) );
	}

	public function test_a_site_can_add_a_custom_domain_through_the_filter(): void {
		Filters\expectApplied( 'profotograaf_oembed_hosts' )->andReturn( array( 'photos.example.com', 'bad host!', 'x.example.org:8443' ) );

		$this->assertTrue( $this->is_matched( 'https://photos.example.com/share/g/spring' ) );
		$this->assertTrue( $this->is_matched( 'https://x.example.org:8443/share/g/spring' ) );
		$this->assertFalse( $this->is_matched( 'https://other.example.com/share/g/spring' ) );
		$this->assertCount( 3, $this->oembed->patterns(), 'The invalid host is dropped.' );
	}

	public function test_the_platform_url_override_moves_the_provider(): void {
		Filters\expectApplied( 'profotograaf_platform_url' )->andReturn( 'http://mock-platform:8090' );

		$this->assertTrue( $this->is_matched( 'http://mock-platform:8090/share/g/spring' ) );
		$this->assertFalse( $this->is_matched( 'https://profotograaf.nl/share/g/spring' ) );
	}

	public function test_the_script_tag_of_our_result_is_replaced_by_the_queued_script(): void {
		$html = '<div data-profotograaf-gallery="g-1"><a href="https://profotograaf.nl/share/g/spring">Spring</a></div>'
			. '<script async src="https://profotograaf.nl/share/embed/embed.0123456789ab.js"></script>';

		$result = $this->oembed->filter_html( $html, 'https://profotograaf.nl/share/g/spring' );

		$this->assertSame( '<div data-profotograaf-gallery="g-1"><a href="https://profotograaf.nl/share/g/spring">Spring</a></div>', $result );
		$this->assertSame( 'https://profotograaf.nl/share/embed/embed.0123456789ab.js', $this->enqueued['profotograaf-embed']['src'], 'The version in the result is remembered.' );
	}

	public function test_a_result_for_another_site_is_left_alone(): void {
		$html = '<script async src="https://profotograaf.nl/share/embed/embed.js"></script>';

		$this->assertSame( $html, $this->oembed->filter_html( $html, 'https://example.com/post' ) );
		$this->assertFalse( $this->oembed->filter_html( false, 'https://profotograaf.nl/share/g/x' ) );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_a_result_without_our_script_tag_is_returned_unchanged(): void {
		$html = '<div data-profotograaf-gallery="g-1"></div>';

		$this->assertSame( $html, $this->oembed->filter_html( $html, 'https://profotograaf.nl/share/g/x' ) );
		$this->assertSame( array(), $this->enqueued );
	}
}
