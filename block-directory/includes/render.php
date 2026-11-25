<?php
/**
 * Front end output of the block.
 *
 * @package Profotograaf_Gallery_Block
 */

defined( 'ABSPATH' ) || exit;

/**
 * The note an editor sees in place of a gallery that cannot be shown.
 * Visitors get nothing.
 *
 * @param string $message Translated message.
 */
function profotograaf_gallery_block_notice( string $message ): string {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}
	return '<p class="profotograaf-gallery-block-notice" role="note">' . esc_html( $message ) . '</p>';
}

/**
 * Renders the block.
 *
 * @param array<string,mixed> $attributes Block attributes.
 */
function profotograaf_gallery_block_render( array $attributes ): string {
	$source = isset( $attributes['source'] ) && is_string( $attributes['source'] ) ? trim( $attributes['source'] ) : '';
	if ( '' === $source ) {
		return profotograaf_gallery_block_notice( __( 'Paste a Profotograaf gallery link in this block.', 'profotograaf-gallery-block' ) );
	}

	$id = profotograaf_gallery_block_resolve( $source );
	if ( '' === $id ) {
		return profotograaf_gallery_block_notice( __( 'This gallery cannot be shown. Check the link and that the gallery allows embedding.', 'profotograaf-gallery-block' ) );
	}

	$layout = isset( $attributes['layout'] ) && is_string( $attributes['layout'] ) ? $attributes['layout'] : 'auto';
	if ( ! in_array( $layout, array( 'auto', 'grid', 'masonry', 'slideshow' ), true ) ) {
		$layout = 'auto';
	}

	wp_enqueue_script(
		'profotograaf-gallery-block-embed',
		PROFOTOGRAAF_GALLERY_BLOCK_URL . 'build/embed.js',
		array(),
		PROFOTOGRAAF_GALLERY_BLOCK_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'async',
		)
	);

	$fallback = profotograaf_gallery_block_is_share_url( $source )
		? '<a href="' . esc_url( $source ) . '">' . esc_html__( 'View the gallery', 'profotograaf-gallery-block' ) . '</a>'
		: '';

	return sprintf(
		'<div %1$s data-profotograaf-gallery="%2$s" data-layout="%3$s">%4$s</div>',
		get_block_wrapper_attributes( array( 'class' => 'profotograaf-gallery' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core.
		esc_attr( $id ),
		esc_attr( $layout ),
		$fallback // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	);
}
