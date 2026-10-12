<?php
/**
 * REST routes for the actions on flagged imported copies.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * POST /profotograaf/v1/sync/keep { attachment_id } keeps a copy as the site's
 * own file ("Keep", "Stop watching"). POST /sync/watch sets it back to watched.
 * GET /sync/usage?attachment_id= counts the posts that use a copy. POST
 * /sync/remove { attachment_id, confirm } removes one copy and POST
 * /sync/remove-missing { confirm } removes the next batch of copies flagged
 * missing. Removal needs `confirm` set to true in the request, which only an
 * explicit click in the admin sends, and `edit_post` on the attachment.
 */
final class Sync_Actions_Rest {

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$id_arg = array(
			'attachment_id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		$routes = array(
			'/sync/keep'   => array( 'POST', 'keep', $id_arg ),
			'/sync/watch'  => array( 'POST', 'watch', $id_arg ),
			'/sync/usage'  => array( 'GET', 'usage', $id_arg ),
			'/sync/remove' => array(
				'POST',
				'remove',
				$id_arg + array(
					'confirm' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				Gallery_Rest::NAMESPACE,
				$path,
				array(
					'methods'             => $route[0],
					'callback'            => array( $this, $route[1] ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $route[2],
				)
			);
		}
		register_rest_route(
			Gallery_Rest::NAMESPACE,
			'/sync/remove-missing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remove_missing' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'confirm' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check for one attachment.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit( $request ): bool {
		$id = (int) $request->get_param( 'attachment_id' );
		return $id > 0 && current_user_can( 'edit_post', $id ) && current_user_can( 'upload_files' );
	}

	/**
	 * Permission check for the bulk route. Each copy is checked again.
	 */
	public function can_upload(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * POST /sync/keep.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function keep( $request ) {
		return Sync_Actions::keep( (int) $request->get_param( 'attachment_id' ), get_current_user_id() );
	}

	/**
	 * POST /sync/watch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function watch( $request ) {
		return Sync_Actions::watch_again( (int) $request->get_param( 'attachment_id' ) );
	}

	/**
	 * GET /sync/usage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function usage( $request ) {
		return Sync_Actions::usage( (int) $request->get_param( 'attachment_id' ) );
	}

	/**
	 * POST /sync/remove.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function remove( $request ) {
		if ( true !== (bool) $request->get_param( 'confirm' ) ) {
			return self::unconfirmed();
		}
		return Sync_Actions::remove( (int) $request->get_param( 'attachment_id' ) );
	}

	/**
	 * POST /sync/remove-missing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function remove_missing( $request ) {
		if ( true !== (bool) $request->get_param( 'confirm' ) ) {
			return self::unconfirmed();
		}
		return Sync_Actions::remove_missing_batch( static fn( int $id ): bool => current_user_can( 'edit_post', $id ) );
	}

	/**
	 * Error for a removal without the confirmation flag.
	 */
	private static function unconfirmed(): \WP_Error {
		return new \WP_Error( 'profotograaf_confirm_required', __( 'Confirm the removal first.', 'profotograaf' ), array( 'status' => 400 ) );
	}
}
