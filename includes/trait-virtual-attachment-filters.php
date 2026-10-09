<?php
/**
 * The core filters of the virtual attachments prototype.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Filter callbacks of Virtual_Attachments, kept apart to keep that file short.
 * They read stored post meta only and never call the platform. Spike #104, see
 * docs/virtual-attachments.md.
 */
trait Virtual_Attachment_Filters {

	/**
	 * `wp_get_attachment_url`: the platform `web` URL.
	 *
	 * @param mixed $url           URL built from the uploads folder.
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public function filter_url( $url, $attachment_id ) {
		$urls = $this->urls( (int) $attachment_id );
		return null === $urls ? $url : $urls['web'];
	}

	/**
	 * `image_downsize`: `thumb` for sizes up to 400 px square, `web` for the rest.
	 *
	 * @param mixed $out           Earlier result.
	 * @param mixed $attachment_id Attachment id.
	 * @param mixed $size          Size name or [ width, height ].
	 * @return mixed
	 */
	public function filter_downsize( $out, $attachment_id, $size ) {
		$urls = $this->urls( (int) $attachment_id );
		if ( null === $urls ) {
			return $out;
		}

		$wants_thumb = false;
		if ( 'thumbnail' === $size ) {
			$wants_thumb = true;
		} elseif ( is_array( $size ) && isset( $size[0], $size[1] ) ) {
			$wants_thumb = (int) $size[0] <= self::THUMB_SIDE && (int) $size[1] <= self::THUMB_SIDE;
		}
		if ( $wants_thumb && $urls['thumb'] !== $urls['web'] ) {
			return array( $urls['thumb'], self::THUMB_SIDE, self::THUMB_SIDE, true );
		}

		$meta = wp_get_attachment_metadata( (int) $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();
		return array( $urls['web'], (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), false );
	}

	/**
	 * `wp_calculate_image_srcset`: only the `web` variant.
	 *
	 * The `thumb` is a square crop. Offering it as a candidate would show a
	 * cropped picture where the layout expects the photo's own shape.
	 *
	 * @param mixed $sources       Candidate sources.
	 * @param mixed $size_array    Requested [ width, height ].
	 * @param mixed $image_src     Source URL.
	 * @param mixed $image_meta    Attachment metadata.
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src );
		$urls = $this->urls( (int) $attachment_id );
		if ( null === $urls || ! is_array( $image_meta ) ) {
			return $sources;
		}
		$width = (int) ( $image_meta['width'] ?? 0 );
		if ( $width <= 0 ) {
			return array();
		}
		return array(
			$width => array(
				'url'        => $urls['web'],
				'descriptor' => 'w',
				'value'      => $width,
			),
		);
	}

	/**
	 * `wp_prepare_attachment_for_js`: tells the media modal the attachment is virtual.
	 *
	 * @param mixed $response   Attachment as the modal sees it.
	 * @param mixed $attachment Attachment post.
	 * @return mixed
	 */
	public function filter_js( $response, $attachment ) {
		if ( ! is_array( $response ) || ! is_object( $attachment ) || ! isset( $attachment->ID ) ) {
			return $response;
		}
		if ( ! $this->is_virtual( (int) $attachment->ID ) ) {
			return $response;
		}
		$response['profotograafVirtual'] = true;
		return $response;
	}

	/**
	 * `load_image_to_edit_path`: the legacy image editor (Media Library, Edit image)
	 * falls back to downloading the file from the attachment URL when none exists
	 * on disk. A save then writes a local copy while the site keeps serving the
	 * platform image. Refusing the path stops the editor before that.
	 *
	 * @param mixed $path          File path or URL the editor would load.
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public function filter_edit_path( $path, $attachment_id ) {
		if ( ! $this->is_virtual( (int) $attachment_id ) ) {
			return $path;
		}
		/**
		 * Whether image editing is refused for virtual attachments. Only the
		 * compatibility test turns this off, to record what happens without it.
		 *
		 * @param bool $block True to refuse.
		 */
		return true === apply_filters( 'profotograaf_virtual_attachments_block_editing', true ) ? false : $path;
	}

	/**
	 * `render_block_data`: a saved Image block holds the image URL as text. For a
	 * photo that has an attachment (virtual, or converted to local), the block
	 * gets the attachment's current URL before it renders, so the lightbox sees
	 * it too. A sync that finds a new version, or a conversion, then reaches
	 * posts that were saved earlier. Reads post meta only.
	 *
	 * @param mixed $parsed_block Parsed block.
	 * @return mixed
	 */
	public function filter_block_data( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || 'core/image' !== ( $parsed_block['blockName'] ?? '' ) || ! isset( $parsed_block['attrs']['id'] ) ) {
			return $parsed_block;
		}
		$attachment_id = (int) $parsed_block['attrs']['id'];
		$html          = $parsed_block['innerHTML'] ?? '';
		if ( $attachment_id <= 0 || ! is_string( $html ) || ! self::enabled() || '' === (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true ) ) {
			return $parsed_block;
		}
		if ( 1 !== preg_match( '~<img[^>]*\ssrc="([^"]+)"~', $html, $match ) ) {
			return $parsed_block;
		}
		$saved   = $match[1];
		$current = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $current ) || '' === $current || $current === $saved || ! $this->importer->is_platform_url( html_entity_decode( $saved ) ) ) {
			return $parsed_block;
		}
		$parsed_block['innerHTML'] = str_replace( $saved, esc_url( $current ), $html );
		if ( isset( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] ) ) {
			foreach ( $parsed_block['innerContent'] as $index => $chunk ) {
				if ( is_string( $chunk ) ) {
					$parsed_block['innerContent'][ $index ] = str_replace( $saved, esc_url( $current ), $chunk );
				}
			}
		}
		return $parsed_block;
	}
}
