<?php
/**
 * Layout map tests: what the site embed draws for each platform layout.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Layout_Map;
use Profotograaf\Settings;

class Layout_Map_Test extends TestCase {

	public function test_every_platform_layout_maps_to_a_layout_embed_js_draws(): void {
		$this->assertCount( 13, Layout_Map::map() );
		foreach ( Layout_Map::map() as $platform => $drawn ) {
			$this->assertContains( $drawn, Settings::LAYOUTS, $platform );
			$this->assertTrue( Layout_Map::knows( $platform ), $platform );
		}
		$this->assertContains( Layout_Map::fallback(), Settings::LAYOUTS );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function mapping(): array {
		return array(
			'masonry'    => array( 'masonry', 'masonry' ),
			'justified'  => array( 'justified', 'masonry' ),
			'mosaic'     => array( 'mosaic', 'masonry' ),
			'lighttable' => array( 'lighttable', 'masonry' ),
			'parallax'   => array( 'parallax', 'slideshow' ),
			'direct'     => array( 'direct', 'slideshow' ),
			'cinema'     => array( 'cinema', 'slideshow' ),
			'filmstrip'  => array( 'filmstrip', 'slideshow' ),
			'slideout'   => array( 'slideout', 'slideshow' ),
			'flickr'     => array( 'flickr', 'grid' ),
			'instagram'  => array( 'instagram', 'grid' ),
			'duo'        => array( 'duo', 'grid' ),
			'grid'       => array( 'grid', 'grid' ),
		);
	}

	/**
	 * @dataProvider mapping
	 */
	public function test_each_layout_is_drawn_as_its_nearest_embed_layout( string $platform, string $drawn ): void {
		$this->assertSame( $drawn, Layout_Map::drawn( $platform ) );
	}

	public function test_names_are_matched_without_case_or_spaces(): void {
		$this->assertSame( 'slideshow', Layout_Map::drawn( ' Parallax ' ) );
	}

	public function test_an_unknown_or_empty_layout_is_drawn_as_a_grid(): void {
		$this->assertSame( 'grid', Layout_Map::drawn( 'carousel' ) );
		$this->assertSame( 'grid', Layout_Map::drawn( '' ) );
		$this->assertFalse( Layout_Map::knows( 'carousel' ) );
	}

	public function test_a_layout_the_platform_reports_as_drawn_wins(): void {
		$this->assertSame( 'masonry', Layout_Map::drawn( 'parallax', 'masonry' ) );
		$this->assertSame( 'slideshow', Layout_Map::drawn( 'carousel', 'slideshow' ) );
		$this->assertSame( 'slideshow', Layout_Map::drawn( 'parallax', 'bogus' ) );
	}

	public function test_a_layout_name_is_cleaned(): void {
		$this->assertSame( 'light-table_2', Layout_Map::clean( ' Light-Table_2 ' ) );
		$this->assertSame( 'ab', Layout_Map::clean( '<a>b' ) );
		$this->assertSame( '', Layout_Map::clean( array( 'x' ) ) );
		$this->assertSame( 32, strlen( Layout_Map::clean( str_repeat( 'a', 80 ) ) ) );
	}
}
