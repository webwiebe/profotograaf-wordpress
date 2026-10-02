<?php
/**
 * The repository mentions public hosts only.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;

class Public_Hosts_Test extends TestCase {

	/**
	 * Top-level directories that hold dependencies, VCS data or build output.
	 */
	private const SKIPPED = array( 'vendor', 'node_modules', '.git', 'build', 'dist', 'coverage', '.phpunit.cache', '.phpstan.cache', '.pnpm-store', '.tools', 'playwright-report', 'test-results' );

	public function test_no_file_names_an_internal_host(): void {
		// Built from two parts so this file does not match itself.
		$needle = implode( '.', array( 'wiebe', 'xyz' ) );
		$root   = dirname( __DIR__, 2 );
		$found  = array();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				function ( \SplFileInfo $file ) use ( $root ) {
					$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
					return ! in_array( $relative, self::SKIPPED, true );
				}
			)
		);
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || $file->getSize() > 5000000 ) {
				continue;
			}
			$content = (string) file_get_contents( $file->getPathname() );
			if ( false !== strpos( $content, $needle ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		$this->assertSame( array(), $found, 'Use https://bb.profotograaf.nl and https://f.profotograaf.nl.' );
	}
}
