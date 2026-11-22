<?php
/**
 * Alphabetical order for every select of the plugin (owner rule 2026-11-21).
 *
 * Options sort case-insensitively and by the language of the site ("é" sorts
 * with "e", digits by value). The entries that stand for "no choice" stay
 * first, in the order they came: the site default, All, Any and None. A list
 * with a natural order (sizes, a ladder, numbers) goes through `natural()`,
 * which names that order. tests/unit/Select_Order_Gate_Test.php fails when a
 * select is built without one of the two.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Sorts the options of a select.
 */
final class Select_Options {

	/**
	 * Values of the entries that stay first.
	 */
	public const PINNED = array( '', 'all', 'any', 'none' );

	/**
	 * A `value => label` map in alphabetical order by label, the pinned values first.
	 *
	 * @template K of array-key
	 * @param array<K,string>   $choices Choices by value.
	 * @param array<int,string> $pinned  Extra values that stay first, for example the value of "Do not send".
	 * @param string|null       $locale  Language tag. The site language when omitted.
	 * @return array<K,string>
	 */
	public static function sorted( array $choices, array $pinned = array(), ?string $locale = null ): array {
		$keep  = array_merge( self::PINNED, $pinned );
		$first = array();
		$rest  = array();
		foreach ( $choices as $value => $label ) {
			if ( in_array( (string) $value, $keep, true ) ) {
				$first[ $value ] = $label;
			} else {
				$rest[ $value ] = $label;
			}
		}
		$compare = self::comparer( $locale );
		uasort( $rest, static fn( $a, $b ) => $compare( (string) $a, (string) $b ) );
		return $first + $rest;
	}

	/**
	 * Rows of a select in alphabetical order by one key, the pinned values first.
	 *
	 * @param array<int,array<string,mixed>> $rows      The rows.
	 * @param string                         $label_key Key that holds the visible label.
	 * @param string                         $value_key Key that holds the value, only read for pinned values.
	 * @param array<int,string>              $pinned    Extra values that stay first.
	 * @param string|null                    $locale    Language tag. The site language when omitted.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sorted_rows( array $rows, string $label_key, string $value_key = '', array $pinned = array(), ?string $locale = null ): array {
		$keep    = array_merge( self::PINNED, $pinned );
		$compare = self::comparer( $locale );
		$first   = array();
		$rest    = array();
		foreach ( $rows as $row ) {
			if ( '' !== $value_key && in_array( (string) ( $row[ $value_key ] ?? '' ), $keep, true ) ) {
				$first[] = $row;
			} else {
				$rest[] = $row;
			}
		}
		usort( $rest, static fn( $a, $b ) => $compare( (string) ( $a[ $label_key ] ?? '' ), (string) ( $b[ $label_key ] ?? '' ) ) );
		return array_merge( $first, $rest );
	}

	/**
	 * Marks a list that keeps a natural order. The choices come back unchanged.
	 *
	 * @template K of array-key
	 * @param array<K,string> $choices Choices by value.
	 * @param string          $order   The order they keep, in a few words ("sizes, small to large").
	 * @return array<K,string>
	 */
	public static function natural( array $choices, string $order ): array {
		unset( $order );
		return $choices;
	}

	/**
	 * A comparison of two labels: case-insensitive and locale aware with the
	 * intl extension, accent and case folding without it.
	 *
	 * @param string|null $locale Language tag.
	 * @return callable(string,string):int
	 */
	private static function comparer( ?string $locale ): callable {
		$locale = $locale ?? ( function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en_US' );
		if ( class_exists( \Collator::class ) ) {
			$collator = new \Collator( $locale );
			$collator->setStrength( \Collator::SECONDARY );
			$collator->setAttribute( \Collator::NUMERIC_COLLATION, \Collator::ON );
			return static fn( string $a, string $b ): int => (int) $collator->compare( $a, $b );
		}
		return static fn( string $a, string $b ): int => strnatcmp( self::fold( $a ), self::fold( $b ) );
	}

	/**
	 * Lower case without accents, for the sort without the intl extension.
	 *
	 * @param string $text Label.
	 */
	private static function fold( string $text ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$text = (string) remove_accents( $text );
		} else {
			$from = array_map( 'strval', (array) preg_split( '//u', 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖØÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöøùúûüýÿ', -1, PREG_SPLIT_NO_EMPTY ) );
			$to   = str_split( 'AAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy' );
			$text = strtr( $text, array_combine( $from, $to ) );
		}
		return strtolower( $text );
	}
}
