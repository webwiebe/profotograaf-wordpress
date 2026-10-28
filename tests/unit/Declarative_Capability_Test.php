<?php
/**
 * Every display option embed.js reads is either drawn by the PHP renderer or
 * listed as a gap, and a listed gap really sends the gallery back to embed.js.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Declarative_Renderer;

class Declarative_Capability_Test extends TestCase {

	private const EMBED    = __DIR__ . '/../e2e/fixtures/embed.js';
	private const FIXTURES = __DIR__ . '/../fixtures/embed/';

	/**
	 * The `data-*` names embed.js reads as options, without its own state attributes
	 * and without `data-platform`, which the script reads from its own tag.
	 *
	 * @return string[]
	 */
	private function script_options(): array {
		preg_match_all( '/data-[a-z-]+/', (string) file_get_contents( self::EMBED ), $found );
		$names = array_diff( array_unique( $found[0] ), array( 'data-profotograaf-gallery', 'data-platform' ) );
		$names = array_filter( $names, static fn( $name ) => 0 !== strpos( $name, 'data-pf-' ) );
		sort( $names );
		return array_values( $names );
	}

	/**
	 * @return string[]
	 */
	private function served(): array {
		return ( new \ReflectionClassConstant( Declarative_Renderer::class, 'SERVED_ATTRIBUTES' ) )->getValue();
	}

	/**
	 * @return string[]
	 */
	private function gaps(): array {
		$list = json_decode( (string) file_get_contents( self::FIXTURES . 'unserved-options.json' ), true );
		return array_keys( $list['options'] );
	}

	public function test_every_option_of_the_script_is_served_or_a_listed_gap(): void {
		$unknown = array_diff( $this->script_options(), $this->served(), $this->gaps() );
		$this->assertSame( array(), array_values( $unknown ), 'embed.js reads an option the plugin neither draws nor lists in tests/fixtures/embed/unserved-options.json' );
	}

	public function test_the_lists_hold_no_option_the_script_does_not_read(): void {
		$stale = array_diff( array_merge( $this->served(), $this->gaps() ), $this->script_options() );
		$this->assertSame( array(), array_values( $stale ), 'the renderer or the gap list names an option embed.js no longer reads' );
	}

	public function test_no_option_is_both_served_and_a_gap(): void {
		$this->assertSame( array(), array_values( array_intersect( $this->served(), $this->gaps() ) ) );
	}

	public function test_a_listed_gap_sends_the_gallery_back_to_the_script(): void {
		$fixture = json_decode( (string) file_get_contents( self::FIXTURES . 'one-photo.json' ), true );
		$this->assertNotNull( Declarative_Renderer::render( $fixture['gallery'], array() ), 'the control gallery is drawn' );
		foreach ( $this->gaps() as $name ) {
			$this->assertNull( Declarative_Renderer::render( $fixture['gallery'], array( $name => '2' ) ), "$name is a gap, so embed.js must draw the gallery" );
		}
	}
}
