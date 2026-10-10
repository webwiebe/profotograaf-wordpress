<?php
/**
 * Space held for a gallery before embed.js draws it.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the inline style and the stylesheet rule that limit layout shift.
 *
 * Until the gallery is drawn the host holds an aspect ratio close to the
 * drawn gallery, so the content below it moves little when the tiles appear.
 * The estimate uses CSS only, so it is right at any container width and the
 * render path makes no request.
 *
 * The ratio of the host is columns x tile width over rows x tile height:
 *
 * - Columns: data-columns, data-columns-tablet and data-columns-mobile, which
 *   already carry the site defaults and, when only the desktop columns are
 *   set, the step down of step_down(). An empty value uses a default close to
 *   what embed.js picks for a content column of about 650px: grid 4, 3 and 2,
 *   masonry 3, 2 and 1 (desktop, tablet at 900px and below, phone at 600px
 *   and below). An empty tablet value never exceeds the desktop value and an
 *   empty phone value never exceeds the tablet value.
 * - Rows: ceil( photos shown / columns ). Photos shown is the smaller of
 *   data-per-page and the photo count that Gallery_Index remembers from the
 *   last gallery list, whichever is known. When neither is known it is
 *   DEFAULT_ROWS rows. Capped at MAX_ROWS, because the page below a taller
 *   gallery is out of view anyway.
 * - Tile shape: data-ratio. Without one (empty or `original`) a grid draws
 *   square tiles and a masonry gallery of mixed photos is estimated at 4:3,
 *   a little flatter than a mix of portrait and landscape photos, so the
 *   estimate errs short. A slideshow is one 3:2 tile.
 * - Gap: ignored. A pixel gap cannot be mixed into a ratio, and it only makes
 *   the drawn gallery taller than the estimate.
 *
 * Each breakpoint gets its own custom property on the host (--pf-ar,
 * --pf-ar-t, --pf-ar-m) and rule() picks one with a media query.
 *
 * The host sets no minimum height. The automatic minimum size of a box with an
 * aspect ratio is its content, so a gallery taller than the estimate (or one
 * that grows with Show more) pushes the box open and never overflows it. A
 * fixed minimum height would switch that off, which was the overflow bug.
 *
 * embed.js sets data-pf-ready when it starts, before it has fetched the
 * photos, so that attribute cannot end the reservation: the box would collapse
 * and then jump open when the tiles arrive. Once the gallery is drawn its
 * content sets the height, and an estimate that errs short leaves no gap. Two
 * cases leave a ratio box taller than its content: an estimate that is too
 * tall and a gallery that never draws (platform down, no photos). A release
 * animation switches the ratio to auto after RELEASE_AFTER seconds for those,
 * and the noscript rule releases it at once without JavaScript.
 *
 * An embed.js that reports its outcome sets data-pf-state on the host:
 * `empty` and `error` release the box at once, `drawn` keeps it. An older
 * embed.js sets no state and keeps the release animation.
 *
 * A gallery without photos the embed can show carries `data-pf-empty`
 * (Empty_Gallery). It holds no space, hides the fallback link and keeps the
 * editor hint. A failed gallery (`data-profotograaf-failed`, or state `error`)
 * shows its fallback link inside the block at the start edge. A script that
 * never loads sets no state, so that case keeps its space until the release
 * animation ends.
 */
final class Reserved_Space {

	/**
	 * Rows assumed when neither the page size nor the photo count is known.
	 */
	public const DEFAULT_ROWS = 3;

	/**
	 * Most rows reserved.
	 */
	public const MAX_ROWS = 10;

	/**
	 * Seconds after which an undrawn gallery gives its space back.
	 */
	public const RELEASE_AFTER = 8;

	/**
	 * Tile shape of a masonry gallery whose photos keep their own shape.
	 */
	private const MASONRY_SHAPE = array( 4, 3 );

	/**
	 * Default columns per layout: desktop, tablet, phone.
	 */
	private const COLUMNS = array(
		'grid'      => array( 4, 3, 2 ),
		'masonry'   => array( 3, 2, 1 ),
		'slideshow' => array( 1, 1, 1 ),
	);

	/**
	 * Most columns on a tablet when only the desktop columns are set.
	 */
	public const TABLET_COLUMNS = 3;

	/**
	 * Most columns on a phone when only the desktop columns are set: a grid 2,
	 * masonry 1.
	 */
	private const PHONE_COLUMNS = array(
		'grid'    => 2,
		'masonry' => 1,
	);

	/**
	 * Columns at tablet and phone widths for a gallery that sets only its
	 * desktop columns: tablet min( columns, 3 ), phone min( tablet, 2 ) for a
	 * grid and 1 for masonry. A slideshow has no columns, so it gets none.
	 * Gallery_Renderer sends these as data-columns-tablet and
	 * data-columns-mobile, and style() estimates with the same numbers, so the
	 * reserved box matches the drawn gallery.
	 *
	 * @param string $layout  Layout.
	 * @param int    $columns Desktop columns.
	 * @return array{0:int,1:int}|null Tablet and phone columns, null for a slideshow.
	 */
	public static function step_down( string $layout, int $columns ): ?array {
		if ( 'slideshow' === $layout ) {
			return null;
		}
		$tablet = min( $columns, self::TABLET_COLUMNS );
		return array( $tablet, min( $tablet, self::PHONE_COLUMNS[ $layout ] ?? self::PHONE_COLUMNS['grid'] ) );
	}

	/**
	 * Fills the tablet and phone columns from the desktop columns when only
	 * those are set, so a 5 column grid does not draw 5 columns of thumbnails
	 * on a phone. A tablet or phone value from the block, shortcode or site
	 * settings is kept. Gallery_Renderer calls it on the data attributes.
	 *
	 * @param array<string,string> $data   Display option data attributes.
	 * @param string               $layout Layout.
	 * @return array<string,string>
	 */
	public static function with_step_down( array $data, string $layout ): array {
		$columns = self::whole( $data['data-columns'] ?? '', 12 );
		$stepped = null === $columns ? null : self::step_down( $layout, $columns );
		if ( null === $stepped ) {
			return $data;
		}
		$tablet = (int) ( $data['data-columns-tablet'] ?? $stepped[0] );
		if ( ! isset( $data['data-columns-tablet'] ) ) {
			$data['data-columns-tablet'] = (string) $stepped[0];
		}
		if ( ! isset( $data['data-columns-mobile'] ) ) {
			$data['data-columns-mobile'] = (string) min( $tablet, $stepped[1] );
		}
		return $data;
	}

	/**
	 * The inline style that holds space for the gallery until it is drawn: the
	 * three ratios that rule() applies.
	 *
	 * @param string               $layout Layout: grid, masonry or slideshow.
	 * @param array<string,string> $data   Display option data attributes, as written on the host.
	 * @param int|null             $count  Photo count of the gallery, when known.
	 */
	public static function style( string $layout = 'grid', array $data = array(), ?int $count = null ): string {
		$shape   = self::shape( $layout, $data['data-ratio'] ?? '' );
		$columns = self::columns( $layout, $data );
		$shown   = self::shown( $data['data-per-page'] ?? '', $count );
		$styles  = array();
		foreach ( array( '--pf-ar', '--pf-ar-t', '--pf-ar-m' ) as $i => $property ) {
			$cols     = 'slideshow' === $layout ? 1 : $columns[ $i ];
			$rows     = 'slideshow' === $layout ? 1 : self::rows( $cols, $shown );
			$styles[] = sprintf( '%s:%s/%s', $property, self::number( $cols * $shape[0] ), self::number( $rows * $shape[1] ) );
		}
		return implode( ';', $styles );
	}

	/**
	 * The style element that holds the space. The release animation gives it
	 * back for a gallery that never draws or that came out shorter than the
	 * estimate.
	 */
	public static function rule(): string {
		$host = '[data-profotograaf-gallery]';
		return '<style>'
			. $host . '{aspect-ratio:var(--pf-ar);animation:pf-release 1ms ' . self::RELEASE_AFTER . 's forwards}'
			. '@media(max-width:900px){' . $host . '{aspect-ratio:var(--pf-ar-t)}}'
			. '@media(max-width:600px){' . $host . '{aspect-ratio:var(--pf-ar-m)}}'
			. '@keyframes pf-release{to{aspect-ratio:auto}}'
			. '[data-pf-empty],[data-pf-state=empty]{display:block;aspect-ratio:auto!important;animation:none}'
			. '[data-pf-state=error]{aspect-ratio:auto!important;animation:none}'
			. '[data-pf-empty],[data-pf-state=empty],[data-profotograaf-failed],[data-pf-state=error]{overflow:visible;text-indent:0;text-align:start}'
			. '[data-pf-empty]>a,[data-pf-empty]>noscript,[data-pf-state=empty]>a,[data-pf-state=empty]>noscript{display:none!important}'
			. $host . '>a{max-width:100%;overflow-wrap:anywhere;text-indent:0}'
			. '</style>'
			. '<noscript><style>' . $host . '{aspect-ratio:auto!important}</style></noscript>';
	}

	/**
	 * Tile width and height from a data-ratio value such as `4:3`.
	 *
	 * @param string $layout Layout.
	 * @param string $ratio  Value, empty or `original` for the photos' own shape.
	 * @return array{0:float,1:float}
	 */
	private static function shape( string $layout, string $ratio ): array {
		if ( 1 === preg_match( '/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $ratio, $m ) && (float) $m[1] > 0 && (float) $m[2] > 0 ) {
			return array( (float) $m[1], (float) $m[2] );
		}
		if ( 'slideshow' === $layout ) {
			return array( 3.0, 2.0 );
		}
		return 'masonry' === $layout ? array( (float) self::MASONRY_SHAPE[0], (float) self::MASONRY_SHAPE[1] ) : array( 1.0, 1.0 );
	}

	/**
	 * Columns at desktop, tablet and phone widths.
	 *
	 * @param string               $layout Layout.
	 * @param array<string,string> $data   Data attributes.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function columns( string $layout, array $data ): array {
		$defaults = self::COLUMNS[ $layout ] ?? self::COLUMNS['grid'];
		// Columns set without a tablet or phone value step down like the markup.
		$set     = self::whole( $data['data-columns'] ?? '', 12 );
		$desktop = $set ?? $defaults[0];
		$stepped = null === $set ? null : self::step_down( $layout, $set );
		$tablet  = self::whole( $data['data-columns-tablet'] ?? '', 12 ) ?? $stepped[0] ?? min( $desktop, $defaults[1] );
		$phone   = self::whole( $data['data-columns-mobile'] ?? '', 12 ) ?? ( null === $stepped ? min( $tablet, $defaults[2] ) : min( $tablet, $stepped[1] ) );
		return array( $desktop, $tablet, $phone );
	}

	/**
	 * Photos shown at first, or null when unknown.
	 *
	 * @param string   $per_page Page size from data-per-page.
	 * @param int|null $count    Photo count.
	 */
	private static function shown( string $per_page, ?int $count ): ?int {
		$page = self::whole( $per_page, 500 );
		if ( null !== $page && null !== $count ) {
			return min( $page, $count );
		}
		return $page ?? $count;
	}

	/**
	 * Rows for a number of columns.
	 *
	 * @param int      $columns Columns.
	 * @param int|null $shown   Photos shown, null when unknown.
	 */
	private static function rows( int $columns, ?int $shown ): int {
		$rows = null === $shown ? self::DEFAULT_ROWS : (int) ceil( $shown / $columns );
		return max( 1, min( self::MAX_ROWS, $rows ) );
	}

	/**
	 * A whole number from 1 to $max, or null.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Largest accepted value.
	 */
	private static function whole( string $value, int $max ): ?int {
		if ( 1 !== preg_match( '/^\d{1,4}$/', $value ) ) {
			return null;
		}
		$number = (int) $value;
		return $number >= 1 && $number <= $max ? $number : null;
	}

	/**
	 * A number for CSS: up to three decimals, no trailing zeros.
	 *
	 * @param float $value Number.
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
	}
}
