<?php
/**
 * The no-JavaScript fallback link of a gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the link to the gallery page that sits inside the embed div. It stays
 * when JavaScript is off or the platform does not answer, so it is a plain
 * inline-block anchor that the stylesheet from Reserved_Space keeps inside the
 * block.
 */
final class Fallback_Link {

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
	 * @param Gallery_Index $index    Gallery details.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( Gallery_Index $index, Settings $settings ) {
		$this->index    = $index;
		$this->settings = $settings;
	}

	/**
	 * The link that stays when the script does not replace it, or an empty
	 * string when no gallery URL is known.
	 *
	 * @param string              $id   Gallery id.
	 * @param array<string,mixed> $args Render arguments.
	 */
	public function render( string $id, array $args ): string {
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
		$label      = '' !== $title ? $title : ( '' !== $site_label ? $site_label : __( 'View this gallery on Profotograaf', 'profotograaf' ) );
		return sprintf( '<a href="%1$s" style="display:inline-block;padding:.5em 0">%2$s</a>', esc_url( $url ), esc_html( $label ) );
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
