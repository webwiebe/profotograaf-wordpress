<?php
/**
 * Removes everything the plugin stored.
 *
 * @package Profotograaf
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the options, transients, scheduled events and backup post meta of
 * one site.
 *
 * Every option and event this plugin adds starts with `profotograaf_`, so
 * modules added later are covered without editing this file. The option
 * `keep_data_on_uninstall` (Settings > Advanced) leaves everything in place.
 */
function profotograaf_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'profotograaf_settings', array() );

	// Photos kept on Profotograaf (virtual attachments) have no file on this site, so they show no image from now on.
	// They stay in the Media Library with their marks, also when the data is deleted, so installing and connecting the plugin again brings back Convert to local.
	$virtual = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_profotograaf_virtual', '1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check on uninstall.
	$admin   = (string) get_option( 'admin_email', '' );
	if ( $virtual > 0 && '' !== $admin ) {
		wp_mail(
			$admin,
			'[' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Photos kept on Profotograaf no longer show', 'profotograaf' ),
			sprintf(
				/* translators: %d: number of photos. */
				_n( '%d photo in the Media Library was kept on Profotograaf and has no copy on this site, so it shows no image now that the plugin is deleted. To get it back, install and connect the Profotograaf plugin again and use Convert to local in the Media Library.', '%d photos in the Media Library were kept on Profotograaf and have no copy on this site, so they show no image now that the plugin is deleted. To get them back, install and connect the Profotograaf plugin again and use Convert to local in the Media Library.', $virtual, 'profotograaf' ),
				$virtual
			)
		);
	}

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
	// Photo import locks (Photo_Importer::LOCK_PREFIX) left behind by a crashed request are covered by the same prefix.
	// The log ring buffer (Logger::OPTION) is one of these options. Naming it keeps the removal explicit.
	delete_option( 'profotograaf_log' );

	// The backup state on Media Library items (`_profotograaf_backup_state` and the other `_profotograaf_backup_` fields).
	// Other post meta of the plugin stays, like the imported photos it describes and the marks of photos kept on Profotograaf. The copies the backup made stay on Profotograaf.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_profotograaf_backup_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.

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
