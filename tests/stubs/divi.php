<?php
/**
 * The parts of the Divi 4 module API the Divi module touches, for PHPStan and
 * the unit tests.
 *
 * Divi is a paid theme and not a dependency of this plugin. The members follow
 * ET_Builder_Module of Divi 4.
 *
 * @package Profotograaf
 */

class ET_Builder_Module {

	/**
	 * Module name in the builder.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Module slug, the shortcode tag.
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * Whether the visual builder supports the module.
	 *
	 * @var string
	 */
	public $vb_support = 'off';

	/**
	 * Modal tabs and toggles.
	 *
	 * @var array<string,mixed>
	 */
	public $settings_modal_toggles = array();

	/**
	 * Advanced (design tab) fields.
	 *
	 * @var array<string,mixed>
	 */
	public $advanced_fields = array();

	/**
	 * Values of the module's fields. Test aid: Divi fills it before render().
	 *
	 * @var array<string,mixed>
	 */
	public $props = array();

	/**
	 * Modules created, as Divi registers them from its constructor. Test aid.
	 *
	 * @var ET_Builder_Module[]
	 */
	public static $created = array();

	public function __construct() {
		self::$created[] = $this;
		$this->init();
	}

	public function init() {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_fields() {
		return array();
	}

	/**
	 * @param array<string,mixed> $attrs       Attributes.
	 * @param string|null         $content     Content.
	 * @param string              $render_slug Slug.
	 * @return string|null
	 */
	public function render( $attrs, $content = null, $render_slug = '' ) {
		return '';
	}
}
