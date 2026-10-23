<?php
/**
 * Divi module tests. Divi is a paid theme, so these unit tests are the only
 * automated check of the module.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Divi_Gallery_Module;
use Profotograaf\Gallery_Index;
use Profotograaf\Modules\Divi;
use Profotograaf\Plugin;

class Divi_Test extends Wp_Test_Case {

	/**
	 * Loads the stand-in for Divi's module class.
	 */
	private function with_divi(): void {
		require_once PROFOTOGRAAF_DIR . 'tests/stubs/divi.php';
		\ET_Builder_Module::$created = array();
	}

	private function module(): Divi_Gallery_Module {
		$this->with_divi();
		$this->options[ Gallery_Index::OPTION ] = array(
			'g_123' => array(
				'title' => 'Wedding',
				'url'   => 'https://example.profotograaf.nl/wedding',
			),
		);
		Divi_Gallery_Module::use_index( new Gallery_Index( Plugin::instance()->api() ) );
		return new Divi_Gallery_Module();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_divi_nothing_of_it_is_loaded(): void {
		Actions\expectAdded( 'et_builder_ready' )->once();

		( new Divi() )->register( Plugin::instance() );

		$this->assertFalse( class_exists( Divi_Gallery_Module::class, false ) );
		$this->assertFalse( class_exists( 'ET_Builder_Module', false ) );
	}

	public function test_the_module_is_created_when_divi_is_ready(): void {
		$this->with_divi();
		Functions\when( 'did_action' )->justReturn( 0 );
		$divi = new Divi();
		Actions\expectAdded( 'et_builder_ready' )->once()->with( array( $divi, 'add_module' ) );

		$divi->register( Plugin::instance() );
		$this->assertCount( 0, \ET_Builder_Module::$created );
		$divi->add_module();

		$this->assertCount( 1, \ET_Builder_Module::$created );
		$this->assertInstanceOf( Divi_Gallery_Module::class, \ET_Builder_Module::$created[0] );
	}

	public function test_the_module_is_created_at_once_when_divi_was_ready_first(): void {
		$this->with_divi();
		Actions\expectAdded( 'et_builder_ready' )->never();
		Functions\when( 'did_action' )->alias( fn( $hook ) => 'et_builder_ready' === $hook ? 1 : 0 );

		( new Divi() )->register( Plugin::instance() );

		$this->assertCount( 1, \ET_Builder_Module::$created );
	}

	public function test_it_is_quiet_when_divi_ready_fires_without_the_module_class(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		$divi = new Divi();
		$divi->register( Plugin::instance() );

		if ( ! class_exists( 'ET_Builder_Module', false ) ) {
			$divi->add_module();
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_the_module_is_named_and_found_by_gallery_searches(): void {
		$module = $this->module();

		$this->assertSame( 'Profotograaf Gallery', $module->name );
		$this->assertSame( 'profotograaf_gallery', $module->slug );
		$this->assertSame( 'on', $module->vb_support );
	}

	public function test_the_fields_match_the_block_and_the_elementor_widget(): void {
		$fields = $this->module()->get_fields();
		$json   = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/gallery/block.json' ), true );

		$this->assertSame( array( 'gallery_id', 'layout', 'columns', 'columns_tablet', 'columns_mobile', 'lightbox' ), array_keys( $fields ) );
		$this->assertSame( array( '', 'g_123' ), array_keys( $fields['gallery_id']['options'] ) );
		$this->assertSame( 'Wedding', $fields['gallery_id']['options']['g_123'] );
		$this->assertSame( $json['attributes']['layout']['enum'], array_keys( $fields['layout']['options'] ) );
		$this->assertSame( array( '', 1, 2, 3, 4, 5, 6, 7, 8 ), array_keys( $fields['columns']['options'] ) );
		$this->assertSame( array( '', 1, 2, 3, 4 ), array_keys( $fields['columns_mobile']['options'] ) );
		$this->assertSame( array( '', 'on', 'off' ), array_keys( $fields['lightbox']['options'] ) );
		foreach ( $fields as $name => $field ) {
			$this->assertSame( 'select', $field['type'], $name );
			$this->assertSame( 'main_content', $field['toggle_slug'], $name );
			$this->assertSame( '', $field['default'], $name );
		}
	}

	public function test_the_picker_says_when_there_are_no_galleries(): void {
		$this->with_divi();
		Divi_Gallery_Module::use_index( new Gallery_Index( Plugin::instance()->api() ) );

		$field = ( new Divi_Gallery_Module() )->get_fields()['gallery_id'];

		$this->assertSame( array( '' ), array_keys( $field['options'] ) );
		$this->assertStringContainsString( 'Connect your Profotograaf account', $field['description'] );
	}

	public function test_render_prints_what_the_block_renders(): void {
		$module        = $this->module();
		$module->props = array(
			'gallery_id' => 'g_123',
			'layout'     => 'slideshow',
			'columns'    => '3',
			'lightbox'   => 'on',
		);
		$seen          = array();
		Functions\when( 'render_block' )->alias(
			function ( array $block ) use ( &$seen ): string {
				$seen = $block;
				return '<div class="profotograaf-gallery">block output</div>';
			}
		);

		$html = $module->render( array(), null, 'profotograaf_gallery' );

		$this->assertSame( '<div class="profotograaf-gallery">block output</div>', $html );
		$this->assertSame( 'profotograaf/gallery', $seen['blockName'] );
		$this->assertSame( 'slideshow', $seen['attrs']['layout'] );
		$this->assertSame( '3', $seen['attrs']['columns'] );
		$this->assertSame( 'on', $seen['attrs']['lightbox'] );
		$this->assertSame( 'Wedding', $seen['attrs']['galleryTitle'] );
	}
}
