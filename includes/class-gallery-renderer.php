<?php
/**
 * Gallery embed markup.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the embed.js markup for one gallery. The block and the shortcode both
 * call it, so they cannot drift apart.
 *
 * The output is the div from docs/embed-js.md: `data-profotograaf-gallery`,
 * `data-layout` and, inside, a link to the gallery that stays when JavaScript
 * is off or the platform does not answer. The script is queued once per page.
 */
class Gallery_Renderer {

	/**
	 * Script handling.
	 *
	 * @var Embed_Script
	 */
	private Embed_Script $script;

	/**
	 * Gallery details.
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
	 * Constructor.
	 *
	 * @param Embed_Script  $script   Script handling.
	 * @param Gallery_Index $index    Gallery details.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( Embed_Script $script, Gallery_Index $index, Settings $settings ) {
		$this->script   = $script;
		$this->index    = $index;
		$this->settings = $settings;
	}

	/**
	 * Whether a value can be a gallery id. Ids are opaque, so only the
	 * characters that could break the markup are refused.
	 *
	 * @param string $id Candidate.
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id );
	}

	/**
	 * Renders a gallery.
	 *
	 * Keys of $args: id (required), layout (grid, masonry or slideshow; the
	 * default layout from the settings when empty or unknown), title and url
	 * (the fallback link; looked up when both are empty) and class (extra CSS
	 * classes for the div).
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return string HTML, empty without a valid id.
	 */
	public function render( array $args ): string {
		$id = isset( $args['id'] ) ? trim( (string) $args['id'] ) : '';
		if ( ! self::valid_id( $id ) ) {
			return '';
		}

		$layout = (string) $this->settings->resolve( 'default_layout', array( 'default_layout' => $args['layout'] ?? '' ) );

		$this->script->enqueue();

		$classes = trim( 'profotograaf-gallery ' . ( isset( $args['class'] ) ? (string) $args['class'] : '' ) );

		return sprintf(
			'<div class="%1$s" data-profotograaf-gallery="%2$s" data-layout="%3$s">%4$s</div>',
			esc_attr( $classes ),
			esc_attr( $id ),
			esc_attr( $layout ),
			$this->fallback_link( $id, $args )
		);
	}

	/**
	 * The link that stays when the script does not replace it.
	 *
	 * @param string              $id   Gallery id.
	 * @param array<string,mixed> $args Render arguments.
	 */
	private function fallback_link( string $id, array $args ): string {
		$title = isset( $args['title'] ) ? trim( wp_strip_all_tags( (string) $args['title'] ) ) : '';
		$url   = isset( $args['url'] ) ? $this->clean_url( (string) $args['url'] ) : '';

		if ( '' === $url ) {
			$known = $this->index->find( $id );
			if ( null !== $known ) {
				$url   = $this->clean_url( $known['url'] );
				$title = '' !== $title ? $title : trim( wp_strip_all_tags( $known['title'] ) );
			}
		}
		if ( '' === $url ) {
			return '';
		}

		$site_label = (string) $this->settings->resolve( 'fallback_link_label' );
		$label      = '' !== $title ? $title : ( '' !== $site_label ? $site_label : __( 'View the gallery', 'profotograaf' ) );
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * An http(s) URL, or an empty string.
	 *
	 * @param string $url Candidate.
	 */
	private function clean_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}
