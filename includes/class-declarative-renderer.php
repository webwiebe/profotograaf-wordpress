<?php
/**
 * Server-side markup of the gallery embed.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the tree embed.js draws, as a declarative shadow root
 * (`<template shadowrootmode="open">`), so a page with a gallery needs no
 * request to Profotograaf. This is a port of the server renderer in
 * professionals web/src/public/embed/_lib/server.ts and is held to the same
 * golden files (tests/fixtures/embed). embed.js adopts the result when it loads.
 *
 * It draws what a server can draw without measuring anything: grid and masonry
 * (one ordered list, no columns), captions off, below or overlay, the lightbox
 * tile and the badge. Every other option, a slideshow or an empty gallery gives
 * null, and the page keeps the host div for embed.js to draw.
 *
 * Every value from the payload is escaped where it is written.
 */
final class Declarative_Renderer {

	/**
	 * Host attributes the server draws. Any other `data-*` attribute with a value
	 * sends the gallery back to embed.js, which may be stricter than embed.js
	 * itself: an unrecognised value is then drawn by the script, not here.
	 *
	 * @var string[]
	 */
	private const SERVED_ATTRIBUTES = array(
		'data-layout',
		'data-captions',
		'data-badge',
		'data-lightbox',
		'data-columns',
		'data-columns-tablet',
		'data-columns-mobile',
		'data-gap',
		'data-gap-row',
		'data-gap-column',
		'data-radius',
		'data-shadow',
		'data-hover',
		'data-hover-strength',
		'data-frame',
		'data-ratio',
		'data-gutter',
		'data-link-to',
		'data-link-new-tab',
		'data-sort',
		'data-exclude',
		'data-image-text',
		'data-duotone',
	);

	/**
	 * The layouts a server draws completely.
	 *
	 * @var string[]
	 */
	private const SERVED_LAYOUTS = array( 'grid', 'masonry' );

	/**
	 * The declarative shadow root for a gallery, or null when embed.js has to draw it.
	 *
	 * @param array<string,mixed>  $gallery The embed API payload.
	 * @param array<string,string> $attrs   The host div's attributes, by name.
	 * @param string               $badge   Badge text, in the page language.
	 */
	public static function render( array $gallery, array $attrs, string $badge = 'Made with Profotograaf' ): ?string {
		if ( Declarative_Selection::needs_client( $attrs ) ) {
			return null;
		}
		$photos = Declarative_Selection::select( array_values( (array) ( $gallery['photos'] ?? array() ) ), $attrs );
		$layout = self::layout( $attrs, (string) ( $gallery['layout'] ?? '' ) );
		if ( ! $photos || ! in_array( $layout, self::SERVED_LAYOUTS, true ) || ! self::servable( $attrs ) ) {
			return null;
		}
		$captions = self::captions( $attrs );
		$grow     = Declarative_Look::grow( $attrs );
		$duotone  = Declarative_Duotone::colours( $attrs );
		$options  = array(
			'lightbox' => self::flag( $attrs['data-lightbox'] ?? '', true ),
			'target'   => Declarative_Links::target( $attrs ),
			'new_tab'  => Declarative_Links::new_tab( $attrs ),
		);
		$tiles    = '';
		foreach ( $photos as $i => $photo ) {
			$tiles .= self::tile( (array) $photo, $gallery, (int) $i, $layout, $captions, $grow, $options );
		}
		$classes = implode( ' ', array_merge( array( 'gallery', $layout ), $duotone ? array( 'duo' ) : array(), Declarative_Look::classes( $attrs, $layout ) ) );
		$style   = Declarative_Look::style( $attrs, $layout, count( $photos ) );
		$style   = '' === $style ? '' : ' style="' . self::esc( $style ) . '"';
		$css     = file_get_contents( __DIR__ . '/embed-shadow.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a file shipped with the plugin.
		return '<template shadowrootmode="open"><style>' . ( false === $css ? '' : $css ) . '</style>'
			. '<div class="pf"><div class="' . $classes . '"' . $style . '><div class="photos">' . $tiles . '</div></div></div>'
			. ( $duotone ? Declarative_Duotone::svg( $duotone ) : '' )
			. self::badge( (array) ( $gallery['badge'] ?? array() ), $attrs, $badge )
			. '</template>';
	}

	/**
	 * The layout embed.js draws: an explicit attribute, else the gallery's own.
	 *
	 * @param array<string,string> $attrs   Host attributes.
	 * @param string               $gallery Layout of the gallery on the platform.
	 */
	private static function layout( array $attrs, string $gallery ): string {
		$attr = Layout_Map::clean( $attrs['data-layout'] ?? '' );
		if ( '' !== $attr ) {
			// A drawn layout name passes through, a platform name is mapped, anything else is ignored.
			$drawn = Layout_Map::drawn( $attr, $attr );
			if ( Layout_Map::knows( $attr ) || $drawn === $attr ) {
				return $drawn;
			}
		}
		return Layout_Map::drawn( $gallery );
	}

	/**
	 * Whether every attribute with a value is one the server draws.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	private static function servable( array $attrs ): bool {
		foreach ( $attrs as $name => $value ) {
			if ( 0 === strpos( $name, 'data-' ) && 'data-profotograaf-gallery' !== $name && '' !== trim( (string) $value ) && ! in_array( $name, self::SERVED_ATTRIBUTES, true ) ) {
				return false;
			}
		}
		return ! Declarative_Look::needs_client( $attrs ) && ! Declarative_Selection::needs_client( $attrs );
	}

	/**
	 * The caption mode.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	private static function captions( array $attrs ): string {
		$value = strtolower( trim( $attrs['data-captions'] ?? '' ) );
		return in_array( $value, array( 'below', 'overlay' ), true ) ? $value : 'off';
	}

	/**
	 * An on/off attribute.
	 *
	 * @param string $value    Attribute value.
	 * @param bool   $fallback Value when absent or unrecognised.
	 */
	private static function flag( string $value, bool $fallback ): bool {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( 'on', 'true', '1', 'yes' ), true ) ) {
			return true;
		}
		return in_array( $value, array( 'off', 'false', '0', 'no' ), true ) ? false : $fallback;
	}

	/**
	 * Escapes text and attribute values.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function esc( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * One photo: the tile, with its caption when there is one.
	 *
	 * @param array<string,mixed> $photo    Photo.
	 * @param array<string,mixed> $gallery  The embed API payload.
	 * @param int                 $i        Index.
	 * @param string              $layout   grid or masonry.
	 * @param string              $captions off, below or overlay.
	 * @param bool                $grow     The grow hover is on: the outermost element carries `u`.
	 * @param array<string,mixed> $options  lightbox (bool), target (link target) and new_tab (bool).
	 */
	private static function tile( array $photo, array $gallery, int $i, string $layout, string $captions, bool $grow, array $options ): string {
		$alt    = self::alt( $photo, (string) ( $gallery['title'] ?? '' ), $i );
		$text   = self::caption( $photo, $captions );
		$mark   = $grow ? ' u' : '';
		$button = self::shell( $photo, $gallery, $alt, '' === $text ? $mark : '', $options, self::image( $photo, $alt, 'grid' === $layout ) );
		if ( '' === $text ) {
			return $button;
		}
		if ( 'below' === $captions ) {
			return '<figure class="item' . $mark . '">' . $button . '<figcaption class="below">' . self::esc( $text ) . '</figcaption></figure>';
		}
		return '<figure class="item ov' . $mark . '">' . $button . '<figcaption class="cap">' . self::esc( $text ) . '</figcaption></figure>';
	}

	/**
	 * The alt text: the photo's alt, else its title, else its caption, else the gallery title and position.
	 *
	 * @param array<string,mixed> $photo Photo.
	 * @param string              $title Gallery title.
	 * @param int                 $i     Position, from 0.
	 */
	private static function alt( array $photo, string $title, int $i ): string {
		foreach ( array( 'alt', 'title', 'caption' ) as $key ) {
			$value = (string) ( $photo[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return $title . ' ' . ( $i + 1 );
	}

	/**
	 * The visible caption: title and caption joined, empty when captions are off.
	 *
	 * @param array<string,mixed> $photo    Photo.
	 * @param string              $captions off, below or overlay.
	 */
	private static function caption( array $photo, string $captions ): string {
		if ( 'off' === $captions ) {
			return '';
		}
		return implode( ' - ', array_filter( array( (string) ( $photo['title'] ?? '' ), (string) ( $photo['caption'] ?? '' ) ), static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * The element a tile is drawn in: the lightbox button, a link, or an inert box.
	 *
	 * @param array<string,mixed> $photo   Photo.
	 * @param array<string,mixed> $gallery The embed API payload.
	 * @param string              $alt     Alt text, the accessible name.
	 * @param string              $mark    ' u' when the tile itself carries the grow marker.
	 * @param array<string,mixed> $options lightbox (bool), target (link target) and new_tab (bool).
	 * @param string              $image   The img element.
	 */
	private static function shell( array $photo, array $gallery, string $alt, string $mark, array $options, string $image ): string {
		$label = ' aria-label="' . self::esc( $alt ) . '"';
		if ( $options['lightbox'] ) {
			return '<button class="tile' . $mark . '" type="button"' . $label . '>' . $image . '</button>';
		}
		$href = Declarative_Links::href( $photo, $gallery, (string) $options['target'] );
		if ( '' !== $href ) {
			$blank = $options['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '';
			// Not esc_url(): Declarative_Links::href() already allowed only http(s) and relative URLs, and esc_url() would change the bytes the golden files fix.
			return '<a class="tile' . $mark . '" href="' . self::esc( $href ) . '"' . $blank . $label . '>' . $image . '</a>';
		}
		if ( '' !== $options['target'] ) {
			return '<div class="tile static' . $mark . '">' . $image . '</div>';
		}
		return '<button class="tile static' . $mark . '" type="button"' . $label . '>' . $image . '</button>';
	}

	/**
	 * The photo's img element.
	 *
	 * @param array<string,mixed> $photo  Photo.
	 * @param string              $alt    Alt text.
	 * @param bool                $square Grid layout: the square thumbs stay in the srcset.
	 */
	private static function image( array $photo, string $alt, bool $square ): string {
		$images = array_values( (array) ( $photo['images'] ?? array() ) );
		$width  = (int) ( $photo['width'] ?? 0 );
		$height = (int) ( $photo['height'] ?? 0 );
		$set    = self::srcset( $images, $width && $height ? $width / $height : 0, $square );
		$best   = self::best_url( $images );
		$out    = '<img alt="' . self::esc( $alt ) . '" src="' . self::esc( $best ) . '"';
		if ( '' !== $set ) {
			$out .= ' srcset="' . self::esc( $set ) . '" sizes="' . ( $square ? '(max-width: 600px) 50vw, 33vw' : '(max-width: 600px) 100vw, 50vw' ) . '"';
		}
		if ( $width && $height ) {
			$out .= ' width="' . $width . '" height="' . $height . '"';
		}
		return $out . ' loading="lazy" decoding="async" draggable="false">';
	}

	/**
	 * The srcset of a photo's images. A thumb with another aspect than the photo stays out, except in a grid.
	 *
	 * @param array<int,mixed> $images Payload images.
	 * @param float|int        $ratio  Width over height of the photo, 0 when unknown.
	 * @param bool             $square Grid layout: the square thumbs stay in.
	 */
	private static function srcset( array $images, $ratio, bool $square ): string {
		$parts = array();
		foreach ( $images as $image ) {
			$image = (array) $image;
			$w     = (int) ( $image['width'] ?? 0 );
			$h     = (int) ( $image['height'] ?? 0 );
			if ( $square || 'thumb' !== ( $image['variant'] ?? '' ) || ( $ratio && $h && abs( $w / $h - $ratio ) < 0.03 ) ) {
				$parts[] = ( $image['url'] ?? '' ) . ' ' . $w . 'w';
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * The src: the web variant, else the last image.
	 *
	 * @param array<int,mixed> $images Payload images.
	 */
	private static function best_url( array $images ): string {
		$best = '';
		foreach ( $images as $image ) {
			$image = (array) $image;
			$best  = (string) ( $image['url'] ?? '' );
			if ( 'web' === ( $image['variant'] ?? '' ) ) {
				break;
			}
		}
		return $best;
	}

	/**
	 * The "Made with" badge, when the platform shows one and the host div does not turn it off.
	 *
	 * @param array<string,mixed>  $badge The payload's badge.
	 * @param array<string,string> $attrs Host attributes.
	 * @param string               $text  Badge text.
	 */
	private static function badge( array $badge, array $attrs, string $text ): string {
		if ( empty( $badge['show'] ) || ! self::flag( $attrs['data-badge'] ?? '', true ) ) {
			return '';
		}
		$class = empty( $badge['prominent'] ) ? 'badge' : 'badge prominent';
		return '<a class="' . $class . '" href="' . self::esc( $badge['url'] ?? '' ) . '" target="_blank" rel="noopener">' . self::esc( $text ) . '</a>';
	}
}
