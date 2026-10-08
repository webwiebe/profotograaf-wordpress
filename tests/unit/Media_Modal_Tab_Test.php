<?php
/**
 * Media modal tab module tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Media_Modal_Tab;
use Profotograaf\Settings;

/**
 * Module that reads the built files from a temporary directory.
 */
class Temporary_Build_Modal_Tab extends Media_Modal_Tab {

	/**
	 * Directory with the built files.
	 *
	 * @var string
	 */
	public string $directory = '';

	protected function build_directory(): string {
		return $this->directory;
	}
}

class Media_Modal_Tab_Test extends Gallery_Test_Case {

	private Temporary_Build_Modal_Tab $module;

	private string $build;

	private string $capability = 'upload_files';

	/**
	 * Calls to wp_set_script_translations.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $translations = array();

	/**
	 * Calls to wp_enqueue_style.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $styles = array();

	protected function setUp(): void {
		parent::setUp();
		$this->build = sys_get_temp_dir() . '/pfmodal' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $this->build );
		file_put_contents( $this->build . 'index.asset.php', "<?php return array( 'dependencies' => array( 'wp-api-fetch', 'wp-i18n' ), 'version' => 'def456' );" );
		file_put_contents( $this->build . 'style-index.css', '.profotograaf-modal{}' );

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $cap === $this->capability );
		Functions\when( 'wp_set_script_translations' )->alias(
			function ( ...$args ) {
				$this->translations[] = $args;
				return true;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src, $deps, $ver ) {
				$this->styles[ $handle ] = compact( 'src', 'deps', 'ver' );
			}
		);

		$this->module            = new Temporary_Build_Modal_Tab();
		$this->module->directory = $this->build;
		$this->module->register( $this->plugin );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->build . '*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->build );
		parent::tearDown();
	}

	public function test_it_hooks_into_the_media_views(): void {
		$this->assertNotFalse( has_action( 'wp_enqueue_media', array( $this->module, 'enqueue' ) ) );
	}

	public function test_the_script_loads_with_the_setting_on_and_upload_files(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );

		$this->module->enqueue();

		$script = $this->enqueued[ Media_Modal_Tab::HANDLE ];
		$this->assertSame( 'https://example.test/wp-content/plugins/profotograaf/build/media-modal/index.js', $script['src'] );
		$this->assertSame( array( 'wp-api-fetch', 'wp-i18n', 'media-views' ), $script['deps'] );
		$this->assertSame( 'def456', $script['ver'] );
		$this->assertSame( array( array( Media_Modal_Tab::HANDLE, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' ) ), $this->translations );
		$this->assertSame( 'https://example.test/wp-content/plugins/profotograaf/build/media-modal/style-index.css', $this->styles[ Media_Modal_Tab::HANDLE ]['src'] );
	}

	public function test_it_skips_the_style_when_the_build_has_none(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		unlink( $this->build . 'style-index.css' );

		$this->module->enqueue();

		$this->assertArrayHasKey( Media_Modal_Tab::HANDLE, $this->enqueued );
		$this->assertSame( array(), $this->styles );
	}

	public function test_nothing_loads_with_the_setting_off(): void {
		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->styles );
	}

	public function test_nothing_loads_without_upload_files(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		$this->capability                  = 'edit_posts';

		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_nothing_loads_before_the_build_exists(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		unlink( $this->build . 'index.asset.php' );

		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_a_malformed_asset_file_falls_back_to_the_plugin_version(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		file_put_contents( $this->build . 'index.asset.php', '<?php return 1;' );

		$this->module->enqueue();

		$this->assertSame( array( 'media-views' ), $this->enqueued[ Media_Modal_Tab::HANDLE ]['deps'] );
		$this->assertSame( PROFOTOGRAAF_VERSION, $this->enqueued[ Media_Modal_Tab::HANDLE ]['ver'] );
	}

	public function test_enqueue_without_register_does_nothing(): void {
		( new Media_Modal_Tab() )->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}
}
