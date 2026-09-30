<?php
/**
 * Settings > Profotograaf leads.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Leads\Bridge;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\Mapping;
use Profotograaf\Leads\Queue;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Per form switches and field mapping for the lead bridges.
 *
 * Both actions (save and retry) are admin-post handlers that check the
 * manage_options capability and a nonce before they change anything.
 */
class Leads_Settings {

	public const SLUG       = 'profotograaf-leads';
	public const CAPABILITY = 'manage_options';

	/**
	 * Per form settings.
	 *
	 * @var Form_Settings
	 */
	private Form_Settings $settings;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Bridges.
	 *
	 * @var Bridge[]
	 */
	private array $bridges;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Form_Settings $settings Per form settings.
	 * @param Queue         $queue    Queue.
	 * @param Bridge[]      $bridges  Bridges.
	 * @param Plugin        $plugin   Plugin container.
	 */
	public function __construct( Form_Settings $settings, Queue $queue, array $bridges, Plugin $plugin ) {
		$this->settings = $settings;
		$this->queue    = $queue;
		$this->bridges  = $bridges;
		$this->plugin   = $plugin;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_profotograaf_leads_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_profotograaf_leads_retry', array( $this, 'handle_retry' ) );
	}

	/**
	 * URL of the page.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Adds the menu entry under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Profotograaf enquiries', 'profotograaf' ),
			__( 'Enquiry forms', 'profotograaf' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the page's styles and script on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'profotograaf-leads', PROFOTOGRAAF_URL . 'assets/admin/leads/leads.css', array(), PROFOTOGRAAF_VERSION );
		wp_enqueue_script( 'profotograaf-leads', PROFOTOGRAAF_URL . 'assets/admin/leads/leads.js', array(), PROFOTOGRAAF_VERSION, true );
	}

	/**
	 * Saves the switches and mappings.
	 */
	public function handle_save(): void {
		$this->authorize( 'profotograaf_leads_save' );

		$known = array();
		foreach ( $this->forms() as $entry ) {
			$known[] = $entry['form']['key'];
		}
		$raw = isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ? wp_unslash( $_POST['forms'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in authorize(); Form_Settings::save() keeps only known keys and validated values.
		$this->settings->save( $raw, $known );

		$this->finish( 'saved' );
	}

	/**
	 * Requeues the leads that failed.
	 */
	public function handle_retry(): void {
		$this->authorize( 'profotograaf_leads_retry' );

		if ( $this->queue->retry_failed() > 0 ) {
			Delivery::schedule( $this->queue->now() );
		}
		$this->finish( 'retried' );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		$forms  = $this->forms();
		$counts = $this->queue->counts();
		?>
		<div class="wrap profotograaf-leads">
			<h1><?php esc_html_e( 'Profotograaf enquiries', 'profotograaf' ); ?></h1>
			<?php $this->render_notice(); ?>

			<p><?php esc_html_e( 'Send the enquiries from your contact forms to your Profotograaf inbox. Switch a form on and check which field feeds which part of the enquiry. Nothing is sent for a form you leave off.', 'profotograaf' ); ?></p>

			<?php if ( ! $this->plugin->connection()->is_connected() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'This site is not connected to Profotograaf, so enquiries wait in a queue.', 'profotograaf' ); ?>
						<a href="<?php echo esc_url( Settings_Page::url() ); ?>"><?php esc_html_e( 'Connect this site', 'profotograaf' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( array() === $forms ) : ?>
				<p><?php esc_html_e( 'No supported form plugin was found. Install Contact Form 7, WPForms or Gravity Forms and create a form.', 'profotograaf' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="profotograaf_leads_save">
					<?php wp_nonce_field( 'profotograaf_leads_save' ); ?>
					<?php foreach ( $forms as $entry ) : ?>
						<?php $this->render_form( $entry['bridge'], $entry['form'] ); ?>
					<?php endforeach; ?>
					<?php submit_button( __( 'Save changes', 'profotograaf' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Delivery', 'profotograaf' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of enquiries waiting to be sent, 2: number that could not be sent. */
						__( 'Waiting to be sent: %1$d. Could not be sent: %2$d.', 'profotograaf' ),
						$counts['pending'],
						$counts['failed']
					)
				);
				?>
			</p>
			<?php $this->render_failures( $counts['failed'] ); ?>
		</div>
		<?php
	}

	/**
	 * Renders one form's switch and mapping.
	 *
	 * @param Bridge                                                                                     $bridge Bridge.
	 * @param array{key:string,title:string,fields:array<int,array{id:string,label:string,type:string}>} $form   Form.
	 */
	private function render_form( Bridge $bridge, array $form ): void {
		$config   = $this->settings->get( $form['key'] );
		$detected = Mapping::detect( $form['fields'] );
		$labels   = array_column( $form['fields'], 'label', 'id' );
		$base     = 'forms[' . $form['key'] . ']';
		$slug     = sanitize_html_class( str_replace( ':', '-', $form['key'] ) );
		$targets  = array(
			'name'       => __( 'Name', 'profotograaf' ),
			'email'      => __( 'Email address', 'profotograaf' ),
			'phone'      => __( 'Phone', 'profotograaf' ),
			'event_date' => __( 'Event date', 'profotograaf' ),
			'message'    => __( 'Message', 'profotograaf' ),
		);
		?>
		<fieldset class="profotograaf-leads-form" data-profotograaf-form>
			<legend>
				<?php echo esc_html( $bridge->label() . ': ' . $form['title'] ); ?>
			</legend>
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" data-profotograaf-toggle <?php checked( $config['enabled'] ); ?>>
					<?php esc_html_e( 'Send submissions of this form to Profotograaf', 'profotograaf' ); ?>
				</label>
			</p>
			<table class="profotograaf-leads-map" data-profotograaf-map>
				<?php foreach ( $targets as $target => $title ) : ?>
					<?php
					$choice  = $config['map'][ $target ] ?? '';
					$field   = 'profotograaf-' . $slug . '-' . $target;
					$guessed = isset( $detected[ $target ] ) ? ( $labels[ $detected[ $target ] ] ?? '' ) : '';
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $title ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $base . '[map][' . $target . ']' ); ?>">
								<option value="" <?php selected( $choice, '' ); ?>>
									<?php
									echo esc_html(
										'' !== $guessed
											/* translators: %s: name of the form field that is picked automatically. */
											? sprintf( __( 'Automatic (%s)', 'profotograaf' ), $guessed )
											: __( 'Automatic (no field found)', 'profotograaf' )
									);
									?>
								</option>
								<option value="<?php echo esc_attr( Mapping::NONE ); ?>" <?php selected( $choice, Mapping::NONE ); ?>><?php esc_html_e( 'Do not send', 'profotograaf' ); ?></option>
								<?php foreach ( $form['fields'] as $option ) : ?>
									<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( $choice, $option['id'] ); ?>><?php echo esc_html( $option['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="description"><?php esc_html_e( 'Every other field is sent along as a label and value.', 'profotograaf' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Lists why leads failed, with a retry button.
	 *
	 * @param int $failed Number of failed leads.
	 */
	private function render_failures( int $failed ): void {
		if ( $failed < 1 ) {
			return;
		}
		?>
		<ul class="profotograaf-leads-failures">
			<?php foreach ( $this->queue->failures() as $failure ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: form name, 2: error message. */
							__( '%1$s: %2$s', 'profotograaf' ),
							$failure['form'],
							$failure['error']
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="profotograaf_leads_retry">
			<?php wp_nonce_field( 'profotograaf_leads_retry' ); ?>
			<?php submit_button( __( 'Try again', 'profotograaf' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Shows the result of the last action.
	 */
	private function render_notice(): void {
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which notice to show.
		if ( 'saved' === $done ) {
			$message = __( 'Changes saved.', 'profotograaf' );
		} elseif ( 'retried' === $done ) {
			$message = __( 'The enquiries are queued again.', 'profotograaf' );
		} else {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php
	}

	/**
	 * Forms of every active bridge.
	 *
	 * @return array<int,array{bridge:Bridge,form:array{key:string,title:string,fields:array<int,array{id:string,label:string,type:string}>}}>
	 */
	private function forms(): array {
		$entries = array();
		foreach ( $this->bridges as $bridge ) {
			if ( ! $bridge->is_active() ) {
				continue;
			}
			foreach ( $bridge->forms() as $form ) {
				$entries[] = array(
					'bridge' => $bridge,
					'form'   => $form,
				);
			}
		}
		return $entries;
	}

	/**
	 * Stops unless the user may manage options and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Sends the browser back to the page.
	 *
	 * @param string $done Which notice to show.
	 */
	protected function finish( string $done ): void {
		wp_safe_redirect( add_query_arg( 'done', $done, self::url() ) );
		exit;
	}
}
