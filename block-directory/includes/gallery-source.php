<?php
/**
 * Turns what the author pasted into a gallery embed id.
 *
 * @package Profotograaf_Gallery_Block
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the text is a gallery share link on a Profotograaf host.
 *
 * @param string $source Text from the block.
 */
function profotograaf_gallery_block_is_share_url( string $source ): bool {
	return 1 === preg_match( '#^https://(?:[a-z0-9-]+\.)?profotograaf\.nl/share/g/[^/?\\#\s]+/?$#i', $source );
}

/**
 * Whether the text looks like an embed id.
 *
 * @param string $source Text from the block.
 */
function profotograaf_gallery_block_is_embed_id( string $source ): bool {
	return 1 === preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $source );
}

/**
 * Finds the embed id of a share link through the platform's oEmbed endpoint.
 * The answer is kept for twelve hours, a failed lookup for five minutes.
 *
 * @param string $url Share link.
 * @return string Embed id, or an empty string.
 */
function profotograaf_gallery_block_id_from_url( string $url ): string {
	$key    = 'pfgb_' . md5( $url );
	$cached = get_transient( $key );
	if ( is_string( $cached ) ) {
		return $cached;
	}

	$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
	$response = wp_safe_remote_get(
		add_query_arg(
			array(
				'url'    => rawurlencode( $url ),
				'format' => 'json',
			),
			'https://' . $host . '/oembed'
		),
		array( 'timeout' => 5 )
	);
	$id       = '';
	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$html = is_array( $body ) && isset( $body['html'] ) && is_string( $body['html'] ) ? $body['html'] : '';
		if ( 1 === preg_match( '/data-profotograaf-gallery="([A-Za-z0-9_-]{8,64})"/', $html, $found ) ) {
			$id = $found[1];
		}
	}
	set_transient( $key, $id, '' === $id ? 5 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	return $id;
}

/**
 * The embed id for what the author pasted.
 *
 * @param string $source Share link or embed id.
 * @return string Embed id, or an empty string.
 */
function profotograaf_gallery_block_resolve( string $source ): string {
	$source = trim( $source );
	if ( profotograaf_gallery_block_is_share_url( $source ) ) {
		return profotograaf_gallery_block_id_from_url( $source );
	}
	return profotograaf_gallery_block_is_embed_id( $source ) ? $source : '';
}
