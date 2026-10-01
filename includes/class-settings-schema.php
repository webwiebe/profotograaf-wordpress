<?php
/**
 * The settings schema.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Describes every plugin option once. Registration, the REST schema, the
 * sanitising and the settings screen all read from here, so adding an option
 * means adding one entry to entries().
 *
 * An entry has these keys:
 *
 * - tab: General, Galleries, Enquiry forms or Advanced (see tabs()).
 * - type: `enum` (needs `choices`), `bool` or `text`.
 * - default: the built-in value, last in the resolution order.
 * - label and description: shown on the screen.
 * - choices: value => label, for `enum`.
 * - sanitize: optional callable that replaces the type's own parsing. It
 *   returns the clean value, or null when the input is not usable.
 *
 * @phpstan-type Entry array{tab:string,type:string,default:mixed,label:string,description:string,choices?:array<int|string,string>,sanitize?:callable}
 */
final class Settings_Schema {

	/**
	 * Bump when an entry is added, renamed or changes meaning, so the stored
	 * values are normalised once (see Settings::migrate()).
	 */
	public const VERSION = 1;

	public const TAB_GENERAL   = 'general';
	public const TAB_GALLERIES = 'galleries';
	public const TAB_ENQUIRY   = 'enquiry-forms';
	public const TAB_ADVANCED  = 'advanced';

	/**
	 * Settings screen tabs, in order.
	 *
	 * @return array<string,string> Slug => label.
	 */
	public static function tabs(): array {
		return array(
			self::TAB_GENERAL   => __( 'General', 'profotograaf' ),
			self::TAB_GALLERIES => __( 'Galleries', 'profotograaf' ),
			self::TAB_ENQUIRY   => __( 'Enquiry forms', 'profotograaf' ),
			self::TAB_ADVANCED  => __( 'Advanced', 'profotograaf' ),
		);
	}

	/**
	 * Every option.
	 *
	 * @return array<string,Entry>
	 */
	public static function entries(): array {
		return array(
			'fallback_link_label'    => array(
				'tab'         => self::TAB_GENERAL,
				'type'        => 'text',
				'default'     => '',
				'label'       => __( 'Gallery link text', 'profotograaf' ),
				'description' => __( 'Text of the link shown until a gallery loads, for galleries without a title. Leave empty to use "View the gallery".', 'profotograaf' ),
			),
			'client_portal_path'     => array(
				'tab'         => self::TAB_GENERAL,
				'type'        => 'text',
				'default'     => Modules\Client_Galleries::DEFAULT_PATH,
				'label'       => __( 'Client portal path', 'profotograaf' ),
				'description' => __( 'The path of the client portal on your Profotograaf address, used by the "Find your gallery" block. A block can set its own. The default is /client.', 'profotograaf' ),
				'sanitize'    => array( Modules\Client_Galleries::class, 'sanitize_path' ),
			),
			'default_layout'         => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => Settings::DEFAULT_LAYOUT,
				'label'       => __( 'Default gallery layout', 'profotograaf' ),
				'description' => __( 'Used when a gallery block or shortcode does not choose a layout.', 'profotograaf' ),
				'choices'     => array(
					'grid'      => __( 'Grid', 'profotograaf' ),
					'masonry'   => __( 'Masonry', 'profotograaf' ),
					'slideshow' => __( 'Slideshow', 'profotograaf' ),
				),
			),
			'leads_enabled'          => array(
				'tab'         => self::TAB_ENQUIRY,
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Collect enquiries', 'profotograaf' ),
				'description' => __( 'Listen for submissions of supported form plugins. Each form still has its own switch on the enquiry forms screen.', 'profotograaf' ),
			),
			'leads_alert_email'      => array(
				'tab'         => self::TAB_ENQUIRY,
				'type'        => 'text',
				'default'     => '',
				'label'       => __( 'Failure alerts go to', 'profotograaf' ),
				'description' => __( 'Receives one email, without personal data, when an enquiry cannot be delivered or is dropped. Leave empty to use the site admin email address.', 'profotograaf' ),
				'sanitize'    => array( self::class, 'sanitize_email_or_empty' ),
			),
			'leads_fallback_email'   => array(
				'tab'         => self::TAB_ENQUIRY,
				'type'        => 'text',
				'default'     => '',
				'label'       => __( 'Mail undeliverable enquiries to', 'profotograaf' ),
				'description' => __( 'When an enquiry cannot be delivered to Profotograaf, send it by email to this address instead. The email contains the enquiry. Leave empty to turn this off.', 'profotograaf' ),
				'sanitize'    => array( self::class, 'sanitize_email_or_empty' ),
			),
			'leads_failed_retention' => array(
				'tab'         => self::TAB_ENQUIRY,
				'type'        => 'enum',
				'default'     => '30',
				'label'       => __( 'Keep undeliverable enquiries for', 'profotograaf' ),
				'description' => __( 'Enquiries that could not be delivered stay in the database until you export them or this time has passed. You get an email before they are removed.', 'profotograaf' ),
				'choices'     => array(
					'7'   => __( '7 days', 'profotograaf' ),
					'30'  => __( '30 days', 'profotograaf' ),
					'90'  => __( '90 days', 'profotograaf' ),
					'365' => __( '1 year', 'profotograaf' ),
				),
			),
			'keep_data_on_uninstall' => array(
				'tab'         => self::TAB_ADVANCED,
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Keep my data when the plugin is deleted', 'profotograaf' ),
				'description' => __( 'Leave the settings and the connection in the database when you delete the plugin, for example to reinstall it later.', 'profotograaf' ),
			),
		);
	}

	/**
	 * One entry.
	 *
	 * @param string $key Option key.
	 * @return Entry|null
	 */
	public static function entry( string $key ): ?array {
		return self::entries()[ $key ] ?? null;
	}

	/**
	 * The entries shown on one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return array<string,Entry>
	 */
	public static function in_tab( string $tab ): array {
		return array_filter(
			self::entries(),
			static fn( array $entry ): bool => $tab === $entry['tab']
		);
	}

	/**
	 * Built-in values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array_map( static fn( array $entry ) => $entry['default'], self::entries() );
	}

	/**
	 * Cleans one value.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Raw value.
	 * @return mixed The clean value, or null when the key is unknown or the value is invalid.
	 */
	public static function parse( string $key, $value ) {
		$entry = self::entry( $key );
		if ( null === $entry ) {
			return null;
		}
		if ( isset( $entry['sanitize'] ) ) {
			return ( $entry['sanitize'] )( $value );
		}
		switch ( $entry['type'] ) {
			case 'enum':
				$choice = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
				return array_key_exists( $choice, $entry['choices'] ?? array() ) ? $choice : null;
			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			default:
				return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : null;
		}
	}

	/**
	 * Cleans an email address option that may stay empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null The address, an empty string, or null when it is not an address.
	 */
	public static function sanitize_email_or_empty( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$email = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $email ) {
			return '';
		}
		return false === is_email( $email ) ? null : $email;
	}

	/**
	 * The `show_in_rest` schema of the option.
	 *
	 * @return array<string,mixed>
	 */
	public static function rest_schema(): array {
		$properties = array();
		foreach ( self::entries() as $key => $entry ) {
			$property = array(
				'type'        => 'bool' === $entry['type'] ? 'boolean' : 'string',
				'description' => $entry['description'],
				'default'     => $entry['default'],
			);
			if ( 'enum' === $entry['type'] ) {
				$property['enum'] = array_keys( $entry['choices'] ?? array() );
			}
			$properties[ $key ] = $property;
		}
		return array(
			'schema' => array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			),
		);
	}
}
