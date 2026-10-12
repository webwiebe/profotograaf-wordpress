<?php
/**
 * Sync status line and action buttons in the attachment details.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Renders what the sync knows about an imported copy under the Source field:
 * a status text (never colour alone) and the buttons for that state. The
 * clicks are handled by assets/admin/sync-actions.js through Sync_Actions_Rest.
 */
final class Sync_Field {

	/**
	 * The status message of a flagged copy.
	 *
	 * @param string $state  Sync state.
	 * @param string $reason Sync reason.
	 */
	public static function message( string $state, string $reason ): string {
		switch ( $state ) {
			case Reconcile::STATE_MISSING:
				return self::missing_message( $reason );
			case Reconcile::STATE_CHANGED:
				return __( 'This photo was changed on Profotograaf.', 'profotograaf' );
			case Reconcile::STATE_CONFLICT:
				return __( 'This photo was changed on Profotograaf and on this site.', 'profotograaf' );
			case Reconcile::STATE_KEPT:
				return __( 'Kept as this site\'s own file. Profotograaf changes are not watched.', 'profotograaf' );
			default:
				return '';
		}
	}

	/**
	 * The status line and buttons for an attachment.
	 *
	 * @param int  $attachment_id Attachment id.
	 * @param bool $can_edit      Whether the user may act on the copy.
	 * @return string HTML.
	 */
	public static function html( int $attachment_id, bool $can_edit ): string {
		$state = (string) get_post_meta( $attachment_id, Reconcile::META_STATE, true );
		if ( ! $can_edit ) {
			$text = self::message( $state, (string) get_post_meta( $attachment_id, Reconcile::META_REASON, true ) );
			return '' === $text ? '' : '<p class="description">' . esc_html( $text ) . '</p>';
		}
		$buttons = array();
		switch ( $state ) {
			case Reconcile::STATE_MISSING:
				$buttons = array(
					'keep'   => __( 'Keep', 'profotograaf' ),
					'remove' => __( 'Remove', 'profotograaf' ),
				);
				break;
			case Reconcile::STATE_CHANGED:
				$buttons = array(
					'review' => __( 'Review changes', 'profotograaf' ),
					'keep'   => __( 'Keep my copy', 'profotograaf' ),
				);
				break;
			case Reconcile::STATE_CONFLICT:
				// The conflict dialog holds Keep mine, so no separate button.
				$buttons = array( 'review' => __( 'Resolve conflict', 'profotograaf' ) );
				break;
			case Reconcile::STATE_KEPT:
				$buttons = array( 'watch' => __( 'Watch again', 'profotograaf' ) );
				break;
			default:
				$buttons = array( 'keep' => __( 'Stop watching', 'profotograaf' ) );
		}//end switch
		$out = '';
		$msg = self::message( $state, (string) get_post_meta( $attachment_id, Reconcile::META_REASON, true ) );
		if ( '' !== $msg ) {
			$out .= '<p class="profotograaf-sync__message" role="status">' . esc_html( $msg ) . '</p>';
		}
		$out .= '<p class="profotograaf-sync">';
		foreach ( $buttons as $action => $label ) {
			$out .= sprintf(
				'<button type="button" class="button profotograaf-sync__button" data-action="%1$s" data-attachment-id="%2$d">%3$s</button> ',
				esc_attr( $action ),
				$attachment_id,
				esc_html( $label )
			);
		}
		$out .= sprintf( '<span class="profotograaf-sync__status" role="status" aria-live="polite" data-attachment-id="%d"></span></p>', $attachment_id );
		return $out;
	}

	/**
	 * Message for a copy the platform no longer lists.
	 *
	 * @param string $reason Sync reason.
	 */
	private static function missing_message( string $reason ): string {
		switch ( $reason ) {
			case Reconcile::REASON_DELETED:
				return __( 'This photo was deleted on Profotograaf.', 'profotograaf' );
			case Reconcile::REASON_LEFT_GALLERY:
				return __( 'This photo was taken out of its gallery on Profotograaf.', 'profotograaf' );
			case Reconcile::REASON_GALLERY_DELETED:
				return __( 'The gallery of this photo was removed on Profotograaf.', 'profotograaf' );
			case Reconcile::REASON_UNAVAILABLE:
				return __( 'The gallery of this photo is not shared on Profotograaf at the moment.', 'profotograaf' );
			default:
				return __( 'This photo is no longer listed on Profotograaf.', 'profotograaf' );
		}
	}
}
