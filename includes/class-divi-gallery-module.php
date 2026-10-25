<?php
/**
 * The Divi 4 module.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * "Profotograaf Gallery" in the Divi 4 builder.
 *
 * This file extends a Divi class, so nothing may reference it before Divi has
 * loaded `ET_Builder_Module`. The Divi module is the only caller and it does so
 * from `et_builder_ready`.
 *
 * Divi draws the visual builder preview from render(), so the preview shows
 * the server render.
 */
class Divi_Gallery_Module extends \ET_Builder_Module {

	public const SLUG = 'profotograaf_gallery';

	/**
	 * Gallery list, set by the Divi module.
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
	 * Names the module.
	 */
	public function init(): void {
		$this->name                   = esc_html__( 'Profotograaf Gallery', 'profotograaf' );
		$this->slug                   = self::SLUG;
		$this->vb_support             = 'on';
		$this->settings_modal_toggles = array(
			'general' => array(
				'toggles' => array(
					'main_content' => esc_html__( 'Gallery', 'profotograaf' ),
				),
			),
		);
		$this->advanced_fields        = array(
			'fonts' => false,
			'text'  => false,
		);
	}

	/**
	 * Fields, the same as the Elementor widget and the block.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_fields() {
		$choices = null === self::$index ? array() : Builder_Gallery::choices( self::$index );

		$fields = array(
			'gallery_id' => $this->select(
				esc_html__( 'Gallery', 'profotograaf' ),
				array( '' => esc_html__( 'Choose a gallery', 'profotograaf' ) ) + $choices,
				array() === $choices ? esc_html__( 'No galleries yet. Connect your Profotograaf account under Settings, Profotograaf.', 'profotograaf' ) : ''
			),
			'layout'     => $this->select( esc_html__( 'Layout', 'profotograaf' ), Builder_Gallery::layout_choices() ),
		);
		foreach ( array_keys( Builder_Gallery::MAX_COLUMNS ) as $field ) {
			$fields[ $field ] = $this->select( Builder_Gallery::column_label( $field ), Builder_Gallery::column_choices( $field ) );
		}
		$fields['lightbox'] = $this->select( esc_html__( 'Lightbox', 'profotograaf' ), Builder_Gallery::lightbox_choices() );
		return $fields;
	}

	/**
	 * Output on the page and in the visual builder.
	 *
	 * @param array<string,mixed> $attrs       Attributes.
	 * @param string|null         $content     Content.
	 * @param string              $render_slug Slug.
	 * @return string HTML.
	 */
	public function render( $attrs, $content = null, $render_slug = '' ) {
		return Builder_Gallery::render( $this->props, self::$index );
	}

	/**
	 * A select field of the General tab.
	 *
	 * @param string                   $label       Label.
	 * @param array<int|string,string> $options     Choices.
	 * @param string                   $description Help text.
	 * @return array<string,mixed>
	 */
	private function select( string $label, array $options, string $description = '' ): array {
		return array(
			'label'           => $label,
			'type'            => 'select',
			'option_category' => 'basic_option',
			'options'         => $options,
			'default'         => '',
			'toggle_slug'     => 'main_content',
			'description'     => $description,
		);
	}
}
