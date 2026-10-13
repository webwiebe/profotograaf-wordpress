<?php
/**
 * Server render of the full-page gallery block.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the block `profotograaf/full-page-gallery` (#194) from the public
 * payload of its gallery: Section_Sequencer splits the photos into sections and
 * Full_Page_Markup writes them in the markup contract of
 * docs/full-page-gallery.md. The photos are in the HTML with their size, so the
 * page shows them without JavaScript; the front-end script adds the lightbox,
 * the filmstrip buttons and the view beacon.
 *
 * Lightbox, link target and captions resolve like the gallery block's: the
 * block value, then the plugin setting, then the default (lightbox on, no link,
 * new tab on, captions as the section says). A block caption setting applies to
 * the sections that keep captions off.
 *
 * When the payload cannot be read or the gallery has no photos to show, the
 * block prints the fallback link of the gallery block.
 */
final class Full_Page_Renderer {

	/** Flags for the JSON in script.pf-fp-data, safe inside a script tag. */
	private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

	/**
	 * Payload reader.
	 *
	 * @var Full_Page_Payload
	 */
	private Full_Page_Payload $payload;

	/**
	 * Gallery details, for the fallback link.
	 *
	 * @var Gallery_Index
	 */
	private Gallery_Index $index;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Option precedence.
	 *
	 * @var Option_Resolver
	 */
	private Option_Resolver $options;

	/**
	 * Constructor.
	 *
	 * @param Full_Page_Payload $payload  Payload reader.
	 * @param Gallery_Index     $index    Gallery details.
	 * @param Settings          $settings Settings.
	 */
	public function __construct( Full_Page_Payload $payload, Gallery_Index $index, Settings $settings ) {
		$this->payload  = $payload;
		$this->index    = $index;
		$this->settings = $settings;
		$this->options  = new Option_Resolver( $settings );
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string HTML, or an empty string without a valid gallery id.
	 */
	public function render( array $attributes ): string {
		$id = is_scalar( $attributes['galleryId'] ?? null ) ? trim( (string) $attributes['galleryId'] ) : '';
		if ( ! Gallery_Renderer::valid_id( $id ) ) {
			return '';
		}
		$gallery = $this->payload->get( $id );
		if ( is_wp_error( $gallery ) ) {
			return $this->fallback( $id, $attributes, array(), false );
		}
		$layout   = Gallery_Layout::resolve( $gallery, $attributes['preset'] ?? '', (array) ( $attributes['sections'] ?? array() ), (array) ( $attributes['picks'] ?? array() ) );
		$preset   = $layout['preset'];
		$sections = Section_Sequencer::sequence(
			array_values( array_filter( (array) ( $gallery['photos'] ?? array() ), 'is_array' ) ),
			$preset,
			$layout['custom'],
			$layout['picks'],
			Gallery_Renderer::clean_ids( $attributes['excludedPhotoIds'] ?? array() )
		);
		if ( array() === $sections ) {
			return $this->fallback( $id, $attributes, $gallery, true );
		}
		$options = $this->display_options( $attributes );
		$markup  = new Full_Page_Markup( $gallery, $options, $this->image_text( $attributes['imageText'] ?? array() ) );
		$html    = $markup->sections( $sections );
		$data    = array(
			'lightbox' => $options['lightbox'],
			'photos'   => $markup->photos(),
			'beacon'   => array(
				'url'     => Config::platform_endpoint( '/share/embed/view' ),
				'gallery' => is_scalar( $gallery['id'] ?? null ) && '' !== (string) $gallery['id'] ? (string) $gallery['id'] : $id,
			),
		);
		return sprintf(
			'<div %1$s>%2$s%3$s<script type="application/json" class="pf-fp-data">%4$s</script></div>',
			$this->wrapper( $attributes, $id, $preset ),
			$html,
			$this->badge( $gallery ),
			(string) wp_json_encode( $data, self::JSON_FLAGS )
		);
	}

	/**
	 * The resolved lightbox, link and caption options.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array{lightbox:bool,link_to:string,new_tab:bool,captions:string}
	 */
	private function display_options( array $attributes ): array {
		return array(
			'lightbox' => 'off' !== $this->options->resolve( 'gallery_lightbox', array( $attributes['lightbox'] ?? '' ), 'on' ),
			'link_to'  => (string) $this->options->resolve( 'gallery_link_to', array( $attributes['linkTo'] ?? '' ) ),
			'new_tab'  => 'off' !== $this->options->resolve( 'gallery_link_new_tab', array( $attributes['linkNewTab'] ?? '' ), 'on' ),
			'captions' => (string) $this->options->resolve( 'gallery_captions', array( $attributes['captions'] ?? '' ) ),
		);
	}

	/**
	 * The per-photo captions and alt texts of the block, by photo id, cleaned
	 * as the gallery block cleans them.
	 *
	 * @param mixed $raw The imageText attribute.
	 * @return array<string,array<string,string>>
	 */
	private function image_text( $raw ): array {
		$clean = Gallery_Renderer::clean_image_text( $raw );
		$list  = '' === $clean ? array() : json_decode( $clean, true );
		$out   = array();
		foreach ( is_array( $list ) ? $list : array() as $entry ) {
			$out[ (string) $entry['id'] ] = array_intersect_key( $entry, array_flip( array( 'caption', 'alt' ) ) );
		}
		return $out;
	}

	/**
	 * The badge, when the payload asks for it.
	 *
	 * @param array<string,mixed> $gallery Payload.
	 */
	private function badge( array $gallery ): string {
		$badge = is_array( $gallery['badge'] ?? null ) ? $gallery['badge'] : array();
		if ( empty( $badge['show'] ) ) {
			return '';
		}
		$url = Full_Page_Images::web_url( $badge['url'] ?? '' );
		return sprintf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener">%3$s</a>',
			empty( $badge['prominent'] ) ? 'pf-fp-badge' : 'pf-fp-badge prominent',
			esc_url( '' !== $url ? $url : Config::platform_url() ),
			esc_html__( 'Made with Profotograaf', 'profotograaf' )
		);
	}

	/**
	 * The fallback link of the gallery block, in the block's box. A gallery
	 * without photos also gets the editor hint.
	 *
	 * @param string              $id         Gallery id.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param array<string,mixed> $gallery    Payload, empty when it could not be read.
	 * @param bool                $no_photos  Whether the gallery has no photos to show.
	 */
	private function fallback( string $id, array $attributes, array $gallery, bool $no_photos ): string {
		$title = is_scalar( $attributes['galleryTitle'] ?? null ) ? (string) $attributes['galleryTitle'] : '';
		$url   = is_scalar( $attributes['galleryUrl'] ?? null ) ? (string) $attributes['galleryUrl'] : '';
		$link  = ( new Fallback_Link( $this->index, $this->settings ) )->render(
			$id,
			array(
				'title' => '' !== $title ? $title : (string) ( is_scalar( $gallery['title'] ?? null ) ? $gallery['title'] : '' ),
				'url'   => '' !== $url ? $url : (string) ( is_scalar( $gallery['url'] ?? null ) ? $gallery['url'] : '' ),
			)
		);
		return sprintf(
			'<div %1$s>%2$s%3$s</div>',
			$this->wrapper( $attributes, $id, '' ),
			$link,
			$no_photos ? Empty_Gallery::hint( true ) : ''
		);
	}

	/**
	 * The attribute string of the outer div. Inside a block render, WordPress
	 * adds the block class, the alignment and the spacing support.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $id         Gallery id.
	 * @param string              $preset     Preset id, or an empty string for the fallback.
	 */
	private function wrapper( array $attributes, string $id, string $preset ): string {
		$extra = array(
			'class'             => 'pf-fp',
			'data-pf-full-page' => $id,
		);
		if ( '' !== $preset ) {
			$extra['data-preset'] = $preset;
		}
		$html = function_exists( 'get_block_wrapper_attributes' ) ? (string) get_block_wrapper_attributes( $extra ) : '';
		if ( '' !== $html ) {
			return $html;
		}
		$align = (string) ( $attributes['align'] ?? 'full' );
		if ( in_array( $align, array( 'wide', 'full' ), true ) ) {
			$extra['class'] .= ' align' . $align;
		}
		$parts = array();
		foreach ( $extra as $name => $value ) {
			$parts[] = $name . '="' . esc_attr( $value ) . '"';
		}
		return implode( ' ', $parts );
	}
}
