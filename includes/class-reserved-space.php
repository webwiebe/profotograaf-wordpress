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
	 * it. The box gets the ratio of one photo and grows when the gallery is
	 * taller. An original or unset ratio keeps the fixed minimum.
	 *
	 * @param array<string,string> $data Display option data attributes.
	 */
	public static function style( array $data ): string {
		$style = 'min-height:8em';
		$ratio = $data['data-ratio'] ?? '';
		if ( 1 === preg_match( '/^([1-9][0-9]{0,2}):([1-9][0-9]{0,2})$/', $ratio, $parts ) ) {
			$style .= ';aspect-ratio:' . $parts[1] . ' / ' . $parts[2];
		}
		return $style;
	}
}
