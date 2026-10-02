<?php
/**
 * Space held for a gallery before embed.js draws it.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the inline style that limits layout shift.
 */
final class Reserved_Space {

	/**
	 * The inline style that holds space for the gallery until embed.js draws
	 * it. Only a minimum height is set, so the box grows with the gallery. An
	 * aspect ratio would fix the box height and let the gallery overflow it.
	 * The rule() below clears the minimum once embed.js sets data-pf-ready.
	 */
	public static function style(): string {
		return 'min-height:8em';
	}

	/**
	 * The style element that releases the reserved space after embed.js has
	 * drawn the gallery. It needs !important to win over the inline style.
	 */
	public static function rule(): string {
		return '<style>[data-profotograaf-gallery][data-pf-ready]{min-height:0!important}</style>';
	}
}
