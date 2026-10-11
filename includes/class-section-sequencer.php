<?php
/**
 * Splits one gallery into the sections of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The sequencer of the full-page gallery (#194). It turns the photos of one
 * gallery, a preset (or the photographer's own sections) and the picks into
 * the list of sections the renderer draws. It is pure: no WordPress state and
 * no platform calls, so the editor preview (Sections_Rest) and the front end
 * run the same code. The rules are in docs/full-page-gallery.md:
 *
 * 1. Gallery order, with excluded photos removed first.
 * 2. A picked photo leaves the flow before the sections are filled, so it
 *    shows once. An unpicked slot takes the next photo in flow order.
 * 3. When fewer photos are left than the next section takes, the rest
 *    becomes a grid of full rows plus one shorter row, so no row has an
 *    empty cell. A single photo left over becomes a hero.
 * 4. A text section takes no photos and never ends the gallery.
 * 5. No photos: no sections, and the renderer shows the fallback link.
 *
 * Every section in the result is normalised (Sections_Schema::normalise())
 * and carries `photos` (the photo arrays as given, in order) and `slots` (the
 * slot id of each photo, `<cycle>.<section index>.<position>`, or '' for a
 * photo of the tail). A pair carries the side it is drawn on, with the
 * mirroring resolved.
 */
final class Section_Sequencer {

	/**
	 * The photos still to place, in gallery order, keyed by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $flow = array();

	/**
	 * Picked photos by slot id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $picked = array();

	/**
	 * The gallery position of every photo, by id.
	 *
	 * @var array<string,int>
	 */
	private array $order = array();

	/**
	 * Sequences one gallery.
	 *
	 * @param array<int,mixed> $photos  Photos in gallery order, each an array with a string `id`.
	 * @param string           $preset  Preset id; Sections_Schema::preset_id() reads it.
	 * @param array<int,mixed> $custom  The stored sections of the `custom` preset.
	 * @param array<int,mixed> $picks   Preset picks: `{ slot, photoId }`. Ignored by `custom`, whose sections hold `photoIds`.
	 * @param string[]         $exclude Photo ids to leave out.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sequence( array $photos, string $preset, array $custom = array(), array $picks = array(), array $exclude = array() ): array {
		$preset  = Sections_Schema::preset_id( $preset );
		$pattern = self::pattern( $preset, $custom );
		$picked  = self::slot_picks( $picks );
		if ( Sections_Schema::CUSTOM === $preset ) {
			$picked = self::custom_picks( $pattern );
		}
		$self = new self();
		$self->load( $photos, $exclude );
		$self->pick( $pattern, $picked );
		return self::mirror( $self->fill( $pattern ) );
	}

	/**
	 * The sections of one cycle. A custom list without a single photo section
	 * would never place a photo, so it falls back to the default preset.
	 *
	 * @param string           $preset Preset id.
	 * @param array<int,mixed> $custom Stored custom sections.
	 * @return array<int,array<string,mixed>>
	 */
	private static function pattern( string $preset, array $custom ): array {
		$pattern = Sections_Schema::CUSTOM === $preset
			? array_values( array_filter( array_map( array( Sections_Schema::class, 'normalise' ), $custom ) ) )
			: Sections_Schema::pattern( $preset );
		$takes   = array_sum( array_map( array( Sections_Schema::class, 'take' ), $pattern ) );
		return $takes > 0 ? $pattern : Sections_Schema::pattern( Sections_Schema::default_preset() );
	}

	/**
	 * Fills the flow: photos with an id, in gallery order, without the excluded ones.
	 *
	 * @param array<int,mixed> $photos  Photos.
	 * @param string[]         $exclude Excluded ids.
	 */
	private function load( array $photos, array $exclude ): void {
		$skip = array_flip( array_map( 'strval', $exclude ) );
		foreach ( $photos as $photo ) {
			$id = is_array( $photo ) && is_scalar( $photo['id'] ?? null ) ? (string) $photo['id'] : '';
			if ( '' === $id || isset( $skip[ $id ] ) || isset( $this->flow[ $id ] ) ) {
				continue;
			}
			$this->order[ $id ] = count( $this->order );
			$this->flow[ $id ]  = $photo;
		}
	}

	/**
	 * Takes the picked photos out of the flow. A pick counts when its slot is
	 * inside the pattern and its photo is in the gallery and not picked yet.
	 *
	 * @param array<int,array<string,mixed>> $pattern Sections of one cycle.
	 * @param array<string,string>           $picks   Photo id by slot id.
	 */
	private function pick( array $pattern, array $picks ): void {
		uksort( $picks, 'strnatcmp' );
		foreach ( $picks as $slot => $id ) {
			[ $cycle, $index, $position ] = array_map( 'intval', explode( '.', $slot ) );
			$valid                        = $cycle >= 0 && isset( $pattern[ $index ] ) && $position >= 0 && $position < Sections_Schema::take( $pattern[ $index ] );
			if ( $valid && isset( $this->flow[ $id ] ) ) {
				$this->picked[ $slot ] = $this->flow[ $id ];
				unset( $this->flow[ $id ] );
			}
		}
	}

	/**
	 * Repeats the pattern until the photos run out.
	 *
	 * @param array<int,array<string,mixed>> $pattern Sections of one cycle.
	 * @return array<int,array<string,mixed>>
	 */
	private function fill( array $pattern ): array {
		$out   = array();
		$texts = array();
		$cycle = -1;
		while ( $this->left() > 0 ) {
			++$cycle;
			foreach ( $pattern as $index => $section ) {
				$take = Sections_Schema::take( $section );
				if ( 0 === $take ) {
					$texts[] = $section + array(
						'photos' => array(),
						'slots'  => array(),
					);
					continue;
				}
				if ( $this->left() === 0 ) {
					break 2;
				}
				$placed = $this->left() < $take ? $this->tail( $section ) : array( $this->section( $section, $cycle . '.' . $index, $take ) );
				array_push( $out, ...$texts, ...$placed );
				$texts = array();
			}
		}
		return $out;
	}

	/**
	 * One section filled from its picks and the flow.
	 *
	 * @param array<string,mixed> $section Normalised section.
	 * @param string              $prefix  `<cycle>.<section index>`.
	 * @param int                 $take    Photos it takes.
	 * @return array<string,mixed>
	 */
	private function section( array $section, string $prefix, int $take ): array {
		$photos = array();
		$slots  = array();
		for ( $position = 0; $position < $take; $position++ ) {
			$slot = $prefix . '.' . $position;
			if ( isset( $this->picked[ $slot ] ) ) {
				$photos[] = $this->picked[ $slot ];
				unset( $this->picked[ $slot ] );
			} else {
				$photos[] = $this->next();
			}
			$slots[] = $slot;
		}
		return array_merge( $section, compact( 'photos', 'slots' ) );
	}

	/**
	 * The rest of the photos, fewer than the next section takes: a grid of full
	 * rows, then one shorter row, or a hero for a single photo. The columns
	 * follow the section the rest replaces when it has columns.
	 *
	 * @param array<string,mixed> $section The section the photos did not fill.
	 * @return array<int,array<string,mixed>>
	 */
	private function tail( array $section ): array {
		$left    = $this->left();
		$columns = min( $left, (int) ( $section['columns'] ?? Sections_Schema::defaults( 'grid' )['columns'] ?? 3 ) );
		$rows    = intdiv( $left, max( 1, $columns ) );
		$out     = array();
		if ( $rows > 0 && $columns > 1 ) {
			$out[] = $this->tail_grid( $section, $columns, $rows );
		}
		$rest = $this->left();
		if ( $rest > 1 ) {
			$out[] = $this->tail_grid( $section, $rest, 1 );
		} elseif ( 1 === $rest ) {
			$hero  = (array) Sections_Schema::normalise(
				array(
					'type'     => 'hero',
					'captions' => $section['captions'] ?? '',
				)
			);
			$out[] = array_merge(
				$hero,
				array(
					'photos' => array( $this->next() ),
					'slots'  => array( '' ),
				)
			);
		}
		return $out;
	}

	/**
	 * A grid of the tail.
	 *
	 * @param array<string,mixed> $section The section the photos did not fill.
	 * @param int                 $columns Columns.
	 * @param int                 $rows    Rows.
	 * @return array<string,mixed>
	 */
	private function tail_grid( array $section, int $columns, int $rows ): array {
		$grid   = (array) Sections_Schema::normalise(
			array(
				'type'     => 'grid',
				'columns'  => $columns,
				'rows'     => $rows,
				'ratio'    => $section['ratio'] ?? '',
				'gap'      => $section['gap'] ?? '',
				'captions' => $section['captions'] ?? '',
			)
		);
		$photos = array();
		for ( $i = 0; $i < $columns * $rows; $i++ ) {
			$photos[] = $this->next();
		}
		return array_merge(
			$grid,
			array(
				'photos' => $photos,
				'slots'  => array_fill( 0, count( $photos ), '' ),
			)
		);
	}

	/**
	 * The next photo in the flow. When the flow is empty, a pick whose slot the
	 * gallery does not reach takes its place, in gallery order.
	 *
	 * @return array<string,mixed>
	 */
	private function next(): array {
		if ( array() === $this->flow ) {
			$this->flow   = $this->picked;
			$this->picked = array();
			uasort( $this->flow, fn( $a, $b ) => $this->order[ (string) $a['id'] ] <=> $this->order[ (string) $b['id'] ] );
		}
		return (array) array_shift( $this->flow );
	}

	/**
	 * The photos not placed yet, picks included. It changes as photos are placed.
	 *
	 * @phpstan-impure
	 */
	private function left(): int {
		return count( $this->flow ) + count( $this->picked );
	}

	/**
	 * Resolves the side of every pair: with mirror on, every second pair in the
	 * sequence flips its side.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	private static function mirror( array $sections ): array {
		$pairs = 0;
		foreach ( $sections as $i => $section ) {
			if ( 'pair' !== $section['type'] ) {
				continue;
			}
			if ( ! empty( $section['mirror'] ) && 1 === $pairs % 2 ) {
				$sections[ $i ]['side'] = 'left' === $section['side'] ? 'right' : 'left';
			}
			++$pairs;
		}
		return $sections;
	}

	/**
	 * Preset picks as photo id by slot id. Invalid entries are dropped.
	 *
	 * @param array<int,mixed> $picks Stored picks.
	 * @return array<string,string>
	 */
	private static function slot_picks( array $picks ): array {
		$out = array();
		foreach ( $picks as $pick ) {
			$slot = is_array( $pick ) && is_string( $pick['slot'] ?? null ) ? $pick['slot'] : '';
			$id   = is_array( $pick ) && is_scalar( $pick['photoId'] ?? null ) ? (string) $pick['photoId'] : '';
			if ( 1 === preg_match( '/^\d{1,4}\.\d{1,3}\.\d{1,3}$/', $slot ) && '' !== $id && ! isset( $out[ $slot ] ) ) {
				$out[ $slot ] = $id;
			}
		}
		return $out;
	}

	/**
	 * The `photoIds` of custom sections as picks of the first cycle.
	 *
	 * @param array<int,array<string,mixed>> $pattern Normalised custom sections.
	 * @return array<string,string>
	 */
	private static function custom_picks( array $pattern ): array {
		$out = array();
		foreach ( $pattern as $index => $section ) {
			foreach ( (array) ( $section['photoIds'] ?? array() ) as $position => $id ) {
				if ( '' !== (string) $id ) {
					$out[ '0.' . $index . '.' . $position ] = (string) $id;
				}
			}
		}
		return $out;
	}
}
