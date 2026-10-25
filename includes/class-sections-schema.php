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
 * normalise() resolves every field through Option_Resolver: the section value,
 * then the plugin setting `sections_<type>_<field>` (the field in snake case)
 * when the settings schema has one, then the default in the file.
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
	 * The plugin setting keys that start with `sections_`, read once.
	 *
	 * @var array<string,true>|null
	 */
	private static ?array $settings = null;

	/**
	 * Resolves the fields that have a plugin setting.
	 *
	 * @var Option_Resolver|null
	 */
	private static ?Option_Resolver $resolver = null;

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
	 * except `photoIds` (picked photos by position, '' for an open one).
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
			$out[ $field ] = self::resolve( $type, $field, $section[ $field ] ?? null, $default );
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
	 * The plugin setting of one field of a type, `sections_<type>_<field>`
	 * with the field in snake case (gapRow becomes gap_row).
	 *
	 * @param string $type  Section type id.
	 * @param string $field Field name.
	 * @return string|null The key, or null when the settings schema has no such setting.
	 */
	public static function setting_key( string $type, string $field ): ?string {
		if ( null === self::$settings ) {
			$keys           = preg_grep( '/^sections_/', array_keys( Settings_Schema::entries() ) );
			self::$settings = array_fill_keys( false === $keys ? array() : $keys, true );
		}
		$key = 'sections_' . $type . '_' . strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $field ) );
		return isset( self::$settings[ $key ] ) ? $key : null;
	}

	/**
	 * One field value: the stored value when it is valid for the field, then
	 * the plugin setting of the field, then the type default.
	 *
	 * @param string $type     Section type id.
	 * @param string $field    Field name.
	 * @param mixed  $value    Stored value.
	 * @param mixed  $fallback Type default.
	 * @return mixed
	 */
	private static function resolve( string $type, string $field, $value, $fallback ) {
		$parse   = static fn( $raw ) => self::clean( $type, $field, $raw, $fallback );
		$setting = self::setting_key( $type, $field );
		if ( null === $setting ) {
			return $parse( $value ) ?? $fallback;
		}
		self::$resolver = self::$resolver ?? new Option_Resolver( new Settings() );
		return self::$resolver->resolve( $setting, array( $value ), $fallback, $parse );
	}

	/**
	 * One stored value, cleaned for its field.
	 *
	 * @param string $type     Section type id.
	 * @param string $field    Field name.
	 * @param mixed  $value    Stored value.
	 * @param mixed  $fallback Type default, which sets the kind of value.
	 * @return mixed Null when the value is empty or not valid for the field.
	 */
	private static function clean( string $type, string $field, $value, $fallback ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$rule = array_merge( self::table()['fields'][ $field ] ?? array(), self::table()['types'][ $type ]['limits'][ $field ] ?? array() );
		if ( is_bool( $fallback ) ) {
			return is_bool( $value ) ? $value : in_array( $value, array( 1, '1', 'true', 'on' ), true );
		}
		if ( is_int( $fallback ) || isset( $rule['min'] ) ) {
			return is_numeric( $value ) ? self::clamp( (int) $value, $rule ) : null;
		}
		return is_scalar( $value ) ? self::clean_text( (string) $value, $rule ) : null;
	}

	/**
	 * A number held to the rule's min and max.
	 *
	 * @param int                 $number Value.
	 * @param array<string,mixed> $rule   Field rule.
	 */
	private static function clamp( int $number, array $rule ): int {
		return max( (int) ( $rule['min'] ?? $number ), min( (int) ( $rule['max'] ?? $number ), $number ) );
	}

	/**
	 * A text value cleaned by the rule's enum or pattern, else sanitized.
	 *
	 * @param string              $text Value.
	 * @param array<string,mixed> $rule Field rule.
	 */
	private static function clean_text( string $text, array $rule ): ?string {
		if ( isset( $rule['enum'] ) ) {
			return in_array( $text, $rule['enum'], true ) ? $text : null;
		}
		if ( isset( $rule['pattern'] ) ) {
			$text = strtolower( trim( $text ) );
			return 1 === preg_match( '/' . $rule['pattern'] . '/', $text ) ? $text : null;
		}
		return sanitize_text_field( $text );
	}

	/**
	 * A list of photo ids by position: strings of letters, digits, dashes and
	 * underscores. An empty string keeps a position open, so a photo picked for
	 * the third slot stays in the third slot. A repeated id becomes an open
	 * position, and open positions at the end are dropped.
	 *
	 * @param mixed $ids Stored ids.
	 * @return string[]
	 */
	private static function ids( $ids ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $ids ) ? array_values( $ids ) : array() as $id ) {
			$clean = is_string( $id ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $id ) : '';
			$out[] = isset( $seen[ $clean ] ) ? '' : $clean;
			if ( '' !== $clean ) {
				$seen[ $clean ] = true;
			}
		}
		while ( array() !== $out && '' === end( $out ) ) {
			array_pop( $out );
		}
		return $out;
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
