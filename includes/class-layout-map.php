<?php
/**
 * Platform layouts and what the site embed draws for them.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The site embed (embed.js) draws three layouts: grid, masonry and slideshow. A gallery on
 * Profotograaf can use more (parallax, filmstrip, justified and so on), and the
 * script draws every layout it does not know as a grid. This class maps each
 * platform layout to the embed layout that looks closest, so a block left on the
 * platform default shows a parallax gallery as a slideshow.
 *
 * The table is includes/layout-map.json. The block editor imports the same file
 * for its hint under the Layout control, so the two cannot disagree. The table
 * follows wiebe-xyz/professionals#2359.
 */
final class Layout_Map {

	/**
	 * The decoded table, read once.
	 *
	 * @var array{fallback:string,drawn:string[],map:array<string,string>}|null
	 */
	private static ?array $table = null;

	/**
	 * The layout embed.js draws for an unknown platform layout.
	 */
	public static function fallback(): string {
		return self::table()['fallback'];
	}

	/**
	 * Every platform layout the plugin knows, with the layout drawn for it.
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		return self::table()['map'];
	}

	/**
	 * Whether the plugin knows a platform layout.
	 *
	 * @param string $platform Layout name from the platform.
	 */
	public static function knows( string $platform ): bool {
		return isset( self::table()['map'][ self::clean( $platform ) ] );
	}

	/**
	 * The layout to draw for a gallery.
	 *
	 * @param string $platform Layout name of the gallery on the platform.
	 * @param string $reported Layout the platform says the embed draws
	 *                         (`embed_layout`), when it reports one. It wins when
	 *                         it names a layout embed.js draws.
	 * @return string grid, masonry or slideshow. An empty or unknown platform
	 *                layout gives the fallback.
	 */
	public static function drawn( string $platform, string $reported = '' ): string {
		$table    = self::table();
		$reported = self::clean( $reported );
		if ( in_array( $reported, $table['drawn'], true ) ) {
			return $reported;
		}
		return $table['map'][ self::clean( $platform ) ] ?? $table['fallback'];
	}

	/**
	 * A layout name reduced to lower case letters, digits, dashes and underscores,
	 * at most 32 characters.
	 *
	 * @param mixed $value Raw name.
	 */
	public static function clean( $value ): string {
		$name = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', $name ), 0, 32 );
	}

	/**
	 * Reads the table.
	 *
	 * @return array{fallback:string,drawn:string[],map:array<string,string>}
	 */
	private static function table(): array {
		if ( null === self::$table ) {
			$raw         = file_get_contents( __DIR__ . '/layout-map.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a file shipped with the plugin.
			$decoded     = json_decode( false === $raw ? '' : $raw, true );
			self::$table = array(
				'fallback' => (string) ( $decoded['fallback'] ?? 'grid' ),
				'drawn'    => array_values( array_map( 'strval', (array) ( $decoded['drawn'] ?? array() ) ) ),
				'map'      => array_map( 'strval', (array) ( $decoded['map'] ?? array() ) ),
			);
		}
		return self::$table;
	}
}
