<?php
/**
 * The backup in the Media Library and in Site Health.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Backup_Eligibility;
use Profotograaf\Backup_Health;
use Profotograaf\Backup_Labels;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the backup state of each file while the backup is switched on
 * (docs/backup-and-pwa.md, "What the photographer sees"):
 *
 * - a Backup column in the Media Library list view,
 * - a Backup line in the attachment details,
 * - the bulk actions "Copy to Profotograaf" and "Do not copy", which
 *   mark files for the scopes `marked` and `used`,
 * - the Site Health tests of Backup_Health.
 *
 * Marking a file only writes its state; the upload worker sends it later in
 * WP-Cron.
 */
class Backup_Admin implements Module {

	public const COLUMN     = 'profotograaf_backup';
	public const MARK       = 'profotograaf_backup_mark';
	public const UNMARK     = 'profotograaf_backup_unmark';
	public const NOTICE_ARG = 'profotograaf_backup_marked';

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Backup state.
	 *
	 * @var Backup_State|null
	 */
	private ?Backup_State $state = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'details_field' ), 30, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Adds the Backup column to the Media Library list.
	 *
	 * @param mixed $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		if ( $this->active() ) {
			$columns[ self::COLUMN ] = __( 'Copy', 'profotograaf' );
		}
		return $columns;
	}

	/**
	 * Prints the backup state of a file, and the reason under it.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Attachment id.
	 */
	public function render_column( $column, $id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$label = Backup_Labels::of( $this->state(), (int) $id );
		echo esc_html( $label['label'] );
		if ( '' !== $label['reason'] ) {
			echo '<br /><span class="description">' . esc_html( $label['reason'] ) . '</span>';
		}
	}

	/**
	 * Adds the Backup line to the attachment details.
	 *
	 * @param mixed    $fields Attachment fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array<string,mixed>
	 */
	public function details_field( $fields, $post ) {
		$fields = is_array( $fields ) ? $fields : array();
		$id     = (int) $post->ID;
		if ( ! $this->active() || ! Backup_Eligibility::supported_type( (string) $post->post_mime_type ) ) {
			return $fields;
		}
		$label = Backup_Labels::of( $this->state(), $id );
		$text  = '' !== $label['label'] ? $label['label'] : __( 'Not copied', 'profotograaf' );
		if ( Backup_State::DONE === $this->state()->state( $id ) ) {
			$at = (int) get_post_meta( $id, Backup_State::META_AT, true );
			/* translators: %s: date the file was copied. */
			$text = sprintf( __( 'Copied to Profotograaf on %s.', 'profotograaf' ), $at > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $at ) : '' );
		}
		$html = esc_html( $text );
		if ( '' !== $label['reason'] ) {
			$html .= '<br /><span class="description">' . esc_html( $label['reason'] ) . '</span>';
		}
		if ( in_array( $this->state()->state( $id ), array( Backup_State::FAILED, Backup_State::NEEDS_CHECK ), true ) && current_user_can( Settings_Page::CAPABILITY ) ) {
			$html .= '<br /><a href="' . esc_url( \Profotograaf\Admin\Backup_Panel::url() ) . '">' . esc_html__( 'Retry it on the Copy to Profotograaf tab', 'profotograaf' ) . '</a>';
		}
		$fields[ self::COLUMN ] = array(
			'label' => __( 'Copy to Profotograaf', 'profotograaf' ),
			'input' => 'html',
			'html'  => '<span class="profotograaf-backup-line">' . $html . '</span>',
		);
		return $fields;
	}

	/**
	 * Adds the bulk actions that mark files, for the scopes that read marks.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function bulk_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( $this->active() && 'all' !== $this->plugin()->settings()->backup_scope() ) {
			$actions[ self::MARK ]   = __( 'Copy to Profotograaf', 'profotograaf' );
			$actions[ self::UNMARK ] = __( 'Do not copy', 'profotograaf' );
		}
		return $actions;
	}

	/**
	 * Marks or unmarks the selected files and applies the backup rules to
	 * each, which queues a marked file or takes an unmarked one out of the
	 * queue. A file that is backed up stays backed up.
	 *
	 * @param mixed  $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param mixed  $ids      Selected attachment ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		$redirect = is_string( $redirect ) ? $redirect : '';
		if ( ! in_array( $action, array( self::MARK, self::UNMARK ), true ) || ! $this->active() ) {
			return $redirect;
		}
		$mark        = self::MARK === $action;
		$eligibility = new Backup_Eligibility( $this->plugin()->settings(), $this->state() );
		$count       = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = (int) $id;
			if ( $id > 0 && current_user_can( 'edit_post', $id ) ) {
				$this->state()->set_marked( $id, $mark );
				$eligibility->apply( $id );
				++$count;
			}
		}
		return add_query_arg( array( self::NOTICE_ARG => ( $mark ? '' : '-' ) . $count ), $redirect );
	}

	/**
	 * Tells the result of a bulk action.
	 */
	public function bulk_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) || ! $this->active() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows a count, changes nothing.
			return;
		}
		$value = sanitize_text_field( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$count = absint( ltrim( $value, '-' ) );
		if ( 0 === strpos( $value, '-' ) ) {
			$message = sprintf(
				/* translators: %d: number of files. */
				_n( '%d file is no longer marked for copying. A file that is copied already stays on Profotograaf.', '%d files are no longer marked for copying. Files that are copied already stay on Profotograaf.', $count, 'profotograaf' ),
				$count
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of files. */
				_n( '%d file is marked for copying. It is copied to Profotograaf in the background.', '%d files are marked for copying. They are copied to Profotograaf in the background.', $count, 'profotograaf' ),
				$count
			);
		}
		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Adds the Site Health tests of the backup.
	 *
	 * @param mixed $tests Tests by type.
	 * @return mixed
	 */
	public function add_tests( $tests ) {
		$plugin = $this->plugin();
		$status = new Backup_Status( $plugin->settings(), $plugin->connection(), $this->state(), $plugin->clock() );
		return ( new Backup_Health( $plugin->settings(), $status ) )->add_tests( $tests );
	}

	/**
	 * Whether the backup is switched on and the user may see the Media Library.
	 */
	private function active(): bool {
		return $this->plugin()->settings()->backup_enabled() && current_user_can( 'upload_files' );
	}

	/**
	 * Backup state, built once.
	 */
	private function state(): Backup_State {
		if ( null === $this->state ) {
			$this->state = new Backup_State( $this->plugin()->clock() );
		}
		return $this->state;
	}

	/**
	 * The plugin container.
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? Plugin::instance();
	}
}
