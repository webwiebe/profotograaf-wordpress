<?php
/**
 * Settings screen field and tab rendering tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Settings_Fields;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Settings_Schema;

class Settings_Fields_Test extends Wp_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => (string) $value );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->alias( fn() => print( '<button>Save</button>' ) );
		Functions\when( 'selected' )->alias( fn( $a, $b ) => (string) $a === (string) $b ? print( 'selected' ) : null );
		Functions\when( 'checked' )->alias( fn( $a, $b ) => $a === $b ? print( 'checked' ) : null );
	}

	private function render( string $tab ): string {
		ob_start();
		( new Settings_Fields( new Settings() ) )->render_form( $tab );
		return (string) ob_get_clean();
	}

	public function test_every_tab_renders_a_form_for_its_entries_and_names_the_tab(): void {
		foreach ( array_keys( Settings_Schema::tabs() ) as $tab ) {
			$html = $this->render( $tab );

			$this->assertStringContainsString( 'name="profotograaf_settings[_tab]" value="' . $tab . '"', $html );
			foreach ( array_keys( Settings_Schema::in_tab( $tab ) ) as $key ) {
				$this->assertStringContainsString( 'name="profotograaf_settings[' . $key . ']"', $html, $tab );
			}
		}
	}

	public function test_the_controls_follow_the_entry_type(): void {
		$this->options['profotograaf_settings'] = array(
			'default_layout' => 'masonry',
			'leads_enabled'  => false,
		);

		$this->assertStringContainsString( '<select id="profotograaf-default-layout"', $this->render( 'galleries' ) );
		$this->assertMatchesRegularExpression( '/value="masonry" selected/', $this->render( 'galleries' ) );
		$this->assertStringContainsString( 'type="checkbox"', $this->render( 'enquiry-forms' ) );
		$this->assertStringNotContainsString( 'checked', $this->render( 'enquiry-forms' ) );
		$this->assertStringContainsString( 'type="text"', $this->render( 'general' ) );
	}

	public function test_the_galleries_tab_has_a_control_for_every_display_option(): void {
		$this->options['profotograaf_settings'] = array(
			'gallery_columns'  => 4,
			'gallery_captions' => 'below',
		);
		$html                                   = $this->render( 'galleries' );

		$this->assertMatchesRegularExpression( '/<input type="number"[^>]*id="profotograaf-gallery-columns"[^>]*value="4"[^>]*min="1" max="8"/', $html );
		$this->assertMatchesRegularExpression( '/<input type="number"[^>]*id="profotograaf-gallery-gap"[^>]*value=""/', $html );
		$this->assertMatchesRegularExpression( '/value="below" selected/', $html );
		foreach ( array( 'columns_tablet', 'columns_mobile', 'ratio', 'sort', 'per_page', 'load_more', 'lightbox' ) as $key ) {
			$this->assertStringContainsString( 'name="profotograaf_settings[gallery_' . $key . ']"', $html, $key );
		}
	}

	public function test_saving_the_galleries_tab_with_empty_numbers_keeps_them_empty(): void {
		$clean = ( new Settings() )->sanitize(
			array(
				'_tab'            => 'galleries',
				'gallery_columns' => '',
				'gallery_gap'     => '12',
			)
		);

		$this->assertSame( '', $clean['gallery_columns'] );
		$this->assertSame( 12, $clean['gallery_gap'] );
	}

	public function test_an_entry_with_a_link_shows_it_after_the_description(): void {
		$html = $this->render( 'advanced' );

		$this->assertStringContainsString( 'name="profotograaf_settings[telemetry_enabled]"', $html );
		$this->assertStringContainsString( 'href="' . Settings_Schema::TELEMETRY_DOC_URL . '"', $html );
		$this->assertStringNotContainsString( 'href=', $this->render( 'galleries' ) );
	}

	public function test_the_settings_page_shows_the_tab_named_in_the_query(): void {
		$connection = new Connection();
		$plugin     = new Plugin( $connection, new Api_Client( $connection, new Fake_Transport(), $this->clock() ), $this->clock() );
		$page       = new Settings_Page();
		Functions\when( 'plugin_basename' )->justReturn( 'profotograaf/profotograaf.php' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		$page->register( $plugin );

		$_GET['tab'] = 'galleries';
		ob_start();
		$page->render();
		$html        = (string) ob_get_clean();
		$_GET['tab'] = 'nonsense';
		ob_start();
		$page->render();
		$fallback = (string) ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'nav-tab-active" href="https://example.com/wp-admin/options-general.php?page=profotograaf&tab=galleries"', $html );
		$this->assertStringContainsString( 'profotograaf-default-layout', $html );
		$this->assertStringNotContainsString( 'Not connected', $html );
		$this->assertStringContainsString( 'Not connected', $fallback );
		$this->assertStringContainsString( 'Gallery link text', $fallback );
	}
}
