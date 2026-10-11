<?php
/**
 * Convert to local: turns photos kept on Profotograaf into normal Media Library files (issue #152).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Virtual_Attachments;

defined( 'ABSPATH' ) || exit;

/**
 * The "Convert to local" action for virtual attachments: a row action and a
 * bulk action in the Media Library list view, a field in the attachment
 * details, and notices for attachments whose photo left the catalogue.
 *
 * Unlike the Virtual_Attachments module this one registers while the setting
 * "Keep photos on Profotograaf" is off. Every piece acts on attachments that
 * carry the virtual mark only, so a site without them sees nothing (the bulk
 * action looks for one marked attachment first). Attachments kept while the
 * setting was on lose their image once it is off, and this is how they get it
 * back (docs/virtual-attachments.md, "Convert to local").
 *
 * A conversion needs `upload_files` and `edit_post` on the attachment, plus
 * a nonce: the bulk-media nonce of the list view, or one per attachment for
 * the single action. The result is kept in a short-lived transient per user
 * and shown as a notice after the redirect.
 */
class Virtual_Convert implements Module {

	public const ACTION     = 'profotograaf_convert_local';
	public const NOTICE_ARG = 'profotograaf_converted';
	public const RESULT     = 'profotograaf_convert_result_';

	/** Gone attachments listed in the Media Library notice. */
	private const GONE_LIMIT = 20;

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
		add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'convert_field' ), 20, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'result_notice' ) );
		add_action( 'admin_notices', array( $this, 'gone_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_single' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_args' ) );
	}

	/**
	 * Drops the notice flag from the address bar once the notice was shown.
	 *
	 * @param mixed $args Query args WordPress removes.
	 * @return array<int,string>
	 */
	public function removable_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$args[] = self::NOTICE_ARG;
		return $args;
	}

	/**
	 * Whether the current user may convert this attachment.
	 *
	 * @param int $id Attachment id.
	 */
	public function can_convert( int $id ): bool {
		return Virtual_Attachments::is_marked( $id ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * The nonced link that converts one attachment.
	 *
	 * @param int $id Attachment id.
	 */
	public function convert_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::ACTION,
					'attachment' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $id
		);
	}

	/**
	 * Adds "Convert to local" to the row actions of a virtual attachment.
	 *
	 * @param mixed    $actions Row actions.
	 * @param \WP_Post $post    Attachment.
	 * @return array<string,string>
	 */
	public function row_actions( $actions, $post ) {
		$actions = is_array( $actions ) ? $actions : array();
		$id      = (int) $post->ID;
		if ( ! $this->can_convert( $id ) ) {
			return $actions;
		}
		$actions['profotograaf_convert'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $this->convert_url( $id ) ),
			/* translators: %s: attachment title. */
			esc_attr( sprintf( __( 'Convert “%s” to local', 'profotograaf' ), get_the_title( $id ) ) ),
			esc_html__( 'Convert to local', 'profotograaf' )
		);
		return $actions;
	}

	/**
	 * Adds the "Local copy" field with the convert button to the details of a
	 * virtual attachment, in the media modal and on the attachment edit screen.
	 *
	 * @param mixed    $fields Attachment fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array<string,mixed>
	 */
	public function convert_field( $fields, $post ) {
		$fields = is_array( $fields ) ? $fields : array();
		$id     = (int) $post->ID;
		if ( ! $this->can_convert( $id ) ) {
			return $fields;
		}
		if ( Virtual_Attachments::is_gone( $id ) ) {
			$text = __( 'Profotograaf no longer lists this photo, so it may stop showing on your site. Convert it to local while Profotograaf still serves it.', 'profotograaf' );
		} elseif ( ! Virtual_Attachments::enabled() ) {
			$text = __( 'Keeping photos on Profotograaf is switched off, so this photo shows no image on this site until you convert it to local.', 'profotograaf' );
		} else {
			$text = __( 'This site stores no copy of this photo. Convert to local downloads it into the Media Library, and posts that use it keep working.', 'profotograaf' );
		}
		$fields['profotograaf_convert'] = array(
			'label' => __( 'Local copy', 'profotograaf' ),
			'input' => 'html',
			'html'  => sprintf(
				'<p class="description">%1$s</p><p><a class="button profotograaf-convert__button" href="%2$s">%3$s</a></p>',
				esc_html( $text ),
				esc_url( $this->convert_url( $id ) ),
				esc_html__( 'Convert to local', 'profotograaf' )
			),
		);
		return $fields;
	}

	/**
	 * Adds the bulk action while the setting is on, or while any attachment
	 * still carries the virtual mark.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function bulk_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( current_user_can( 'upload_files' ) && ( Virtual_Attachments::enabled() || array() !== $this->marked_ids( 1, false ) ) ) {
			$actions[ self::ACTION ] = __( 'Convert to local', 'profotograaf' );
		}
		return $actions;
	}

	/**
	 * Converts the selected attachments. upload.php checked the bulk-media
	 * nonce before this filter runs.
	 *
	 * @param mixed  $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param mixed  $ids      Selected attachment ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		$redirect = is_string( $redirect ) ? $redirect : '';
		if ( self::ACTION !== $action || ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}
		$this->remember( $this->convert_many( is_array( $ids ) ? $ids : array() ) );
		return add_query_arg( self::NOTICE_ARG, 1, $redirect );
	}

	/**
	 * Handles the single "Convert to local" link and goes back to the page it was on.
	 */
	public function handle_single(): void {
		$id = isset( $_GET['attachment'] ) ? absint( wp_unslash( $_GET['attachment'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below with the id in the action.
		if ( $id <= 0 || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		} else {
			check_admin_referer( self::ACTION . '_' . $id );
			$this->remember( $this->convert_many( array( $id ) ) );
			$back = wp_get_referer();
			$this->finish( add_query_arg( self::NOTICE_ARG, 1, is_string( $back ) && '' !== $back ? $back : admin_url( 'upload.php' ) ) );
		}
	}

	/**
	 * Converts attachments one by one. One failure never stops the rest.
	 *
	 * @param array<int|string,mixed> $ids Attachment ids.
	 * @return array{converted:int,skipped:int,failed:array<int,array{title:string,message:string}>}
	 */
	public function convert_many( array $ids ): array {
		$result = array(
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => array(),
		);
		foreach ( $ids as $id ) {
			$id = is_numeric( $id ) ? (int) $id : 0;
			if ( ! Virtual_Attachments::is_marked( $id ) ) {
				++$result['skipped'];
				continue;
			}
			$done = current_user_can( 'edit_post', $id )
				? $this->converter()->convert_to_local( $id )
				: new \WP_Error( 'profotograaf_convert_forbidden', __( 'You are not allowed to change this file.', 'profotograaf' ) );
			if ( is_wp_error( $done ) ) {
				$title              = get_the_title( $id );
				$result['failed'][] = array(
					/* translators: %d: attachment id. */
					'title'   => '' !== $title ? $title : sprintf( __( 'File %d', 'profotograaf' ), $id ),
					'message' => $done->get_error_message(),
				);
				continue;
			}
			++$result['converted'];
		}
		return $result;
	}

	/**
	 * Prints the result of the last conversion of the current user, once.
	 */
	public function result_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows a stored result, changes nothing.
			return;
		}
		$key    = self::RESULT . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );
		$converted = (int) ( $result['converted'] ?? 0 );
		$skipped   = (int) ( $result['skipped'] ?? 0 );
		$failed    = isset( $result['failed'] ) && is_array( $result['failed'] ) ? $result['failed'] : array();
		if ( $converted > 0 || ( array() === $failed && 0 === $skipped ) ) {
			printf(
				'<div class="notice notice-success is-dismissible profotograaf-convert-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of photos converted. */
						_n( '%d photo converted to local.', '%d photos converted to local.', $converted, 'profotograaf' ),
						$converted
					)
				)
			);
		}
		if ( array() !== $failed ) {
			$items = '';
			foreach ( $failed as $row ) {
				$items .= sprintf( '<li><strong>%1$s</strong>: %2$s</li>', esc_html( (string) ( $row['title'] ?? '' ) ), esc_html( (string) ( $row['message'] ?? '' ) ) );
			}
			printf(
				'<div class="notice notice-error profotograaf-convert-notice"><p>%1$s</p><ul>%2$s</ul></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of photos that failed. */
						_n( '%d photo could not be converted and stays kept on Profotograaf:', '%d photos could not be converted and stay kept on Profotograaf:', count( $failed ), 'profotograaf' ),
						count( $failed )
					)
				),
				$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
		if ( $skipped > 0 ) {
			printf(
				'<div class="notice notice-info is-dismissible profotograaf-convert-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of files skipped. */
						_n( '%d file was skipped because it is not kept on Profotograaf.', '%d files were skipped because they are not kept on Profotograaf.', $skipped, 'profotograaf' ),
						$skipped
					)
				)
			);
		}
	}

	/**
	 * Warns about photos that left the catalogue: on the edit screen of such an
	 * attachment, and in the Media Library with a list of them. Each offers the
	 * conversion. Reads post meta only.
	 */
	public function gone_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( 'attachment' === $screen->id ) {
			$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the attachment being edited.
			if ( Virtual_Attachments::is_gone( $id ) && $this->can_convert( $id ) ) {
				printf(
					'<div class="notice notice-warning profotograaf-gone-notice"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
					esc_html__( 'Profotograaf no longer lists this photo, so it may stop showing on your site. Convert it to local while Profotograaf still serves it.', 'profotograaf' ),
					esc_url( $this->convert_url( $id ) ),
					esc_html__( 'Convert to local', 'profotograaf' )
				);
			}
			return;
		}
		if ( 'upload' !== $screen->id ) {
			return;
		}
		$ids = array_values( array_filter( $this->marked_ids( self::GONE_LIMIT, true ), array( $this, 'can_convert' ) ) );
		if ( array() === $ids ) {
			return;
		}
		$items = '';
		foreach ( $ids as $id ) {
			$items .= sprintf(
				'<li><a href="%1$s">%2$s</a> <a class="button button-small" href="%3$s">%4$s</a></li>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ),
				esc_url( $this->convert_url( $id ) ),
				esc_html__( 'Convert to local', 'profotograaf' )
			);
		}
		printf(
			'<div class="notice notice-warning profotograaf-gone-notice"><p>%1$s</p><ul>%2$s</ul></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of photos. */
					_n(
						'%d photo kept on Profotograaf is no longer listed there and may stop showing on your site. Convert it to local while Profotograaf still serves it.',
						'%d photos kept on Profotograaf are no longer listed there and may stop showing on your site. Convert them to local while Profotograaf still serves them.',
						count( $ids ),
						'profotograaf'
					),
					count( $ids )
				)
			),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/**
	 * Ids of attachments with the virtual mark.
	 *
	 * @param int  $limit At most this many.
	 * @param bool $gone  Only those marked gone.
	 * @return array<int,int>
	 */
	private function marked_ids( int $limit, bool $gone ): array {
		$meta_query = array(
			array(
				'key'   => Virtual_Attachments::META_VIRTUAL,
				'value' => '1',
			),
		);
		if ( $gone ) {
			$meta_query[] = array(
				'key'     => Virtual_Attachments::META_GONE,
				'compare' => 'EXISTS',
			);
		}
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin only, limited.
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Keeps a result for the notice after the redirect.
	 *
	 * @param array<string,mixed> $result Result of convert_many().
	 */
	private function remember( array $result ): void {
		set_transient( self::RESULT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * The converter, built the way the Virtual_Attachments module builds it.
	 */
	private function converter(): Virtual_Attachments {
		$plugin    = $this->plugin ?? Plugin::instance();
		$settings  = $plugin->settings();
		$catalogue = new Photo_Catalogue( $plugin->api(), $settings );
		return new Virtual_Attachments( $settings, new Photo_Importer( $settings, null, $catalogue ), $catalogue );
	}

	/**
	 * Sends the browser to the given admin URL.
	 *
	 * @param string $url Where to go.
	 */
	protected function finish( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
