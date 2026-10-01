<?php
/**
 * Site Health tests and debug information.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Config;
use Profotograaf\Connection;
use Profotograaf\Cron_Health;
use Profotograaf\Embed_Script;
use Profotograaf\Gallery_Rest;
use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Leads\Queue;
use Profotograaf\Logger;
use Profotograaf\Module;
use Profotograaf\Module_Loader;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the connection, token refresh, embed.js, lead backlog and failed leads
 * under Tools, Site Health, and adds a Profotograaf section to the Info tab.
 *
 * The WP-Cron test belongs to Cron_Health (`profotograaf_cron`) and is not
 * repeated here. The debug section holds versions, states and counts, never
 * tokens, the device id or lead contents. Recent errors come from the Logger,
 * which scrubs what it stores.
 */
class Site_Health implements Module {

	public const CONNECTION_TEST = 'profotograaf_connection';
	public const TOKEN_TEST      = 'profotograaf_token';
	public const EMBED_TEST      = 'profotograaf_embed';
	public const BACKLOG_TEST    = 'profotograaf_backlog';
	public const FAILED_TEST     = 'profotograaf_failed_leads';

	/**
	 * Seconds an access token may stay expired before the background refresh counts as stuck.
	 */
	public const TOKEN_STALE_AFTER = 10800;

	/**
	 * Seconds a lead may wait past its due time before the backlog counts as a problem.
	 */
	public const BACKLOG_OVERDUE_AFTER = 3600;

	private const ERRORS_SHOWN = 5;

	/**
	 * Lead queue.
	 *
	 * @var Queue|null
	 */
	private ?Queue $queue;

	/**
	 * The embed.js script.
	 *
	 * @var Embed_Script
	 */
	private Embed_Script $embed;

	/**
	 * Returns the HTTP status of an address, or a WP_Error.
	 *
	 * @var callable
	 */
	private $fetch;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Constructor. Tests pass their own services.
	 *
	 * @param Queue|null        $queue Lead queue, the options table queue by default.
	 * @param Embed_Script|null $embed Embed script.
	 * @param callable|null     $fetch Returns the HTTP status for a URL, or a WP_Error.
	 * @param callable|null     $clock Returns the current unix time.
	 */
	public function __construct( ?Queue $queue = null, ?Embed_Script $embed = null, ?callable $fetch = null, ?callable $clock = null ) {
		$this->queue = $queue;
		$this->embed = $embed ?? new Embed_Script();
		$this->fetch = $fetch ?? static function ( string $url ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => Config::http_timeout(),
					'redirection' => 0,
				)
			);
			return is_wp_error( $response ) ? $response : (int) wp_remote_retrieve_response_code( $response );
		};
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Adds the tests to Site Health.
	 *
	 * @param mixed $tests Tests by type.
	 * @return mixed
	 */
	public function add_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct'][ self::CONNECTION_TEST ] = array(
			'label' => __( 'Profotograaf connection', 'profotograaf' ),
			'test'  => array( $this, 'connection_result' ),
		);
		$tests['direct'][ self::TOKEN_TEST ]      = array(
			'label' => __( 'Profotograaf token refresh', 'profotograaf' ),
			'test'  => array( $this, 'token_result' ),
		);
		$tests['direct'][ self::BACKLOG_TEST ]    = array(
			'label' => __( 'Profotograaf lead queue', 'profotograaf' ),
			'test'  => array( $this, 'backlog_result' ),
		);
		$tests['direct'][ self::FAILED_TEST ]     = array(
			'label' => __( 'Profotograaf failed leads', 'profotograaf' ),
			'test'  => array( $this, 'failed_result' ),
		);
		$tests['async'][ self::EMBED_TEST ]       = array(
			'label'             => __( 'Profotograaf gallery script', 'profotograaf' ),
			'test'              => rest_url( Gallery_Rest::NAMESPACE . '/health/embed' ),
			'has_rest'          => true,
			'async_direct_test' => array( $this, 'embed_result' ),
		);
		return $tests;
	}

	/**
	 * Registers the route the asynchronous embed.js test calls.
	 */
	public function register_routes(): void {
		register_rest_route(
			Gallery_Rest::NAMESPACE,
			'/health/embed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'embed_result' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);
	}

	/**
	 * Whether the user may run Site Health tests.
	 */
	public function can_view(): bool {
		return current_user_can( 'view_site_health_checks' );
	}

	/**
	 * Connection state.
	 *
	 * @return array<string,mixed>
	 */
	public function connection_result(): array {
		$connection = $this->connection();
		$state      = $connection->status()['state'];
		$settings   = '<p><a href="' . esc_url( admin_url( 'options-general.php?page=' . Settings_Page::SLUG ) ) . '">' . esc_html__( 'Open the Profotograaf settings', 'profotograaf' ) . '</a></p>';

		if ( 'connected' === $state && ! $connection->needs_reconnect() ) {
			return $this->result( self::CONNECTION_TEST, 'good', __( 'Profotograaf is connected', 'profotograaf' ), __( 'This site is linked to a Profotograaf account.', 'profotograaf' ) );
		}
		if ( 'connected' === $state ) {
			return $this->result( self::CONNECTION_TEST, 'recommended', __( 'Profotograaf needs to be connected again', 'profotograaf' ), __( 'The stored connection lacks a permission the plugin needs to switch galleries on for embedding.', 'profotograaf' ), $settings . '<p>' . esc_html__( 'Disconnect and connect again on the settings page.', 'profotograaf' ) . '</p>' );
		}
		if ( 'revoked' === $state ) {
			return $this->result( self::CONNECTION_TEST, 'critical', __( 'Profotograaf ended the connection', 'profotograaf' ), __( 'The account ended the connection, so galleries and lead delivery stopped working.', 'profotograaf' ), $settings . '<p>' . esc_html__( 'Connect the site again on the settings page.', 'profotograaf' ) . '</p>' );
		}
		return $this->result( self::CONNECTION_TEST, 'recommended', __( 'Profotograaf is not connected', 'profotograaf' ), __( 'Connect this site to a Profotograaf account to show galleries and deliver leads.', 'profotograaf' ), $settings );
	}

	/**
	 * Whether the background token refresh keeps the access token current.
	 *
	 * @return array<string,mixed>
	 */
	public function token_result(): array {
		$connection = $this->connection();
		$expired_by = $this->now() - $connection->access_expires_at();

		if ( ! $connection->is_connected() || $expired_by <= self::TOKEN_STALE_AFTER ) {
			return $this->result( self::TOKEN_TEST, 'good', __( 'Profotograaf tokens are refreshed', 'profotograaf' ), __( 'The connection token is current, or nothing needs refreshing yet.', 'profotograaf' ) );
		}
		return $this->result(
			self::TOKEN_TEST,
			'recommended',
			__( 'Profotograaf tokens are not refreshed', 'profotograaf' ),
			__( 'The access token expired more than three hours ago, so the hourly background refresh did not run. WP-Cron may be disabled or blocked. The 60 day refresh token lapses when nothing refreshes it.', 'profotograaf' ),
			'<p>' . esc_html__( 'Check the WP-Cron test on this page and fix it first. A visit to a gallery page refreshes the token on demand.', 'profotograaf' ) . '</p>'
		);
	}

	/**
	 * Whether embed.js can be reached. Runs as an asynchronous test so the
	 * Site Health page never waits for the platform.
	 *
	 * @return array<string,mixed>
	 */
	public function embed_result(): array {
		$url    = Config::platform_endpoint( '/share/embed/embed.js' );
		$status = ( $this->fetch )( $url );

		if ( ! is_wp_error( $status ) && 200 === $status ) {
			return $this->result( self::EMBED_TEST, 'good', __( 'The Profotograaf gallery script is reachable', 'profotograaf' ), __( 'This server can load embed.js from the platform.', 'profotograaf' ) );
		}
		return $this->result(
			self::EMBED_TEST,
			'critical',
			__( 'The Profotograaf gallery script cannot be reached', 'profotograaf' ),
			sprintf(
				/* translators: %s: address of embed.js. */
				__( 'Galleries only show their fallback link while %s cannot be loaded.', 'profotograaf' ),
				$url
			),
			'<p>' . esc_html__( 'Check that the platform is online and that no firewall, proxy or Content-Security-Policy script-src rule blocks the address.', 'profotograaf' ) . '</p>'
		);
	}

	/**
	 * Leads that waited past their due time.
	 *
	 * @return array<string,mixed>
	 */
	public function backlog_result(): array {
		$queue   = $this->queue();
		$pending = $queue->counts()['pending'];
		$next    = $queue->next_due_at();

		if ( $pending < 1 || null === $next || $this->now() - $next <= self::BACKLOG_OVERDUE_AFTER ) {
			return $this->result( self::BACKLOG_TEST, 'good', __( 'Profotograaf leads are delivered on time', 'profotograaf' ), __( 'No lead is waiting longer than expected.', 'profotograaf' ) );
		}
		return $this->result(
			self::BACKLOG_TEST,
			'recommended',
			__( 'Profotograaf leads are waiting for delivery', 'profotograaf' ),
			sprintf(
				/* translators: %d: number of leads waiting for delivery. */
				_n( '%d lead is overdue for delivery.', '%d leads are overdue for delivery.', $pending, 'profotograaf' ),
				$pending
			),
			'<p>' . esc_html__( 'Check the WP-Cron test and the gallery script test on this page.', 'profotograaf' ) . '</p>' . $this->leads_link()
		);
	}

	/**
	 * Leads that ran out of attempts.
	 *
	 * @return array<string,mixed>
	 */
	public function failed_result(): array {
		$failed = $this->queue()->counts()['failed'];

		if ( $failed < 1 ) {
			return $this->result( self::FAILED_TEST, 'good', __( 'No Profotograaf leads failed', 'profotograaf' ), __( 'Every lead was delivered or is still being retried.', 'profotograaf' ) );
		}
		return $this->result(
			self::FAILED_TEST,
			'critical',
			__( 'Profotograaf leads failed to deliver', 'profotograaf' ),
			sprintf(
				/* translators: %d: number of failed leads. */
				_n( '%d lead could not be delivered.', '%d leads could not be delivered.', $failed, 'profotograaf' ),
				$failed
			),
			'<p>' . esc_html__( 'Open the leads screen to retry them or export them before they are removed.', 'profotograaf' ) . '</p>' . $this->leads_link()
		);
	}

	/**
	 * Adds the Profotograaf section to the Info tab.
	 *
	 * @param mixed $info Sections by slug.
	 * @return mixed
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		$counts  = $this->queue()->counts();
		$cron    = new Cron_Health( $this->queue() );
		$last    = $cron->last_run();
		$status  = $this->connection()->status();
		$version = $this->embed->version();
		$errors  = array();
		foreach ( array_slice( Logger::entries(), -self::ERRORS_SHOWN ) as $entry ) {
			if ( Logger::ERROR === $entry['level'] || Logger::WARNING === $entry['level'] ) {
				$errors[] = gmdate( 'Y-m-d H:i', $entry['time'] ) . ' ' . $entry['level'] . ' ' . $entry['message'];
			}
		}
		$never = __( 'never', 'profotograaf' );

		$info['profotograaf'] = array(
			'label'  => __( 'Profotograaf', 'profotograaf' ),
			'fields' => array(
				'plugin_version' => $this->field( __( 'Plugin version', 'profotograaf' ), PROFOTOGRAAF_VERSION ),
				'wp_version'     => $this->field( __( 'WordPress version', 'profotograaf' ), (string) get_bloginfo( 'version' ) ),
				'php_version'    => $this->field( __( 'PHP version', 'profotograaf' ), PHP_VERSION ),
				'connection'     => $this->field( __( 'Connection', 'profotograaf' ), $status['state'] ),
				'connected_at'   => $this->field( __( 'Connected since', 'profotograaf' ), $status['connected_at'] > 0 ? gmdate( 'Y-m-d H:i', $status['connected_at'] ) . ' UTC' : $never ),
				'modules'        => $this->field( __( 'Active modules', 'profotograaf' ), implode( ', ', $this->module_names() ) ),
				'leads_pending'  => $this->field( __( 'Leads waiting', 'profotograaf' ), $counts['pending'] ),
				'leads_failed'   => $this->field( __( 'Leads failed', 'profotograaf' ), $counts['failed'] ),
				'embed_version'  => $this->field( __( 'Embed script version', 'profotograaf' ), '' === $version ? __( 'unknown', 'profotograaf' ) : $version ),
				'wp_cron'        => $this->field(
					__( 'WP-Cron', 'profotograaf' ),
					( $cron->is_disabled() ? __( 'disabled', 'profotograaf' ) : __( 'enabled', 'profotograaf' ) )
						. ', ' . __( 'last delivery run', 'profotograaf' ) . ': ' . ( null === $last ? $never : gmdate( 'Y-m-d H:i', $last ) . ' UTC' )
				),
				'recent_errors'  => $this->field( __( 'Recent errors', 'profotograaf' ), array() === $errors ? __( 'none', 'profotograaf' ) : implode( "\n", $errors ) ),
			),
		);
		return $info;
	}

	/**
	 * One debug field.
	 *
	 * @param string     $label Label.
	 * @param string|int $value Value.
	 * @return array{label:string,value:string|int}
	 */
	private function field( string $label, $value ): array {
		return array(
			'label' => $label,
			'value' => $value,
		);
	}

	/**
	 * Short names of the registered modules.
	 *
	 * @return array<int,string>
	 */
	private function module_names(): array {
		$names = array();
		foreach ( ( new Module_Loader( PROFOTOGRAAF_DIR . 'includes/modules' ) )->classes() as $class ) {
			$parts   = explode( '\\', $class );
			$names[] = (string) end( $parts );
		}
		return $names;
	}

	/**
	 * A Site Health result array.
	 *
	 * @param string $test        Test id.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Title.
	 * @param string $description Plain text explanation.
	 * @param string $actions     HTML for what to do, already escaped.
	 * @return array<string,mixed>
	 */
	private function result( string $test, string $status, string $label, string $description, string $actions = '' ): array {
		$colors = array(
			'good'        => 'blue',
			'recommended' => 'orange',
			'critical'    => 'red',
		);
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Profotograaf', 'profotograaf' ),
				'color' => $colors[ $status ],
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}

	/**
	 * Link to the leads screen.
	 */
	private function leads_link(): string {
		return '<p><a href="' . esc_url( admin_url( 'options-general.php?page=profotograaf-leads' ) ) . '">' . esc_html__( 'Open the leads screen', 'profotograaf' ) . '</a></p>';
	}

	/**
	 * Connection state.
	 */
	private function connection(): Connection {
		return null === $this->plugin ? new Connection() : $this->plugin->connection();
	}

	/**
	 * Lead queue.
	 */
	private function queue(): Queue {
		if ( null === $this->queue ) {
			$this->queue = new Queue( new Option_Job_Store() );
		}
		return $this->queue;
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
