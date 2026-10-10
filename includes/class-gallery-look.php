<?php
/**
 * Spacing and shape of the gallery tiles.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The look options of the gallery block: a gap per axis, corner radius, hover
 * effect, frame and shadow (#198). They resolve like every other display
 * option (Gallery_Renderer::OPTIONS, Option_Resolver) and embed.js reads them
 * from the data attributes listed in docs/embed-js.md on the platform.
 *
 * Two attributes are written differently from what they store:
 * - the radius is stored as a name (none, subtle, rounded, full) and written
 *   as pixels or `full`;
 * - the frame colour and width are stored apart and written as one
 *   `data-frame="#ffffff,8"`, only when the width is above 0.
 *
 * The slideshow ignores the gaps per axis, the frame, the shadow and the grow
 * effect, so they are not sent for it.
 *
 * @phpstan-import-type Entry from Settings_Schema
 */
final class Gallery_Look {

	/**
	 * The look options, in the shape of Gallery_Renderer::OPTIONS.
	 *
	 * @var array<string,array{setting:string,attribute:string,data:string}>
	 */
	public const OPTIONS = array(
		'gap_row'        => array(
			'setting'   => 'gallery_gap_row',
			'attribute' => 'gapRow',
			'data'      => 'data-gap-row',
		),
		'gap_column'     => array(
			'setting'   => 'gallery_gap_column',
			'attribute' => 'gapColumn',
			'data'      => 'data-gap-column',
		),
		'radius'         => array(
			'setting'   => 'gallery_radius',
			'attribute' => 'radius',
			'data'      => 'data-radius',
		),
		'hover'          => array(
			'setting'   => 'gallery_hover',
			'attribute' => 'hover',
			'data'      => 'data-hover',
		),
		'hover_strength' => array(
			'setting'   => 'gallery_hover_strength',
			'attribute' => 'hoverStrength',
			'data'      => 'data-hover-strength',
		),
		'frame_color'    => array(
			'setting'   => 'gallery_frame_color',
			'attribute' => 'frameColor',
			'data'      => 'data-frame-color',
		),
		'frame_width'    => array(
			'setting'   => 'gallery_frame_width',
			'attribute' => 'frameWidth',
			'data'      => 'data-frame-width',
		),
		'shadow'         => array(
			'setting'   => 'gallery_shadow',
			'attribute' => 'shadow',
			'data'      => 'data-shadow',
		),
	);

	/** Corner radius names and what embed.js reads for them. */
	public const RADIUS = array(
		'none'    => '0',
		'subtle'  => '4',
		'rounded' => '12',
		'full'    => 'full',
	);

	/** The frame colour when a block sets only a width. */
	public const FRAME_COLOR = '#ffffff';

	/** Widest frame embed.js draws, in pixels. */
	public const FRAME_MAX = 24;

	/** Attributes the slideshow ignores. */
	private const NOT_IN_SLIDESHOW = array( 'data-gap-row', 'data-gap-column', 'data-frame', 'data-shadow' );

	/**
	 * Turns the resolved look attributes into the ones embed.js reads.
	 *
	 * @param array<string,string> $data   Data attributes as resolved.
	 * @param string               $layout Resolved layout.
	 * @return array<string,string>
	 */
	public static function finish( array $data, string $layout ): array {
		if ( isset( $data['data-radius'] ) ) {
			$data['data-radius'] = self::RADIUS[ $data['data-radius'] ] ?? '';
		}
		$width = (int) ( $data['data-frame-width'] ?? 0 );
		$color = $data['data-frame-color'] ?? '';
		unset( $data['data-frame-width'], $data['data-frame-color'] );
		if ( $width > 0 ) {
			$data['data-frame'] = ( '' !== $color ? $color : self::FRAME_COLOR ) . ',' . $width;
		}
		if ( 'slideshow' === $layout ) {
			foreach ( self::NOT_IN_SLIDESHOW as $name ) {
				unset( $data[ $name ] );
			}
			if ( 'grow' === ( $data['data-hover'] ?? '' ) ) {
				unset( $data['data-hover'], $data['data-hover-strength'] );
			}
		}
		return array_filter( $data, static fn( $value ) => '' !== $value );
	}

	/**
	 * A frame colour: one 3 or 6 digit hex colour, in lower case.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null The colour, an empty string for an empty value, or
	 *                     null when it is not a hex colour.
	 */
	public static function clean_color( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		return 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value ) ? $value : null;
	}

	/**
	 * The settings entries of the look options, for the Galleries tab.
	 *
	 * @param string $tab  Settings tab.
	 * @param string $note The sentence every gallery option ends its description with.
	 * @return array<string,Entry>
	 */
	public static function schema_entries( string $tab, string $note ): array {
		$platform = array( '' => __( 'Profotograaf default', 'profotograaf' ) );
		$gap      = static fn( string $label, string $description ): array => array(
			'tab'         => $tab,
			'type'        => 'int_or_empty',
			'default'     => '',
			'min'         => 0,
			'max'         => 96,
			'label'       => $label,
			'description' => $description . ' ' . $note,
		);
		return array(
			'gallery_gap_row'        => $gap( __( 'Space between rows (pixels)', 'profotograaf' ), __( 'From 0 to 96 pixels. Empty uses the gap between photos.', 'profotograaf' ) ),
			'gallery_gap_column'     => $gap( __( 'Space between columns (pixels)', 'profotograaf' ), __( 'From 0 to 96 pixels. Empty uses the gap between photos.', 'profotograaf' ) ),
			'gallery_radius'         => array(
				'tab'         => $tab,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Corners', 'profotograaf' ),
				'description' => __( 'Rounded corners on every photo. Circle applies to square photos only; other shapes keep the default corners.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + self::radius_labels(),
			),
			'gallery_hover'          => array(
				'tab'         => $tab,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Hover effect', 'profotograaf' ),
				'description' => __( 'What a photo does under the mouse or keyboard focus. Grow lifts the whole photo above its neighbours; the slideshow does not grow. Visitors who ask for less motion see no effect.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + self::hover_labels(),
			),
			'gallery_hover_strength' => array(
				'tab'         => $tab,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Hover strength', 'profotograaf' ),
				'description' => $note,
				'choices'     => $platform + array(
					'subtle' => __( 'Subtle', 'profotograaf' ),
					'strong' => __( 'Strong', 'profotograaf' ),
				),
			),
			'gallery_frame_color'    => array(
				'tab'         => $tab,
				'type'        => 'text',
				'default'     => '',
				'label'       => __( 'Frame colour', 'profotograaf' ),
				'description' => __( 'The colour of the frame around each photo, as a hex code such as #ffffff. White when only a width is set.', 'profotograaf' ) . ' ' . $note,
				'sanitize'    => array( self::class, 'clean_color' ),
			),
			'gallery_frame_width'    => array(
				'tab'         => $tab,
				'type'        => 'int_or_empty',
				'default'     => '',
				'min'         => 0,
				'max'         => self::FRAME_MAX,
				'label'       => __( 'Frame width (pixels)', 'profotograaf' ),
				'description' => __( 'A frame around each photo, from 1 to 24 pixels. 0 means no frame. Not drawn in a slideshow.', 'profotograaf' ) . ' ' . $note,
			),
			'gallery_shadow'         => array(
				'tab'         => $tab,
				'type'        => 'enum',
				'default'     => '',
				'label'       => __( 'Shadow', 'profotograaf' ),
				'description' => __( 'A shadow under each photo. Not drawn in a slideshow.', 'profotograaf' ) . ' ' . $note,
				'choices'     => $platform + array(
					'none'   => __( 'None', 'profotograaf' ),
					'soft'   => __( 'Soft', 'profotograaf' ),
					'strong' => __( 'Strong', 'profotograaf' ),
				),
			),
		);
	}

	/**
	 * Corner radius labels by name.
	 *
	 * @return array<string,string>
	 */
	private static function radius_labels(): array {
		return array(
			'none'    => __( 'Square corners', 'profotograaf' ),
			'subtle'  => __( 'Subtle (4 pixels)', 'profotograaf' ),
			'rounded' => __( 'Rounded (12 pixels)', 'profotograaf' ),
			'full'    => __( 'Circle (square photos only)', 'profotograaf' ),
		);
	}

	/**
	 * Hover effect labels by name.
	 *
	 * @return array<string,string>
	 */
	private static function hover_labels(): array {
		return array(
			'zoom' => __( 'Zoom inside the frame', 'profotograaf' ),
			'grow' => __( 'Grow above the neighbours', 'profotograaf' ),
			'none' => __( 'None', 'profotograaf' ),
		);
	}
}
