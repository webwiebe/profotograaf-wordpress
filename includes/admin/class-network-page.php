<?php
/**
 * Network admin screen: Profotograaf per subsite.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every subsite with its connection state and failed leads, and links to
 * the subsite's own settings page where it connects. Read only: connecting
 * happens per site because each site pairs with its own account.
 */
class Network_Page {

	public const SLUG       = 'profotograaf-network';
	public const CAPABILITY = 'manage_network_options';

	/**
	 * Data source.
	 *
	 * @var Multisite
	 */
	private Multisite $multisite;

	/**
	 * Constructor.
	 *
	 * @param Multisite $multisite Network data.
	 */
	public function __construct( Multisite $multisite ) {
		$this->multisite = $multisite;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the entry under the network Settings menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Profotograaf', 'profotograaf' ),
			__( 'Profotograaf', 'profotograaf' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Label of a connection state.
	 *
	 * @param string $state Connection state, or `inactive`.
	 */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case 'connected':
				return __( 'Connected', 'profotograaf' );
			case 'revoked':
				return __( 'Connection ended', 'profotograaf' );
			case 'inactive':
				return __( 'Plugin not active', 'profotograaf' );
			default:
				return __( 'Not connected', 'profotograaf' );
		}
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only paging.
		$page     = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$overview = $this->multisite->overview( $page );
		$pages    = max( 1, (int) ceil( $overview['total'] / Multisite::PAGE_SIZE ) );
		?>
		<div class="wrap profotograaf-network">
			<h1><?php esc_html_e( 'Profotograaf', 'profotograaf' ); ?></h1>
			<p><?php esc_html_e( 'Each site connects to Profotograaf on its own. Open a site to connect it or to see its failed enquiries.', 'profotograaf' ); ?></p>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'profotograaf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Connection', 'profotograaf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Failed enquiries', 'profotograaf' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $overview['rows'] as $row ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $row['name'] ? $row['name'] : $row['url'] ); ?></strong><br>
							<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['url'] ); ?></a>
							<?php if ( 'inactive' !== $row['state'] ) : ?>
								| <a href="<?php echo esc_url( $row['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'profotograaf' ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::state_label( $row['state'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['failed'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => max( 1, $page ),
								'total'   => $pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
