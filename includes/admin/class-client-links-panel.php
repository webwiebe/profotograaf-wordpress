<?php
/**
 * Settings > Profotograaf, Galleries tab, the "Client galleries" list.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Gallery_Index;
use Profotograaf\Plugin;
use Profotograaf\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the galleries the plugin has stored (Gallery_Index) with their share
 * link, a copy button and a QR code, so the photographer can send a client
 * the link. Reads the stored list only and makes no request to Profotograaf.
 *
 * Whether a share page offers to install the gallery as an app depends on its
 * mode and password, which the stored list does not hold. The panel states the
 * rule and says nothing about the state of a single gallery.
 *
 * The copy button and the QR code come from build/client-links/index.js. The
 * QR code is drawn in the browser by a bundled encoder, with no request to any
 * other service.
 */
class Client_Links_Panel {

	public const CAPABILITY = 'manage_options';

	public const HANDLE = 'profotograaf-client-links';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Loads the copy and QR code script on the Galleries tab.
	 *
	 * @param string $tab Tab shown now.
	 */
	public function enqueue( string $tab ): void {
		if ( Settings_Schema::TAB_GALLERIES !== $tab ) {
			return;
		}
		$asset_file = $this->build_directory() . 'index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$asset = is_array( $asset ) ? $asset : array();
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : PROFOTOGRAAF_VERSION;
		wp_enqueue_script( self::HANDLE, PROFOTOGRAAF_URL . 'build/client-links/index.js', $deps, $ver, true );
		wp_set_script_translations( self::HANDLE, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
	}

	/**
	 * Prints the list on the Galleries tab.
	 *
	 * @param string $tab Tab shown now.
	 */
	public function render( string $tab ): void {
		if ( Settings_Schema::TAB_GALLERIES !== $tab || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$galleries = ( new Gallery_Index( $this->plugin->api() ) )->all();
		?>
		<h2 id="profotograaf-client-links"><?php esc_html_e( 'Client galleries', 'profotograaf' ); ?></h2>
		<p><?php esc_html_e( 'Send a client the share link of their gallery, or let them scan the QR code with a phone.', 'profotograaf' ); ?></p>
		<p class="description"><?php esc_html_e( 'On a phone, the share page offers to install the gallery as an app when the gallery is delivered and has no password. This list does not show which galleries meet both conditions.', 'profotograaf' ); ?></p>
		<?php if ( array() === $galleries ) : ?>
			<p class="profotograaf-client-links__empty"><?php esc_html_e( 'No galleries are stored on this site yet. The list fills in after you choose a gallery in a Profotograaf block in the editor.', 'profotograaf' ); ?></p>
		<?php else : ?>
		<p class="description"><?php esc_html_e( 'The list shows your galleries as this site last stored them.', 'profotograaf' ); ?></p>
		<table class="widefat striped profotograaf-client-links">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Gallery', 'profotograaf' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Share link', 'profotograaf' ); ?></th>
					<th scope="col"><?php esc_html_e( 'QR code', 'profotograaf' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $galleries as $i => $gallery ) : ?>
				<?php
				$title = '' !== $gallery['title'] ? $gallery['title'] : $gallery['id'];
				$field = 'profotograaf-client-link-' . $i;
				?>
				<tr class="profotograaf-client-link" data-url="<?php echo esc_url( $gallery['url'] ); ?>">
					<th scope="row"><?php echo esc_html( $title ); ?></th>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $field ); ?>">
							<?php
							/* translators: %s: gallery title. */
							echo esc_html( sprintf( __( 'Share link of %s', 'profotograaf' ), $title ) );
							?>
						</label>
						<input type="text" id="<?php echo esc_attr( $field ); ?>" class="large-text code profotograaf-client-link__url" value="<?php echo esc_url( $gallery['url'] ); ?>" readonly />
						<p>
							<button type="button" class="button profotograaf-client-link__copy" hidden><?php esc_html_e( 'Copy link', 'profotograaf' ); ?></button>
							<span class="profotograaf-client-link__status" role="status"></span>
						</p>
					</td>
					<td>
						<?php
						/* translators: %s: gallery title. */
						$qr_label = sprintf( __( 'QR code with the share link of %s', 'profotograaf' ), $title );
						?>
						<div class="profotograaf-client-link__qr" data-label="<?php echo esc_attr( $qr_label ); ?>"></div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Absolute path of the built script's directory, with a trailing slash.
	 */
	protected function build_directory(): string {
		return PROFOTOGRAAF_DIR . 'build/client-links/';
	}
}
