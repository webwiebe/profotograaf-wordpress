<?php
/**
 * Module contract.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * A feature of the plugin. A module hooks itself into WordPress in register()
 * and does nothing before that, so constructing one has no side effects.
 */
interface Module {

	/**
	 * Adds the module's hooks.
	 *
	 * @param Plugin $plugin Service container: connection, API client, settings.
	 */
	public function register( Plugin $plugin ): void;
}
