<?php
/**
 * Helpers for the core-only patterns that are submitted to the wordpress.org
 * Pattern Directory. Not part of the plugin zip.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

/**
 * Where the placeholders send a photographer: the single-block plugin that shows a gallery.
 */
const PROFOTOGRAAF_PATTERN_BLOCK_URL = 'https://wordpress.org/plugins/profotograaf-gallery-block/';

/**
 * A group that stands in for a gallery, with a link to the block that fills it.
 *
 * @param string $label What goes here, in plain words.
 */
function profotograaf_pattern_placeholder( string $label ): string {
	return '<!-- wp:group {"style":{"border":{"width":"1px","style":"dashed"},"spacing":{"padding":{"top":"2rem","bottom":"2rem","left":"2rem","right":"2rem"}}},"layout":{"type":"constrained"}} -->'
		. '<div class="wp-block-group has-border-color" style="border-style:dashed;border-width:1px;padding-top:2rem;padding-right:2rem;padding-bottom:2rem;padding-left:2rem">'
		. Patterns::paragraph( $label )
		. '<!-- wp:paragraph --><p><a href="' . esc_url( PROFOTOGRAAF_PATTERN_BLOCK_URL ) . '">' . esc_html( 'Add your Profotograaf gallery with the Client gallery block' ) . '</a></p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';
}

/**
 * A core button.
 *
 * @param string $label Button text.
 */
function profotograaf_pattern_button( string $label ): string {
	return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">' . esc_html( $label ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
}
