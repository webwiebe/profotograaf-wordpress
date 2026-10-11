<?php
/**
 * Offload and thumbnail plugins next to photos kept on Profotograaf (issue #156).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Virtual_Attachments;

defined( 'ABSPATH' ) || exit;

/**
 * A virtual attachment has no file on disk. Some popular plugins assume every
 * image has one and fail on it. This module tells them to skip a kept photo,
 * through the filters they offer for that:
 *
 * - WP Offload Media (Lite): `as3cf_pre_update_attachment_metadata` and
 *   `as3cf_pre_handle_item_upload` cancel the upload. Without this, it logs
 *   "Primary file ... does not exist" for each kept photo.
 * - Advanced Media Offloader (S3, Cloudflare R2 and others):
 *   `advmo_should_offload_attachment` returns false. Without this, its bulk
 *   offload fails on each kept photo and stores an error log on it.
 * - Regenerate Thumbnails: kept photos leave its bulk list, its links and
 *   buttons disappear for them, and its regenerate route answers
 *   `profotograaf_virtual_no_file` (409) with a clear message where the plugin
 *   would answer "The fullsize image file cannot be found".
 *
 * A kept photo is an attachment with the virtual mark or the virtual path,
 * and it counts only while its file is missing. During Convert to local
 * the new file exists before the final metadata update, so an offload plugin
 * can still pick up the converted photo. Like Virtual_Convert, the module
 * registers while the setting "Keep photos on Profotograaf" is off, so photos
 * kept earlier stay covered. Each hook does nothing while its plugin is
 * inactive, because the plugin then never applies the filter.
 */
class Virtual_Plugin_Compat implements Module {

	public const ERROR_CODE = 'profotograaf_virtual_no_file';

	/** Regenerate Thumbnails' regenerate route. */
	private const REGENERATE_ROUTE = '~^/regenerate-thumbnails/v1/regenerate/(\d+)/?$~';

	/** Key of Regenerate Thumbnails' link and button. */
	private const RT_KEY = 'regenerate_thumbnails';

	/** WP Offload Media's source type for Media Library items. */
	private const AS3CF_SOURCE = 'media-library';

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_filter( 'as3cf_pre_update_attachment_metadata', array( $this, 'cancel_as3cf_metadata' ), 10, 3 );
		add_filter( 'as3cf_pre_handle_item_upload', array( $this, 'cancel_as3cf_upload' ), 10, 2 );
		add_filter( 'advmo_should_offload_attachment', array( $this, 'advmo_should_offload' ), 10, 2 );
		add_filter( 'rest_attachment_query', array( $this, 'leave_regenerate_list' ), 20, 2 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'refuse_regenerate' ), 10, 3 );
		add_filter( 'media_row_actions', array( $this, 'remove_row_action' ), 11, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'remove_modal_button' ), 100, 2 );
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'hide_edit_screen_button' ), 10, 1 );
	}

	/**
	 * Whether this is a kept photo whose file is missing.
	 *
	 * @param int $id Attachment id.
	 */
	public static function kept_without_file( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		// Virtual_Attachments::create() saves the metadata before it sets the
		// mark, and offload plugins act on that save. The virtual path tells
		// the photo apart at that moment.
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$virtual  = is_string( $relative ) && 0 === strpos( $relative, Virtual_Attachments::FILE_DIR . '/' );
		if ( ! $virtual && ! Virtual_Attachments::is_marked( $id ) ) {
			return false;
		}
		$file = get_attached_file( $id, true );
		return ! is_string( $file ) || '' === $file || ! file_exists( $file );
	}

	/**
	 * `as3cf_pre_update_attachment_metadata`: WP Offload Media skips a kept photo.
	 *
	 * @param mixed $cancel  Earlier answer.
	 * @param mixed $data    Attachment metadata.
	 * @param mixed $post_id Attachment id.
	 * @return mixed
	 */
	public function cancel_as3cf_metadata( $cancel, $data, $post_id ) {
		unset( $data );
		return self::kept_without_file( (int) $post_id ) ? true : $cancel;
	}

	/**
	 * `as3cf_pre_handle_item_upload`: WP Offload Media skips a kept photo
	 * wherever an upload starts.
	 *
	 * @param mixed $cancel Earlier answer.
	 * @param mixed $item   The offload item.
	 * @return mixed
	 */
	public function cancel_as3cf_upload( $cancel, $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'source_type' ) || ! method_exists( $item, 'source_id' ) ) {
			return $cancel;
		}
		if ( self::AS3CF_SOURCE !== $item->source_type() ) {
			return $cancel;
		}
		return self::kept_without_file( (int) $item->source_id() ) ? true : $cancel;
	}

	/**
	 * `advmo_should_offload_attachment`: Advanced Media Offloader skips a kept photo.
	 *
	 * @param mixed $should        Earlier answer.
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public function advmo_should_offload( $should, $attachment_id ) {
		return self::kept_without_file( (int) $attachment_id ) ? false : $should;
	}

	/**
	 * `rest_attachment_query`: Regenerate Thumbnails lists its work with
	 * `is_regeneratable=1`. Kept photos leave that list, so its count and its
	 * bulk run cover local images only.
	 *
	 * @param mixed $args    Query arguments.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public function leave_regenerate_list( $args, $request ) {
		if ( ! is_array( $args ) || ! $request instanceof \WP_REST_Request || empty( $request->get_param( 'is_regeneratable' ) ) ) {
			return $args;
		}
		$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[]       = array(
			'key'     => Virtual_Attachments::META_VIRTUAL,
			'compare' => 'NOT EXISTS',
		);
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only on Regenerate Thumbnails' list.
		return $args;
	}

	/**
	 * `rest_request_before_callbacks`: answers Regenerate Thumbnails' regenerate
	 * route for a kept photo with a clear error.
	 *
	 * @param mixed $response Earlier result, null when nothing answered yet.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function refuse_regenerate( $response, $handler, $request ) {
		unset( $handler );
		if ( null !== $response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 1 !== preg_match( self::REGENERATE_ROUTE, $request->get_route(), $match ) ) {
			return $response;
		}
		$id = (int) $match[1];
		if ( ! self::kept_without_file( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $response;
		}
		return new \WP_Error(
			self::ERROR_CODE,
			self::message(),
			array(
				'status'        => 409,
				'attachment_id' => $id,
			)
		);
	}

	/**
	 * The text for a regenerate attempt on a kept photo.
	 */
	public static function message(): string {
		return __( 'This photo is kept on Profotograaf, so this site has no file to make thumbnails from. Profotograaf serves its sizes. Convert it to local if you need the files on this site.', 'profotograaf' );
	}

	/**
	 * `media_row_actions`: removes "Regenerate Thumbnails" from a kept photo in
	 * the list view.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Attachment post.
	 * @return mixed
	 */
	public function remove_row_action( $actions, $post ) {
		if ( is_array( $actions ) && isset( $actions[ self::RT_KEY ] ) && $this->is_kept_post( $post ) ) {
			unset( $actions[ self::RT_KEY ] );
		}
		return $actions;
	}

	/**
	 * `attachment_fields_to_edit`: removes the "Regenerate Thumbnails" button
	 * from a kept photo in the media modal.
	 *
	 * @param mixed $fields Form fields.
	 * @param mixed $post   Attachment post.
	 * @return mixed
	 */
	public function remove_modal_button( $fields, $post ) {
		if ( is_array( $fields ) && isset( $fields[ self::RT_KEY ] ) && $this->is_kept_post( $post ) ) {
			unset( $fields[ self::RT_KEY ] );
		}
		return $fields;
	}

	/**
	 * `attachment_submitbox_misc_actions`: hides the "Regenerate Thumbnails"
	 * button on the edit screen of a kept photo.
	 *
	 * @param mixed $post Attachment post.
	 */
	public function hide_edit_screen_button( $post = null ): void {
		if ( ! $this->is_kept_post( $post ) ) {
			return;
		}
		echo '<style>.misc-pub-regenerate-thumbnails { display: none; }</style>';
	}

	/**
	 * Whether a post is a kept photo whose file is missing.
	 *
	 * @param mixed $post Post.
	 */
	private function is_kept_post( $post ): bool {
		return is_object( $post ) && isset( $post->ID ) && self::kept_without_file( (int) $post->ID );
	}
}
