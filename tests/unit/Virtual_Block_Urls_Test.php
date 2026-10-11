<?php
/**
 * Current URLs of Profotograaf photos in Image and Gallery blocks (issue #154).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Virtual_Block_Urls as Module;
use Profotograaf\Photo_Importer;
use Profotograaf\Settings;
use Profotograaf\Virtual_Attachments;
use Profotograaf\Virtual_Block_Urls;

/**
 * The module with a build directory the test controls.
 */
class Temporary_Build_Virtual_Urls extends Module {

	/**
	 * Build directory.
	 *
	 * @var string
	 */
	public string $directory = '';

	protected function build_directory(): string {
		return $this->directory;
	}
}

class Virtual_Block_Urls_Test extends Gallery_Test_Case {

	private const OLD   = 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg';
	private const NEW   = 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg';
	private const THUMB = 'https://profotograaf.nl/share/img/a1/thumb-bbbbbbbbbbbb.jpg';
	private const LOCAL = 'https://site.test/wp-content/uploads/2026/10/profotograaf-p-1.jpg';
	private const LARGE = 'https://site.test/wp-content/uploads/2026/10/profotograaf-p-1-1024x683.jpg';

	private Virtual_Block_Urls $urls;

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Image URLs by attachment id and size, as wp_get_attachment_image_src returns them.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $images = array();

	/**
	 * Number of post meta reads.
	 */
	private int $meta_reads = 0;

	/**
	 * Capabilities of the current user.
	 *
	 * @var array<int,string>
	 */
	private array $caps = array( 'edit_posts' );

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) {
				++$this->meta_reads;
				return $this->meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			function ( $id, $size ) {
				$url = $this->images[ $id ][ $size ] ?? '';
				return '' === $url ? false : array( $url, 100, 100, false );
			}
		);
		Functions\when( 'wp_get_attachment_url' )->alias( fn( $id ) => $this->images[ $id ]['full'] ?? false );
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn( $id ) => $this->meta[ $id ]['_wp_attachment_metadata'] ?? false );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		$this->urls = new Virtual_Block_Urls( new Photo_Importer( new Settings() ) );
	}

	/**
	 * A photo converted to local: photo id, no virtual mark, local URLs.
	 *
	 * @param int $id Attachment id.
	 */
	private function converted( int $id ): void {
		$this->meta[ $id ]   = array(
			Photo_Importer::META_PHOTO_ID => 'p-1',
			'_wp_attachment_metadata'     => array(
				'sizes' => array(
					'large'     => array(),
					'thumbnail' => array(),
				),
			),
		);
		$this->images[ $id ] = array(
			'full'  => self::LOCAL,
			'large' => self::LARGE,
		);
	}

	/**
	 * A photo kept on Profotograaf whose sync found a new version.
	 *
	 * @param int $id Attachment id.
	 */
	private function kept( int $id ): void {
		$this->meta[ $id ]   = array(
			Photo_Importer::META_PHOTO_ID     => 'p-1',
			Virtual_Attachments::META_VIRTUAL => '1',
		);
		$this->images[ $id ] = array(
			'full'      => self::NEW,
			'large'     => self::NEW,
			'thumbnail' => self::THUMB,
		);
	}

	private function setting_on(): void {
		$this->options[ Settings::OPTION ] = array( 'virtual_attachments' => true );
	}

	/**
	 * A saved core/image block.
	 *
	 * @param int                 $id    Attachment id.
	 * @param string              $src   Saved src.
	 * @param array<string,mixed> $attrs More attributes.
	 * @return array<string,mixed>
	 */
	private function image_block( int $id, string $src, array $attrs = array() ): array {
		$html = '<figure class="wp-block-image"><a href="' . $src . '"><img src="' . $src . '" alt="" class="wp-image-' . $id . '"/></a></figure>';
		return array(
			'blockName'    => 'core/image',
			'attrs'        => array( 'id' => $id ) + $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	public function test_a_converted_photo_gets_its_local_url_while_the_setting_is_off(): void {
		$this->converted( 7 );
		$this->assertFalse( Virtual_Attachments::enabled() );

		$block = $this->urls->filter_block_data( $this->image_block( 7, self::OLD ) );

		$this->assertStringContainsString( 'src="' . self::LOCAL . '"', $block['innerHTML'] );
		$this->assertStringContainsString( 'href="' . self::LOCAL . '"', $block['innerHTML'] );
		$this->assertStringNotContainsString( self::OLD, $block['innerContent'][0] );
	}

	public function test_the_size_of_the_block_picks_the_url(): void {
		$this->converted( 7 );

		$block = $this->urls->filter_block_data( $this->image_block( 7, self::OLD, array( 'sizeSlug' => 'large' ) ) );

		$this->assertStringContainsString( 'src="' . self::LARGE . '"', $block['innerHTML'] );
	}

	public function test_a_size_wordpress_does_not_know_falls_back_to_the_attachment_url(): void {
		$this->converted( 7 );
		unset( $this->images[7]['full'] );
		Functions\when( 'wp_get_attachment_url' )->justReturn( self::LOCAL );

		$block = $this->urls->filter_block_data( $this->image_block( 7, self::OLD, array( 'sizeSlug' => 'medium' ) ) );

		$this->assertStringContainsString( 'src="' . self::LOCAL . '"', $block['innerHTML'] );
	}

	public function test_a_kept_photo_gets_its_new_version_while_the_setting_is_on(): void {
		$this->setting_on();
		$this->kept( 8 );

		$block = $this->urls->filter_block_data( $this->image_block( 8, self::OLD ) );

		$this->assertStringContainsString( 'src="' . self::NEW . '"', $block['innerHTML'] );
	}

	public function test_a_kept_photo_keeps_its_saved_url_while_the_setting_is_off(): void {
		$this->kept( 8 );
		$block = $this->image_block( 8, self::OLD );

		$this->assertSame( $block, $this->urls->filter_block_data( $block ) );
	}

	public function test_a_current_url_foreign_host_plain_attachment_or_other_block_is_untouched(): void {
		$this->setting_on();
		$this->kept( 8 );
		$this->images[5] = array( 'full' => self::LOCAL );

		$current = $this->image_block( 8, self::NEW );
		$foreign = $this->image_block( 8, 'https://example.com/a.jpg' );
		$plain   = $this->image_block( 5, self::OLD );
		$no_id   = $this->image_block( 0, self::OLD );
		$no_img  = array(
			'blockName' => 'core/image',
			'attrs'     => array( 'id' => 8 ),
			'innerHTML' => '<figure></figure>',
		);
		$other   = array(
			'blockName' => 'core/paragraph',
			'attrs'     => array(),
			'innerHTML' => '<p><img src="' . self::OLD . '" class="wp-image-8"/></p>',
		);

		foreach ( array( $current, $foreign, $plain, $no_id, $no_img, $other, 'x', array( 'blockName' => 'core/image' ) ) as $block ) {
			$this->assertSame( $block, $this->urls->filter_block_data( $block ) );
		}
	}

	public function test_an_image_on_another_host_costs_no_database_read(): void {
		$this->urls->filter_block_data( $this->image_block( 8, 'https://site.test/wp-content/uploads/a.jpg' ) );

		$this->assertSame( 0, $this->meta_reads );
	}

	public function test_the_images_of_an_older_gallery_block_get_their_current_urls(): void {
		$this->converted( 7 );
		$this->setting_on();
		$this->kept( 8 );
		$item  = fn( int $id, string $url ) => '<li class="blocks-gallery-item"><figure><a href="' . $url . '"><img src="' . $url . '" alt="" data-id="' . $id . '" data-full-url="' . $url . '" data-link="' . $url . '" class="wp-image-' . $id . '"/></a></figure></li>';
		$html  = '<figure class="wp-block-gallery columns-2"><ul class="blocks-gallery-grid">' . $item( 7, self::OLD ) . $item( 8, 'https://profotograaf.nl/share/img/b2/web-cccccccccccc.jpg' ) . '<li><img src="' . self::OLD . '" alt=""/></li></ul></figure>';
		$block = array(
			'blockName'    => 'core/gallery',
			'attrs'        => array( 'ids' => array( 7, 8 ) ),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);

		$result = $this->urls->filter_block_data( $block );

		$this->assertStringContainsString( 'src="' . self::LARGE . '"', $result['innerHTML'] );
		$this->assertStringContainsString( 'src="' . self::NEW . '"', $result['innerContent'][0] );
		$this->assertStringNotContainsString( 'web-cccccccccccc', $result['innerHTML'] );
		$this->assertStringNotContainsString( self::OLD, $result['innerHTML'], 'The unidentified image shares the URL of item 7, so it moves with it.' );
	}

	public function test_the_gallery_size_and_the_full_url_of_an_older_gallery(): void {
		$this->converted( 7 );
		$this->images[7]['thumbnail'] = 'https://site.test/wp-content/uploads/2026/10/profotograaf-p-1-150x150.jpg';
		$thumb                        = 'https://profotograaf.nl/share/img/a1/thumb-aaaaaaaaaaaa.jpg';
		$html                         = '<figure class="wp-block-gallery"><ul><li><img src="' . $thumb . '" data-full-url="' . self::OLD . '" class="wp-image-7"/></li></ul></figure>';
		$block                        = array(
			'blockName'    => 'core/gallery',
			'attrs'        => array( 'sizeSlug' => 'thumbnail' ),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);

		$result = $this->urls->filter_block_data( $block );

		$this->assertStringContainsString( 'src="https://site.test/wp-content/uploads/2026/10/profotograaf-p-1-150x150.jpg"', $result['innerHTML'] );
		$this->assertStringContainsString( 'data-full-url="' . self::LOCAL . '"', $result['innerHTML'] );
	}

	public function test_a_current_gallery_block_leaves_its_image_blocks_to_their_own_render(): void {
		$this->converted( 7 );
		$html  = '<figure class="wp-block-gallery has-nested-images"></figure>';
		$block = array(
			'blockName'    => 'core/gallery',
			'attrs'        => array( 'sizeSlug' => '../bad' ),
			'innerBlocks'  => array( $this->image_block( 7, self::OLD ) ),
			'innerHTML'    => $html,
			'innerContent' => array( '<figure class="wp-block-gallery has-nested-images">', null, '</figure>' ),
		);

		$this->assertSame( $block, $this->urls->filter_block_data( $block ) );
	}

	public function test_the_rest_field_lists_the_current_urls_of_a_photo_only(): void {
		$this->converted( 7 );
		$this->images[7]['thumbnail'] = '';
		$this->kept( 8 );

		$this->assertSame(
			array(
				'full'  => self::LOCAL,
				'sizes' => array( 'large' => self::LARGE ),
			),
			$this->urls->rest_urls( array( 'id' => 7 ) )
		);
		$this->assertNull( $this->urls->rest_urls( array( 'id' => 8 ) ), 'Kept while the setting is off: no working URL.' );
		$this->assertNull( $this->urls->rest_urls( array( 'id' => 5 ) ) );
		$this->assertNull( $this->urls->rest_urls( 'x' ) );

		$this->setting_on();
		$this->assertSame(
			array(
				'full'  => self::NEW,
				'sizes' => array(),
			),
			$this->urls->rest_urls( array( 'id' => 8 ) )
		);
	}

	public function test_the_rest_field_is_registered_on_attachments(): void {
		$registered = array();
		Functions\when( 'register_rest_field' )->alias(
			function ( ...$args ) use ( &$registered ) {
				$registered[] = $args;
			}
		);

		$this->urls->register_rest_field();

		$this->assertSame( 'attachment', $registered[0][0] );
		$this->assertSame( Virtual_Block_Urls::REST_FIELD, $registered[0][1] );
		$this->assertSame( array( $this->urls, 'rest_urls' ), $registered[0][2]['get_callback'] );
		$this->assertTrue( $registered[0][2]['schema']['readonly'] );
	}

	/**
	 * The module with a temporary build directory, registered.
	 *
	 * @param string $asset Contents of index.asset.php, or empty for none.
	 */
	private function module( string $asset ): Temporary_Build_Virtual_Urls {
		$dir = sys_get_temp_dir() . '/pfvu' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $dir );
		if ( '' !== $asset ) {
			file_put_contents( $dir . 'index.asset.php', $asset );
		}
		$module            = new Temporary_Build_Virtual_Urls();
		$module->directory = $dir;
		$module->register( $this->plugin );
		return $module;
	}

	/**
	 * Removes a temporary build directory.
	 *
	 * @param Temporary_Build_Virtual_Urls $module Module.
	 */
	private function clean( Temporary_Build_Virtual_Urls $module ): void {
		foreach ( (array) glob( $module->directory . '*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $module->directory );
	}

	public function test_the_module_registers_whatever_the_setting(): void {
		$this->assertFalse( Virtual_Attachments::enabled() );

		$module = $this->module( '' );

		$this->assertNotFalse( has_filter( 'render_block_data' ) );
		$this->assertNotFalse( has_action( 'rest_api_init' ) );
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', array( $module, 'enqueue' ) ) );
		$this->clean( $module );
	}

	public function test_the_editor_script_loads_with_the_platform_url(): void {
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			function ( ...$args ) use ( &$localized ) {
				$localized[] = $args;
				return true;
			}
		);
		$module = $this->module( "<?php return array( 'dependencies' => array( 'wp-api-fetch', 'wp-data' ), 'version' => 'abc123' );" );

		$module->enqueue();

		$script = $this->enqueued[ Module::HANDLE ];
		$this->assertSame( 'https://example.test/wp-content/plugins/profotograaf/build/virtual-urls/index.js', $script['src'] );
		$this->assertSame( array( 'wp-api-fetch', 'wp-data' ), $script['deps'] );
		$this->assertSame( 'abc123', $script['ver'] );
		$this->assertSame( array( array( Module::HANDLE, Module::SCRIPT_GLOBAL, array( 'platformUrl' => 'https://profotograaf.nl' ) ) ), $localized );
		$this->clean( $module );
	}

	public function test_the_editor_script_needs_edit_posts_and_a_build(): void {
		$module = $this->module( '' );
		$module->enqueue();
		$this->assertSame( array(), $this->enqueued );
		$this->clean( $module );

		$this->caps = array();
		$module     = $this->module( "<?php return array( 'dependencies' => array(), 'version' => 'x' );" );
		$module->enqueue();
		$this->assertSame( array(), $this->enqueued );
		$this->clean( $module );
	}

	public function test_a_malformed_asset_file_falls_back_to_the_plugin_version(): void {
		Functions\when( 'wp_localize_script' )->justReturn( true );
		$module = $this->module( '<?php return 1;' );

		$module->enqueue();

		$this->assertSame( array(), $this->enqueued[ Module::HANDLE ]['deps'] );
		$this->assertSame( PROFOTOGRAAF_VERSION, $this->enqueued[ Module::HANDLE ]['ver'] );
		$this->clean( $module );
	}
}
