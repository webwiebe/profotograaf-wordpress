<?php
/**
 * The notices on the settings page that ask the owner to connect again.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Connection;
use Profotograaf\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists why the connection has to be made again, in the order the settings
 * page shows the reasons.
 */
final class Reconnect_Notices {

	/**
	 * Texts of the notices, none when the connection is complete.
	 *
	 * @param Connection $connection Connection state.
	 * @param Settings   $settings   Plugin settings.
	 * @return list<string>
	 */
	public static function texts( Connection $connection, Settings $settings ): array {
		$notices = array();
		if ( $connection->needs_reconnect() ) {
			$notices[] = __( 'This connection was made before Profotograaf could let this site switch galleries on for embedding. Connect again to grant that permission. Your galleries and settings stay as they are.', 'profotograaf' );
		} elseif ( $connection->needs_write_reconnect( $settings->writes_to_platform() ) ) {
			$notices[] = Backup_Intro::reconnect_text( $settings, true );
		}
		if ( $connection->needs_leads_reconnect() && $settings->get( 'leads_enabled' ) ) {
			$notices[] = __( 'This connection lacks the permission to receive enquiries, so enquiries from your forms cannot be sent. Connect again to grant it. Enquiries that were refused are sent again afterwards.', 'profotograaf' );
		}
		return $notices;
	}
}
