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
 * - type: `enum` (needs `choices`), `bool`, `int` (needs `min` and `max`),
 *   `int_or_empty` (like `int`, and an empty string stays empty to mean "use the
 *   platform default") or `text`.
 * - default: the built-in value, last in the resolution order.
 * - label and description: shown on the screen.
 * - choices: value => label, for `enum`.
 * - min and max: inclusive bounds for `int`. A number outside them is
 *   stored as the nearest bound.
 * - sanitize: optional callable that replaces the type's own parsing. It
 *   returns the clean value, or null when the input is not usable.
 * - link: optional array with `url` and `label`, shown as a link after the
 *   description.
 *
 * @phpstan-type Entry array{tab:string,type:string,default:mixed,label:string,description:string,choices?:array<int|string,string>,min?:int,max?:int,sanitize?:callable,link?:array{url:string,label:string}}
 */
final class Settings_Schema {

	/**
	 * Bump when an entry is added, renamed or changes meaning, so the stored
	 * values are normalised once (see Settings::migrate()).
	 */
	public const VERSION = 5;

	public const TAB_GENERAL   = 'general';
	public const TAB_GALLERIES = 'galleries';
	public const TAB_ENQUIRY   = 'enquiry-forms';
	public const TAB_ADVANCED  = 'advanced';

	/**
	 * Where the telemetry design is published.
	 */
	public const TELEMETRY_DOC_URL = 'https://github.com/webwiebe/profotograaf-wordpress/blob/main/docs/telemetry.md';

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
		return array_merge( self::base_entries(), self::gallery_display_entries() );
	}

	/**
	 * The options that are not gallery display settings.
	 *
	 * @return array<string,Entry>
	 */
	private static function base_entries(): array {
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
				'type'        => 'int',
				'default'     => 7,
				'min'         => 1,
				'max'         => 90,
				'label'       => __( 'Keep undeliverable enquiries for (days)', 'profotograaf' ),
				'description' => __( 'Enquiries that could not be delivered stay in the database until you export them or this many days have passed, from 1 to 90. You get an email before they are removed.', 'profotograaf' ),
			),
			'telemetry_enabled'      => array(
				'tab'         => self::TAB_ADVANCED,
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Share anonymous usage data', 'profotograaf' ),
				'description' => __( 'Help improve Profotograaf by sharing anonymous usage and error data. This is optional and you can turn it off at any time. No personal information is collected. Turning it off stops sending at once and deletes data that is waiting to be sent.', 'profotograaf' ),
				'link'        => array(
					'url'   => self::TELEMETRY_DOC_URL,
					'label' => __( 'What is shared', 'profotograaf' ),
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
	 * The gallery display options, shown on the Galleries tab after the layout.
	 * An empty value means the option is not sent and Profotograaf decides.
	 * The block attribute and the shortcode attribute of each one are listed in
	 * Gallery_Renderer::OPTIONS.
	 *
	 * @return array<string,Entry>
	 */
	private static function gallery_display_entries(): array {
		$platform = array( '' => __( 'Profotograaf default', 'profotograaf' ) );
		$on_off   = $platform + array(
			'on'  => __( 'On', 'profotograaf' ),
			'off' => __( 'Off', 'profotograaf' ),
		);
		$note     = __( 'A block or shortcode can set its own. Leave empty for the Profotograaf default.', 'profotograaf' );

		return array(
			'gallery_columns'        => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'int_or_empty',
				'default'     => '',
				'min'         => 1,
				'max'         => 8,
				'label'       => __( 'Columns', 'profotograaf' ),
				'description' => __( 'Columns on wide screens, from 1 to 8.', 'profotograaf' ) . ' ' . $note,
			),
			'gallery_columns_tablet' => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'int_or_empty',
				'default'     => '',
				'min'         => 1,
				'max'         => 8,
				'label'       => __( 'Columns on tablets', 'profotograaf' ),
				'description' => __( 'Columns on medium screens, from 1 to 8.', 'profotograaf' ) . ' ' . $note,
			),
			'gallery_columns_mobile' => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'int_or_empty',
				'default'     => '',
				'min'         => 1,
				'max'         => 4,
				'label'       => __( 'Columns on phones', 'profotograaf' ),
				'description' => __( 'Columns on narrow screens, from 1 to 4.', 'profotograaf' ) . ' ' . $note,
			),
			'gallery_gap'            => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'int_or_empty',
				'default'     => '',
				'min'         => 0,
				'max'         => 96,
				'label'       => __( 'Gap between photos (pixels)', 'profotograaf' ),
				'description' => __( 'Space between photos, from 0 to 96 pixels.', 'profotograaf' ) . ' ' . $note,
			),
			'gallery_ratio'          => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Photo shape', 'profotograaf' ),
				'description' => __( 'Crop every photo to one aspect ratio, or keep each photo as shot.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + array(
					'original' => __( 'Original', 'profotograaf' ),
					'1-1'      => __( 'Square (1:1)', 'profotograaf' ),
					'4-3'      => '4:3',
					'3-2'      => '3:2',
					'16-9'     => '16:9',
					'3-4'      => '3:4',
					'2-3'      => '2:3',
				),
			),
			'gallery_captions'       => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Captions', 'profotograaf' ),
				'description' => __( 'Whether photo captions show and where.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + array(
					'off'     => __( 'Hidden', 'profotograaf' ),
					'below'   => __( 'Below the photo', 'profotograaf' ),
					'overlay' => __( 'On top of the photo', 'profotograaf' ),
				),
			),
			'gallery_sort'           => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Sort order', 'profotograaf' ),
				'description' => __( 'The order of the photos.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + array(
					'newest' => __( 'Newest first', 'profotograaf' ),
					'oldest' => __( 'Oldest first', 'profotograaf' ),
					'name'   => __( 'By file name', 'profotograaf' ),
					'random' => __( 'Random', 'profotograaf' ),
				),
			),
			'gallery_per_page'       => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'int_or_empty',
				'default'     => '',
				'min'         => 1,
				'max'         => 200,
				'label'       => __( 'Photos per page', 'profotograaf' ),
				'description' => __( 'Show this many photos at first, from 1 to 200. Leave empty to show all of them.', 'profotograaf' ),
			),
			'gallery_load_more'      => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Load more button', 'profotograaf' ),
				'description' => __( 'Add a button that shows the next photos when "Photos per page" is set.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $on_off,
			),
			'gallery_lightbox'       => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Lightbox', 'profotograaf' ),
				'description' => __( 'Open a photo in a larger view when a visitor clicks it.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $on_off,
			),
			'gallery_duotone'        => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'text',
				'default'     => '',
				'label'       => __( 'Duotone colours', 'profotograaf' ),
				'description' => __( 'Tint every photo with two colours, the shadow and the highlight, as two hex codes separated by a comma, for example #1a1a2e,#f5c542. A block can choose its own duotone. Leave empty for the original colours.', 'profotograaf' ),
				'sanitize'    => array( Gallery_Renderer::class, 'clean_duotone' ),
			),
			'gallery_link_to'        => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Link photos to', 'profotograaf' ),
				'description' => __( 'What a click on a photo does. This applies only when the lightbox is off, because an open lightbox handles the click itself.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + array(
					'none' => __( 'Nothing', 'profotograaf' ),
					'page' => __( 'The photo page on Profotograaf', 'profotograaf' ),
					'site' => __( 'Your photographer site', 'profotograaf' ),
					'file' => __( 'The original photo file', 'profotograaf' ),
				),
			),
			'gallery_link_new_tab'   => array(
				'tab'         => self::TAB_GALLERIES,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Open photo links in a new tab', 'profotograaf' ),
				'description' => __( 'Whether a photo link opens in a new browser tab. This applies only when the lightbox is off and photos link somewhere.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $on_off,
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
		if ( 'int_or_empty' === $entry['type'] && ( '' === $value || null === $value ) ) {
			return '';
		}
		switch ( $entry['type'] ) {
			case 'enum':
				$choice = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
				return array_key_exists( $choice, $entry['choices'] ?? array() ) ? $choice : null;
			case 'int_or_empty':
			case 'int':
				if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
					return null;
				}
				return max( (int) ( $entry['min'] ?? PHP_INT_MIN ), min( (int) ( $entry['max'] ?? PHP_INT_MAX ), (int) $value ) );
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
			$types    = array(
				'bool'         => 'boolean',
				'int'          => 'integer',
				'int_or_empty' => array( 'integer', 'string' ),
			);
			$property = array(
				'type'        => $types[ $entry['type'] ] ?? 'string',
				'description' => $entry['description'],
				'default'     => $entry['default'],
			);
			if ( 'enum' === $entry['type'] ) {
				$property['enum'] = array_keys( $entry['choices'] ?? array() );
			}
			if ( 'int' === $entry['type'] || 'int_or_empty' === $entry['type'] ) {
				$property['minimum'] = $entry['min'] ?? null;
				$property['maximum'] = $entry['max'] ?? null;
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
