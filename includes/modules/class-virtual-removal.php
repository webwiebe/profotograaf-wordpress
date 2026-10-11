<?php
/**
 * Before deactivation and uninstall: offers to convert every photo kept on Profotograaf to local (issue #158).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Cli\Virtual_Command;
use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Virtual_Bulk_Convert;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a row under the plugin on the Plugins screen while virtual attachments
 * exist. It says they lose their image once the plugin is deactivated or
 * deleted, and offers "Convert all to local", which starts the background job
 * of Virtual_Bulk_Convert. The same row shows the progress and the result.
 * Also registers `wp profotograaf virtual convert` for large libraries.
 *
 * Like Virtual_Convert it registers whatever the setting "Keep photos on
 * Profotograaf" says, because attachments kept while it was on still need the
 * conversion after it was switched off (docs/virtual-attachments.md,
 * "Deactivation and uninstall").
 */
class Virtual_Removal implements Module {

	public const ACTION = 'profotograaf_convert_all';

	/**
	 * The bulk job.
	 *
	 * @var Virtual_Bulk_Convert|null
	 */
	private ?Virtual_Bulk_Convert $job = null;

	/**
	 * Constructor.
	 *
	 * @param Virtual_Bulk_Convert|null $job Bulk job, built on demand when null.
	 */
	public function __construct( ?Virtual_Bulk_Convert $job = null ) {
		$this->job = $job;
	}

	/**
	 * Adds the hooks and the WP-CLI command.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		add_action( 'after_plugin_row_' . plugin_basename( PROFOTOGRAAF_FILE ), array( $this, 'plugin_row' ), 10, 0 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_start' ) );
		add_action( Virtual_Bulk_Convert::HOOK, array( $this, 'run' ) );
		add_action( 'load-plugins.php', array( $this, 'resume' ) );
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'profotograaf virtual', new Virtual_Command( $this->job() ) );
		}
	}

	/**
	 * WP-Cron callback for one batch.
	 */
	public function run(): void {
		$this->job()->run();
	}

	/**
	 * Picks up a running job that lost its event, when the Plugins screen loads.
	 */
	public function resume(): void {
		$this->job()->resume();
	}

	/**
	 * Starts the background conversion from the button in the plugin row.
	 */
	public function handle_start(): void {
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		} else {
			check_admin_referer( self::ACTION );
			$this->job()->start( get_current_user_id() );
			$this->finish( admin_url( 'plugins.php' ) );
		}
	}

	/**
	 * Prints the row under the plugin on the Plugins screen.
	 */
	public function plugin_row(): void {
		if ( is_network_admin() || ! current_user_can( 'upload_files' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$state = $this->job()->state();
		$count = Virtual_Bulk_Convert::count();
		if ( 'running' === $state['status'] ) {
			$html = $this->paragraph(
				sprintf(
					/* translators: 1: photos converted so far, 2: photos that could not be converted, 3: photos still waiting. */
					__( 'Converting photos kept on Profotograaf to local in the background: %1$d converted, %2$d could not be converted, %3$d left. Reload this page to see the progress.', 'profotograaf' ),
					$state['converted'],
					count( $state['failed_ids'] ),
					max( 0, $count - count( $state['failed_ids'] ) )
				)
			);
			$this->print_row( 'notice-info', $html );
			return;
		}
		if ( 0 === $count ) {
			if ( 'done' === $state['status'] ) {
				$this->print_row( 'notice-success', $this->paragraph( __( 'Every photo kept on Profotograaf is converted to local. You can deactivate or delete this plugin and your images stay in place.', 'profotograaf' ) ) );
			}
			return;
		}
		$html = $this->paragraph(
			sprintf(
				/* translators: %d: number of photos. */
				_n(
					'%d photo is kept on Profotograaf and has no copy on this site. It shows no image while this plugin is deactivated or after you delete it. Convert it to local first.',
					'%d photos are kept on Profotograaf and have no copy on this site. They show no image while this plugin is deactivated or after you delete it. Convert them to local first.',
					$count,
					'profotograaf'
				),
				$count
			)
		);
		$html .= $this->last_run( $state );
		// A nonced link: the row sits inside the form of the plugins list, and a form cannot hold another.
		$html .= sprintf(
			'<p><a class="button button-primary profotograaf-convert-all" href="%1$s">%2$s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ),
			esc_html__( 'Convert all to local', 'profotograaf' )
		);
		$html .= $this->paragraph(
			sprintf(
				/* translators: 1: batch size, 2: WP-CLI command. */
				__( 'The conversion runs in the background, %1$d photos at a time. For a large library, or when WP-Cron does not run on this site, run %2$s instead.', 'profotograaf' ),
				Virtual_Bulk_Convert::BATCH,
				'wp profotograaf virtual convert'
			)
		);
		$this->print_row( 'notice-warning', $html );
	}

	/**
	 * What went wrong in the last finished job.
	 *
	 * @param array{status:string,failed_ids:array<int,int>,failures:array<int,array{title:string,message:string}>} $state Job state.
	 */
	private function last_run( array $state ): string {
		if ( 'stopped' === $state['status'] ) {
			return $this->paragraph( __( 'The last conversion stopped because the user who started it may no longer upload files.', 'profotograaf' ) );
		}
		if ( 'done' !== $state['status'] || array() === $state['failed_ids'] ) {
			return '';
		}
		$items = '';
		foreach ( $state['failures'] as $row ) {
			$items .= sprintf( '<li><strong>%1$s</strong>: %2$s</li>', esc_html( $row['title'] ), esc_html( $row['message'] ) );
		}
		$failed = count( $state['failed_ids'] );
		return $this->paragraph(
			sprintf(
				/* translators: %d: number of photos. */
				_n( '%d photo could not be converted in the last run:', '%d photos could not be converted in the last run:', $failed, 'profotograaf' ),
				$failed
			)
		) . '<ul>' . $items . '</ul>';
	}

	/**
	 * One escaped paragraph.
	 *
	 * @param string $text Plain text.
	 */
	private function paragraph( string $text ): string {
		return '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Prints a plugin row in the style of the update notice.
	 *
	 * @param string $type Notice class.
	 * @param string $html Escaped content.
	 */
	private function print_row( string $type, string $html ): void {
		$table   = $GLOBALS['wp_list_table'] ?? null;
		$columns = is_object( $table ) && method_exists( $table, 'get_column_count' ) ? (int) $table->get_column_count() : 4;
		printf(
			'<tr class="plugin-update-tr active profotograaf-virtual-row"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-alt %2$s">%3$s</div></td></tr>',
			(int) $columns,
			esc_attr( $type ),
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
		);
	}

	/**
	 * The bulk job, built on first use.
	 */
	private function job(): Virtual_Bulk_Convert {
		if ( null === $this->job ) {
			$this->job = new Virtual_Bulk_Convert();
		}
		return $this->job;
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
