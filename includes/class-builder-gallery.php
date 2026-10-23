<?php
/**
 * The gallery as page builders show it.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Modules\Gallery_Embed;

defined( 'ABSPATH' ) || exit;

/**
 * What every page builder widget shares: the choices of its controls, the
 * cleaning of what the builder stores and the rendering.
 *
 * Rendering goes through WordPress's `render_block()` for `profotograaf/gallery`,
 * so a builder gallery gets the same consent check, wrapper and Gallery_Renderer
 * output as the block. This class loads no builder code.
 */
final class Builder_Gallery {

	/**
	 * Layouts a builder offers, besides the site default (empty).
	 */
	public const LAYOUTS = array( 'auto', 'grid', 'masonry', 'slideshow' );

	/**
	 * Largest column count, per screen: desktop and tablet, then phone. The same
	 * limits as the settings page.
	 */
	public const MAX_COLUMNS = array(
		'columns'        => 8,
		'columns_tablet' => 8,
		'columns_mobile' => 4,
	);

	/**
	 * Galleries of the connected account, as `id => title`. Reads the stored list
	 * only, so opening a builder makes no request to Profotograaf.
	 *
	 * @param Gallery_Index $index Gallery list.
	 * @return array<string,string>
	 */
	public static function choices( Gallery_Index $index ): array {
		$choices = array();
		foreach ( $index->all() as $row ) {
			$choices[ $row['id'] ] = '' !== $row['title'] ? $row['title'] : $row['id'];
		}
		return $choices;
	}

	/**
	 * Block attributes for the values a builder stored. Anything outside the
	 * block's own choices becomes empty, which means the site default.
	 *
	 * @param array<string,mixed> $fields Keys gallery_id, layout, columns,
	 *                                    columns_tablet, columns_mobile and lightbox.
	 * @return array<string,string>
	 */
	public static function attributes( array $fields ): array {
		$id     = isset( $fields['gallery_id'] ) && is_scalar( $fields['gallery_id'] ) ? trim( (string) $fields['gallery_id'] ) : '';
		$layout = isset( $fields['layout'] ) && is_scalar( $fields['layout'] ) ? (string) $fields['layout'] : '';

		$attributes = array(
			'galleryId' => Gallery_Renderer::valid_id( $id ) ? $id : '',
			'layout'    => in_array( $layout, self::LAYOUTS, true ) ? $layout : '',
		);
		$names      = array(
			'columns'        => 'columns',
			'columns_tablet' => 'columnsTablet',
			'columns_mobile' => 'columnsMobile',
		);
		foreach ( $names as $field => $attribute ) {
			$attributes[ $attribute ] = self::columns( $fields[ $field ] ?? '', self::MAX_COLUMNS[ $field ] );
		}
		$lightbox               = isset( $fields['lightbox'] ) && is_scalar( $fields['lightbox'] ) ? (string) $fields['lightbox'] : '';
		$attributes['lightbox'] = in_array( $lightbox, array( 'on', 'off' ), true ) ? $lightbox : '';
		return $attributes;
	}

	/**
	 * Renders the gallery the way the block does.
	 *
	 * @param array<string,mixed> $fields    Values as attributes() takes them.
	 * @param Gallery_Index|null  $index     Gallery list, to fill the fallback title and link.
	 * @return string HTML. Without a gallery id, editors get the renderer's notice.
	 */
	public static function render( array $fields, ?Gallery_Index $index = null ): string {
		$attributes = self::attributes( $fields );
		if ( null !== $index && '' !== $attributes['galleryId'] ) {
			$found = $index->find( $attributes['galleryId'] );
			if ( null !== $found ) {
				$attributes['galleryTitle'] = $found['title'];
				$attributes['galleryUrl']   = $found['url'];
			}
		}
		return render_block(
			array(
				'blockName'    => Gallery_Embed::BLOCK,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * A column count from 1 to $max as a string, or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Largest count.
	 */
	private static function columns( $value, int $max ): string {
		if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,2}$/', trim( (string) $value ) ) ) {
			return '';
		}
		$count = (int) $value;
		return $count >= 1 && $count <= $max ? (string) $count : '';
	}
}
