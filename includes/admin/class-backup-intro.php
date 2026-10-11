<?php
/**
 * The text above the backup settings.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Settings;
use Profotograaf\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Says on the Backup tab what the backup sends, where it goes and what stays
 * on Profotograaf, before the photographer switches it on.
 */
final class Backup_Intro {

	/**
	 * The paragraphs, in order. The first one is the warning.
	 *
	 * @return array<int,string>
	 */
	public static function paragraphs(): array {
		return array(
			__( 'Copies your Media Library images to your Profotograaf account.', 'profotograaf' ),
			__( 'The backup has its own switch below and is off until you turn it on. Nothing is sent before that. Sharing anonymous usage data, using Profotograaf photos in the editor and sending Media Library files do not switch it on.', 'profotograaf' ),
			__( 'Files go to private galleries in your Profotograaf account, one per month, named after the title of this site. Each file is sent as stored, with its file name and its metadata, which can include the GPS position. Images, RAW files and videos are copied. The copies count towards your Profotograaf storage.', 'profotograaf' ),
			__( 'Each file is copied once. Editing or deleting it here later changes nothing on Profotograaf. When you switch the backup off or delete the plugin, the copies stay in your Profotograaf account until you delete them there.', 'profotograaf' ),
			__( 'Photos of people are personal data. Check that your privacy policy and your agreements with clients allow a copy on Profotograaf. On a site with several authors, the backup also copies files that other users uploaded.', 'profotograaf' ),
		);
	}

	/**
	 * Prints the heading and the paragraphs on the Backup tab.
	 *
	 * @param string $tab The current settings tab.
	 */
	public static function render( string $tab ): void {
		if ( Settings_Schema::TAB_BACKUP !== $tab ) {
			return;
		}
		$paragraphs = self::paragraphs();
		?>
		<h2><?php esc_html_e( 'Backup to Profotograaf', 'profotograaf' ); ?></h2>
		<p><strong><?php echo esc_html( (string) array_shift( $paragraphs ) ); ?></strong></p>
		<?php foreach ( $paragraphs as $paragraph ) : ?>
			<p><?php echo esc_html( $paragraph ); ?></p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Why a connection without galleries:write must connect again. The backup
	 * gets its own words when it is the only feature that needs it.
	 *
	 * @param Settings $settings Settings.
	 * @param bool     $long     Whether to add how to fix it, for the settings page.
	 */
	public static function reconnect_text( Settings $settings, bool $long ): string {
		if ( $settings->backup_enabled() && ! $settings->media_upload_enabled() ) {
			return $long
				? __( 'The backup needs a new permission to create its galleries and copy files. Nothing is copied until you connect this site again to grant it. Your galleries and settings stay as they are.', 'profotograaf' )
				: __( 'The backup to Profotograaf needs a new permission.', 'profotograaf' );
		}
		return $long
			? __( 'Sending files needs a new permission. Connect this site again to grant it. Your galleries and settings stay as they are.', 'profotograaf' )
			: __( 'Sending files needs a new permission.', 'profotograaf' );
	}
}
