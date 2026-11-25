<?php
/**
 * Core-only pattern tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/pattern-directory/helpers.php';

class Pattern_Directory_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function patterns(): array {
		$patterns = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/pattern-directory/*.php' ) as $file ) {
			if ( 'helpers' !== basename( $file, '.php' ) ) {
				$patterns[ basename( $file, '.php' ) ] = require $file;
			}
		}
		return $patterns;
	}

	public function test_every_plugin_pattern_has_a_core_only_version(): void {
		$plugin = array_map( fn( $file ) => basename( $file, '.php' ), glob( dirname( __DIR__, 2 ) . '/patterns/*.php' ) );

		$this->assertSame( $plugin, array_keys( $this->patterns() ) );
	}

	public function test_core_only_versions_use_core_blocks_only(): void {
		foreach ( $this->patterns() as $name => $pattern ) {
			$this->assertNotSame( '', $pattern['title'], $name );
			$this->assertNotSame( '', $pattern['description'], $name );
			preg_match_all( '/<!-- wp:([a-z0-9\/-]+)/', $pattern['content'], $blocks );
			$this->assertNotEmpty( $blocks[1], $name );
			foreach ( $blocks[1] as $block ) {
				$this->assertStringNotContainsString( '/', $block, $name . ' ' . $block );
			}
		}
	}

	public function test_gallery_placeholders_link_to_the_block(): void {
		$patterns = $this->patterns();

		$this->assertStringContainsString( PROFOTOGRAAF_PATTERN_BLOCK_URL, $patterns['portfolio-grid']['content'] );
		$this->assertStringNotContainsString( PROFOTOGRAAF_PATTERN_BLOCK_URL, $patterns['client-portal-cta']['content'] );
	}
}
