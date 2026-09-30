<?php
/**
 * Module discovery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of modules to register.
 *
 * Two sources:
 *
 * 1. Every `includes/modules/class-*.php` file, core modules included. The
 *    class inside must be `Profotograaf\Modules\<Name>` and implement
 *    Profotograaf\Module. Dropping a file there is all a feature needs.
 * 2. The `profotograaf_modules` filter, for classes that live elsewhere and to
 *    remove or replace one.
 */
final class Module_Loader {

	/**
	 * Absolute path of includes/modules.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Constructor.
	 *
	 * @param string $directory Absolute path of the modules directory.
	 */
	public function __construct( string $directory ) {
		$this->directory = rtrim( $directory, '/\\' );
	}

	/**
	 * Class names of the modules to register, without duplicates.
	 *
	 * @return array<int,class-string<Module>>
	 */
	public function classes(): array {
		$classes = $this->discover();

		/**
		 * Filters the modules the plugin registers.
		 *
		 * @param string[] $classes Fully qualified class names, each implementing Profotograaf\Module.
		 */
		$classes = apply_filters( 'profotograaf_modules', $classes );

		$valid = array();
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, Module::class ) ) {
				$valid[ $class ] = $class;
			}
		}
		return array_values( $valid );
	}

	/**
	 * Instantiated modules.
	 *
	 * @return array<int,Module>
	 */
	public function modules(): array {
		$modules = array();
		foreach ( $this->classes() as $class ) {
			$modules[] = new $class();
		}
		return $modules;
	}

	/**
	 * Modules found in the modules directory.
	 *
	 * @return array<int,string>
	 */
	private function discover(): array {
		$files = glob( $this->directory . '/class-*.php' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		sort( $files );

		$classes = array();
		foreach ( $files as $file ) {
			$slug      = substr( basename( $file, '.php' ), strlen( 'class-' ) );
			$name      = implode( '_', array_map( 'ucfirst', explode( '-', $slug ) ) );
			$classes[] = __NAMESPACE__ . '\\Modules\\' . $name;
		}
		return $classes;
	}
}
