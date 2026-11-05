<?php
/**
 * The seeded shuffle of the random sort.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The shuffle of selectPhotos in professionals web/src/public/embed/_lib/options.ts
 * (Fisher-Yates from the end), driven by mulberry32 instead of Math.random so a
 * seed gives one order. The 32-bit arithmetic is kept apart from PHP's 64-bit
 * integers with masks; tests/unit/Declarative_Shuffle_Test.php holds values
 * computed with the script's own functions.
 */
final class Declarative_Shuffle {

	private const MASK = 0xFFFFFFFF;

	/**
	 * The photos in the order the seed gives; the input is not changed.
	 *
	 * @param array<int,array<string,mixed>> $photos Photos.
	 * @param int                            $seed   Any integer; only its low 32 bits count.
	 * @return array<int,array<string,mixed>>
	 */
	public static function order( array $photos, int $seed ): array {
		$state = $seed & self::MASK;
		for ( $i = count( $photos ) - 1; $i > 0; $i-- ) {
			$state = ( $state + 0x6D2B79F5 ) & self::MASK;
			$j     = (int) floor( self::draw( $state ) / 4294967296 * ( $i + 1 ) );
			$swap  = $photos[ $i ];

			$photos[ $i ] = $photos[ $j ];
			$photos[ $j ] = $swap;
		}
		return $photos;
	}

	/**
	 * One mulberry32 output for a state, as an unsigned 32-bit integer.
	 *
	 * @param int $state The state after the increment.
	 */
	private static function draw( int $state ): int {
		$t = self::imul( $state ^ ( $state >> 15 ), $state | 1 );
		$t = $t ^ ( ( $t + self::imul( $t ^ ( $t >> 7 ), $t | 61 ) ) & self::MASK );
		return ( $t ^ ( $t >> 14 ) ) & self::MASK;
	}

	/**
	 * Math.imul on unsigned 32-bit values: the product mod 2^32, without leaving 64-bit integers.
	 *
	 * @param int $a Left factor, 0 to 2^32 - 1.
	 * @param int $b Right factor, 0 to 2^32 - 1.
	 */
	private static function imul( int $a, int $b ): int {
		return ( ( $a & 0xFFFF ) * $b + ( ( ( ( $a >> 16 ) * $b ) & 0xFFFF ) << 16 ) ) & self::MASK;
	}
}
