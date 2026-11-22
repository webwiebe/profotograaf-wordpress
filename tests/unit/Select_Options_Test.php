<?php
/**
 * Alphabetical order of select options.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Select_Options;

class Select_Options_Test extends Wp_Test_Case {

	public function test_labels_sort_case_insensitively(): void {
		$sorted = Select_Options::sorted(
			array(
				'z' => 'zebra',
				'b' => 'Banana',
				'a' => 'apple',
			),
			array(),
			'en_US'
		);
		$this->assertSame( array( 'a', 'b', 'z' ), array_keys( $sorted ) );
	}

	public function test_accents_sort_with_their_letter_in_dutch_german_and_french(): void {
		$labels = array(
			'ecole' => 'École',
			'eagle' => 'Eagle',
			'zoo'   => 'Zoo',
			'oben'  => 'Öffnen',
			'ofen'  => 'Ofen',
		);
		foreach ( array( 'nl_NL', 'de_DE', 'fr_FR' ) as $locale ) {
			$keys = array_keys( Select_Options::sorted( $labels, array(), $locale ) );
			$this->assertLessThan( array_search( 'ecole', $keys, true ), array_search( 'eagle', $keys, true ), $locale );
			$this->assertLessThan( array_search( 'zoo', $keys, true ), array_search( 'ecole', $keys, true ), $locale );
			$this->assertLessThan( array_search( 'zoo', $keys, true ), array_search( 'oben', $keys, true ), $locale );
		}
	}

	public function test_the_entries_for_no_choice_stay_first_in_the_order_given(): void {
		$sorted = Select_Options::sorted(
			array(
				'grid' => 'Grid',
				'none' => 'None',
				''     => 'Site default',
				'all'  => 'All',
				'any'  => 'Any',
				'auto' => 'Automatic',
			),
			array(),
			'en_US'
		);
		$this->assertSame( array( 'none', '', 'all', 'any', 'auto', 'grid' ), array_keys( $sorted ) );
	}

	public function test_extra_pinned_values_stay_first(): void {
		$sorted = Select_Options::sorted( array( 'x' => 'Zed', 'skip' => 'Do not send', 'a' => 'Alpha' ), array( 'skip' ), 'en_US' );
		$this->assertSame( array( 'skip', 'a', 'x' ), array_keys( $sorted ) );
	}

	public function test_numbers_sort_by_value(): void {
		$sorted = Select_Options::sorted( array( '10' => '10', '2' => '2', '1' => '1' ), array(), 'en_US' );
		$this->assertSame( array( '1', '2', '10' ), array_map( 'strval', array_keys( $sorted ) ) );
	}

	public function test_rows_sort_by_label_and_keep_their_other_fields(): void {
		$rows   = array(
			array( 'id' => '2', 'title' => 'wedding' ),
			array( 'id' => '1', 'title' => 'Birthday' ),
		);
		$sorted = Select_Options::sorted_rows( $rows, 'title', 'id', array(), 'en_US' );
		$this->assertSame( array( '1', '2' ), array_column( $sorted, 'id' ) );
	}

	public function test_natural_returns_the_list_unchanged(): void {
		$sizes = array( 'l' => 'Large', 's' => 'Small', 'm' => 'Medium' );
		$this->assertSame( $sizes, Select_Options::natural( $sizes, 'sizes' ) );
	}

	public function test_the_input_is_left_alone(): void {
		$input = array( 'b' => 'B', 'a' => 'A' );
		Select_Options::sorted( $input, array(), 'en_US' );
		$this->assertSame( array( 'b', 'a' ), array_keys( $input ) );
	}
}
