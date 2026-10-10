<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin and hands out the shared services.
 *
 * Features are modules (see Module and Module_Loader). Nothing else here needs
 * editing to add one.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Stored connection state.
	 *
	 * @var Connection|null
	 */
	private ?Connection $connection = null;

	/**
	 * API client.
	 *
	 * @var Api_Client|null
	 */
	private ?Api_Client $api = null;

	/**
	 * Pairing flow.
	 *
	 * @var Pairing|null
	 */
	private ?Pairing $pairing = null;

	/**
	 * Platform status call.
	 *
	 * @var Platform_Status|null
	 */
	private ?Platform_Status $platform_status = null;

	/**
	 * Clock override for tests.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Constructor. Tests pass their own services.
	 *
	 * @param Connection|null $connection Connection state.
	 * @param Api_Client|null $api        API client.
	 * @param callable|null   $clock      Returns the current unix time.
	 */
	public function __construct( ?Connection $connection = null, ?Api_Client $api = null, ?callable $clock = null ) {
		$this->connection = $connection;
		$this->api        = $api;
		$this->clock      = $clock;
	}

	/**
	 * The shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the modules once all plugins are loaded.
	 */
	public function boot(): void {
		add_action( 'plugins_loaded', array( $this, 'load_modules' ) );
		register_activation_hook( PROFOTOGRAAF_FILE, array( self::class, 'activate' ) );
		if ( is_multisite() ) {
			( new Multisite() )->register();
		}
	}

	/**
	 * Instantiates and registers every module.
	 */
	public function load_modules(): void {
		$loader = new Module_Loader( PROFOTOGRAAF_DIR . 'includes/modules' );
		foreach ( $loader->modules() as $module ) {
			$module->register( $this );
		}

		/**
		 * Fires after every module registered.
		 *
		 * @param Plugin $plugin The plugin.
		 */
		do_action( 'profotograaf_loaded', $this );
	}

	/**
	 * Connection state.
	 */
	public function connection(): Connection {
		if ( null === $this->connection ) {
			$this->connection = new Connection();
		}
		return $this->connection;
	}

	/**
	 * Authenticated API client. Use this for every call to the platform.
	 */
	public function api(): Api_Client {
		if ( null === $this->api ) {
			$this->api = new Api_Client( $this->connection(), null, $this->clock );
		}
		return $this->api;
	}

	/**
	 * Pairing flow.
	 */
	public function pairing(): Pairing {
		if ( null === $this->pairing ) {
			$this->pairing = new Pairing( $this->connection(), $this->api(), $this->clock );
		}
		return $this->pairing;
	}

	/**
	 * Platform status call and its stored answer.
	 */
	public function platform_status(): Platform_Status {
		if ( null === $this->platform_status ) {
			$this->platform_status = new Platform_Status( $this->api(), $this->clock );
		}
		return $this->platform_status;
	}

	/**
	 * Settings.
	 */
	public function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
		return $this->settings;
	}

	/**
	 * Activation: gives the site, or every subsite on network activation, its
	 * defaults and schedule. The site stays disconnected until it pairs.
	 *
	 * @param bool $network_wide Whether the plugin was activated for the whole network.
	 */
	public static function activate( $network_wide = false ): void {
		( new Multisite() )->activate( (bool) $network_wide );
	}

	/**
	 * Deactivation: stops background work on this site, or on every subsite
	 * when the whole network is deactivated. Options stay until uninstall.
	 *
	 * @param bool $network_wide Whether the plugin was deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ): void {
		( new Multisite() )->deactivate( (bool) $network_wide );
	}
}
