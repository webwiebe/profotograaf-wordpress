<?php
/**
 * The text above the backup settings.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

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
	 * Prints the heading and the paragraphs.
	 */
	public static function render(): void {
		$paragraphs = self::paragraphs();
		?>
		<h2><?php esc_html_e( 'Backup to Profotograaf', 'profotograaf' ); ?></h2>
		<p><strong><?php echo esc_html( (string) array_shift( $paragraphs ) ); ?></strong></p>
		<?php foreach ( $paragraphs as $paragraph ) : ?>
			<p><?php echo esc_html( $paragraph ); ?></p>
		<?php endforeach; ?>
		<?php
	}
}
