<?php
/**
 * Settings > Profotograaf, the "Send files" panel.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Modules\Settings_Page;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the owner link galleries for the Media Library upload: create one, use
 * an existing one, unlink one and switch automatic sending on for one. Shown
 * on the General tab while "Send Media Library files to Profotograaf" is on
 * and the site is connected. Every action is an admin-post handler that
 * checks manage_options and a nonce.
 */
class Upload_Panel {

	public const CAPABILITY = 'manage_options';

	/**
	 * Transient that holds the gallery choices for a few minutes.
	 */
	public const CHOICES = 'profotograaf_upload_choices';

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
	 * Adds the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_profotograaf_upload_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_profotograaf_upload_link', array( $this, 'handle_link' ) );
		add_action( 'admin_post_profotograaf_upload_unlink', array( $this, 'handle_unlink' ) );
		add_action( 'admin_post_profotograaf_upload_auto', array( $this, 'handle_auto' ) );
	}

	/**
	 * Creates a gallery on Profotograaf and links it.
	 */
	public function handle_create(): void {
		$this->authorize( 'profotograaf_upload_create' );
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
		$gallery = $this->plugin->api()->create_gallery( $title );
		if ( is_wp_error( $gallery ) ) {
			$this->notice( 'error', $gallery->get_error_message() );
		} else {
			$this->plugin->upload_links()->add( $gallery['id'], $gallery['title'], get_current_user_id(), time() );
			delete_transient( self::CHOICES );
			/* translators: %s: gallery title. */
			$this->notice( 'success', sprintf( __( 'Created the gallery "%s" on Profotograaf and linked it.', 'profotograaf' ), $gallery['title'] ) );
		}
		$this->finish();
	}

	/**
	 * Links an existing gallery.
	 */
	public function handle_link(): void {
		$this->authorize( 'profotograaf_upload_link' );
		$id = isset( $_POST['gallery_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gallery_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
		if ( empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
			$this->notice( 'error', __( 'Tick the box to confirm that files you send land in this gallery.', 'profotograaf' ) );
			$this->finish();
			return;
		}
		$title = null;
		foreach ( $this->choices() as $choice ) {
			if ( $choice['id'] === $id ) {
				$title = $choice['title'];
			}
		}
		if ( null === $title ) {
			$this->notice( 'error', __( 'Choose one of your Profotograaf galleries.', 'profotograaf' ) );
		} else {
			$this->plugin->upload_links()->add( $id, $title, get_current_user_id(), time() );
			/* translators: %s: gallery title. */
			$this->notice( 'success', sprintf( __( 'Linked the gallery "%s".', 'profotograaf' ), $title ) );
		}
		$this->finish();
	}

	/**
	 * Unlinks a gallery. The gallery and its photos stay on Profotograaf.
	 */
	public function handle_unlink(): void {
		$this->authorize( 'profotograaf_upload_unlink' );
		$id = isset( $_POST['gallery_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gallery_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
		$this->plugin->upload_links()->remove( $id );
		$this->notice( 'success', __( 'Unlinked the gallery. It and its photos stay on Profotograaf.', 'profotograaf' ) );
		$this->finish();
	}

	/**
	 * Switches automatic sending of new image uploads on or off for a link.
	 */
	public function handle_auto(): void {
		$this->authorize( 'profotograaf_upload_auto' );
		$id   = isset( $_POST['gallery_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gallery_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
		$auto = ! empty( $_POST['auto'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
		if ( $this->plugin->upload_links()->set_auto( $id, $auto ) ) {
			$this->notice(
				'success',
				$auto
					? __( 'New image uploads now go to this gallery automatically.', 'profotograaf' )
					: __( 'New uploads are no longer sent automatically.', 'profotograaf' )
			);
		}
		$this->finish();
	}

	/**
	 * Prints the panel.
	 */
	public function render(): void {
		$settings = $this->plugin->settings();
		if ( ! $settings->media_upload_enabled() || ! $this->plugin->connection()->is_connected() ) {
			return;
		}
		$links  = $this->plugin->upload_links()->all();
		$counts = $this->plugin->upload_queue()->counts();
		?>
		<h2><?php esc_html_e( 'Send files to Profotograaf', 'profotograaf' ); ?></h2>
		<p><?php esc_html_e( 'A file is sent only after a person chooses a linked gallery for it. Link the galleries here.', 'profotograaf' ); ?></p>
		<?php if ( $this->plugin->connection()->needs_write_reconnect( true ) ) : ?>
			<p class="description"><?php esc_html_e( 'Sending files needs a new permission. Connect this site again to grant it. Files wait until then.', 'profotograaf' ); ?></p>
		<?php endif; ?>
		<?php if ( $counts['queued'] + $counts['uploading'] + $counts['failed'] > 0 ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of files waiting to be sent, 2: number of files that failed. */
						__( '%1$d waiting to be sent, %2$d failed.', 'profotograaf' ),
						$counts['queued'] + $counts['uploading'],
						$counts['failed']
					)
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( array() !== $links ) : ?>
			<table class="widefat striped profotograaf-upload-links">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Gallery', 'profotograaf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'New uploads', 'profotograaf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Link', 'profotograaf' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td>
							<?php echo esc_html( '' !== $link['title'] ? $link['title'] : $link['gallery_id'] ); ?>
							<?php if ( $link['disabled'] ) : ?>
								<br /><span class="description"><?php esc_html_e( 'Profotograaf no longer knows this gallery. Files cannot be sent to it.', 'profotograaf' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! $link['disabled'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="profotograaf_upload_auto" />
									<input type="hidden" name="gallery_id" value="<?php echo esc_attr( $link['gallery_id'] ); ?>" />
									<input type="hidden" name="auto" value="<?php echo $link['auto'] ? '0' : '1'; ?>" />
									<?php wp_nonce_field( 'profotograaf_upload_auto' ); ?>
									<?php if ( $link['auto'] ) : ?>
										<?php esc_html_e( 'Sent automatically.', 'profotograaf' ); ?>
										<?php submit_button( __( 'Stop sending automatically', 'profotograaf' ), 'secondary small', 'submit', false ); ?>
									<?php else : ?>
										<?php submit_button( __( 'Send new image uploads automatically', 'profotograaf' ), 'secondary small', 'submit', false ); ?>
									<?php endif; ?>
								</form>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="profotograaf_upload_unlink" />
								<input type="hidden" name="gallery_id" value="<?php echo esc_attr( $link['gallery_id'] ); ?>" />
								<?php wp_nonce_field( 'profotograaf_upload_unlink' ); ?>
								<?php submit_button( __( 'Unlink', 'profotograaf' ), 'secondary small', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Create a gallery', 'profotograaf' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="profotograaf-action">
			<input type="hidden" name="action" value="profotograaf_upload_create" />
			<?php wp_nonce_field( 'profotograaf_upload_create' ); ?>
			<label for="profotograaf-upload-title"><?php esc_html_e( 'Title', 'profotograaf' ); ?></label>
			<input type="text" id="profotograaf-upload-title" name="title" class="regular-text" maxlength="200" required />
			<?php submit_button( __( 'Create and link', 'profotograaf' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Use an existing gallery', 'profotograaf' ); ?></h3>
		<?php $this->render_existing_form(); ?>
		<?php
	}

	/**
	 * The form that links an existing gallery.
	 */
	private function render_existing_form(): void {
		$choices = $this->choices();
		if ( array() === $choices ) {
			echo '<p class="description">' . esc_html__( 'No galleries were found in your Profotograaf account.', 'profotograaf' ) . '</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="profotograaf-action">
			<input type="hidden" name="action" value="profotograaf_upload_link" />
			<?php wp_nonce_field( 'profotograaf_upload_link' ); ?>
			<label for="profotograaf-upload-gallery"><?php esc_html_e( 'Gallery', 'profotograaf' ); ?></label>
			<select id="profotograaf-upload-gallery" name="gallery_id">
				<?php foreach ( $choices as $choice ) : ?>
					<option value="<?php echo esc_attr( $choice['id'] ); ?>"><?php echo esc_html( $choice['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<p>
				<label>
					<input type="checkbox" name="confirm" value="1" />
					<?php esc_html_e( 'Files I send land in this gallery, including a client gallery.', 'profotograaf' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Link this gallery', 'profotograaf' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * The galleries of the account, kept for five minutes.
	 *
	 * @return array<int,array{id:string,title:string}>
	 */
	private function choices(): array {
		$cached = get_transient( self::CHOICES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rows = $this->plugin->api()->list_galleries();
		if ( is_wp_error( $rows ) ) {
			return array();
		}
		$choices = array();
		foreach ( $rows as $row ) {
			$choices[] = array(
				'id'    => $row['id'],
				'title' => '' !== $row['title'] ? $row['title'] : $row['id'],
			);
		}
		set_transient( self::CHOICES, $choices, 5 * MINUTE_IN_SECONDS );
		return $choices;
	}

	/**
	 * Leaves a message for the settings page.
	 *
	 * @param string $type    success or error.
	 * @param string $message Text.
	 */
	private function notice( string $type, string $message ): void {
		set_transient(
			'profotograaf_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Requires the capability, the setting and a valid nonce, or stops the request.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
		if ( ! $this->plugin->settings()->media_upload_enabled() ) {
			wp_die( esc_html__( 'Sending files to Profotograaf is switched off in the plugin settings.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Sends the browser back to the settings page.
	 */
	protected function finish(): void {
		wp_safe_redirect( Settings_Page::url() );
		exit;
	}
}
