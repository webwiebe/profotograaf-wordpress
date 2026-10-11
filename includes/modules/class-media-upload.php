<?php
/**
 * Send Media Library files to Profotograaf.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Admin\Upload_Panel;
use Profotograaf\Media_Baseline;
use Profotograaf\Module;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Upload_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * The hooks of the opt-in upload (docs/media-lifecycle.md, rule 4): the
 * "Send to Profotograaf" field in the attachment details, the bulk action and
 * the status column in the Media Library list, the automatic send of a link
 * that has it on, the cron event that works the queue, and the push of text
 * edits to a file that was sent.
 *
 * Everything but the cron event and the text push needs the setting
 * `media_upload` and a user who can upload files. A request that changes
 * something only queues; the queue sends in the background.
 */
class Media_Upload implements Module {

	public const FIELD       = 'profotograaf_send_to';
	public const BULK_PREFIX = 'profotograaf_send:';
	public const NOTICE_ARG  = 'profotograaf_queued';
	public const AGAIN       = 'profotograaf_upload_again';

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
		( new Upload_Panel( $plugin ) )->register();
		add_action( Upload_Queue::HOOK, array( $this, 'run_queue' ) );
		add_action( 'profotograaf_connected', array( $this, 'resume_queue' ) );
		add_action( 'add_attachment', array( $this, 'maybe_send_automatically' ) );
		add_action( 'edit_attachment', array( $this, 'note_edit' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'send_field' ), 20, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_field' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'admin_post_' . self::AGAIN, array( $this, 'handle_again' ) );
	}

	/**
	 * Cron: sends one file or one text update.
	 */
	public function run_queue(): void {
		$this->plugin()->upload_queue()->run();
	}

	/**
	 * After a pairing: picks the queue up again.
	 */
	public function resume_queue(): void {
		$this->plugin()->upload_queue()->resume();
	}

	/**
	 * Queues a new image upload for the link that sends automatically.
	 *
	 * @param int|string $attachment_id Attachment id.
	 */
	public function maybe_send_automatically( $attachment_id ): void {
		$this->plugin()->upload_queue()->assign_automatic( (int) $attachment_id );
	}

	/**
	 * Notes a possible text change of a file that was sent.
	 *
	 * @param int|string $attachment_id Attachment id.
	 */
	public function note_edit( $attachment_id ): void {
		$this->plugin()->upload_queue()->note_text_edit( (int) $attachment_id );
	}

	/**
	 * Whether the UI is on: the setting is on and the user may upload files.
	 */
	private function active(): bool {
		return $this->plugin()->settings()->media_upload_enabled() && current_user_can( 'upload_files' );
	}

	/**
	 * Adds the "Send to Profotograaf" field to the attachment details, or the
	 * state of a file that was queued or sent.
	 *
	 * @param mixed    $fields Attachment fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array<string,mixed>
	 */
	public function send_field( $fields, $post ) {
		$fields = is_array( $fields ) ? $fields : array();
		$id     = (int) $post->ID;
		$queue  = $this->plugin()->upload_queue();
		if ( ! $this->active() || ! current_user_can( 'edit_post', $id ) || ! $queue->sendable( $id ) ) {
			return $fields;
		}
		$state = $queue->state( $id );
		if ( '' === $state && '' !== (string) get_post_meta( $id, Photo_Importer::META_PHOTO_ID, true ) ) {
			return $fields;
			// An imported photo is on Profotograaf already.
		}

		$html = '';
		switch ( $state ) {
			case Upload_Queue::STATE_UPLOADED:
				$at   = (int) get_post_meta( $id, Upload_Queue::META_UPLOADED, true );
				$html = sprintf(
					/* translators: %s: date the file was sent. */
					esc_html__( 'Sent to Profotograaf on %s.', 'profotograaf' ),
					esc_html( $at > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $at ) : '' )
				);
				break;
			case Upload_Queue::STATE_QUEUED:
			case Upload_Queue::STATE_UPLOADING:
				$html = esc_html__( 'Waiting to be sent to Profotograaf.', 'profotograaf' );
				if ( $this->plugin()->connection()->needs_write_reconnect( true ) ) {
					$html .= ' ' . esc_html__( 'Sending needs a new permission. Connect this site again under Settings > Profotograaf.', 'profotograaf' );
				}
				break;
			default:
				if ( Upload_Queue::STATE_FAILED === $state ) {
					$html = '<span class="profotograaf-upload__error" role="status">' . esc_html( $queue->error( $id ) ) . '</span><br />' . $this->again_link( $id );
				}
				$html .= $this->select_html( $id );
		}//end switch
		if ( '' === $html ) {
			return $fields;
		}
		$fields[ self::FIELD ] = array(
			'label' => __( 'Send to Profotograaf', 'profotograaf' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/**
	 * The select of linked galleries. Empty when none is linked.
	 *
	 * @param int $id Attachment id.
	 */
	private function select_html( int $id ): string {
		$links = $this->plugin()->upload_links()->usable();
		if ( array() === $links ) {
			return '<span class="description">' . esc_html__( 'Link a gallery under Settings > Profotograaf to send files.', 'profotograaf' ) . '</span>';
		}
		$html  = sprintf( '<select name="attachments[%1$d][%2$s]" id="attachments-%1$d-%2$s">', $id, esc_attr( self::FIELD ) );
		$html .= '<option value="">' . esc_html__( 'Do not send', 'profotograaf' ) . '</option>';
		foreach ( $links as $link ) {
			$html .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $link['gallery_id'] ), esc_html( '' !== $link['title'] ? $link['title'] : $link['gallery_id'] ) );
		}
		return $html . '</select>';
	}

	/**
	 * Queues the file when a gallery was chosen in the field.
	 *
	 * @param array<string,mixed> $post       Attachment post data.
	 * @param array<string,mixed> $attachment Submitted fields.
	 * @return array<string,mixed>
	 */
	public function save_field( $post, $attachment ) {
		$id      = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		$gallery = isset( $attachment[ self::FIELD ] ) && is_string( $attachment[ self::FIELD ] ) ? sanitize_text_field( $attachment[ self::FIELD ] ) : '';
		if ( $id <= 0 || '' === $gallery || ! $this->active() || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		$result = $this->plugin()->upload_queue()->assign( $id, $gallery );
		if ( is_wp_error( $result ) ) {
			$post['errors'][ self::FIELD ]['errors'][] = $result->get_error_message();
		}
		return $post;
	}

	/**
	 * Adds one bulk action per linked gallery.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function bulk_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! $this->active() ) {
			return $actions;
		}
		foreach ( $this->plugin()->upload_links()->usable() as $link ) {
			$actions[ self::BULK_PREFIX . $link['gallery_id'] ] = sprintf(
				/* translators: %s: gallery title. */
				__( 'Send to Profotograaf: %s', 'profotograaf' ),
				'' !== $link['title'] ? $link['title'] : $link['gallery_id']
			);
		}
		return $actions;
	}

	/**
	 * Queues the selected files for the chosen gallery.
	 *
	 * @param mixed  $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param mixed  $ids      Selected attachment ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		$redirect = is_string( $redirect ) ? $redirect : '';
		if ( 0 !== strpos( (string) $action, self::BULK_PREFIX ) || ! $this->active() ) {
			return $redirect;
		}
		$gallery = substr( (string) $action, strlen( self::BULK_PREFIX ) );
		$queued  = 0;
		$skipped = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = (int) $id;
			if ( $id > 0 && current_user_can( 'edit_post', $id ) && true === $this->plugin()->upload_queue()->assign( $id, $gallery ) ) {
				++$queued;
			} else {
				++$skipped;
			}
		}
		return add_query_arg(
			array(
				self::NOTICE_ARG       => $queued,
				'profotograaf_skipped' => $skipped,
			),
			$redirect
		);
	}

	/**
	 * Tells the result of a bulk action.
	 */
	public function bulk_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) || ! $this->active() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows two counts, changes nothing.
			return;
		}
		$queued  = absint( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$skipped = isset( $_GET['profotograaf_skipped'] ) ? absint( wp_unslash( $_GET['profotograaf_skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$message = sprintf(
			/* translators: %d: number of files queued. */
			_n( '%d file will be sent to Profotograaf in the background.', '%d files will be sent to Profotograaf in the background.', $queued, 'profotograaf' ),
			$queued
		);
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of files skipped. */
				_n( '%d file was skipped: it is not a supported image, is on Profotograaf already, or you may not edit it.', '%d files were skipped: they are not supported images, are on Profotograaf already, or you may not edit them.', $skipped, 'profotograaf' ),
				$skipped
			);
		}
		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Adds the Profotograaf column to the Media Library list.
	 *
	 * @param mixed $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		if ( $this->active() ) {
			$columns['profotograaf'] = __( 'Profotograaf', 'profotograaf' );
		}
		return $columns;
	}

	/**
	 * Prints the short status of a file in the Profotograaf column.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Attachment id.
	 */
	public function render_column( $column, $id ): void {
		if ( 'profotograaf' !== $column ) {
			return;
		}
		$id    = (int) $id;
		$state = $this->plugin()->upload_queue()->state( $id );
		if ( '' === $state ) {
			if ( '' !== (string) get_post_meta( $id, Photo_Importer::META_PHOTO_ID, true ) ) {
				echo esc_html( Media_Baseline::is_upload( $id ) ? __( 'Sent', 'profotograaf' ) : __( 'Imported', 'profotograaf' ) );
			}
			return;
		}
		$labels = array(
			Upload_Queue::STATE_QUEUED    => __( 'Waiting to be sent', 'profotograaf' ),
			Upload_Queue::STATE_UPLOADING => __( 'Sending', 'profotograaf' ),
			Upload_Queue::STATE_UPLOADED  => __( 'Sent', 'profotograaf' ),
			Upload_Queue::STATE_FAILED    => __( 'Not sent', 'profotograaf' ),
		);
		echo esc_html( $labels[ $state ] ?? '' );
		$again = $this->again_link( $id );
		if ( '' !== $again ) {
			echo '<br />' . $again; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in again_link().
		}
	}

	/**
	 * The "Upload again" link of a failed file that may be on Profotograaf
	 * already, followed by a line break. Empty for any other file.
	 *
	 * @param int $id Attachment id.
	 */
	private function again_link( int $id ): string {
		if ( ! $this->plugin()->upload_queue()->maybe_sent( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return '';
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::AGAIN,
					'attachment' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::AGAIN . '_' . $id
		);
		return sprintf( '<a class="button button-small profotograaf-upload__again" href="%1$s">%2$s</a><br />', esc_url( $url ), esc_html__( 'Upload again', 'profotograaf' ) );
	}

	/**
	 * Handles the "Upload again" link: queues the file for its gallery and
	 * goes back to the page the link was on.
	 */
	public function handle_again(): void {
		$id = isset( $_GET['attachment'] ) ? absint( wp_unslash( $_GET['attachment'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below with the id in the action.
		if ( $id <= 0 || ! $this->active() || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		} else {
			check_admin_referer( self::AGAIN . '_' . $id );
			$this->queue_again( $id );
		}
	}

	/**
	 * Queues the file again and goes back, or stops with the reason it cannot.
	 *
	 * @param int $id Attachment id.
	 */
	private function queue_again( int $id ): void {
		$result = $this->plugin()->upload_queue()->upload_again( $id );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				'',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		} else {
			$back = wp_get_referer();
			$this->finish( add_query_arg( array( self::NOTICE_ARG => 1 ), is_string( $back ) && '' !== $back ? $back : admin_url( 'upload.php' ) ) );
		}
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

	/**
	 * The plugin container.
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? Plugin::instance();
	}
}
