<?php
/**
 * Client galleries list on the settings page.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Client_Links_Panel;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Gallery_Index;
use Profotograaf\Plugin;

/**
 * Client_Links_Panel with the build directory pointed at a test folder.
 */
class Client_Links_Panel_With_Build extends Client_Links_Panel {

	/**
	 * Directory that holds index.asset.php.
	 *
	 * @var string
	 */
	public string $dir = '';

	protected function build_directory(): string {
		return $this->dir;
	}
}

class Client_Links_Panel_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Client_Links_Panel_With_Build $panel;

	protected function setUp(): void {
		parent::setUp();
		$this->http  = new Fake_Transport();
		$connection  = new Connection();
		$plugin      = new Plugin( $connection, new Api_Client( $connection, $this->http, $this->clock() ), $this->clock() );
		$this->panel = new Client_Links_Panel_With_Build( $plugin );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	private function store(): void {
		$this->options[ Gallery_Index::OPTION ] = array(
			'g-2'    => array(
				'title' => 'Winter shoot',
				'url'   => 'https://studio.profotograaf.nl/share/g/winter',
			),
			'g-1'    => array(
				'title' => 'Spring wedding',
				'url'   => 'https://profotograaf.nl/share/g/spring-wedding',
			),
			'g-3'    => array(
				'title' => 'No link',
				'url'   => '',
			),
			'g-4'    => array(
				'title' => 'Script',
				'url'   => 'javascript:alert(1)',
			),
			'g-xss'  => array(
				'title' => '<b>Bold</b>',
				'url'   => 'https://profotograaf.nl/share/g/bold',
			),
			'broken' => 'not an entry',
		);
	}

	private function render( string $tab = 'galleries' ): string {
		ob_start();
		$this->panel->render( $tab );
		return (string) ob_get_clean();
	}

	public function test_the_index_lists_stored_galleries_with_a_web_link_by_title(): void {
		$this->store();

		$rows = ( new Gallery_Index( new Api_Client( new Connection(), $this->http, $this->clock() ) ) )->all();

		$this->assertSame( array( '<b>Bold</b>', 'Spring wedding', 'Winter shoot' ), array_column( $rows, 'title' ) );
		$this->assertSame( 'https://profotograaf.nl/share/g/spring-wedding', $rows[1]['url'] );
		$this->assertSame( 'g-1', $rows[1]['id'] );
	}

	public function test_the_index_list_is_empty_without_the_option(): void {
		$rows = ( new Gallery_Index( new Api_Client( new Connection(), $this->http, $this->clock() ) ) )->all();

		$this->assertSame( array(), $rows );
	}

	public function test_an_untitled_gallery_sorts_by_its_id(): void {
		$this->options[ Gallery_Index::OPTION ] = array(
			'b' => array( 'url' => 'https://profotograaf.nl/share/g/b' ),
			'a' => array(
				'title' => '',
				'url'   => 'https://profotograaf.nl/share/g/a',
			),
		);

		$rows = ( new Gallery_Index( new Api_Client( new Connection(), $this->http, $this->clock() ) ) )->all();

		$this->assertSame( array( 'a', 'b' ), array_column( $rows, 'id' ) );
	}

	public function test_the_list_shows_share_links_copy_buttons_and_qr_cells(): void {
		$this->store();

		$html = $this->render();

		$this->assertStringContainsString( 'Client galleries', $html );
		$this->assertStringContainsString( 'value="https://profotograaf.nl/share/g/spring-wedding" readonly', $html );
		$this->assertStringContainsString( 'data-url="https://studio.profotograaf.nl/share/g/winter"', $html );
		$this->assertSame( 3, substr_count( $html, 'class="button profotograaf-client-link__copy" hidden' ) );
		$this->assertSame( 3, substr_count( $html, 'class="profotograaf-client-link__qr"' ) );
		$this->assertStringContainsString( 'data-label="QR code with the share link of Spring wedding"', $html );
		$this->assertStringContainsString( 'Share link of Winter shoot', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'No link', $html );
	}

	public function test_titles_are_escaped(): void {
		$this->store();

		$html = $this->render();

		$this->assertStringNotContainsString( '<b>Bold</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', $html );
	}

	public function test_the_install_note_states_the_rule_and_claims_no_state(): void {
		$this->store();

		$html = $this->render();

		$this->assertStringContainsString( 'when the gallery is delivered and has no password', $html );
		$this->assertStringContainsString( 'This list does not show which galleries meet both conditions.', $html );
		$this->assertStringNotContainsString( 'installable', strtolower( $html ) );
	}

	public function test_the_list_reads_the_stored_list_only(): void {
		$this->store();
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->render();

		$this->assertSame( array(), $this->http->requests );
	}

	public function test_an_empty_index_explains_how_the_list_fills(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'No galleries are stored on this site yet.', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertStringContainsString( 'when the gallery is delivered and has no password', $html );
	}

	public function test_other_tabs_show_no_list(): void {
		$this->store();

		$this->assertSame( '', $this->render( 'general' ) );
	}

	public function test_a_user_without_the_capability_sees_no_list(): void {
		$this->store();
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', $this->render() );
	}

	public function test_the_script_loads_on_the_galleries_tab_with_translations(): void {
		$dir = sys_get_temp_dir() . '/profotograaf-client-links-' . getmypid() . '/';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}
		file_put_contents( $dir . 'index.asset.php', "<?php return array( 'dependencies' => array( 'wp-i18n' ), 'version' => 'abc' );" );
		$this->panel->dir = $dir;
		$seen             = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src, $deps, $ver ) use ( &$seen ) {
				$seen = array( $handle, $src, $deps, $ver );
			}
		);
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( Client_Links_Panel::HANDLE, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );

		$this->panel->enqueue( 'galleries' );

		unlink( $dir . 'index.asset.php' );
		rmdir( $dir );
		$this->assertSame(
			array( Client_Links_Panel::HANDLE, PROFOTOGRAAF_URL . 'build/client-links/index.js', array( 'wp-i18n' ), 'abc' ),
			$seen
		);
	}

	public function test_no_script_on_other_tabs_or_without_a_build(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->panel->dir = sys_get_temp_dir() . '/profotograaf-missing-build/';

		$this->panel->enqueue( 'general' );
		$this->panel->enqueue( 'galleries' );
	}
}
