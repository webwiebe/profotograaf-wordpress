<?php
/**
 * Form plugin bridge contract.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Connects one form plugin to the lead queue. A bridge only listens to the
 * plugin's public submission hook and describes its forms for the settings
 * screen. It never talks to the platform.
 */
interface Bridge {

	/**
	 * Short id, such as `cf7`.
	 */
	public function id(): string;

	/**
	 * Name shown to the photographer.
	 */
	public function label(): string;

	/**
	 * Adds the submission hook.
	 */
	public function register(): void;

	/**
	 * Whether the form plugin is installed and active.
	 */
	public function is_active(): bool;

	/**
	 * The forms of the plugin, for the settings screen.
	 *
	 * @return array<int,array{key:string,title:string,fields:array<int,array{id:string,label:string,type:string}>}>
	 */
	public function forms(): array;
}
