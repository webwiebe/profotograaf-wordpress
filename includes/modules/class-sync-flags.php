<?php
/**
 * Shows which imported copies need attention.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Reconcile;
use Profotograaf\Sync_Attention as Attention;

defined( 'ABSPATH' ) || exit;

/**
 * Media grid and list filter "Needs attention", the Profotograaf column in
 * the list, and the Site Health item (docs/media-lifecycle.md, rule 1). The
 * admin notice lives in Admin_Notices and the Import screen section in
 * Media_Import_Screen. Everything reads the counts option the sync keeps.
 */
class Sync_Flags implements Module {

	public const COLUMN = 'profotograaf_sync';

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_grid' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_list' ) );
		add_action( 'restrict_manage_posts', array( $this, 'list_filter' ) );
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Whether the column, filter and test are on.
	 */
	private function active(): bool {
		return null !== $this->plugin && $this->plugin->settings()->media_source_enabled() && current_user_can( 'upload_files' );
	}

	/**
	 * Adds the Profotograaf column to the list.
	 *
	 * @param mixed $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		if ( $this->active() ) {
			$columns[ self::COLUMN ] = __( 'Profotograaf', 'profotograaf' );
		}
		return $columns;
	}

	/**
	 * Prints the sync state of an imported copy, as words.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Attachment id.
	 */
	public function render_column( $column, $id ): void {
		if ( self::COLUMN !== $column || '' === (string) get_post_meta( (int) $id, Photo_Importer::META_PHOTO_ID, true ) ) {
			return;
		}
		echo esc_html( Attention::column_text( (string) get_post_meta( (int) $id, Reconcile::META_STATE, true ) ) );
	}

	/**
	 * Narrows the grid query to flagged copies when the filter asks for them.
	 * It only narrows the result, which makes a nonce check unnecessary.
	 *
	 * @param mixed $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function filter_grid( $args ) {
		$args  = is_array( $args ) ? $args : array();
		$query = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only narrowing, the value is compared below.
		$value = isset( $query[ Media_Import_Screen::FILTER_ARG ] ) && is_string( $query[ Media_Import_Screen::FILTER_ARG ] ) ? $query[ Media_Import_Screen::FILTER_ARG ] : '';
		if ( Attention::FILTER_VAL === $value ) {
			$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$meta_query[]       = Attention::meta_query();
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter is opt-in and narrows the grid.
		}
		return $args;
	}

	/**
	 * Narrows the Media Library list when the URL asks for flagged copies.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function filter_list( $query ): void {
		global $pagenow;
		if ( 'upload.php' !== $pagenow || ! $query->is_main_query() || ! $this->active() ) {
			return;
		}
		$value = isset( $_GET[ Media_Import_Screen::FILTER_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ Media_Import_Screen::FILTER_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only narrowing.
		if ( Attention::FILTER_VAL === $value ) {
			$query->set( 'meta_query', array( Attention::meta_query() ) );
		}
	}

	/**
	 * Prints the filter checkbox in the list view toolbar.
	 *
	 * @param string $post_type Post type of the list.
	 */
	public function list_filter( $post_type = '' ): void {
		if ( 'attachment' !== $post_type || ! $this->active() ) {
			return;
		}
		$on = isset( $_GET[ Media_Import_Screen::FILTER_ARG ] ) && Attention::FILTER_VAL === sanitize_key( wp_unslash( $_GET[ Media_Import_Screen::FILTER_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		printf(
			'<label class="screen-reader-text" for="profotograaf-source-list">%1$s</label><select name="%2$s" id="profotograaf-source-list"><option value="">%3$s</option><option value="%4$s"%5$s>%6$s</option></select>',
			esc_html__( 'Filter by Profotograaf state', 'profotograaf' ),
			esc_attr( Media_Import_Screen::FILTER_ARG ),
			esc_html__( 'All sources', 'profotograaf' ),
			esc_attr( Attention::FILTER_VAL ),
			selected( $on, true, false ),
			esc_html__( 'Profotograaf: needs attention', 'profotograaf' )
		);
	}

	/**
	 * Adds the Site Health test while the media source is on.
	 *
	 * @param mixed $tests Tests.
	 * @return mixed
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) || null === $this->plugin || ! $this->plugin->settings()->media_source_enabled() ) {
			return $tests;
		}
		$tests['direct'][ Attention::HEALTH_TEST ] = array(
			'label' => __( 'Imported Profotograaf photos', 'profotograaf' ),
			'test'  => array( Attention::class, 'health_result' ),
		);
		return $tests;
	}
}
