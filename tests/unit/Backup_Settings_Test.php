<?php
/**
 * Backup opt-in settings, the text on the Backup tab and the privacy copy.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Backup_Intro;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Privacy;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Settings_Schema;

class Backup_Settings_Test extends Wp_Test_Case {

	private const WARNING = 'Copies your Media Library images to your Profotograaf account.';

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( (string) $value ) );
		$this->settings = new Settings();
	}

	public function test_the_backup_is_off_by_default_with_scope_used_on_its_own_tab(): void {
		$backup = Settings_Schema::entry( 'backup' );
		$scope  = Settings_Schema::entry( 'backup_scope' );

		$this->assertFalse( $backup['default'] );
		$this->assertSame( 'bool', $backup['type'] );
		$this->assertSame( Settings_Schema::TAB_BACKUP, $backup['tab'] );
		$this->assertSame( 'used', $scope['default'] );
		$this->assertSame( Settings_Schema::TAB_BACKUP, $scope['tab'] );
		$this->assertSame( Settings::BACKUP_SCOPES, array_keys( $scope['choices'] ) );
		$this->assertSame( array( 'backup', 'backup_scope' ), array_keys( Settings_Schema::in_tab( Settings_Schema::TAB_BACKUP ) ) );

		$this->assertFalse( $this->settings->backup_enabled() );
		$this->assertSame( 'used', $this->settings->backup_scope() );
		$this->assertFalse( $this->settings->sanitize( array() )['backup'] );
	}

	public function test_the_switch_carries_the_warning(): void {
		$this->assertStringStartsWith( self::WARNING, Settings_Schema::entry( 'backup' )['description'] );
		$this->assertStringContainsString( 'Nothing is sent before you switch it on.', Settings_Schema::entry( 'backup' )['description'] );
		$this->assertSame( self::WARNING, Backup_Intro::paragraphs()[0] );
	}

	public function test_the_other_opt_ins_do_not_switch_the_backup_on(): void {
		$this->options[ Settings::OPTION ] = $this->settings->sanitize(
			array(
				'telemetry_enabled'   => '1',
				'media_source'        => '1',
				'media_upload'        => '1',
				'virtual_attachments' => '1',
			)
		);

		$this->assertTrue( $this->settings->get( 'telemetry_enabled' ) );
		$this->assertTrue( $this->settings->media_source_enabled() );
		$this->assertTrue( $this->settings->media_upload_enabled() );
		$this->assertFalse( $this->settings->backup_enabled() );
		$this->assertFalse( $this->options[ Settings::OPTION ]['backup'] );
	}

	public function test_saving_the_switch_turns_the_backup_on_and_unchecking_it_turns_it_off(): void {
		$this->options[ Settings::OPTION ] = $this->settings->sanitize(
			array(
				'_tab'         => 'backup',
				'backup'       => '1',
				'backup_scope' => 'all',
			)
		);
		$this->assertTrue( $this->settings->backup_enabled() );
		$this->assertSame( 'all', $this->settings->backup_scope() );

		$this->options[ Settings::OPTION ] = $this->settings->sanitize( array( 'backup_scope' => 'folder' ) );
		$this->assertSame( 'all', $this->settings->backup_scope(), 'An unknown scope keeps the saved one.' );
		$this->assertTrue( $this->settings->backup_enabled(), 'Saving another key keeps the backup on.' );

		$this->options[ Settings::OPTION ] = $this->settings->sanitize( array( '_tab' => 'general' ) );
		$this->assertTrue( $this->settings->backup_enabled(), 'Saving another tab keeps the backup on.' );

		$this->options[ Settings::OPTION ] = $this->settings->sanitize( array( '_tab' => 'backup' ) );
		$this->assertFalse( $this->settings->backup_enabled(), 'Unchecking the box on the Backup tab turns it off.' );
		$this->assertSame( 'all', $this->settings->backup_scope() );
	}

	public function test_unusable_stored_values_read_as_off_and_used(): void {
		$this->options[ Settings::OPTION ] = array(
			'backup'       => 'junk',
			'backup_scope' => 'everything',
		);
		$this->assertFalse( $this->settings->backup_enabled() );
		$this->assertSame( 'used', $this->settings->backup_scope() );

		$this->options[ Settings::OPTION ] = array( 'backup_scope' => array( 'all' ) );
		$this->assertSame( 'used', $this->settings->backup_scope() );

		$this->options[ Settings::OPTION ] = 'nonsense';
		$this->assertFalse( $this->settings->backup_enabled() );
		$this->assertSame( 'used', $this->settings->backup_scope() );

		$this->options[ Settings::OPTION ] = array(
			'backup'       => true,
			'backup_scope' => 'marked',
		);
		$this->assertTrue( $this->settings->backup_enabled() );
		$this->assertSame( 'marked', $this->settings->backup_scope() );
	}

	public function test_settings_saved_by_schema_version_7_get_the_backup_off(): void {
		$this->options[ Settings::OPTION ]         = array(
			'media_source'      => true,
			'telemetry_enabled' => true,
		);
		$this->options[ Settings::VERSION_OPTION ] = 7;

		$this->assertSame( 8, Settings_Schema::VERSION );
		$this->assertTrue( $this->settings->migrate() );

		$this->assertFalse( $this->options[ Settings::OPTION ]['backup'] );
		$this->assertSame( 'used', $this->options[ Settings::OPTION ]['backup_scope'] );
		$this->assertTrue( $this->options[ Settings::OPTION ]['media_source'] );
		$this->assertFalse( $this->settings->backup_enabled() );
	}

	public function test_the_rest_schema_offers_the_scopes_and_defaults_off(): void {
		$properties = Settings_Schema::rest_schema()['schema']['properties'];

		$this->assertSame( 'boolean', $properties['backup']['type'] );
		$this->assertFalse( $properties['backup']['default'] );
		$this->assertSame( array( 'used', 'all', 'marked' ), $properties['backup_scope']['enum'] );
	}

	public function test_the_intro_says_what_is_sent_and_what_stays(): void {
		$text = implode( ' ', Backup_Intro::paragraphs() );

		foreach ( array( 'private galleries', 'title of this site', 'file name', 'GPS position', 'RAW files and videos', 'storage', 'copies stay in your Profotograaf account', 'personal data', 'other users uploaded', 'Sharing anonymous usage data' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
	}

	public function test_the_backup_tab_shows_the_intro_and_the_two_fields(): void {
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->alias( fn() => print( '<button>Save</button>' ) );
		Functions\when( 'selected' )->alias( fn( $a, $b ) => (string) $a === (string) $b ? print( 'selected' ) : null );
		Functions\when( 'checked' )->alias( fn( $a, $b ) => $a === $b ? print( 'checked' ) : null );
		Functions\when( 'plugin_basename' )->justReturn( 'profotograaf/profotograaf.php' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		$connection = new Connection();
		$page       = new Settings_Page();
		$page->register( new Plugin( $connection, new Api_Client( $connection, new Fake_Transport(), $this->clock() ), $this->clock() ) );

		$_GET['tab'] = 'backup';
		ob_start();
		$page->render();
		$html        = (string) ob_get_clean();
		$_GET['tab'] = 'advanced';
		ob_start();
		$page->render();
		$other = (string) ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( '<p><strong>' . self::WARNING . '</strong></p>', $html );
		$this->assertStringContainsString( 'name="profotograaf_settings[backup]"', $html );
		$this->assertStringContainsString( 'name="profotograaf_settings[backup_scope]"', $html );
		$this->assertMatchesRegularExpression( '/value="used" selected/', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'The switch is unchecked by default.' );
		$this->assertStringNotContainsString( self::WARNING, $other );
	}

	public function test_the_privacy_text_names_the_data_the_endpoint_and_what_stays(): void {
		$captured = '';
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			function ( $name, $content ) use ( &$captured ) {
				$captured = $content;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();

		( new Privacy() )->add_policy_content();

		$this->assertStringContainsString( 'Copy to Profotograaf', $captured );
		foreach ( array( 'image files', 'file names', 'site title in the gallery name', 'access token', 'https://profotograaf.nl/api/v1/embed/galleries/&lt;gallery id&gt;/photos', 'https://profotograaf.nl/api/v1/embed/galleries,', 'leaves the copies in the Profotograaf account', 'Only when the site owner switches on copying' ) as $needle ) {
			$this->assertStringContainsString( $needle, $captured );
		}
	}

	public function test_readme_has_an_external_services_entry_for_the_backup(): void {
		$readme   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		$services = explode( '== External services ==', $readme )[1];
		$entry    = explode( '= Checking for changes', explode( '= Backup to Profotograaf (only if you opt in) =', $services )[1] )[0];

		foreach ( array( 'Nothing is sent before that.', 'POST https://profotograaf.nl/api/v1/embed/galleries`', 'POST https://profotograaf.nl/api/v1/embed/galleries/<gallery id>/photos', 'the file, its file name and the access token', 'site title in the gallery name', 'leaves the copies in your Profotograaf account', 'anonymous usage data' ) as $needle ) {
			$this->assertStringContainsString( $needle, $entry );
		}
		$this->assertStringContainsString( 'The copies the backup made stay in your Profotograaf account', $readme );
	}
}
