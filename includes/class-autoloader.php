<?php
/**
 * Class autoloader.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `Profotograaf\Sub_Space\Class_Name` to
 * `includes/sub-space/class-class-name.php` (or `interface-` / `trait-`).
 *
 * The rule follows the WordPress file naming standard, so adding a class means
 * adding one file and nothing else.
 */
final class Autoloader {

	private const PREFIX = 'Profotograaf\\';

	/**
	 * Absolute path of the includes directory, with a trailing slash.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir Absolute path of the includes directory.
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads the file for a class, interface or trait of this plugin.
	 *
	 * @param string $name Fully qualified name.
	 */
	public static function load( string $name ): void {
		if ( 0 !== strpos( $name, self::PREFIX ) ) {
			return;
		}
		$relative = substr( $name, strlen( self::PREFIX ) );
		if ( '' === $relative || ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$parts = explode( '\\', $relative );
		$leaf  = self::slug( (string) array_pop( $parts ) );
		$dir   = self::$base_dir;
		foreach ( $parts as $part ) {
			$dir .= self::slug( $part ) . '/';
		}

		foreach ( array( 'class', 'interface', 'trait' ) as $type ) {
			$file = $dir . $type . '-' . $leaf . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}

	/**
	 * Turns `Api_Client` into `api-client`.
	 *
	 * @param string $segment Name segment.
	 */
	public static function slug( string $segment ): string {
		return strtolower( str_replace( '_', '-', $segment ) );
	}
}
