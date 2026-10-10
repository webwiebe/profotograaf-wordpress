<?php
/**
 * The order in which a display option gets its value.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves one display option through its layers, the same way for every
 * option and every block:
 *
 * 1. The values passed in, most specific first: the block, then the shortcode.
 *    An empty value means "inherit" and is skipped, and so is a value that is
 *    not valid for the option.
 * 2. The plugin setting.
 * 3. The default in code: the schema default, else the fallback passed in
 *    (an empty string for the gallery block, where embed.js then applies its
 *    own behaviour; the sections.json default for the full-page gallery).
 *
 * A layer that holds `auto` stops the chain with the code default, whatever the
 * plugin setting says. The gallery block uses it for "Profotograaf default"
 * layouts, the gallery's own order, all photos and automatic columns. The
 * duotone value `none` (a block that turns duotone off) stops it in the same way.
 */
final class Option_Resolver {

	/** The layer value that asks for the built-in behaviour. */
	public const AUTO = 'auto';

	/**
	 * Values that stop the chain for one setting, besides `auto`.
	 *
	 * @var array<string,string>
	 */
	private const STOP = array( 'gallery_duotone' => 'none' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolves one option.
	 *
	 * @param string|null      $setting  Schema key of the plugin setting, or null when the
	 *                                   option has none yet.
	 * @param array<int,mixed> $values   Layer values, most specific first.
	 * @param mixed            $fallback The default in code when the schema has none.
	 * @param callable|null    $parse    Cleans one layer value and returns null when it
	 *                                   is not valid; Settings_Schema::parse by default.
	 * @return mixed
	 */
	public function resolve( ?string $setting, array $values, $fallback = '', ?callable $parse = null ) {
		$parse = $parse ?? static fn( $value ) => null === $setting ? $value : Settings_Schema::parse( $setting, $value );
		foreach ( $values as $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( self::stops( $setting, $value ) ) {
				return $fallback;
			}
			$clean = $parse( $value );
			if ( null !== $clean && '' !== $clean ) {
				return $clean;
			}
		}
		if ( null === $setting ) {
			return $fallback;
		}
		$saved = $this->settings->get( $setting );
		return null === $saved || '' === $saved ? $fallback : $saved;
	}

	/**
	 * Whether a layer value asks for the code default.
	 *
	 * @param string|null $setting Schema key.
	 * @param mixed       $value   Layer value.
	 */
	public static function stops( ?string $setting, $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = strtolower( trim( $value ) );
		return self::AUTO === $value || ( null !== $setting && ( self::STOP[ $setting ] ?? null ) === $value );
	}
}
