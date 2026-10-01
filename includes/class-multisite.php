<?php
/**
 * Multisite handling: network activation, new sites, deactivation and the network overview data.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Admin\Network_Page;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Job_Store;
use Profotograaf\Leads\Option_Job_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Options and cron events belong to one site, so each subsite connects to
 * Profotograaf on its own. This class puts every subsite in a usable but
 * disconnected state on network activation and on creation, clears the cron
 * events of every subsite on deactivation, and reports each subsite's
 * connection state and failed leads to the network admin screen.
 */
class Multisite {

	public const PAGE_SIZE = 50;

	/**
	 * Lead job storage of the current site.
	 *
	 * @var Job_Store
	 */
	private Job_Store $jobs;

	/**
	 * Constructor. Tests pass their own job store.
	 *
	 * @param Job_Store|null $jobs Lead job storage, the options table by default.
	 */
	public function __construct( ?Job_Store $jobs = null ) {
		$this->jobs = $jobs ?? new Option_Job_Store();
	}

	/**
	 * Adds the hooks. Call on a multisite install only.
	 */
	public function register(): void {
		add_action( 'wp_initialize_site', array( $this, 'initialize_site' ), 900 );
		( new Network_Page( $this ) )->register();
	}

	/**
	 * Activation: prepares this site, or every subsite on network activation.
	 *
	 * @param bool $network_wide Whether the plugin was activated for the whole network.
	 */
	public function activate( bool $network_wide ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( $this->site_ids() as $site_id ) {
				$this->on_site( $site_id, array( $this, 'prepare_site' ) );
			}
			return;
		}
		$this->prepare_site();
	}

	/**
	 * Deactivation: stops the background work of this site, or of every
	 * subsite on network deactivation. Options stay until uninstall.
	 *
	 * @param bool $network_wide Whether the plugin was deactivated for the whole network.
	 */
	public function deactivate( bool $network_wide ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( $this->site_ids() as $site_id ) {
				$this->on_site( $site_id, array( $this, 'clear_crons' ) );
			}
			return;
		}
		$this->clear_crons();
	}

	/**
	 * Prepares a site created while the plugin is network active.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public function initialize_site( $site ): void {
		if ( ! $this->network_active() ) {
			return;
		}
		$this->on_site( (int) $site->blog_id, array( $this, 'prepare_site' ) );
	}

	/**
	 * Gives the current site its settings defaults and its recurring lead
	 * sweep. The connection stays empty: every site pairs for itself.
	 */
	public function prepare_site(): void {
		add_option( Settings::OPTION, Settings_Schema::defaults() );
		add_option( Settings::VERSION_OPTION, Settings_Schema::VERSION, '', false );
		if ( ! wp_next_scheduled( Delivery::SWEEP ) ) {
			wp_schedule_event( time() + 300, 'hourly', Delivery::SWEEP );
		}
	}

	/**
	 * Removes every scheduled event of the plugin from the current site.
	 */
	public function clear_crons(): void {
		wp_clear_scheduled_hook( Modules\Token_Refresh::HOOK );
		$cron = _get_cron_array();
		foreach ( (array) $cron as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'profotograaf_' ) ) {
					wp_clear_scheduled_hook( (string) $hook );
				}
			}
		}
	}

	/**
	 * One page of subsites with their connection state and failed leads.
	 *
	 * @param int $page Page number, from 1.
	 * @return array{rows:array<int,array{id:int,name:string,url:string,settings_url:string,state:string,failed:int}>,total:int}
	 */
	public function overview( int $page = 1 ): array {
		$page  = max( 1, $page );
		$total = (int) get_sites( array( 'count' => true ) );
		$ids   = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => self::PAGE_SIZE,
				'offset'  => ( $page - 1 ) * self::PAGE_SIZE,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$rows = array();
		foreach ( $ids as $site_id ) {
			$rows[] = $this->on_site( (int) $site_id, fn(): array => $this->row( (int) $site_id ) );
		}
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * The overview entry of the current site.
	 *
	 * @param int $site_id Site id.
	 * @return array{id:int,name:string,url:string,settings_url:string,state:string,failed:int}
	 */
	private function row( int $site_id ): array {
		$active = $this->network_active() || $this->active_on_site();
		$failed = 0;
		if ( $active ) {
			foreach ( $this->jobs->all() as $job ) {
				if ( 'failed' === ( $job['status'] ?? '' ) ) {
					++$failed;
				}
			}
		}
		return array(
			'id'           => $site_id,
			'name'         => (string) get_option( 'blogname', '' ),
			'url'          => (string) home_url( '/' ),
			'settings_url' => (string) admin_url( 'options-general.php?page=profotograaf' ),
			'state'        => $active ? (string) ( new Connection() )->status()['state'] : 'inactive',
			'failed'       => $failed,
		);
	}

	/**
	 * Whether the plugin is active for the whole network.
	 */
	private function network_active(): bool {
		$plugins = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $plugins ) && isset( $plugins[ plugin_basename( PROFOTOGRAAF_FILE ) ] );
	}

	/**
	 * Whether the plugin is active on the current site by itself.
	 */
	private function active_on_site(): bool {
		$plugins = get_option( 'active_plugins', array() );
		return is_array( $plugins ) && in_array( plugin_basename( PROFOTOGRAAF_FILE ), $plugins, true );
	}

	/**
	 * Every site id of the network.
	 *
	 * @return array<int,int>
	 */
	private function site_ids(): array {
		// number 0 lifts the default limit of 100 sites.
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Runs a callback with another site as the current one.
	 *
	 * @template T
	 * @param int           $site_id  Site id.
	 * @param callable(): T $callback Work to do on that site.
	 * @return T
	 */
	private function on_site( int $site_id, callable $callback ) {
		switch_to_blog( $site_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}
}
