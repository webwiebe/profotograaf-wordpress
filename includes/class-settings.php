<?php
/**
 * Plugin settings.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, resolves and sanitises the plugin settings (option
 * `profotograaf_settings`, one array keyed by the Settings_Schema entries).
 */
final class Settings {

	public const OPTION = 'profotograaf_settings';

	/**
	 * Schema version the stored values were last normalised for.
	 */
	public const VERSION_OPTION = 'profotograaf_settings_version';

	public const GROUP = 'profotograaf';

	public const LAYOUTS = array( 'grid', 'masonry', 'slideshow' );

	/** Empty stands for the platform default: each gallery uses the layout it has on Profotograaf. */
	public const DEFAULT_LAYOUT = '';

	/**
	 * Registers the option with WordPress: sanitiser, default and REST schema.
	 * Call on `init`, so the REST endpoint sees it too.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings_Schema::defaults(),
				'show_in_rest'      => Settings_Schema::rest_schema(),
			)
		);
	}

	/**
	 * The saved value of an option, or its built-in default.
	 *
	 * @param string $key Schema key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$entry = Settings_Schema::entry( $key );
		if ( null === $entry ) {
			return null;
		}
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			$value = Settings_Schema::parse( $key, $stored[ $key ] );
			if ( null !== $value ) {
				return $value;
			}
		}
		return $entry['default'];
	}

	/**
	 * Resolves an option: the first layer with a usable value wins, then the
	 * site default, then the built-in default.
	 *
	 * Pass the layers from most to least specific, for example the block
	 * attributes and then the shortcode attributes. A layer without the key,
	 * with an empty string or with an invalid value is skipped.
	 *
	 * @param string              $key       Schema key.
	 * @param array<string,mixed> ...$layers Attribute arrays.
	 * @return mixed
	 */
	public function resolve( string $key, array ...$layers ) {
		foreach ( $layers as $layer ) {
			if ( ! array_key_exists( $key, $layer ) || null === $layer[ $key ] || '' === $layer[ $key ] ) {
				continue;
			}
			$value = Settings_Schema::parse( $key, $layer[ $key ] );
			if ( null !== $value && '' !== $value ) {
				return $value;
			}
		}
		$saved = $this->get( $key );
		if ( '' !== $saved ) {
			return $saved;
		}
		$entry = Settings_Schema::entry( $key );
		return null === $entry ? null : $entry['default'];
	}

	/**
	 * The layout used when a block or shortcode does not name one.
	 */
	public function default_layout(): string {
		return (string) $this->get( 'default_layout' );
	}

	/**
	 * Whether the media source is switched on. Every media source feature
	 * checks this first, so nothing registers or contacts the platform for
	 * photos while it is off.
	 */
	public function media_source_enabled(): bool {
		return true === $this->get( 'media_source' );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Keys missing from the input keep their saved value, so a REST request
	 * can send one key. A settings tab form sends `_tab`: its unchecked
	 * checkboxes are absent from the post, so every bool on that tab is read
	 * as off. Unknown keys are dropped.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$tab   = isset( $input['_tab'] ) && is_scalar( $input['_tab'] ) ? sanitize_key( (string) $input['_tab'] ) : '';

		$clean = array();
		foreach ( Settings_Schema::entries() as $key => $entry ) {
			if ( array_key_exists( $key, $input ) ) {
				$raw = $input[ $key ];
			} elseif ( '' !== $tab && $tab === $entry['tab'] && 'bool' === $entry['type'] ) {
				$raw = false;
			} else {
				$clean[ $key ] = $this->get( $key );
				continue;
			}
			$value         = Settings_Schema::parse( $key, $raw );
			$clean[ $key ] = null === $value ? $this->get( $key ) : $value;
		}
		return $clean;
	}

	/**
	 * Normalises values saved by an older version of the plugin, once per
	 * schema version. Old values keep their meaning: unknown keys are dropped,
	 * invalid ones fall back to the default and new keys get their default.
	 *
	 * @return bool Whether the check ran.
	 */
	public function migrate(): bool {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= Settings_Schema::VERSION ) {
			return false;
		}
		if ( false !== get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, $this->sanitize( array() ) );
		}
		update_option( self::VERSION_OPTION, Settings_Schema::VERSION, false );
		return true;
	}
}
