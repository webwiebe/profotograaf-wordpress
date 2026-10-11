<?php
/**
 * Current URLs for Image and Gallery blocks that use Profotograaf photos (issue #154).
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Saved block markup holds image URLs as text. A photo kept on Profotograaf
 * gets a new URL when the sync finds a new image version, and a conversion
 * to local moves it to the uploads folder. This class works out the current
 * URL of such an attachment and puts it in the saved markup when a block
 * renders: the core Image block, and the core Gallery block, both the current
 * one with Image blocks inside and the older one that saved its images in its
 * own markup. The editor script `build/virtual-urls/` reads the same URLs
 * through the REST field `profotograaf_urls` and updates the block attributes.
 *
 * Only a saved URL on the platform host is replaced, and only for an
 * attachment that carries the Profotograaf photo id. An attachment that still
 * carries the virtual mark while the setting "Keep photos on Profotograaf" is
 * off has no working URL, so it keeps the saved one. Everything else, a
 * converted photo included, gets its current URL whatever the setting
 * (issue #219). The check for the platform host comes first and reads no
 * database, so ordinary images cost nothing.
 */
final class Virtual_Block_Urls {

	/** REST field on attachments with the current URLs. */
	public const REST_FIELD = 'profotograaf_urls';

	/** Size a Gallery block uses when its attributes name none. */
	private const GALLERY_SIZE = 'large';

	/**
	 * Importer, for the platform host check.
	 *
	 * @var Photo_Importer
	 */
	private Photo_Importer $importer;

	/**
	 * Constructor.
	 *
	 * @param Photo_Importer $importer Importer.
	 */
	public function __construct( Photo_Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Adds the render filter and the REST field.
	 */
	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'filter_block_data' ), 20, 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
	}

	/**
	 * Registers the REST field `profotograaf_urls` on attachments.
	 */
	public function register_rest_field(): void {
		register_rest_field(
			'attachment',
			self::REST_FIELD,
			array(
				'get_callback' => array( $this, 'rest_urls' ),
				'schema'       => array(
					'description' => 'Current URLs of a Profotograaf photo, by size, or null for any other attachment.',
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * REST field callback.
	 *
	 * @param mixed $item Attachment as prepared for the response.
	 * @return array{full:string,sizes:array<string,string>}|null
	 */
	public function rest_urls( $item ): ?array {
		$id = is_array( $item ) && isset( $item['id'] ) ? (int) $item['id'] : 0;
		return $this->urls( $id );
	}

	/**
	 * The current URLs of a Profotograaf attachment: `full`, and one per size
	 * in its metadata. Null for any other attachment and for one that has no
	 * working URL.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{full:string,sizes:array<string,string>}|null
	 */
	public function urls( int $attachment_id ): ?array {
		$full = $this->current_url( $attachment_id, 'full' );
		if ( null === $full ) {
			return null;
		}
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$sizes = array();
		if ( is_array( $meta ) && isset( $meta['sizes'] ) ) {
			foreach ( array_keys( $meta['sizes'] ) as $size ) {
				$url = $this->sized_url( $attachment_id, (string) $size );
				if ( '' !== $url ) {
					$sizes[ (string) $size ] = $url;
				}
			}
		}
		return array(
			'full'  => $full,
			'sizes' => $sizes,
		);
	}

	/**
	 * `render_block_data`: puts the current URL in a saved Image block or in
	 * the images of an older Gallery block. WordPress applies the filter to the
	 * Image blocks inside a Gallery block as well.
	 *
	 * @param mixed $parsed_block Parsed block.
	 * @return mixed
	 */
	public function filter_block_data( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || ! isset( $parsed_block['innerHTML'] ) || ! is_string( $parsed_block['innerHTML'] ) ) {
			return $parsed_block;
		}
		$name  = $parsed_block['blockName'] ?? '';
		$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		if ( 'core/image' === $name ) {
			$map = $this->image_map( $parsed_block['innerHTML'], (int) ( $attrs['id'] ?? 0 ), $this->size_of( $attrs, 'full' ) );
		} elseif ( 'core/gallery' === $name ) {
			$map = $this->gallery_map( $parsed_block['innerHTML'], $this->size_of( $attrs, self::GALLERY_SIZE ) );
		} else {
			return $parsed_block;
		}
		return array() === $map ? $parsed_block : $this->replace( $parsed_block, $map );
	}

	/**
	 * Old to new URL for a saved Image block.
	 *
	 * @param string $html          Saved markup.
	 * @param int    $attachment_id Attachment id from the block attributes.
	 * @param string $size          Size slug.
	 * @return array<string,string>
	 */
	private function image_map( string $html, int $attachment_id, string $size ): array {
		if ( $attachment_id <= 0 || 1 !== preg_match( '~<img\b[^>]*\ssrc="([^"]+)"~', $html, $match ) ) {
			return array();
		}
		return $this->pair( $match[1], $attachment_id, $size );
	}

	/**
	 * Old to new URLs for the images of a Gallery block saved before WordPress
	 * 5.9, which keeps each image in its own markup with a `data-id`. A current
	 * Gallery block holds Image blocks, whose own render handles them, and its
	 * markup has no `<img>` of its own.
	 *
	 * @param string $html Saved markup.
	 * @param string $size Size slug of the gallery.
	 * @return array<string,string>
	 */
	private function gallery_map( string $html, string $size ): array {
		if ( ! preg_match_all( '~<img\b[^>]*>~', $html, $tags ) ) {
			return array();
		}
		$map = array();
		foreach ( $tags[0] as $tag ) {
			$id = 0;
			if ( 1 === preg_match( '~\sdata-id="(\d+)"~', $tag, $found ) || 1 === preg_match( '~\bwp-image-(\d+)\b~', $tag, $found ) ) {
				$id = (int) $found[1];
			}
			if ( $id <= 0 ) {
				continue;
			}
			if ( 1 === preg_match( '~\ssrc="([^"]+)"~', $tag, $src ) ) {
				$map += $this->pair( $src[1], $id, $size );
			}
			if ( 1 === preg_match( '~\sdata-full-url="([^"]+)"~', $tag, $full ) ) {
				$map += $this->pair( $full[1], $id, 'full' );
			}
		}
		return $map;
	}

	/**
	 * One old to new URL pair, or nothing when the saved URL is not on the
	 * platform host or is already current.
	 *
	 * @param string $saved         URL as saved in the markup (HTML-escaped).
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Size slug.
	 * @return array<string,string>
	 */
	private function pair( string $saved, int $attachment_id, string $size ): array {
		if ( ! $this->importer->is_platform_url( html_entity_decode( $saved ) ) ) {
			return array();
		}
		$current = $this->current_url( $attachment_id, $size );
		if ( null === $current || html_entity_decode( $saved ) === $current ) {
			return array();
		}
		return array( $saved => esc_url( $current ) );
	}

	/**
	 * The current URL of a Profotograaf attachment in a size, or null.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Size slug.
	 */
	private function current_url( int $attachment_id, string $size ): ?string {
		if ( $attachment_id <= 0 || '' === (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true ) ) {
			return null;
		}
		if ( Virtual_Attachments::is_marked( $attachment_id ) && ! Virtual_Attachments::enabled() ) {
			return null;
		}
		$url = $this->sized_url( $attachment_id, $size );
		if ( '' === $url ) {
			$fallback = wp_get_attachment_url( $attachment_id );
			$url      = is_string( $fallback ) ? $fallback : '';
		}
		return '' === $url ? null : $url;
	}

	/**
	 * URL of an attachment in a size, empty when WordPress has none.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Size slug.
	 */
	private function sized_url( int $attachment_id, string $size ): string {
		$image = wp_get_attachment_image_src( $attachment_id, $size );
		return is_array( $image ) ? (string) $image[0] : '';
	}

	/**
	 * Size slug from block attributes.
	 *
	 * @param array<string,mixed> $attrs    Block attributes.
	 * @param string              $fallback Size when the block names none.
	 */
	private function size_of( array $attrs, string $fallback ): string {
		$size = $attrs['sizeSlug'] ?? '';
		return is_string( $size ) && 1 === preg_match( '/^[a-z0-9_-]+$/i', $size ) ? $size : $fallback;
	}

	/**
	 * Replaces the old URLs with the new ones in the block markup.
	 *
	 * @param array<string,mixed>  $parsed_block Parsed block.
	 * @param array<string,string> $map          Old URL to new URL.
	 * @return array<string,mixed>
	 */
	private function replace( array $parsed_block, array $map ): array {
		$parsed_block['innerHTML'] = strtr( (string) $parsed_block['innerHTML'], $map );
		if ( isset( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] ) ) {
			foreach ( $parsed_block['innerContent'] as $index => $chunk ) {
				if ( is_string( $chunk ) ) {
					$parsed_block['innerContent'][ $index ] = strtr( $chunk, $map );
				}
			}
		}
		return $parsed_block;
	}
}
