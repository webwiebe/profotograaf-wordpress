<?php
/**
 * Import from Profotograaf screen under Media, and the media grid source filter.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Media > Import from Profotograaf: a React screen over the photo routes, for
 * users with upload_files while the media source setting is on.
 *
 * The same module adds a Source filter to the media grid (Profotograaf photos
 * are the attachments that carry the photo id meta) and a read-only Source
 * field to the attachment details.
 */
class Media_Import_Screen implements Module {

	public const SLUG       = 'profotograaf-import';
	public const CAPABILITY = 'upload_files';
	public const FILTER_ARG = 'profotograaf_source';
	public const FILTER_VAL = 'profotograaf';

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Hook suffix of the screen, empty while it is not registered.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_filter' ) );
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_query' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'source_field' ), 10, 2 );
	}

	/**
	 * Whether the screen and the filter are on: the setting is on and the user may upload.
	 */
	private function active(): bool {
		return null !== $this->plugin && $this->plugin->settings()->media_source_enabled() && current_user_can( self::CAPABILITY );
	}

	/**
	 * Adds the entry under Media.
	 */
	public function add_menu(): void {
		if ( ! $this->active() ) {
			return;
		}
		$hook       = add_media_page(
			__( 'Import from Profotograaf', 'profotograaf' ),
			__( 'Import from Profotograaf', 'profotograaf' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Prints the page. The app mounts on the root element.
	 */
	public function render(): void {
		printf(
			'<div class="wrap"><h1>%1$s</h1><div id="profotograaf-import-root" data-admin-url="%2$s"></div></div>',
			esc_html__( 'Import from Profotograaf', 'profotograaf' ),
			esc_url( admin_url() )
		);
	}

	/**
	 * Loads the app on its own screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook || $this->hook !== $hook_suffix ) {
			return;
		}
		$asset_file = $this->asset_file();
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;
		$asset = is_array( $asset ) ? $asset : array();
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : PROFOTOGRAAF_VERSION;

		wp_enqueue_style( 'profotograaf-import-screen', PROFOTOGRAAF_URL . 'build/import-screen/style-index.css', array( 'wp-components' ), $ver );
		wp_enqueue_script( 'profotograaf-import-screen', PROFOTOGRAAF_URL . 'build/import-screen/index.js', $deps, $ver, true );
		wp_set_script_translations( 'profotograaf-import-screen', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
	}

	/**
	 * Path of the asset file the build writes next to the screen script. It
	 * does not exist in a checkout that was not built.
	 */
	private function asset_file(): string {
		return PROFOTOGRAAF_DIR . 'build/import-screen/index.asset.php';
	}

	/**
	 * Loads the media grid filter wherever the media views load.
	 */
	public function enqueue_filter(): void {
		if ( ! $this->active() ) {
			return;
		}
		wp_enqueue_script( 'profotograaf-media-source', PROFOTOGRAAF_URL . 'assets/admin/media-source.js', array( 'media-views', 'wp-i18n' ), PROFOTOGRAAF_VERSION, true );
		wp_set_script_translations( 'profotograaf-media-source', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
	}

	/**
	 * Narrows the media grid query to imported photos when the Source filter asks for them.
	 *
	 * WordPress drops unknown keys of the grid query before this filter runs,
	 * so the choice is read from the request itself. It only narrows the
	 * result, which makes a nonce check unnecessary.
	 *
	 * @param mixed $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function filter_query( $args ) {
		$args  = is_array( $args ) ? $args : array();
		$query = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only narrowing, the value is compared below.
		$value = isset( $query[ self::FILTER_ARG ] ) && is_string( $query[ self::FILTER_ARG ] ) ? $query[ self::FILTER_ARG ] : '';
		if ( self::FILTER_VAL !== $value ) {
			return $args;
		}
		$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[]       = array(
			'key'     => Photo_Importer::META_PHOTO_ID,
			'compare' => 'EXISTS',
		);
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter is opt-in and narrows the grid.
		return $args;
	}

	/**
	 * Adds a read-only Source field to the details of an imported photo.
	 *
	 * @param mixed    $fields Attachment fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array<string,mixed>
	 */
	public function source_field( $fields, $post ) {
		$fields   = is_array( $fields ) ? $fields : array();
		$photo_id = (string) get_post_meta( (int) $post->ID, Photo_Importer::META_PHOTO_ID, true );
		if ( '' === $photo_id ) {
			return $fields;
		}
		$gallery = $this->gallery_title( (string) get_post_meta( (int) $post->ID, Photo_Importer::META_GALLERY_ID, true ) );

		$fields['profotograaf_source'] = array(
			'label' => __( 'Source', 'profotograaf' ),
			'input' => 'html',
			'html'  => '' !== $gallery
				? sprintf(
					/* translators: %s: gallery title. */
					esc_html__( 'Profotograaf, gallery %s', 'profotograaf' ),
					esc_html( $gallery )
				)
				: esc_html__( 'Profotograaf', 'profotograaf' ),
		);
		return $fields;
	}

	/**
	 * Title of a gallery from the stored catalogue. Never asks the platform.
	 *
	 * @param string $gallery_id Platform gallery id.
	 */
	private function gallery_title( string $gallery_id ): string {
		if ( '' === $gallery_id ) {
			return '';
		}
		$stored = get_option( Photo_Catalogue::OPTION, array() );
		foreach ( is_array( $stored ) && isset( $stored['photos'] ) && is_array( $stored['photos'] ) ? $stored['photos'] : array() as $photo ) {
			if ( is_array( $photo ) && (string) ( $photo['gallery_id'] ?? '' ) === $gallery_id ) {
				return (string) ( $photo['gallery_title'] ?? '' );
			}
		}
		return '';
	}
}
