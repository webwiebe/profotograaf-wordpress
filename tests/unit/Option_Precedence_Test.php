<?php
/**
 * The precedence of every gallery display option (#197): the block wins,
 * including an explicit off value and `auto`; then the plugin setting; then
 * the default in code.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Option_Resolver;
use Profotograaf\Settings;

class Option_Precedence_Test extends Gallery_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
	}

	/**
	 * Per option: setting, site value, a block value with the attribute it
	 * writes, and the block's explicit off value (null when the option has none)
	 * with the attribute that writes (null for none).
	 *
	 * @return array<string,array{string,string,mixed,string,string,?string,?string}>
	 */
	public static function options(): array {
		return array(
			'columns'        => array( 'columns', 'gallery_columns', 5, '2', 'data-columns="2"', null, null ),
			'columns_tablet' => array( 'columns_tablet', 'gallery_columns_tablet', 4, '2', 'data-columns-tablet="2"', null, null ),
			'columns_mobile' => array( 'columns_mobile', 'gallery_columns_mobile', 3, '1', 'data-columns-mobile="1"', null, null ),
			'gap'            => array( 'gap', 'gallery_gap', 20, '4', 'data-gap="4"', '0', 'data-gap="0"' ),
			'ratio'          => array( 'ratio', 'gallery_ratio', '16-9', '1-1', 'data-ratio="1:1"', 'original', 'data-ratio="original"' ),
			'captions'       => array( 'captions', 'gallery_captions', 'overlay', 'below', 'data-captions="below"', 'off', 'data-captions="off"' ),
			'sort'           => array( 'sort', 'gallery_sort', 'random', 'oldest', 'data-sort="oldest"', null, null ),
			'per_page'       => array( 'per_page', 'gallery_per_page', 24, '6', 'data-per-page="6"', null, null ),
			'load_more'      => array( 'load_more', 'gallery_load_more', 'on', 'off', 'data-load-more="off"', 'off', 'data-load-more="off"' ),
			'lightbox'       => array( 'lightbox', 'gallery_lightbox', 'on', 'off', 'data-lightbox="off"', 'off', 'data-lightbox="off"' ),
			'duotone'        => array( 'duotone', 'gallery_duotone', '#111111,#eeeeee', '#000,#fff', 'data-duotone="#000,#fff"', 'none', null ),
			'link_to'        => array( 'link_to', 'gallery_link_to', 'file', 'page', 'data-link-to="page"', 'none', 'data-link-to="none"' ),
			'link_new_tab'   => array( 'link_new_tab', 'gallery_link_new_tab', 'on', 'off', 'data-link-new-tab="off"', 'off', 'data-link-new-tab="off"' ),
		);
	}

	/**
	 * The data attribute of one option, without its value.
	 *
	 * @param string $key Render argument.
	 */
	private static function data_name( string $key ): string {
		return Gallery_Renderer::OPTIONS[ $key ]['data'] . '=';
	}

	/**
	 * @dataProvider options
	 */
	public function test_a_block_value_wins_over_the_site_setting( string $key, string $setting, $site, string $block, string $written ): void {
		$this->options['profotograaf_settings'] = array( $setting => $site );

		$html = $this->renderer->render( array( 'id' => 'g-1', $key => $block ) );

		$this->assertStringContainsString( ' ' . $written, $html );
	}

	/**
	 * @dataProvider options
	 */
	public function test_a_block_on_auto_gets_the_built_in_behaviour_whatever_the_site_says( string $key, string $setting, $site ): void {
		$this->options['profotograaf_settings'] = array( $setting => $site );

		$html = $this->renderer->render( array( 'id' => 'g-1', $key => 'auto' ) );

		$this->assertStringNotContainsString( ' ' . self::data_name( $key ), $html );
	}

	/**
	 * The options that have an explicit off value besides `auto`.
	 *
	 * @return array<string,array{string,string,mixed,string,string,?string,?string}>
	 */
	public static function off_values(): array {
		return array_filter( self::options(), fn( array $row ) => null !== $row[5] );
	}

	/**
	 * @dataProvider off_values
	 */
	public function test_an_explicit_off_value_on_the_block_wins( string $key, string $setting, $site, string $block, string $written, string $off, ?string $off_written ): void {
		$this->options['profotograaf_settings'] = array( $setting => $site );

		$html = $this->renderer->render( array( 'id' => 'g-1', $key => $off ) );

		if ( null === $off_written ) {
			$this->assertStringNotContainsString( ' ' . self::data_name( $key ), $html );
		} else {
			$this->assertStringContainsString( ' ' . $off_written, $html );
		}
	}

	/**
	 * @dataProvider options
	 */
	public function test_an_unset_block_takes_the_site_setting( string $key, string $setting, $site ): void {
		$this->options['profotograaf_settings'] = array( $setting => $site );

		$html = $this->renderer->render( array( 'id' => 'g-1', $key => '' ) );

		$value = 'ratio' === $key ? str_replace( '-', ':', (string) $site ) : (string) $site;
		$this->assertStringContainsString( ' ' . self::data_name( $key ) . '"' . $value . '"', $html );
	}

	/**
	 * @dataProvider options
	 */
	public function test_with_neither_set_nothing_is_written( string $key ): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', $key => '' ) );

		$this->assertStringNotContainsString( ' ' . self::data_name( $key ), $html );
	}

	public function test_auto_stops_the_shortcode_layer_too(): void {
		$html = $this->renderer->render( array( 'id' => 'g-1', 'sort' => 'auto' ), array( 'sort' => 'newest' ) );
		$this->assertStringNotContainsString( 'data-sort', $html );

		$html = $this->renderer->render( array( 'id' => 'g-1' ), array( 'sort' => 'auto' ) );
		$this->assertStringNotContainsString( 'data-sort', $html );
	}

	public function test_automatic_desktop_columns_do_not_step_the_site_columns_down(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_columns' => 6 );

		$html = $this->renderer->render( array( 'id' => 'g-1', 'columns' => 'auto' ) );

		$this->assertStringNotContainsString( 'data-columns', $html );
	}

	public function test_the_layout_follows_the_same_order(): void {
		$this->options['profotograaf_gallery_index'] = array(
			'g-1' => array(
				'title'        => 'Shown',
				'url'          => 'https://profotograaf.nl/share/g/x',
				'layout'       => 'parallax',
				'embed_layout' => 'slideshow',
			),
		);
		$this->options['profotograaf_settings']      = array( 'default_layout' => 'masonry' );

		$layout = fn( string $value ) => $this->renderer->render( array( 'id' => 'g-1', 'layout' => $value ) );

		$this->assertStringContainsString( 'data-layout="grid"', $layout( 'grid' ) );
		$this->assertStringContainsString( 'data-layout="slideshow"', $layout( 'auto' ) );
		$this->assertStringContainsString( 'data-layout="masonry"', $layout( '' ) );

		$this->options['profotograaf_settings'] = array();
		$this->assertStringContainsString( 'data-layout="slideshow"', $layout( '' ) );
	}

	public function test_a_block_duotone_in_any_colour_format_or_an_unknown_preset_hides_the_site_duotone(): void {
		$this->options['profotograaf_settings'] = array( 'gallery_duotone' => '#111111,#eeeeee' );
		Functions\when( 'wp_get_global_settings' )->justReturn( array() );

		foreach ( array(
			array( array( 'rgb(32, 74, 135)', '#ffd6e0' ), ' data-duotone="#204a87,#ffd6e0"' ),
			array( array( '#204a87cc', '#FFD6E0FF' ), ' data-duotone="#204a87,#ffd6e0"' ),
			array( 'var:preset|duotone|missing', null ),
			array( array( 'hsl(0 0% 0%)', '#fff' ), null ),
			array( 'unset', null ),
		) as list( $duotone, $written ) ) {
			$args = Gallery_Renderer::options_from_block( array( 'style' => array( 'color' => array( 'duotone' => $duotone ) ) ) );
			$html = $this->renderer->render( array( 'id' => 'g-1' ) + $args );
			$this->assertStringNotContainsString( '#111111', $html, (string) json_encode( $duotone ) );
			if ( null !== $written ) {
				$this->assertStringContainsString( $written, $html );
			}
		}
	}

	public function test_the_resolver_falls_back_to_the_code_default_given_for_an_option_without_a_setting(): void {
		$resolver = new Option_Resolver( new Settings() );

		$this->assertSame( 3, $resolver->resolve( null, array( '', null ), 3 ) );
		$this->assertSame( 3, $resolver->resolve( null, array( 'auto', 5 ), 3 ) );
		$this->assertSame( 5, $resolver->resolve( null, array( '', 5 ), 3, fn( $value ) => is_int( $value ) ? $value : null ) );
		$this->assertSame( 3, $resolver->resolve( null, array( 'x' ), 3, fn( $value ) => is_int( $value ) ? $value : null ) );
	}
}
