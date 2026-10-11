<?php
/**
 * Error handling, waits and the lock of the backup worker.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The second half of Backup_Worker, kept apart to keep that file short: what
 * a failed request means for the file and for the backup as a whole, the
 * pause and backoff state, and the run lock. It uses the properties and
 * constants of Backup_Worker.
 */
trait Backup_Worker_Outcome {

	/**
	 * Handles a failed upload.
	 *
	 * @param int      $id         Attachment id.
	 * @param string   $gallery_id Gallery the file was sent to.
	 * @param WP_Error $error      The error.
	 * @return string `next` to go on with the next file, else the reason the run stops.
	 */
	private function upload_failed( int $id, string $gallery_id, WP_Error $error ): string {
		$code   = (string) $error->get_error_code();
		$status = self::status_of( $error );

		if ( 'profotograaf_storage_full' === $code ) {
			$this->state->requeue( $id );
			$this->pause( 'storage_full', self::STORAGE_PAUSE );
			Logger::warning( 'Profotograaf storage is full; the backup is paused.', array( 'attachment' => $id ) );
			return 'storage_full';
		}
		if ( in_array( $code, array( 'profotograaf_reconnect', 'profotograaf_not_connected', 'profotograaf_refresh_busy' ), true ) ) {
			// The request was refused before the platform took the file.
			$this->state->requeue( $id );
			return 'profotograaf_reconnect' === $code ? 'reconnect' : 'not_connected';
		}
		if ( 404 === $status ) {
			$this->galleries->forget( $gallery_id );
			$this->state->requeue( $id );
			Logger::warning( 'A backup gallery is gone from Profotograaf; a new one will be created.', array( 'gallery' => $gallery_id ) );
			return 'gallery_missing';
		}
		if ( 429 === $status || 503 === $status ) {
			$this->state->requeue( $id );
			$this->wait( $error );
			return 'backoff';
		}
		if ( 'profotograaf_network' === $code || 408 === $status || $status >= 500 ) {
			// The platform may have stored the file before the answer was lost. Sending it again could store it twice.
			$this->state->needs_check( $id, self::REASON_NO_ANSWER );
			$this->wait( $error );
			Logger::warning(
				'A backup upload got no clear answer; the file may be on Profotograaf.',
				array(
					'attachment' => $id,
					'status'     => $status,
				)
			);
			return 'backoff';
		}
		if ( 400 === $status ) {
			$this->state->skip( $id, self::REASON_REJECTED );
			Logger::warning( 'Profotograaf did not accept a backup file.', array( 'attachment' => $id ) );
			return 'next';
		}
		$this->state->fail( $id, $status > 0 ? 'http_' . $status : $code );
		Logger::error(
			'A backup file could not be sent to Profotograaf.',
			array(
				'attachment' => $id,
				'status'     => $status,
				'error'      => $code,
			)
		);
		return 'next';
	}

	/**
	 * Handles a failed gallery creation. The file stays queued.
	 *
	 * @param WP_Error $error The error.
	 * @return string The reason the run stops.
	 */
	private function gallery_failed( WP_Error $error ): string {
		$code = (string) $error->get_error_code();
		if ( 'profotograaf_reconnect' === $code ) {
			return 'reconnect';
		}
		if ( in_array( $code, array( 'profotograaf_not_connected', 'profotograaf_refresh_busy' ), true ) ) {
			return 'not_connected';
		}
		if ( 413 === self::status_of( $error ) ) {
			$this->pause( 'storage_full', self::STORAGE_PAUSE );
			Logger::warning( 'Profotograaf storage is full; the backup is paused.' );
			return 'storage_full';
		}
		// Not caused by one file, so no file fails. The backup waits and tries again.
		$this->wait( $error );
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['retryable'] ) ) {
			return 'backoff';
		}
		Logger::error(
			'A backup gallery could not be created on Profotograaf.',
			array(
				'status' => self::status_of( $error ),
				'error'  => $code,
			)
		);
		return 'backoff';
	}

	/**
	 * Waits before the next run: Retry-After when the platform sent it, else
	 * the next step of BACKOFF.
	 *
	 * @param WP_Error $error The error that ended the run.
	 */
	private function wait( WP_Error $error ): void {
		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;
		$count       = 'backoff' === $this->pause_state()['reason'] ? $this->pause_state()['count'] + 1 : 1;
		$delay       = $retry_after > 0 ? min( $retry_after, self::MAX_WAIT ) : self::BACKOFF[ min( $count, count( self::BACKOFF ) ) - 1 ];
		$this->pause( 'backoff', $delay, $count );
		Logger::info(
			'The backup waits before it sends again.',
			array(
				'seconds' => $delay,
				'status'  => self::status_of( $error ),
			)
		);
	}

	/**
	 * Stores a pause.
	 *
	 * @param string $reason  `storage_full` or `backoff`.
	 * @param int    $seconds How long.
	 * @param int    $count   Waits in a row.
	 */
	private function pause( string $reason, int $seconds, int $count = 1 ): void {
		update_option(
			self::PAUSE,
			array(
				'reason' => $reason,
				'until'  => $this->now() + $seconds,
				'count'  => $count,
			),
			false
		);
	}

	/**
	 * Ends a wait after a file went through.
	 */
	private function clear_wait(): void {
		if ( '' !== $this->pause_state()['reason'] ) {
			delete_option( self::PAUSE );
		}
	}

	/**
	 * The HTTP status of an error, 0 when none came.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function status_of( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}

	/**
	 * Takes the run lock, replacing one older than LOCK_TTL.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK, $this->now(), '', false ) ) {
			return true;
		}
		$since = get_option( self::LOCK, 0 );
		if ( is_numeric( $since ) && $this->now() - (int) $since >= self::LOCK_TTL ) {
			delete_option( self::LOCK );
			return (bool) add_option( self::LOCK, $this->now(), '', false );
		}
		return false;
	}
}
