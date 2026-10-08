<?php
/**
 * Import from Profotograaf screen under Media, and the media grid source filter.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Config;
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
		$this->enqueue_reimport();
	}

	/**
	 * Loads the Re-import button script, in the media modal and on the
	 * attachment edit screen (wp_enqueue_script after the head still prints
	 * a footer script).
	 */
	public function enqueue_reimport(): void {
		if ( ! $this->active() ) {
			return;
		}
		wp_enqueue_script( 'profotograaf-reimport', PROFOTOGRAAF_URL . 'assets/admin/reimport.js', array( 'wp-api-fetch', 'wp-i18n' ), PROFOTOGRAAF_VERSION, true );
		wp_set_script_translations( 'profotograaf-reimport', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
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
	 * Adds the Source field to the details of an imported photo: the gallery
	 * with a link to it on Profotograaf, and a Re-import button for users who
	 * may replace the file.
	 *
	 * @param mixed    $fields Attachment fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array<string,mixed>
	 */
	public function source_field( $fields, $post ) {
		$fields   = is_array( $fields ) ? $fields : array();
		$id       = (int) $post->ID;
		$photo_id = (string) get_post_meta( $id, Photo_Importer::META_PHOTO_ID, true );
		if ( '' === $photo_id ) {
			return $fields;
		}
		$gallery_id = (string) get_post_meta( $id, Photo_Importer::META_GALLERY_ID, true );
		$gallery    = $this->gallery_row( $gallery_id );
		$title      = (string) ( $gallery['gallery_title'] ?? '' );
		$url        = $this->gallery_url( $gallery_id, $gallery );

		if ( '' === $url ) {
			$html = '' !== $title
				? sprintf(
					/* translators: %s: gallery title. */
					esc_html__( 'Profotograaf, gallery %s', 'profotograaf' ),
					esc_html( $title )
				)
				: esc_html__( 'Profotograaf', 'profotograaf' );
		} else {
			$link = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $url ),
				'' !== $title ? esc_html( $title ) : esc_html__( 'Open on Profotograaf', 'profotograaf' )
			);
			$html = '' !== $title
				? sprintf(
					/* translators: %s: link to the gallery on Profotograaf, titled with the gallery name. */
					esc_html__( 'Profotograaf, gallery %s', 'profotograaf' ),
					$link
				)
				: esc_html__( 'Profotograaf', 'profotograaf' ) . ', ' . $link;
		}//end if

		if ( $this->active() && current_user_can( 'edit_post', $id ) ) {
			// The attachment edit screen does not load the media views, so the field asks for its own script.
			$this->enqueue_reimport();
			$html .= sprintf(
				'<p class="profotograaf-reimport"><button type="button" class="button profotograaf-reimport__button" data-attachment-id="%1$d">%2$s</button> <span class="profotograaf-reimport__status" role="status" aria-live="polite" data-attachment-id="%1$d"></span></p>',
				$id,
				esc_html__( 'Re-import', 'profotograaf' )
			);
		}

		$fields['profotograaf_source'] = array(
			'label' => __( 'Source', 'profotograaf' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/**
	 * A stored catalogue row of a gallery. Never asks the platform.
	 *
	 * @param string $gallery_id Platform gallery id.
	 * @return array<string,mixed>
	 */
	private function gallery_row( string $gallery_id ): array {
		if ( '' === $gallery_id ) {
			return array();
		}
		$stored = get_option( Photo_Catalogue::OPTION, array() );
		foreach ( is_array( $stored ) && isset( $stored['photos'] ) && is_array( $stored['photos'] ) ? $stored['photos'] : array() as $photo ) {
			if ( is_array( $photo ) && (string) ( $photo['gallery_id'] ?? '' ) === $gallery_id ) {
				return $photo;
			}
		}
		return array();
	}

	/**
	 * Address of a gallery on Profotograaf: the catalogue's own gallery URL
	 * when it has one on the platform host, else the gallery in the platform
	 * app. Empty without a gallery id.
	 *
	 * @param string              $gallery_id Platform gallery id.
	 * @param array<string,mixed> $row        Stored catalogue row of the gallery.
	 */
	private function gallery_url( string $gallery_id, array $row ): string {
		if ( '' === $gallery_id ) {
			return '';
		}
		$own = isset( $row['gallery_url'] ) && is_string( $row['gallery_url'] ) ? $row['gallery_url'] : '';
		if ( '' !== $own && strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( Config::platform_url(), PHP_URL_HOST ) ) ) {
			return $own;
		}
		return Config::platform_endpoint( '/app/galleries/' . rawurlencode( $gallery_id ) );
	}
}
