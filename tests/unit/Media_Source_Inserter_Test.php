<?php
/**
 * Inserter category module tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Modules\Media_Source_Inserter;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Rest;
use Profotograaf\Settings;

/**
 * Module that reads the built script from a temporary directory.
 */
class Temporary_Build_Inserter extends Media_Source_Inserter {

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

class Media_Source_Inserter_Test extends Gallery_Test_Case {

	private Temporary_Build_Inserter $module;

	private string $build;

	/**
	 * Calls to wp_set_script_translations.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $translations = array();

	private string $capability = 'upload_files';

	protected function setUp(): void {
		parent::setUp();
		$this->build = sys_get_temp_dir() . '/pfbuild' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $this->build );
		file_put_contents( $this->build . 'index.asset.php', "<?php return array( 'dependencies' => array( 'wp-data', 'wp-i18n' ), 'version' => 'abc123' );" );

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $cap === $this->capability );
		Functions\when( 'wp_set_script_translations' )->alias(
			function ( ...$args ) {
				$this->translations[] = $args;
				return true;
			}
		);

		$this->module            = new Temporary_Build_Inserter();
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

	public function test_it_registers_the_route_filter_and_editor_hook(): void {
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', array( $this->module, 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init' ) );
		$this->assertNotFalse( has_filter( 'profotograaf_photo_rest_item' ) );
		$this->assertNotFalse( has_action( 'rest_after_insert_attachment' ) );
	}

	public function test_the_script_loads_with_the_setting_on_and_upload_files(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );

		$this->module->enqueue();

		$script = $this->enqueued[ Media_Source_Inserter::HANDLE ];
		$this->assertSame( 'https://example.test/wp-content/plugins/profotograaf/build/inserter-category/index.js', $script['src'] );
		$this->assertSame( array( 'wp-data', 'wp-i18n' ), $script['deps'] );
		$this->assertSame( 'abc123', $script['ver'] );
		$this->assertSame( array( array( Media_Source_Inserter::HANDLE, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' ) ), $this->translations );
	}

	public function test_the_script_does_not_load_with_the_setting_off(): void {
		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_script_does_not_load_without_upload_files(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		$this->capability                  = 'edit_posts';

		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_script_does_not_load_before_it_is_built(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		unlink( $this->build . 'index.asset.php' );

		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_a_malformed_asset_file_falls_back_to_the_plugin_version(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		file_put_contents( $this->build . 'index.asset.php', '<?php return 1;' );

		$this->module->enqueue();

		$this->assertSame( array(), $this->enqueued[ Media_Source_Inserter::HANDLE ]['deps'] );
		$this->assertSame( PROFOTOGRAAF_VERSION, $this->enqueued[ Media_Source_Inserter::HANDLE ]['ver'] );
	}

	public function test_enqueue_without_register_does_nothing(): void {
		( new Media_Source_Inserter() )->enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_photos_route_applies_the_item_filter(): void {
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		Functions\when( 'get_posts' )->justReturn( array() );
		$seen = array();
		Filters\expectApplied( 'profotograaf_photo_rest_item' )->once()->andReturnUsing(
			function ( $item, $photo ) use ( &$seen ) {
				$seen        = array( $item, $photo );
				$item['url'] = 'https://site.test/signed';
				return $item;
			}
		);
		$this->http->reply(
			200,
			array(
				'photos' => array(
					array(
						'id'     => 'p-1',
						'images' => array(
							array(
								'variant' => 'web',
								'url'     => 'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg',
							),
						),
					),
				),
				'total'  => 1,
			)
		);
		$settings = new Settings();
		$rest     = new Photo_Rest( new Photo_Catalogue( $this->api, $settings, $this->clock() ), new Photo_Importer( $settings ), $settings );

		$result = $rest->list_photos( new Photo_Rest_Request() );

		$this->assertSame( 'https://site.test/signed', $result['items'][0]['url'] );
		$this->assertSame( 'p-1', $seen[1]['id'] );
	}
}
