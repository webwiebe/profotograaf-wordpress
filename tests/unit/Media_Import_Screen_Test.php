<?php
/**
 * Import screen, media grid filter and attachment field tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Modules\Media_Import_Screen;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Plugin;
use Profotograaf\Settings;

class Media_Import_Screen_Test extends Wp_Test_Case {

	private Media_Import_Screen $screen;

	private string $capability = 'upload_files';

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $meta = array();

	/**
	 * Asset file written by a test, removed again afterwards.
	 */
	private string $created = '';

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $cap === $this->capability );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		$this->screen = new Media_Import_Screen();
		$this->screen->register( new Plugin() );
	}

	protected function tearDown(): void {
		unset( $_REQUEST['query'] );
		if ( '' !== $this->created ) {
			unlink( $this->created );
			rmdir( dirname( $this->created ) );
			@rmdir( dirname( $this->created, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only when empty.
		}
		parent::tearDown();
	}

	public function test_it_registers_its_hooks(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->screen, 'add_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->screen, 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_media', array( $this->screen, 'enqueue_filter' ) ) );
		$this->assertNotFalse( has_filter( 'ajax_query_attachments_args', array( $this->screen, 'filter_query' ) ) );
		$this->assertNotFalse( has_filter( 'attachment_fields_to_edit', array( $this->screen, 'source_field' ) ) );
	}

	public function test_the_menu_entry_sits_under_media_for_upload_files(): void {
		$added = array();
		Functions\when( 'add_media_page' )->alias(
			function ( ...$args ) use ( &$added ) {
				$added = $args;
				return 'media_page_profotograaf-import';
			}
		);

		$this->screen->add_menu();

		$this->assertSame( 'Import from Profotograaf', $added[0] );
		$this->assertSame( 'Import from Profotograaf', $added[1] );
		$this->assertSame( 'upload_files', $added[2] );
		$this->assertSame( 'profotograaf-import', $added[3] );
	}

	public function test_no_menu_entry_while_the_setting_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		Functions\expect( 'add_media_page' )->never();

		$this->screen->add_menu();
	}

	public function test_no_menu_entry_without_upload_files(): void {
		$this->capability = 'edit_posts';
		Functions\expect( 'add_media_page' )->never();

		$this->screen->add_menu();
	}

	public function test_the_page_prints_the_mount_point(): void {
		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Import from Profotograaf</h1>', $html );
		$this->assertStringContainsString( 'id="profotograaf-import-root"', $html );
		$this->assertStringContainsString( 'data-admin-url="https://photos.example.com/wp-admin/"', $html );
	}

	public function test_assets_load_on_the_screen_only_with_translations(): void {
		Functions\when( 'add_media_page' )->justReturn( 'media_page_profotograaf-import' );
		$this->screen->add_menu();
		$asset = PROFOTOGRAAF_DIR . 'build/import-screen/index.asset.php';
		if ( ! is_readable( $asset ) ) {
			if ( ! is_dir( dirname( $asset ) ) ) {
				mkdir( dirname( $asset ), 0777, true );
			}
			file_put_contents( $asset, "<?php return array( 'dependencies' => array( 'wp-element' ), 'version' => 'abc' );" );
			$this->created = $asset;
		}
		$scripts = array();
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( ...$args ) use ( &$scripts ) {
				$scripts[] = $args;
			}
		);
		Functions\expect( 'wp_set_script_translations' )->once()->with( 'profotograaf-import-screen', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );

		$this->screen->enqueue( 'upload.php' );
		$this->assertSame( array(), $scripts );

		$this->screen->enqueue( 'media_page_profotograaf-import' );
		$this->assertCount( 1, $scripts );
		$this->assertSame( 'profotograaf-import-screen', $scripts[0][0] );
		$this->assertSame( 'https://example.test/wp-content/plugins/profotograaf/build/import-screen/index.js', $scripts[0][1] );
		$this->assertTrue( $scripts[0][4] );
	}

	public function test_the_filter_script_loads_only_while_active(): void {
		$loaded = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( ...$args ) use ( &$loaded ) {
				$loaded[] = $args[0];
			}
		);
		Functions\when( 'wp_set_script_translations' )->justReturn( true );

		$this->screen->enqueue_filter();
		$this->assertSame( array( 'profotograaf-media-source' ), $loaded );

		$loaded                            = array();
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->screen->enqueue_filter();
		$this->assertSame( array(), $loaded );
	}

	public function test_the_grid_query_is_untouched_without_the_source_choice(): void {
		$args = array( 'post_type' => 'attachment' );

		$this->assertSame( $args, $this->screen->filter_query( $args ) );

		$_REQUEST['query'] = array( 'profotograaf_source' => 'other' );
		$this->assertSame( $args, $this->screen->filter_query( $args ) );

		$_REQUEST['query'] = array( 'profotograaf_source' => array( 'profotograaf' ) );
		$this->assertSame( $args, $this->screen->filter_query( $args ) );
	}

	public function test_the_source_choice_limits_the_grid_to_imported_photos(): void {
		$_REQUEST['query'] = array( 'profotograaf_source' => 'profotograaf' );

		$args = $this->screen->filter_query( array( 'post_type' => 'attachment' ) );

		$this->assertSame(
			array(
				array(
					'key'     => '_profotograaf_photo_id',
					'compare' => 'EXISTS',
				),
			),
			$args['meta_query']
		);
	}

	public function test_the_source_choice_keeps_an_existing_meta_query(): void {
		$_REQUEST['query'] = array( 'profotograaf_source' => 'profotograaf' );
		$existing          = array(
			'key'   => 'color',
			'value' => 'red',
		);

		$args = $this->screen->filter_query( array( 'meta_query' => array( $existing ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test input.

		$this->assertCount( 2, $args['meta_query'] );
		$this->assertSame( $existing, $args['meta_query'][0] );
	}

	public function test_an_imported_photo_shows_its_gallery_as_read_only_source(): void {
		$this->meta[12]                           = array(
			'_profotograaf_photo_id'   => 'p-1',
			'_profotograaf_gallery_id' => 'g-2',
		);
		$this->options[ Photo_Catalogue::OPTION ] = array(
			'photos' => array(
				array(
					'gallery_id'    => 'g-1',
					'gallery_title' => 'Spring',
				),
				array(
					'gallery_id'    => 'g-2',
					'gallery_title' => 'Autumn <walk>',
				),
			),
		);

		$fields = $this->screen->source_field( array(), (object) array( 'ID' => 12 ) );

		$this->assertSame( 'html', $fields['profotograaf_source']['input'] );
		$this->assertSame( 'Source', $fields['profotograaf_source']['label'] );
		$this->assertSame( 'Profotograaf, gallery Autumn <walk>', html_entity_decode( $fields['profotograaf_source']['html'] ) );
	}

	public function test_without_a_stored_catalogue_the_field_names_the_platform_only(): void {
		$this->meta[12] = array(
			'_profotograaf_photo_id'   => 'p-1',
			'_profotograaf_gallery_id' => 'g-9',
		);

		$fields = $this->screen->source_field( array( 'a' => 1 ), (object) array( 'ID' => 12 ) );

		$this->assertSame( 'Profotograaf', $fields['profotograaf_source']['html'] );
		$this->assertSame( 1, $fields['a'] );
	}

	public function test_other_attachments_get_no_source_field(): void {
		$fields = $this->screen->source_field( array( 'a' => 1 ), (object) array( 'ID' => 13 ) );

		$this->assertSame( array( 'a' => 1 ), $fields );
	}
}
