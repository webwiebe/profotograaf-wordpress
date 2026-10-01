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

	// Enquiries that never reached Profotograaf are deleted with the rest. Tell the admin first, without personal data.
	$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", $wpdb->esc_like( 'profotograaf_lead_job_' ) . '%', '%' . $wpdb->esc_like( '"status":"failed"' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check on uninstall.
	if ( $failed > 0 ) {
		$to = is_array( $settings ) && ! empty( $settings['leads_alert_email'] ) ? (string) $settings['leads_alert_email'] : (string) get_option( 'admin_email', '' );
		if ( '' !== $to ) {
			wp_mail(
				$to,
				'[' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Undeliverable enquiries were deleted', 'profotograaf' ),
				sprintf(
					/* translators: %d: number of enquiries. */
					_n( '%d enquiry that could not be delivered to Profotograaf was deleted together with the plugin.', '%d enquiries that could not be delivered to Profotograaf were deleted together with the plugin.', $failed, 'profotograaf' ),
					$failed
				)
			);
		}
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
	// number 0 lifts the default limit of 100 sites, so large networks are cleaned completely.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $profotograaf_site_id ) {
		switch_to_blog( (int) $profotograaf_site_id );
		profotograaf_uninstall_site();
		restore_current_blog();
	}
} else {
	profotograaf_uninstall_site();
}
