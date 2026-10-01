<?php
/**
 * Removes everything the plugin stored.
 *
 * @package Profotograaf
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the options, transients and scheduled events of one site.
 *
 * Every option and event this plugin adds starts with `profotograaf_`, so
 * modules added later are covered without editing this file. The option
 * `keep_data_on_uninstall` (Settings > Advanced) leaves everything in place.
 */
function profotograaf_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'profotograaf_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['keep_data_on_uninstall'] ) ) {
		return;
	}

	$like  = $wpdb->esc_like( 'profotograaf_' ) . '%';
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	foreach ( (array) $names as $name ) {
		delete_option( (string) $name );
	}
	// The log ring buffer (Logger::OPTION) is one of these options. Naming it keeps the removal explicit.
	delete_option( 'profotograaf_log' );

	$transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_profotograaf_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	foreach ( (array) $transients as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}

	$cron = _get_cron_array();
	if ( is_array( $cron ) ) {
		foreach ( $cron as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'profotograaf_' ) ) {
					wp_clear_scheduled_hook( (string) $hook );
				}
			}
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $profotograaf_site_id ) {
		switch_to_blog( (int) $profotograaf_site_id );
		profotograaf_uninstall_site();
		restore_current_blog();
	}
} else {
	profotograaf_uninstall_site();
}
