<?php
/**
 * Sequencer tests: how the full-page gallery splits a gallery into sections.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Section_Sequencer;
use Profotograaf\Sections_Schema;

class Section_Sequencer_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	/**
	 * Photos p-0, p-1, … in gallery order.
	 *
	 * @param int $count Photos.
	 * @return array<int,array{id:string}>
	 */
	private static function photos( int $count ): array {
		return array_map( static fn( int $i ): array => array( 'id' => 'p-' . $i ), $count > 0 ? range( 0, $count - 1 ) : array() );
	}

	/**
	 * The photo ids of the sequence, in render order.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return string[]
	 */
	private static function ids( array $sections ): array {
		$ids = array();
		foreach ( $sections as $section ) {
			foreach ( $section['photos'] as $photo ) {
				$ids[] = $photo['id'];
			}
		}
		return $ids;
	}

	/**
	 * The types of the sequence, with grids as `grid CxR`.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return string[]
	 */
	private static function shape( array $sections ): array {
		return array_map(
			static fn( array $s ): string => 'grid' === $s['type'] ? 'grid ' . $s['columns'] . 'x' . $s['rows'] : $s['type'],
			$sections
		);
	}

	/**
	 * Every preset at the photo counts of the issue, with and without picks.
	 *
	 * @return array<string,array{string,int,bool}>
	 */
	public static function galleries(): array {
		$cases = array();
		foreach ( array( 'calm', 'editorial', 'playful', 'custom' ) as $preset ) {
			foreach ( array( 0, 1, 2, 3, 7, 19, 50, 200 ) as $count ) {
				foreach ( array( false, true ) as $picks ) {
					$cases[ $preset . ' ' . $count . ( $picks ? ' picked' : '' ) ] = array( $preset, $count, $picks );
				}
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider galleries
	 */
	public function test_every_photo_shows_once_and_every_section_is_full( string $preset, int $count, bool $picked ): void {
		$custom = array(
			array( 'type' => 'text', 'heading' => 'Ceremony' ),
			array( 'type' => 'hero', 'photoIds' => $picked ? array( 'p-5' ) : array() ),
			array( 'type' => 'grid', 'columns' => 4, 'rows' => 1 ),
		);
		$picks  = $picked ? array(
			array( 'slot' => '0.0.0', 'photoId' => 'p-6' ),
			array( 'slot' => '0.1.1', 'photoId' => 'p-0' ),
			array( 'slot' => '1.0.0', 'photoId' => 'p-1' ),
		) : array();

		$sections = Section_Sequencer::sequence( self::photos( $count ), $preset, $custom, $picks );

		$ids = self::ids( $sections );
		sort( $ids, SORT_NATURAL );
		$this->assertSame( array_column( self::photos( $count ), 'id' ), $ids, 'every photo once' );
		foreach ( $sections as $section ) {
			$take = Sections_Schema::take( $section );
			$this->assertCount( $take, $section['photos'], $section['type'] . ' is full' );
			$this->assertCount( $take, $section['slots'] );
		}
		if ( array() !== $sections ) {
			$this->assertNotSame( 'text', end( $sections )['type'], 'a text section never ends the gallery' );
		}
	}

	public function test_no_photos_gives_no_sections(): void {
		$this->assertSame( array(), Section_Sequencer::sequence( array(), 'editorial' ) );
		$this->assertSame( array(), Section_Sequencer::sequence( self::photos( 3 ), 'calm', array(), array(), array( 'p-0', 'p-1', 'p-2' ) ) );
	}

	public function test_one_editorial_cycle_takes_19_photos_in_gallery_order(): void {
		$sections = Section_Sequencer::sequence( self::photos( 19 ), 'editorial' );

		$this->assertSame( array( 'hero', 'grid 3x2', 'pair', 'grid 3x2', 'feature' ), self::shape( $sections ) );
		$this->assertSame( array_column( self::photos( 19 ), 'id' ), self::ids( $sections ) );
		$this->assertSame( array( '0.0.0' ), $sections[0]['slots'] );
		$this->assertSame( '0.4.2', $sections[4]['slots'][2] );
	}

	public function test_the_pattern_repeats_and_the_rest_becomes_full_rows(): void {
		// calm: grid 3x2 + feature 2 = 8 a cycle. 8 + 8 + 3 left: grid 3x1.
		$this->assertSame( array( 'grid 3x2', 'feature', 'grid 3x2', 'feature', 'grid 3x1' ), self::shape( Section_Sequencer::sequence( self::photos( 19 ), 'calm' ) ) );
		// 8 + 5 left: one full row of 3, then a row of 2.
		$this->assertSame( array( 'grid 3x2', 'feature', 'grid 3x1', 'grid 2x1' ), self::shape( Section_Sequencer::sequence( self::photos( 13 ), 'calm' ) ) );
		// 6 + 1 left: the single photo becomes a hero.
		$this->assertSame( array( 'grid 3x2', 'hero' ), self::shape( Section_Sequencer::sequence( self::photos( 7 ), 'calm' ) ) );
		// 3 + 1 after a 4-column row: a row of 3 never needs more columns than photos.
		$this->assertSame( array( 'grid 3x1' ), self::shape( Section_Sequencer::sequence( self::photos( 3 ), 'playful' ) ) );
	}

	public function test_the_rest_keeps_the_gap_and_captions_of_the_section_it_replaces(): void {
		$custom = array(
			array( 'type' => 'grid', 'columns' => 4, 'rows' => 2, 'gap' => 20, 'captions' => 'below', 'ratio' => '1-1' ),
		);

		$sections = Section_Sequencer::sequence( self::photos( 6 ), 'custom', $custom );

		$this->assertSame( array( 'grid 4x1', 'grid 2x1' ), self::shape( $sections ) );
		$this->assertSame( array( 20, 'below', '1-1' ), array( $sections[1]['gap'], $sections[1]['captions'], $sections[1]['ratio'] ) );
		$this->assertSame( array( '', '' ), $sections[1]['slots'] );
	}

	public function test_a_pick_lands_on_its_slot_and_leaves_the_flow(): void {
		$picks = array(
			array( 'slot' => '0.0.0', 'photoId' => 'p-10' ),
			array( 'slot' => '0.2.0', 'photoId' => 'p-0' ),
		);

		$sections = Section_Sequencer::sequence( self::photos( 19 ), 'editorial', array(), $picks );

		$this->assertSame( 'p-10', $sections[0]['photos'][0]['id'] );
		$this->assertSame( array( 'p-1', 'p-2', 'p-3', 'p-4', 'p-5', 'p-6' ), array_column( $sections[1]['photos'], 'id' ) );
		$this->assertSame( array( 'p-0', 'p-7', 'p-8' ), array_column( $sections[2]['photos'], 'id' ) );
	}

	public function test_bad_picks_are_ignored(): void {
		$picks = array(
			array( 'slot' => '0.9.0', 'photoId' => 'p-3' ),
			array( 'slot' => '0.0.1', 'photoId' => 'p-3' ),
			array( 'slot' => 'x', 'photoId' => 'p-3' ),
			array( 'slot' => '0.1.0', 'photoId' => 'missing' ),
			array( 'slot' => '0.1.1', 'photoId' => 'p-2' ),
			array( 'slot' => '0.1.2', 'photoId' => 'p-2' ),
			'junk',
		);

		$sections = Section_Sequencer::sequence( self::photos( 19 ), 'editorial', array(), $picks );

		$this->assertSame( 'p-0', $sections[0]['photos'][0]['id'] );
		$this->assertSame( array( 'p-1', 'p-2', 'p-3' ), array_slice( array_column( $sections[1]['photos'], 'id' ), 0, 3 ) );
	}

	public function test_an_excluded_photo_cannot_be_picked(): void {
		$picks = array( array( 'slot' => '0.0.0', 'photoId' => 'p-4' ) );

		$sections = Section_Sequencer::sequence( self::photos( 8 ), 'editorial', array(), $picks, array( 'p-4' ) );

		$this->assertSame( 'p-0', $sections[0]['photos'][0]['id'] );
		$this->assertNotContains( 'p-4', self::ids( $sections ) );
	}

	public function test_a_pick_the_gallery_never_reaches_still_shows(): void {
		$picks = array( array( 'slot' => '5.0.0', 'photoId' => 'p-0' ) );

		$sections = Section_Sequencer::sequence( self::photos( 8 ), 'calm', array(), $picks );

		$this->assertSame( array( 'p-1', 'p-2', 'p-3', 'p-4', 'p-5', 'p-6', 'p-7', 'p-0' ), self::ids( $sections ) );
	}

	public function test_pairs_alternate_sides_when_mirrored(): void {
		$sides = static fn( array $sections ): array => array_column( array_filter( $sections, static fn( $s ) => 'pair' === $s['type'] ), 'side' );

		$this->assertSame( array( 'left', 'right', 'left' ), array_values( $sides( Section_Sequencer::sequence( self::photos( 57 ), 'editorial' ) ) ) );

		$custom = array(
			array( 'type' => 'pair', 'side' => 'right', 'mirror' => false ),
		);
		$this->assertSame( array( 'right', 'right' ), array_values( $sides( Section_Sequencer::sequence( self::photos( 6 ), 'custom', $custom ) ) ) );
	}

	public function test_text_stays_before_the_tail_and_never_ends_the_gallery(): void {
		$custom = array(
			array( 'type' => 'grid', 'columns' => 2, 'rows' => 1 ),
			array( 'type' => 'text', 'heading' => 'Party' ),
			array( 'type' => 'feature', 'count' => 3 ),
		);

		$this->assertSame( array( 'grid 2x1', 'text', 'grid 2x1' ), self::shape( Section_Sequencer::sequence( self::photos( 4 ), 'custom', $custom ) ) );
		$this->assertSame( array( 'grid 2x1' ), self::shape( Section_Sequencer::sequence( self::photos( 2 ), 'custom', $custom ) ) );
	}

	public function test_custom_section_picks_and_a_list_without_photos(): void {
		$custom = array(
			array( 'type' => 'hero', 'photoIds' => array( 'p-2' ) ),
			array( 'type' => 'grid', 'columns' => 2, 'rows' => 1 ),
		);
		$this->assertSame( array( 'p-2', 'p-0', 'p-1' ), self::ids( Section_Sequencer::sequence( self::photos( 3 ), 'custom', $custom ) ) );

		$only_text = array( array( 'type' => 'text' ) );
		$this->assertSame( 'hero', Section_Sequencer::sequence( self::photos( 19 ), 'custom', $only_text )[0]['type'], 'falls back to the default preset' );
	}

	public function test_an_unknown_preset_is_the_default_and_photos_without_an_id_are_skipped(): void {
		$photos = array_merge( array( array( 'id' => '' ), array( 'title' => 'no id' ), 'junk' ), self::photos( 1 ) );

		$sections = Section_Sequencer::sequence( $photos, 'nope' );

		$this->assertSame( array( 'hero' ), self::shape( $sections ) );
		$this->assertSame( array( 'p-0' ), self::ids( $sections ) );
	}
}
