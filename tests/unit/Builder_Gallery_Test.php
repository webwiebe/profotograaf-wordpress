<?php
/**
 * Page builder gallery tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Builder_Gallery;
use Profotograaf\Gallery_Index;
use Profotograaf\Plugin;

class Builder_Gallery_Test extends Wp_Test_Case {

	/**
	 * @return array<string,mixed>
	 */
	private function block_json(): array {
		$json = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/gallery/block.json' ), true );
		$this->assertIsArray( $json );
		return $json;
	}

	public function test_the_layouts_are_the_ones_the_block_offers(): void {
		$enum = array_values( array_filter( $this->block_json()['attributes']['layout']['enum'] ) );

		$this->assertSame( $enum, Builder_Gallery::LAYOUTS );
	}

	public function test_attributes_use_the_block_attribute_names(): void {
		$attributes = Builder_Gallery::attributes(
			array(
				'gallery_id'     => 'g_123',
				'layout'         => 'masonry',
				'columns'        => '4',
				'columns_tablet' => '3',
				'columns_mobile' => '2',
				'lightbox'       => 'off',
			)
		);

		$this->assertSame(
			array(
				'galleryId'     => 'g_123',
				'layout'        => 'masonry',
				'columns'       => '4',
				'columnsTablet' => '3',
				'columnsMobile' => '2',
				'lightbox'      => 'off',
			),
			$attributes
		);
		foreach ( array_keys( $attributes ) as $name ) {
			$this->assertArrayHasKey( $name, $this->block_json()['attributes'], $name );
		}
	}

	public function test_values_outside_the_choices_fall_back_to_the_site_default(): void {
		$attributes = Builder_Gallery::attributes(
			array(
				'gallery_id'     => '../etc',
				'layout'         => 'carousel',
				'columns'        => '9',
				'columns_tablet' => '0',
				'columns_mobile' => '5',
				'lightbox'       => 'maybe',
			)
		);

		$this->assertSame( array_fill_keys( array( 'galleryId', 'layout', 'columns', 'columnsTablet', 'columnsMobile', 'lightbox' ), '' ), $attributes );
	}

	public function test_missing_and_odd_values_do_not_fail(): void {
		$empty = array_fill_keys( array( 'galleryId', 'layout', 'columns', 'columnsTablet', 'columnsMobile', 'lightbox' ), '' );

		$this->assertSame( $empty, Builder_Gallery::attributes( array() ) );
		$this->assertSame( $empty, Builder_Gallery::attributes( array( 'gallery_id' => array( 'x' ), 'columns' => array( 4 ), 'layout' => null ) ) );
	}

	public function test_columns_stop_at_the_limits_of_the_settings_page(): void {
		$this->assertSame( '8', Builder_Gallery::attributes( array( 'columns' => 8 ) )['columns'] );
		$this->assertSame( '4', Builder_Gallery::attributes( array( 'columns_mobile' => 4 ) )['columnsMobile'] );
		$this->assertSame( '', Builder_Gallery::attributes( array( 'columns_mobile' => 5 ) )['columnsMobile'] );
	}

	public function test_render_goes_through_the_gallery_block(): void {
		Functions\expect( 'render_block' )
			->once()
			->with(
				\Mockery::on(
					function ( array $block ): bool {
						return 'profotograaf/gallery' === $block['blockName']
							&& 'g_123' === $block['attrs']['galleryId']
							&& 'grid' === $block['attrs']['layout'];
					}
				)
			)
			->andReturn( '<div class="profotograaf-gallery"></div>' );

		$this->assertSame( '<div class="profotograaf-gallery"></div>', Builder_Gallery::render( array( 'gallery_id' => 'g_123', 'layout' => 'grid' ) ) );
	}

	public function test_render_hands_the_stored_title_and_link_to_the_block(): void {
		$this->options['profotograaf_gallery_index'] = array(
			'g_123' => array(
				'title' => 'Wedding',
				'url'   => 'https://example.profotograaf.nl/wedding',
			),
		);
		$seen                                        = array();
		Functions\when( 'render_block' )->alias(
			function ( array $block ) use ( &$seen ): string {
				$seen = $block['attrs'];
				return '';
			}
		);

		Builder_Gallery::render( array( 'gallery_id' => 'g_123' ), new Gallery_Index( Plugin::instance()->api() ) );

		$this->assertSame( 'Wedding', $seen['galleryTitle'] );
		$this->assertSame( 'https://example.profotograaf.nl/wedding', $seen['galleryUrl'] );
	}

	public function test_choices_list_the_galleries_by_title(): void {
		$this->options[ Gallery_Index::OPTION ] = array(
			'g_b' => array(
				'title' => 'Beach',
				'url'   => 'https://example.profotograaf.nl/b',
			),
			'g_a' => array(
				'title' => '',
				'url'   => 'https://example.profotograaf.nl/a',
			),
			'g_c' => array(
				'title' => 'No link',
				'url'   => '',
			),
		);

		$this->assertSame(
			array(
				'g_b' => 'Beach',
				'g_a' => 'g_a',
			),
			Builder_Gallery::choices( new Gallery_Index( Plugin::instance()->api() ) )
		);
	}
}
