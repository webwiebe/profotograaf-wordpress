<?php
/**
 * Module loader tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Profotograaf\Module;
use Profotograaf\Module_Loader;
use Profotograaf\Modules\Blocks;
use Profotograaf\Modules\Origin_Sync;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Modules\Token_Refresh;

class Module_Loader_Test extends Wp_Test_Case {

	private function loader(): Module_Loader {
		return new Module_Loader( dirname( __DIR__, 2 ) . '/includes/modules' );
	}

	public function test_every_file_in_the_modules_directory_is_loaded(): void {
		$classes = $this->loader()->classes();

		foreach ( array( Settings_Page::class, Token_Refresh::class, Origin_Sync::class, Blocks::class ) as $core ) {
			$this->assertContains( $core, $classes );
		}
		foreach ( $classes as $class ) {
			$this->assertTrue( is_subclass_of( $class, Module::class ), $class . ' must implement Module' );
		}
	}

	public function test_the_filter_can_add_and_remove_modules(): void {
		Filters\expectApplied( 'profotograaf_modules' )->once()->andReturnUsing(
			function ( array $classes ) {
				$classes   = array_diff( $classes, array( Blocks::class ) );
				$classes[] = Stub_Module::class;
				$classes[] = 'Not\\A\\Class';
				$classes[] = \stdClass::class;
				$classes[] = Stub_Module::class;
				return $classes;
			}
		);

		$classes = $this->loader()->classes();

		$this->assertNotContains( Blocks::class, $classes );
		$this->assertSame( 1, count( array_keys( $classes, Stub_Module::class, true ) ) );
		$this->assertNotContains( 'Not\\A\\Class', $classes );
		$this->assertNotContains( \stdClass::class, $classes );
	}

	public function test_modules_are_instantiated(): void {
		foreach ( $this->loader()->modules() as $module ) {
			$this->assertInstanceOf( Module::class, $module );
		}
	}
}

class Stub_Module implements Module {

	public function register( \Profotograaf\Plugin $plugin ): void {
	}
}
