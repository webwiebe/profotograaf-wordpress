<?php
/**
 * Words for the backup states and reasons.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the states of Backup_State and the reasons of Backup_Eligibility and
 * Backup_Worker into short text for the Media Library, the settings page and
 * Site Health.
 */
final class Backup_Labels {

	/**
	 * The short label of a state. A file without a state that the
	 * photographer marked reads "Marked"; any other one has no label.
	 *
	 * @param string $state  One of Backup_State::STATES, or empty.
	 * @param bool   $marked Whether the photographer marked the file.
	 */
	public static function state( string $state, bool $marked = false ): string {
		switch ( $state ) {
			case Backup_State::QUEUED:
				return __( 'Waiting to be copied', 'profotograaf' );
			case Backup_State::UPLOADING:
				return __( 'Being copied', 'profotograaf' );
			case Backup_State::DONE:
				return __( 'Copied', 'profotograaf' );
			case Backup_State::SKIPPED:
				return __( 'Not copied', 'profotograaf' );
			case Backup_State::FAILED:
				return __( 'Copy failed', 'profotograaf' );
			case Backup_State::NEEDS_CHECK:
				return __( 'Copy not confirmed', 'profotograaf' );
		}
		return $marked ? __( 'Marked for copying', 'profotograaf' ) : '';
	}

	/**
	 * Why a file was skipped or failed, as one sentence. Empty for no reason.
	 *
	 * @param string $reason A Backup_Eligibility or Backup_Worker reason, or an error code.
	 */
	public static function reason( string $reason ): string {
		$known = array(
			Backup_Eligibility::REASON_IMPORTED     => __( 'It came from Profotograaf, so it is there already.', 'profotograaf' ),
			Backup_Eligibility::REASON_MISSING_FILE => __( 'The file is missing on this server.', 'profotograaf' ),
			Backup_Eligibility::REASON_TOO_LARGE    => __( 'The file is larger than 100 MB, the most Profotograaf accepts.', 'profotograaf' ),
			Backup_Eligibility::REASON_EXCLUDED     => __( 'A filter on this site leaves it out.', 'profotograaf' ),
			Backup_Worker::REASON_REJECTED          => __( 'Profotograaf did not accept the file.', 'profotograaf' ),
			Backup_Worker::REASON_NO_ANSWER         => __( 'The file was sent but Profotograaf did not answer. It may be there already.', 'profotograaf' ),
			Backup_Worker::REASON_INTERRUPTED       => __( 'The upload was interrupted. The file may be on Profotograaf already.', 'profotograaf' ),
			Backup_Worker::REASON_HOST_MEMORY       => __( 'The file is too large for the memory of this site.', 'profotograaf' ),
		);
		if ( isset( $known[ $reason ] ) ) {
			return $known[ $reason ];
		}
		if ( 1 === preg_match( '/^http_(\d{3})$/', $reason, $match ) ) {
			/* translators: %d: HTTP status code, for example 500. */
			return sprintf( __( 'Profotograaf answered with error %d.', 'profotograaf' ), (int) $match[1] );
		}
		if ( '' === $reason ) {
			return '';
		}
		/* translators: %s: error code. */
		return sprintf( __( 'The upload failed (%s).', 'profotograaf' ), $reason );
	}

	/**
	 * The label of a file and, when it has one, the reason.
	 *
	 * @param Backup_State $state         Backup state.
	 * @param int          $attachment_id Attachment id.
	 * @return array{label:string,reason:string}
	 */
	public static function of( Backup_State $state, int $attachment_id ): array {
		$current = $state->state( $attachment_id );
		$reason  = in_array( $current, array( Backup_State::SKIPPED, Backup_State::FAILED, Backup_State::NEEDS_CHECK ), true )
			? self::reason( $state->reason( $attachment_id ) )
			: '';
		return array(
			'label'  => self::state( $current, $state->is_marked( $attachment_id ) ),
			'reason' => $reason,
		);
	}
}
