<?php
/**
 * Image editing for photos kept on Profotograaf (issue #155).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Virtual_Attachments;

defined( 'ABSPATH' ) || exit;

/**
 * A virtual attachment has no file on disk, so WordPress cannot edit its
 * image. This module says so in every place that offers editing, and points
 * to Convert to local:
 *
 * - The REST image editor (`POST /wp/v2/media/{id}/edit`) answers
 *   `profotograaf_virtual_not_editable` (409) with the convert link, where
 *   core would fail with `rest_unknown_attachment`.
 * - The media modal and the attachment edit screen hide the Edit Image
 *   button of a virtual attachment and explain why.
 * - The block editor hides the Crop button of an Image block that shows a
 *   virtual attachment and adds a notice to the block settings.
 *
 * Like Virtual_Convert it registers while the setting "Keep photos on
 * Profotograaf" is off and checks the virtual mark per attachment, so
 * attachments kept earlier stay covered. Other attachments are left alone.
 */
class Virtual_Edit_Guard implements Module {

	public const ERROR_CODE    = 'profotograaf_virtual_not_editable';
	public const REST_FIELD    = 'profotograaf_virtual';
	public const JS_KEY        = 'profotograafNotEditable';
	public const MEDIA_HANDLE  = 'profotograaf-virtual-edit-media';
	public const EDITOR_HANDLE = 'profotograaf-virtual-edit-editor';

	/** The REST image editor route. */
	private const EDIT_ROUTE = '~^/wp/v2/media/(\d+)/edit/?$~';

	/**
	 * Builds the convert links.
	 *
	 * @var Virtual_Convert
	 */
	private Virtual_Convert $convert;

	/**
	 * Constructor.
	 *
	 * @param Virtual_Convert|null $convert Convert module, for its links and checks.
	 */
	public function __construct( ?Virtual_Convert $convert = null ) {
		$this->convert = $convert ?? new Virtual_Convert();
	}

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_filter( 'rest_request_before_callbacks', array( $this, 'refuse_rest_edit' ), 10, 3 );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'mark_for_js' ), 20, 2 );
		add_action( 'edit_form_after_title', array( $this, 'edit_screen_note' ), 11 );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * The text shown wherever editing is offered.
	 */
	public static function message(): string {
		return __( 'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local to crop, rotate or scale it.', 'profotograaf' );
	}

	/**
	 * `rest_request_before_callbacks`: answers the REST image editor for a
	 * virtual attachment with a specific error. A user who may not edit the
	 * attachment gets core's own answer.
	 *
	 * @param mixed $response Earlier result, null when nothing answered yet.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function refuse_rest_edit( $response, $handler, $request ) {
		unset( $handler );
		if ( null !== $response || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $response;
		}
		if ( 1 !== preg_match( self::EDIT_ROUTE, $request->get_route(), $match ) ) {
			return $response;
		}
		$id = (int) $match[1];
		if ( ! Virtual_Attachments::is_marked( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $response;
		}
		return new \WP_Error(
			self::ERROR_CODE,
			__( 'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local in the Media Library first, then edit it.', 'profotograaf' ),
			array(
				'status'        => 409,
				'attachment_id' => $id,
				'convert_url'   => $this->convert_link( $id ),
			)
		);
	}

	/**
	 * Adds `profotograaf_virtual` to the REST media response: null for a
	 * normal attachment, the convert link for a virtual one. The block editor
	 * reads it to hide the Crop button.
	 */
	public function register_rest_field(): void {
		register_rest_field(
			'attachment',
			self::REST_FIELD,
			array(
				'get_callback' => array( $this, 'rest_value' ),
				'schema'       => array(
					'description' => __( 'Set when the photo is kept on Profotograaf and has no file on this site.', 'profotograaf' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
					'properties'  => array(
						'convert_url' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Value of the REST field.
	 *
	 * @param mixed $item Attachment as REST prepares it.
	 * @return array{convert_url:string}|null
	 */
	public function rest_value( $item ) {
		$id = is_array( $item ) && isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( ! Virtual_Attachments::is_marked( $id ) ) {
			return null;
		}
		return array( 'convert_url' => $this->convert_link( $id ) );
	}

	/**
	 * `wp_prepare_attachment_for_js`: tells the media modal that a virtual
	 * attachment cannot be edited, with the convert link.
	 *
	 * @param mixed $response   Attachment as the modal sees it.
	 * @param mixed $attachment Attachment post.
	 * @return mixed
	 */
	public function mark_for_js( $response, $attachment ) {
		if ( ! is_array( $response ) || ! is_object( $attachment ) || ! isset( $attachment->ID ) ) {
			return $response;
		}
		$id = (int) $attachment->ID;
		if ( ! Virtual_Attachments::is_marked( $id ) ) {
			return $response;
		}
		$response[ self::JS_KEY ] = array( 'convertUrl' => $this->convert_link( $id ) );
		return $response;
	}

	/**
	 * On the edit screen of a virtual attachment: hides the Edit Image button
	 * and says why. Runs after core printed the image (priority 10).
	 *
	 * @param mixed $post Post being edited.
	 */
	public function edit_screen_note( $post ): void {
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! Virtual_Attachments::is_marked( (int) $post->ID ) ) {
			return;
		}
		$id   = (int) $post->ID;
		$link = $this->convert_link( $id );
		printf(
			'<style>#imgedit-open-btn-%1$d, #imgedit-open-btn-%1$d + .spinner { display: none; }</style><div class="notice notice-info inline profotograaf-not-editable"><p>%2$s</p>%3$s</div>',
			(int) $id,
			esc_html( self::message() ),
			'' === $link ? '' : sprintf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( $link ), esc_html__( 'Convert to local', 'profotograaf' ) )
		);
	}

	/**
	 * Loads the media modal script that swaps Edit Image for the explanation.
	 */
	public function enqueue_media(): void {
		$this->enqueue( self::MEDIA_HANDLE, 'virtual-edit-media', array( 'media-views' ) );
	}

	/**
	 * Loads the block editor script that hides Crop on a virtual image.
	 */
	public function enqueue_editor(): void {
		$this->enqueue( self::EDITOR_HANDLE, 'virtual-edit-editor', array( 'wp-hooks', 'wp-core-data' ) );
	}

	/**
	 * Loads a built script for users who can upload files, while the setting
	 * is on or a virtual attachment exists.
	 *
	 * @param non-empty-string  $handle Script handle.
	 * @param string            $folder Folder under build/.
	 * @param array<int,string> $extra  Dependencies the asset file cannot know.
	 */
	private function enqueue( string $handle, string $folder, array $extra ): void {
		if ( ! current_user_can( 'upload_files' ) || ! ( Virtual_Attachments::enabled() || $this->any_marked() ) ) {
			return;
		}
		$asset_file = $this->build_directory() . $folder . '/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$asset = is_array( $asset ) ? $asset : array();
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : PROFOTOGRAAF_VERSION;
		wp_enqueue_script( $handle, PROFOTOGRAAF_URL . 'build/' . $folder . '/index.js', array_values( array_unique( array_merge( $deps, $extra ) ) ), $ver, true );
		wp_set_script_translations( $handle, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
	}

	/**
	 * Whether any attachment carries the virtual mark. One query for one id.
	 */
	protected function any_marked(): bool {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_key'               => Virtual_Attachments::META_VIRTUAL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin only, one id.
				'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin only, one id.
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array() !== $ids;
	}

	/**
	 * The convert link for script use (plain `&`), or '' when the current
	 * user may not convert this attachment.
	 *
	 * @param int $id Attachment id.
	 */
	private function convert_link( int $id ): string {
		return $this->convert->can_convert( $id ) ? str_replace( '&amp;', '&', $this->convert->convert_url( $id ) ) : '';
	}

	/**
	 * Absolute path of build/, with a trailing slash.
	 */
	protected function build_directory(): string {
		return PROFOTOGRAAF_DIR . 'build/';
	}
}
