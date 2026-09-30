<?php
/**
 * Plugin settings.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the plugin settings (option `profotograaf_settings`).
 */
final class Settings {

	public const OPTION = 'profotograaf_settings';

	public const LAYOUTS = array( 'grid', 'masonry', 'slideshow' );

	public const DEFAULT_LAYOUT = 'grid';

	/**
	 * The layout used when a block or shortcode does not name one.
	 */
	public function default_layout(): string {
		$stored = get_option( self::OPTION, array() );
		$layout = is_array( $stored ) && isset( $stored['default_layout'] ) ? (string) $stored['default_layout'] : self::DEFAULT_LAYOUT;
		return in_array( $layout, self::LAYOUTS, true ) ? $layout : self::DEFAULT_LAYOUT;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array{default_layout:string}
	 */
	public function sanitize( $input ): array {
		$layout = is_array( $input ) && isset( $input['default_layout'] ) ? sanitize_key( (string) $input['default_layout'] ) : self::DEFAULT_LAYOUT;
		return array(
			'default_layout' => in_array( $layout, self::LAYOUTS, true ) ? $layout : self::DEFAULT_LAYOUT,
		);
	}
}
