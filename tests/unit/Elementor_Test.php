<?php
/**
 * Elementor module and widget tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Builder_Gallery;
use Profotograaf\Elementor_Gallery_Widget;
use Profotograaf\Gallery_Index;
use Profotograaf\Modules\Elementor;
use Profotograaf\Plugin;

class Elementor_Test extends Wp_Test_Case {

	/**
	 * Loads the stand-in for Elementor's base classes.
	 */
	private function with_elementor(): void {
		require_once PROFOTOGRAAF_DIR . 'tests/stubs/elementor.php';
	}

	/**
	 * A widget with the options table filled.
	 */
	private function widget(): Elementor_Gallery_Widget {
		$this->with_elementor();
		$this->options[ Gallery_Index::OPTION ] = array(
			'g_123' => array(
				'title' => 'Wedding',
				'url'   => 'https://example.profotograaf.nl/wedding',
			),
		);
		Elementor_Gallery_Widget::use_index( new Gallery_Index( Plugin::instance()->api() ) );
		return new Elementor_Gallery_Widget();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_elementor_nothing_of_it_is_loaded(): void {
		Actions\expectAdded( 'elementor/loaded' )->once();
		Actions\expectAdded( 'elementor/widgets/register' )->never();

		( new Elementor() )->register( Plugin::instance() );

		$this->assertFalse( class_exists( Elementor_Gallery_Widget::class, false ) );
		$this->assertFalse( class_exists( 'Elementor\Widget_Base', false ) );
		$this->assertFalse( class_exists( Builder_Gallery::class, false ) );
	}

	public function test_the_widget_hook_is_added_when_elementor_has_loaded(): void {
		Actions\expectAdded( 'elementor/loaded' )->never();
		Actions\expectAdded( 'elementor/widgets/register' )->once();
		Functions\when( 'did_action' )->alias( fn( $hook ) => 'elementor/loaded' === $hook ? 1 : 0 );

		( new Elementor() )->register( Plugin::instance() );
	}

	public function test_the_widget_hook_waits_for_elementor_to_load(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		$module = new Elementor();
		Actions\expectAdded( 'elementor/loaded' )->once()->with( array( $module, 'hook_widgets' ) );
		Actions\expectAdded( 'elementor/widgets/register' )->once()->with( array( $module, 'register_widget' ) );

		$module->register( Plugin::instance() );
		$module->hook_widgets();
	}

	public function test_it_registers_the_gallery_widget(): void {
		$this->with_elementor();
		Functions\when( 'did_action' )->justReturn( 0 );
		$module = new Elementor();
		$module->register( Plugin::instance() );
		$manager = new class() {
			/** @var object[] */
			public array $registered = array();

			public function register( object $widget ): void {
				$this->registered[] = $widget;
			}
		};

		$module->register_widget( $manager );

		$this->assertCount( 1, $manager->registered );
		$this->assertInstanceOf( Elementor_Gallery_Widget::class, $manager->registered[0] );
	}

	public function test_it_ignores_a_widgets_manager_it_does_not_know(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		$module = new Elementor();
		$module->register( Plugin::instance() );

		$module->register_widget( new \stdClass() );

		$this->addToAssertionCount( 1 );
	}

	public function test_the_widget_is_named_and_found_by_gallery_searches(): void {
		$widget = $this->widget();

		$this->assertSame( 'profotograaf_gallery', $widget->get_name() );
		$this->assertSame( 'Profotograaf Gallery', $widget->get_title() );
		foreach ( array( 'gallery', 'photo', 'photography', 'portfolio', 'client gallery' ) as $keyword ) {
			$this->assertContains( $keyword, $widget->get_keywords() );
		}
	}

	public function test_the_controls_match_the_block(): void {
		$widget = $this->widget();
		$method = new \ReflectionMethod( $widget, 'register_controls' );
		$method->invoke( $widget );
		$json = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/gallery/block.json' ), true );

		$this->assertSame( array( 'gallery_id', 'layout', 'columns', 'columns_tablet', 'columns_mobile', 'lightbox' ), array_keys( $widget->controls ) );
		$this->assertSame( array( '', 'g_123' ), array_keys( $widget->controls['gallery_id']['options'] ) );
		$this->assertSame( 'Wedding', $widget->controls['gallery_id']['options']['g_123'] );
		$this->assertSame( $json['attributes']['layout']['enum'], array_keys( $widget->controls['layout']['options'] ) );
		// PHP turns the numeric keys into integers.
		$this->assertSame( array( '', 1, 2, 3, 4, 5, 6, 7, 8 ), array_keys( $widget->controls['columns']['options'] ) );
		$this->assertSame( array( '', 1, 2, 3, 4 ), array_keys( $widget->controls['columns_mobile']['options'] ) );
		$this->assertSame( array( '', 'on', 'off' ), array_keys( $widget->controls['lightbox']['options'] ) );
	}

	public function test_the_picker_says_when_there_are_no_galleries(): void {
		$this->with_elementor();
		Elementor_Gallery_Widget::use_index( new Gallery_Index( Plugin::instance()->api() ) );
		$widget = new Elementor_Gallery_Widget();
		$method = new \ReflectionMethod( $widget, 'register_controls' );
		$method->invoke( $widget );

		$this->assertSame( array( '' ), array_keys( $widget->controls['gallery_id']['options'] ) );
		$this->assertStringContainsString( 'Connect your Profotograaf account', $widget->controls['gallery_id']['description'] );
	}

	public function test_render_prints_what_the_block_renders(): void {
		$widget           = $this->widget();
		$widget->settings = array(
			'gallery_id' => 'g_123',
			'layout'     => 'slideshow',
			'columns'    => '3',
			'lightbox'   => 'on',
		);
		$seen             = array();
		Functions\when( 'render_block' )->alias(
			function ( array $block ) use ( &$seen ): string {
				$seen = $block;
				return '<div class="profotograaf-gallery">block output</div>';
			}
		);
		$method = new \ReflectionMethod( $widget, 'render' );

		ob_start();
		$method->invoke( $widget );
		$html = (string) ob_get_clean();

		$this->assertSame( '<div class="profotograaf-gallery">block output</div>', $html );
		$this->assertSame( 'profotograaf/gallery', $seen['blockName'] );
		$this->assertSame( 'slideshow', $seen['attrs']['layout'] );
		$this->assertSame( '3', $seen['attrs']['columns'] );
		$this->assertSame( 'on', $seen['attrs']['lightbox'] );
		$this->assertSame( 'Wedding', $seen['attrs']['galleryTitle'] );
	}
}
