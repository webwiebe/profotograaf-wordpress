<?php
/**
 * The parts of Elementor the widget touches, for PHPStan and the unit tests.
 *
 * Elementor is not a dependency of this plugin. The signatures follow the
 * Elementor 3 API.
 *
 * @package Profotograaf
 */

namespace Elementor;

abstract class Widget_Base {

	/**
	 * Controls added by register_controls(), by id. Test aid, not Elementor API.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $controls = array();

	/**
	 * Settings render() reads. Test aid, not Elementor API.
	 *
	 * @var array<string,mixed>
	 */
	public array $settings = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>      $data Element data.
	 * @param array<string,mixed>|null $args Element arguments.
	 */
	public function __construct( $data = array(), $args = null ) {
	}

	abstract public function get_name();

	abstract public function get_title();

	public function get_icon() {
		return '';
	}

	/**
	 * @return string[]
	 */
	public function get_categories() {
		return array();
	}

	/**
	 * @return string[]
	 */
	public function get_keywords() {
		return array();
	}

	protected function register_controls() {
	}

	protected function render() {
	}

	/**
	 * @param string|null $setting_key Setting key.
	 * @return mixed
	 */
	public function get_settings_for_display( $setting_key = null ) {
		return $this->settings;
	}

	/**
	 * @param string              $section_id Section id.
	 * @param array<string,mixed> $args       Arguments.
	 */
	protected function start_controls_section( $section_id, array $args = array() ) {
	}

	protected function end_controls_section() {
	}

	/**
	 * @param string              $id      Control id.
	 * @param array<string,mixed> $args    Arguments.
	 * @param array<string,mixed> $options Options.
	 */
	public function add_control( $id, array $args, $options = array() ) {
		$this->controls[ $id ] = $args;
		return true;
	}
}

class Controls_Manager {

	public const SELECT = 'select';
}
