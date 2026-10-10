<?php
/**
 * Section types, their defaults and the presets of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads includes/sections.json, the shared vocabulary of the full-page gallery
 * (#194): the section types, the default of every field and the named presets.
 * The block editor imports the same file, and the platform share page follows
 * the same table (wiebe-xyz/professionals#2385), so a gallery looks the same on
 * every surface.
 *
 * A block stores an empty value for every field the photographer left alone.
 * normalise() fills it from the file, so a site-wide setting can later slot in
 * between the block value and the file default without a migration.
 */
final class Sections_Schema {

	/** The preset that holds the photographer's own list of sections. */
	public const CUSTOM = 'custom';

	/**
	 * The decoded file, read once.
	 *
	 * @var array{version:int,defaultPreset:string,phoneBreakpoint:int,fields:array<string,array<string,mixed>>,types:array<string,array<string,mixed>>,presets:array<string,array<int,array<string,mixed>>>}|null
	 */
	private static ?array $table = null;

	/**
	 * The section type ids, in file order.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array_keys( self::table()['types'] );
	}

	/**
	 * Whether a section type exists.
	 *
	 * @param string $type Section type id.
	 */
	public static function has_type( string $type ): bool {
		return isset( self::table()['types'][ $type ] );
	}

	/**
	 * The preset ids, in file order, without `custom`.
	 *
	 * @return string[]
	 */
	public static function presets(): array {
		return array_keys( self::table()['presets'] );
	}

	/**
	 * The preset a block without a choice uses.
	 */
	public static function default_preset(): string {
		return (string) self::table()['defaultPreset'];
	}

	/**
	 * The preset to use for a stored value: a known preset, `custom`, or the
	 * default preset for anything else.
	 *
	 * @param mixed $value Stored preset.
	 */
	public static function preset_id( $value ): string {
		$id = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( self::CUSTOM === $id || isset( self::table()['presets'][ $id ] ) ) {
			return $id;
		}
		return self::default_preset();
	}

	/**
	 * The sections of one preset cycle, normalised.
	 *
	 * @param string $preset Preset id.
	 * @return array<int,array<string,mixed>> An empty list for `custom` or an unknown id.
	 */
	public static function pattern( string $preset ): array {
		$pattern = self::table()['presets'][ $preset ] ?? array();
		return array_values( array_filter( array_map( array( self::class, 'normalise' ), $pattern ) ) );
	}

	/**
	 * The width at which sections switch to their phone shape, in px.
	 */
	public static function phone_breakpoint(): int {
		return (int) self::table()['phoneBreakpoint'];
	}

	/**
	 * The default values of one section type.
	 *
	 * @param string $type Section type id.
	 * @return array<string,mixed>
	 */
	public static function defaults( string $type ): array {
		return self::table()['types'][ $type ]['fields'] ?? array();
	}

	/**
	 * The values one section type uses below the phone breakpoint.
	 *
	 * @param string $type Section type id.
	 * @return array<string,mixed>
	 */
	public static function phone( string $type ): array {
		return self::table()['types'][ $type ]['phone'] ?? array();
	}

	/**
	 * A section with every field of its type set: the stored value when it is
	 * valid, the default otherwise. Fields the type does not have are dropped,
	 * except `photoIds` (picked photos, kept as a list of strings).
	 *
	 * @param mixed $section Stored section.
	 * @return array<string,mixed>|null Null when the type is missing or unknown.
	 */
	public static function normalise( $section ): ?array {
		if ( ! is_array( $section ) ) {
			return null;
		}
		$type = is_string( $section['type'] ?? null ) ? strtolower( trim( $section['type'] ) ) : '';
		if ( ! self::has_type( $type ) ) {
			return null;
		}
		$out = array( 'type' => $type );
		foreach ( self::defaults( $type ) as $field => $default ) {
			$out[ $field ] = self::value( $type, $field, $section[ $field ] ?? null, $default );
		}
		$out['photoIds'] = self::ids( $section['photoIds'] ?? array() );
		return $out;
	}

	/**
	 * How many photos a normalised section takes from the gallery.
	 *
	 * @param array<string,mixed> $section Normalised section.
	 */
	public static function take( array $section ): int {
		$rule = self::table()['types'][ (string) ( $section['type'] ?? '' ) ]['take'] ?? 0;
		if ( is_int( $rule ) ) {
			return $rule;
		}
		$total = 1;
		foreach ( explode( '*', (string) $rule ) as $field ) {
			$total *= (int) ( $section[ $field ] ?? 0 );
		}
		return max( 0, $total );
	}

	/**
	 * One field value: the stored value when it is valid for the field, the
	 * type default otherwise. An empty string counts as unset.
	 *
	 * @param string $type     Section type id.
	 * @param string $field    Field name.
	 * @param mixed  $value    Stored value.
	 * @param mixed  $fallback Type default.
	 * @return mixed
	 */
	private static function value( string $type, string $field, $value, $fallback ) {
		if ( null === $value || '' === $value ) {
			return $fallback;
		}
		$rule = array_merge( self::table()['fields'][ $field ] ?? array(), self::table()['types'][ $type ]['limits'][ $field ] ?? array() );
		if ( is_bool( $fallback ) ) {
			return is_bool( $value ) ? $value : in_array( $value, array( 1, '1', 'true', 'on' ), true );
		}
		if ( is_int( $fallback ) ) {
			if ( ! is_numeric( $value ) ) {
				return $fallback;
			}
			$number = (int) $value;
			return max( (int) ( $rule['min'] ?? $number ), min( (int) ( $rule['max'] ?? $number ), $number ) );
		}
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}
		$text = (string) $value;
		if ( isset( $rule['enum'] ) ) {
			return in_array( $text, $rule['enum'], true ) ? $text : $fallback;
		}
		return sanitize_text_field( $text );
	}

	/**
	 * A list of photo ids: strings of letters, digits, dashes and underscores.
	 *
	 * @param mixed $ids Stored ids.
	 * @return string[]
	 */
	private static function ids( $ids ): array {
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$clean = is_string( $id ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $id ) : '';
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Reads the file.
	 *
	 * @return array{version:int,defaultPreset:string,phoneBreakpoint:int,fields:array<string,array<string,mixed>>,types:array<string,array<string,mixed>>,presets:array<string,array<int,array<string,mixed>>>}
	 */
	private static function table(): array {
		if ( null === self::$table ) {
			$raw         = file_get_contents( __DIR__ . '/sections.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a file shipped with the plugin.
			$decoded     = json_decode( false === $raw ? '' : $raw, true );
			$decoded     = is_array( $decoded ) ? $decoded : array();
			self::$table = array(
				'version'         => (int) ( $decoded['version'] ?? 1 ),
				'defaultPreset'   => (string) ( $decoded['defaultPreset'] ?? 'editorial' ),
				'phoneBreakpoint' => (int) ( $decoded['phoneBreakpoint'] ?? 600 ),
				'fields'          => (array) ( $decoded['fields'] ?? array() ),
				'types'           => (array) ( $decoded['types'] ?? array() ),
				'presets'         => (array) ( $decoded['presets'] ?? array() ),
			);
		}
		return self::$table;
	}
}
