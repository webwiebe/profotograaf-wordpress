<?php
/**
 * Settings schema, sanitising, resolution order and migration tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Settings;
use Profotograaf\Settings_Schema;

class Settings_Test extends Wp_Test_Case {

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		$this->settings = new Settings();
	}

	public function test_every_entry_has_a_known_tab_and_a_default_that_passes_its_own_sanitiser(): void {
		foreach ( Settings_Schema::entries() as $key => $entry ) {
			$this->assertArrayHasKey( $entry['tab'], Settings_Schema::tabs(), $key );
			$this->assertSame( $entry['default'], Settings_Schema::parse( $key, $entry['default'] ), $key );
		}
	}

	public function test_the_tabs_are_general_galleries_enquiry_forms_and_advanced(): void {
		$this->assertSame( array( 'general', 'galleries', 'enquiry-forms', 'advanced' ), array_keys( Settings_Schema::tabs() ) );
		foreach ( array_keys( Settings_Schema::tabs() ) as $tab ) {
			$this->assertNotSame( array(), Settings_Schema::in_tab( $tab ), $tab );
		}
	}

	public function test_nothing_saved_gives_the_built_in_defaults(): void {
		$this->assertSame( 'grid', $this->settings->default_layout() );
		$this->assertSame( '', $this->settings->get( 'fallback_link_label' ) );
		$this->assertTrue( $this->settings->get( 'leads_enabled' ) );
		$this->assertFalse( $this->settings->get( 'keep_data_on_uninstall' ) );
		$this->assertNull( $this->settings->get( 'no_such_option' ) );
	}

	public function test_a_saved_junk_value_falls_back_to_the_default(): void {
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'carousel' );
		$this->assertSame( 'grid', $this->settings->default_layout() );

		$this->options['profotograaf_settings'] = array( 'default_layout' => 'masonry' );
		$this->assertSame( 'masonry', $this->settings->default_layout() );

		$this->options['profotograaf_settings'] = 'nonsense';
		$this->assertSame( 'grid', $this->settings->default_layout() );
	}

	public function test_sanitising_cleans_each_type(): void {
		$clean = $this->settings->sanitize(
			array(
				'default_layout'         => 'slideshow',
				'fallback_link_label'    => '  <b>Open</b> the gallery ',
				'leads_enabled'          => 'false',
				'keep_data_on_uninstall' => '1',
				'unknown'                => 'dropped',
			)
		);

		$this->assertSame(
			array(
				'fallback_link_label'    => 'Open the gallery',
				'client_portal_path'     => '/client',
				'default_layout'         => 'slideshow',
				'leads_enabled'          => false,
				'leads_alert_email'      => '',
				'leads_fallback_email'   => '',
				'leads_failed_retention' => 7,
				'keep_data_on_uninstall' => true,
				'gallery_columns'        => '',
				'gallery_columns_tablet' => '',
				'gallery_columns_mobile' => '',
				'gallery_gap'            => '',
				'gallery_ratio'          => '',
				'gallery_captions'       => '',
				'gallery_sort'           => '',
				'gallery_per_page'       => '',
				'gallery_load_more'      => '',
				'gallery_lightbox'       => '',
			),
			$clean
		);
	}

	public function test_sanitising_refuses_invalid_values(): void {
		$this->assertSame( 'grid', $this->settings->sanitize( array( 'default_layout' => '<script>' ) )['default_layout'] );
		$this->assertSame( 'grid', $this->settings->sanitize( 'nonsense' )['default_layout'] );
		$this->assertSame( '', $this->settings->sanitize( array( 'fallback_link_label' => array( 'x' ) ) )['fallback_link_label'] );
		$this->assertTrue( $this->settings->sanitize( array( 'leads_enabled' => 'maybe' ) )['leads_enabled'] );
	}

	public function test_an_invalid_value_keeps_what_was_saved(): void {
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'masonry' );

		$this->assertSame( 'masonry', $this->settings->sanitize( array( 'default_layout' => 'carousel' ) )['default_layout'] );
	}

	public function test_a_partial_update_keeps_the_other_saved_values(): void {
		$this->options['profotograaf_settings'] = array(
			'default_layout'         => 'masonry',
			'leads_enabled'          => false,
			'keep_data_on_uninstall' => true,
		);

		$clean = $this->settings->sanitize( array( 'default_layout' => 'slideshow' ) );

		$this->assertSame( 'slideshow', $clean['default_layout'] );
		$this->assertFalse( $clean['leads_enabled'] );
		$this->assertTrue( $clean['keep_data_on_uninstall'] );
	}

	public function test_a_tab_form_reads_its_missing_checkboxes_as_off_and_leaves_other_tabs_alone(): void {
		$this->options['profotograaf_settings'] = array(
			'default_layout'         => 'masonry',
			'leads_enabled'          => true,
			'keep_data_on_uninstall' => true,
		);

		$clean = $this->settings->sanitize( array( '_tab' => 'enquiry-forms' ) );

		$this->assertFalse( $clean['leads_enabled'] );
		$this->assertTrue( $clean['keep_data_on_uninstall'] );
		$this->assertSame( 'masonry', $clean['default_layout'] );
	}

	public function test_the_block_attribute_wins_over_the_shortcode_attribute(): void {
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'masonry' );

		$this->assertSame(
			'slideshow',
			$this->settings->resolve( 'default_layout', array( 'default_layout' => 'slideshow' ), array( 'default_layout' => 'grid' ) )
		);
	}

	public function test_the_shortcode_attribute_wins_over_the_site_default(): void {
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'masonry' );

		$this->assertSame(
			'slideshow',
			$this->settings->resolve( 'default_layout', array( 'default_layout' => '' ), array( 'default_layout' => 'slideshow' ) )
		);
	}

	public function test_invalid_or_missing_layers_fall_through_to_the_site_default(): void {
		$this->options['profotograaf_settings'] = array( 'default_layout' => 'masonry' );

		$this->assertSame(
			'masonry',
			$this->settings->resolve( 'default_layout', array( 'default_layout' => 'carousel' ), array(), array( 'default_layout' => null ) )
		);
	}

	public function test_the_built_in_default_comes_last(): void {
		$this->assertSame( 'grid', $this->settings->resolve( 'default_layout', array( 'default_layout' => 'carousel' ) ) );
		$this->assertSame( '', $this->settings->resolve( 'fallback_link_label' ) );
		$this->assertNull( $this->settings->resolve( 'no_such_option', array( 'no_such_option' => 'x' ) ) );
	}

	public function test_a_layer_text_wins_over_an_empty_site_text(): void {
		$this->options['profotograaf_settings'] = array( 'fallback_link_label' => '' );

		$this->assertSame( 'Open', $this->settings->resolve( 'fallback_link_label', array( 'fallback_link_label' => 'Open' ) ) );
		$this->assertSame( '', $this->settings->resolve( 'fallback_link_label' ) );
	}

	public function test_a_boolean_layer_of_false_is_a_usable_value(): void {
		$this->assertFalse( $this->settings->resolve( 'leads_enabled', array( 'leads_enabled' => false ) ) );
	}

	public function test_migration_normalises_values_saved_by_an_older_version_once(): void {
		$this->options['profotograaf_settings'] = array(
			'default_layout' => 'masonry',
			'retired_key'    => 'x',
		);

		$this->assertTrue( $this->settings->migrate() );

		$this->assertSame(
			array(
				'fallback_link_label'    => '',
				'client_portal_path'     => '/client',
				'default_layout'         => 'masonry',
				'leads_enabled'          => true,
				'leads_alert_email'      => '',
				'leads_fallback_email'   => '',
				'leads_failed_retention' => 7,
				'keep_data_on_uninstall' => false,
				'gallery_columns'        => '',
				'gallery_columns_tablet' => '',
				'gallery_columns_mobile' => '',
				'gallery_gap'            => '',
				'gallery_ratio'          => '',
				'gallery_captions'       => '',
				'gallery_sort'           => '',
				'gallery_per_page'       => '',
				'gallery_load_more'      => '',
				'gallery_lightbox'       => '',
			),
			$this->options['profotograaf_settings']
		);
		$this->assertSame( Settings_Schema::VERSION, $this->options['profotograaf_settings_version'] );
		$this->assertFalse( $this->autoload['profotograaf_settings_version'] );

		$this->options['profotograaf_settings']['default_layout'] = 'slideshow';
		$this->assertFalse( $this->settings->migrate(), 'The second run does nothing.' );
		$this->assertSame( 'slideshow', $this->options['profotograaf_settings']['default_layout'] );
	}

	public function test_migration_without_saved_settings_only_records_the_version(): void {
		$this->assertTrue( $this->settings->migrate() );

		$this->assertArrayNotHasKey( 'profotograaf_settings', $this->options );
		$this->assertSame( Settings_Schema::VERSION, $this->options['profotograaf_settings_version'] );
	}

	public function test_registration_hands_the_schema_to_wordpress(): void {
		$registered = array();
		Functions\when( 'register_setting' )->alias(
			function ( $group, $name, $args ) use ( &$registered ) {
				$registered = array( $group, $name, $args );
			}
		);

		$this->settings->register();

		$this->assertSame( 'profotograaf', $registered[0] );
		$this->assertSame( 'profotograaf_settings', $registered[1] );
		$this->assertSame( array( $this->settings, 'sanitize' ), $registered[2]['sanitize_callback'] );
		$this->assertSame( Settings_Schema::defaults(), $registered[2]['default'] );

		$schema = $registered[2]['show_in_rest']['schema'];
		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array_keys( Settings_Schema::entries() ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'grid', 'masonry', 'slideshow' ), $schema['properties']['default_layout']['enum'] );
		$this->assertSame( 'boolean', $schema['properties']['leads_enabled']['type'] );
	}

	public function test_optional_numbers_stay_empty_or_are_clamped(): void {
		$this->assertSame( '', Settings_Schema::parse( 'gallery_columns', '' ) );
		$this->assertSame( 8, Settings_Schema::parse( 'gallery_columns', '12' ) );
		$this->assertSame( 0, Settings_Schema::parse( 'gallery_gap', '0' ) );
		$this->assertNull( Settings_Schema::parse( 'gallery_gap', 'wide' ) );
		$this->assertSame( '', ( new Settings() )->get( 'gallery_per_page' ) );
	}

	public function test_the_rest_schema_accepts_an_empty_optional_number(): void {
		$property = Settings_Schema::rest_schema()['schema']['properties']['gallery_columns'];

		$this->assertSame( array( 'integer', 'string' ), $property['type'] );
		$this->assertSame( 8, $property['maximum'] );
	}
}
