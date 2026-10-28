<?php
/**
 * Server-side markup of a slideshow at its first slide.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Port of `slides` in professionals web/src/public/embed/_lib/server.ts and of
 * the slideshow helpers in _lib/slideshow.ts: the stage with slide 1, the two
 * arrow buttons and the pager. A slideshow shows one photo at a time, so only
 * the first is drawn. embed.js fetches the gallery once and swaps in its own
 * slideshow; slide 1 is identical in both.
 */
final class Declarative_Slides {

	/**
	 * Up to this many photos get one dot each; above it a counter replaces them.
	 */
	private const DOT_LIMIT = 12;

	/**
	 * Shape of the stage when a photo has no usable size.
	 */
	private const FALLBACK_RATIO = '3/2';

	/**
	 * The `.slides` group.
	 *
	 * @param string               $first_tile The tile of slide 1, drawn by the renderer.
	 * @param array<string,mixed>  $first      Slide 1's photo.
	 * @param int                  $total      Photos in the slideshow.
	 * @param string               $title      Gallery title, the accessible name of the group.
	 * @param bool                 $fixed      A fixed crop ratio is set: no blurred backdrop.
	 * @param array<string,string> $labels    Arrow labels, `prev` and `next`, in the page language.
	 */
	public static function html( string $first_tile, array $first, int $total, string $title, bool $fixed, array $labels ): string {
		$stage = '<div class="stage" style="--pr: ' . self::esc( self::ratio( $first ) ) . ';">'
			. ( $fixed ? '' : '<img class="bg" alt="" aria-hidden="true" src="' . self::esc( self::smallest_src( $first ) ) . '">' )
			. $first_tile . '</div>';
		return '<div class="slides" role="group" aria-roledescription="carousel" aria-label="' . self::esc( $title ) . '"><div class="frame">'
			. $stage . self::nav( 'prev', $labels['prev'] ?? 'Previous photo' ) . self::nav( 'next', $labels['next'] ?? 'Next photo' )
			. '</div>' . self::pager( $total ) . '</div>';
	}

	/**
	 * One arrow button.
	 *
	 * @param string $dir   prev or next.
	 * @param string $label Accessible name.
	 */
	private static function nav( string $dir, string $label ): string {
		return '<button class="nav ' . $dir . '" type="button" aria-label="' . self::esc( $label ) . '">' . ( 'prev' === $dir ? "\u{2039}" : "\u{203a}" ) . '</button>';
	}

	/**
	 * The dots, or the counter above the dot limit.
	 *
	 * @param int $total Photos in the slideshow.
	 */
	private static function pager( int $total ): string {
		if ( $total > self::DOT_LIMIT ) {
			return '<div class="count" aria-live="polite">1 / ' . $total . '</div>';
		}
		$dots = '';
		for ( $k = 0; $k < $total; $k++ ) {
			$dots .= '<button class="dot" type="button" aria-label="' . ( $k + 1 ) . ' / ' . $total . '" aria-current="' . ( 0 === $k ? 'true' : 'false' ) . '"></button>';
		}
		return '<div class="dots">' . $dots . '</div>';
	}

	/**
	 * The CSS aspect-ratio of the stage: the photo's own width/height, else 3/2.
	 *
	 * @param array<string,mixed> $photo Photo.
	 */
	private static function ratio( array $photo ): string {
		$width  = (int) ( $photo['width'] ?? 0 );
		$height = (int) ( $photo['height'] ?? 0 );
		return $width > 0 && $height > 0 ? $width . '/' . $height : self::FALLBACK_RATIO;
	}

	/**
	 * The smallest image of a photo: enough for a blurred backdrop.
	 *
	 * @param array<string,mixed> $photo Photo.
	 */
	private static function smallest_src( array $photo ): string {
		$images = array_values( (array) ( $photo['images'] ?? array() ) );
		usort(
			$images,
			static fn( $a, $b ): int => (int) ( ( (array) $a )['width'] ?? 0 ) <=> (int) ( ( (array) $b )['width'] ?? 0 )
		);
		return $images ? (string) ( ( (array) $images[0] )['url'] ?? '' ) : '';
	}

	/**
	 * Escapes text and attribute values.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function esc( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
