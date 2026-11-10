<?php
/**
 * The seeded shuffle, held to values computed with the embed script's own
 * mulberry32 and the shuffle of selectPhotos.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Declarative_Shuffle;

class Declarative_Shuffle_Test extends TestCase {

	/**
	 * Seed => the order of the ids a to h, from options.ts with mulberry32.
	 */
	private const ORDERS = array(
		1          => 'cbghedaf',
		42         => 'chbagfde',
		2147483647 => 'hefgbcad',
		4294967295 => 'acgdfebh',
	);

	/**
	 * @param string $letters Photo ids, one letter each.
	 * @return array<int,array<string,string>>
	 */
	private function photos( string $letters ): array {
		return array_map( static fn( string $id ): array => array( 'id' => $id ), str_split( $letters ) );
	}

	private function ids( array $photos ): string {
		return implode( '', array_column( $photos, 'id' ) );
	}

	public function test_a_seed_gives_the_order_the_script_gives(): void {
		foreach ( self::ORDERS as $seed => $expected ) {
			$this->assertSame( $expected, $this->ids( Declarative_Shuffle::order( $this->photos( 'abcdefgh' ), $seed ) ), (string) $seed );
		}
	}

	public function test_the_same_seed_gives_the_same_order_and_the_input_is_left_alone(): void {
		$photos = $this->photos( 'abcdefgh' );
		$this->assertSame( Declarative_Shuffle::order( $photos, 99 ), Declarative_Shuffle::order( $photos, 99 ) );
		$this->assertSame( 'abcdefgh', $this->ids( $photos ) );
	}

	public function test_every_photo_is_kept_once(): void {
		$order = $this->ids( Declarative_Shuffle::order( $this->photos( 'abcdefghijklmnopqrstuvwxyz' ), 123456 ) );
		$this->assertSame( 26, strlen( $order ) );
		$letters = str_split( $order );
		sort( $letters );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz', implode( '', $letters ) );
	}

	public function test_nothing_to_shuffle_stays_as_it_is(): void {
		$this->assertSame( array(), Declarative_Shuffle::order( array(), 5 ) );
		$this->assertSame( 'a', $this->ids( Declarative_Shuffle::order( $this->photos( 'a' ), 5 ) ) );
	}
}
