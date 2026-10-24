<?php
/**
 * The Elementor widget.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * "Profotograaf Gallery" in the Elementor panel.
 *
 * This file extends an Elementor class, so nothing may reference it before
 * Elementor has loaded. The Elementor module is the only caller and it does so
 * from `elementor/widgets/register`.
 *
 * Elementor draws the editor canvas from render(), so the canvas shows what
 * visitors see.
 */
class Elementor_Gallery_Widget extends Widget_Base {

	public const NAME = 'profotograaf_gallery';

	/**
	 * Gallery list, set by the Elementor module.
	 *
	 * @var Gallery_Index|null
	 */
	private static ?Gallery_Index $index = null;

	/**
	 * Sets the gallery list the picker and the renderer read.
	 *
	 * @param Gallery_Index $index Gallery list.
	 */
	public static function use_index( Gallery_Index $index ): void {
		self::$index = $index;
	}

	/**
	 * Widget name.
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return esc_html__( 'Profotograaf Gallery', 'profotograaf' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-gallery-grid';
	}

	/**
	 * Panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( 'basic' );
	}

	/**
	 * Words the panel search matches, besides the title.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array(
			esc_html__( 'gallery', 'profotograaf' ),
			esc_html__( 'photo', 'profotograaf' ),
			esc_html__( 'photography', 'profotograaf' ),
			esc_html__( 'portfolio', 'profotograaf' ),
			esc_html__( 'client gallery', 'profotograaf' ),
			'profotograaf',
		);
	}

	/**
	 * Controls.
	 */
	protected function register_controls(): void {
		$choices = null === self::$index ? array() : Builder_Gallery::choices( self::$index );

		$this->start_controls_section(
			'section_gallery',
			array( 'label' => esc_html__( 'Gallery', 'profotograaf' ) )
		);

		$this->add_control(
			'gallery_id',
			array(
				'label'       => esc_html__( 'Gallery', 'profotograaf' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array( '' => esc_html__( 'Choose a gallery', 'profotograaf' ) ) + $choices,
				'default'     => '',
				'description' => array() === $choices ? esc_html__( 'No galleries yet. Connect your Profotograaf account under Settings, Profotograaf.', 'profotograaf' ) : '',
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => esc_html__( 'Layout', 'profotograaf' ),
				'type'    => Controls_Manager::SELECT,
				'options' => Builder_Gallery::layout_choices(),
				'default' => '',
			)
		);

		foreach ( array_keys( Builder_Gallery::MAX_COLUMNS ) as $field ) {
			$this->add_control(
				$field,
				array(
					'label'   => Builder_Gallery::column_label( $field ),
					'type'    => Controls_Manager::SELECT,
					'options' => Builder_Gallery::column_choices( $field ),
					'default' => '',
				)
			);
		}

		$this->add_control(
			'lightbox',
			array(
				'label'   => esc_html__( 'Lightbox', 'profotograaf' ),
				'type'    => Controls_Manager::SELECT,
				'options' => Builder_Gallery::lightbox_choices(),
				'default' => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Output on the page and in the editor canvas.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		// The renderer escapes everything it prints.
		echo Builder_Gallery::render( is_array( $settings ) ? $settings : array(), self::$index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
