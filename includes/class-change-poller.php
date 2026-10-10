<?php
/**
 * Change feed poll for imported copies.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the platform change feed and hands what concerns imported copies to
 * Reconcile (docs/media-lifecycle.md, rule 3).
 *
 * A run takes a lock, reads at most MAX_PAGES feed pages or MAX_SECONDS
 * seconds, and keeps only the entries that name an imported photo or one of
 * its galleries. The cursor option is written after each page that was
 * applied, so a failed page is read again and a repeated page changes
 * nothing (delivery is at least once). A failed run records the error and
 * leaves the cursor and the last-success time as they were.
 *
 * The feed does not report a gallery that stopped serving. Every run also
 * compares the gallery list with the stored states and reconciles when a
 * gallery of an imported copy changed what it serves.
 *
 * A `resync` answer stores the new cursor and starts a reconcile of every
 * imported copy in batches of BATCH_SIZE through single cron events.
 *
 * The poll runs whatever the media source setting says. Nothing here deletes
 * a file, an attachment or any content.
 */
final class Change_Poller {

	public const OPTION_CURSOR  = 'profotograaf_changes_cursor';
	public const OPTION_LAST_OK = 'profotograaf_changes_last_ok';
	public const OPTION_STATUS  = 'profotograaf_changes_status';
	public const OPTION_LOCK    = 'profotograaf_changes_lock';
	public const OPTION_RESYNC  = 'profotograaf_changes_resync';

	/** Single event that reconciles one batch after a resync. */
	public const BATCH_HOOK = 'profotograaf_reconcile_batch';

	public const MAX_PAGES   = 20;
	public const MAX_SECONDS = 20;
	public const PAGE_LIMIT  = 500;
	public const BATCH_SIZE  = 50;
	public const LOCK_TTL    = 300;

	/** Galleries looked up per run to settle unexplained absences. */
	private const LOOKUP_CAP = 10;

	/** Seconds a stored catalogue counts as fresh inside a resync. */
	private const CATALOGUE_MAX_AGE = 900;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Photo catalogue.
	 *
	 * @var Photo_Catalogue
	 */
	private Photo_Catalogue $catalogue;

	/**
	 * Clock override for tests.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Catalogue of the current run.
	 *
	 * @var array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}|WP_Error|null
	 */
	private $run_catalogue = null;

	/**
	 * Gallery list of the current run, null when not read yet or when it failed.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $run_galleries = null;

	/**
	 * Whether the gallery list was requested in the current run.
	 *
	 * @var bool
	 */
	private bool $galleries_read = false;

	/**
	 * Constructor.
	 *
	 * @param Api_Client      $api       API client.
	 * @param Photo_Catalogue $catalogue Photo catalogue.
	 * @param callable|null   $clock     Returns the current unix time.
	 */
	public function __construct( Api_Client $api, Photo_Catalogue $catalogue, ?callable $clock = null ) {
		$this->api       = $api;
		$this->catalogue = $catalogue;
		$this->clock     = $clock;
	}

	/**
	 * Runs one bounded poll.
	 *
	 * @return array{ok:bool,pages:int,applied:int,error:string,paused:bool,resync:bool,busy:bool}
	 */
	public function run(): array {
		$result = array(
			'ok'      => true,
			'pages'   => 0,
			'applied' => 0,
			'error'   => '',
			'paused'  => false,
			'resync'  => false,
			'busy'    => false,
		);
		if ( ! $this->acquire_lock() ) {
			$result['busy'] = true;
			return $result;
		}
		try {
			$this->reset_run();
			$result = $this->poll( $result );
		} finally {
			delete_option( self::OPTION_LOCK );
		}
		return $result;
	}

	/**
	 * Reconciles the next batch of imported copies after a resync.
	 */
	public function run_batch(): void {
		$offset = get_option( self::OPTION_RESYNC, false );
		if ( false === $offset || ! is_numeric( $offset ) || ! $this->acquire_lock() ) {
			return;
		}
		try {
			$this->reset_run();
			$this->batch( max( 0, (int) $offset ) );
		} finally {
			delete_option( self::OPTION_LOCK );
		}
	}

	/**
	 * Whether a resync still has batches to run.
	 */
	public function resync_pending(): bool {
		return false !== get_option( self::OPTION_RESYNC, false );
	}

	/**
	 * Unix time of the last run that finished without error, null for never.
	 */
	public function last_ok(): ?int {
		$value = get_option( self::OPTION_LAST_OK, 0 );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * The last run result.
	 *
	 * @return array{error:string,paused:bool,verified:bool,applied:int,pages:int,at:int}
	 */
	public function status(): array {
		$stored = get_option( self::OPTION_STATUS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'error'    => (string) ( $stored['error'] ?? '' ),
			'paused'   => ! empty( $stored['paused'] ),
			'verified' => ! empty( $stored['verified'] ),
			'applied'  => (int) ( $stored['applied'] ?? 0 ),
			'pages'    => (int) ( $stored['pages'] ?? 0 ),
			'at'       => (int) ( $stored['at'] ?? 0 ),
		);
	}

	/**
	 * Ends the account pause. The next run starts from a fresh cursor and
	 * reconciles every copy without asking whether the account matches.
	 */
	public function resume(): void {
		$status             = $this->status();
		$status['paused']   = false;
		$status['verified'] = true;
		update_option( self::OPTION_STATUS, $status, false );
		delete_option( self::OPTION_CURSOR );
	}

	/**
	 * Forgets the cursor and every record of earlier runs.
	 */
	public static function forget(): void {
		foreach ( array( self::OPTION_CURSOR, self::OPTION_LAST_OK, self::OPTION_STATUS, self::OPTION_LOCK, self::OPTION_RESYNC ) as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Reads the feed pages of one run.
	 *
	 * @param array{ok:bool,pages:int,applied:int,error:string,paused:bool,resync:bool,busy:bool} $result Result skeleton.
	 * @return array{ok:bool,pages:int,applied:int,error:string,paused:bool,resync:bool,busy:bool}
	 */
	private function poll( array $result ): array {
		$status = $this->status();
		if ( $status['paused'] ) {
			$result['paused'] = true;
			return $result;
		}

		$started  = $this->now();
		$cursor   = (string) get_option( self::OPTION_CURSOR, '' );
		$imported = Imported_Copies::all();
		$done     = false;
		$resynced = false;

		while ( $result['pages'] < self::MAX_PAGES && $this->now() - $started < self::MAX_SECONDS ) {
			$page = $this->api->changes( $cursor, self::PAGE_LIMIT );
			if ( is_wp_error( $page ) ) {
				return $this->fail( $result, $page );
			}
			++$result['pages'];

			if ( $page['resync'] ) {
				// The new cursor first, so nothing falls between the listing and the next poll.
				update_option( self::OPTION_CURSOR, $page['cursor'], false );
				update_option( self::OPTION_RESYNC, 0, false );
				$result['resync'] = true;
				$resynced         = true;
				$done             = true;
				break;
			}

			$feed = Imported_Copies::filter_feed( $page, $imported );
			if ( $feed['relevant'] ) {
				$applied = $this->reconcile( array_column( $imported, 'id' ), $feed );
				if ( is_wp_error( $applied ) ) {
					return $this->fail( $result, $applied );
				}
				$result['applied'] += $applied;
				$done               = true;
			}
			update_option( self::OPTION_CURSOR, $page['cursor'], false );
			$cursor = $page['cursor'];
			if ( ! $page['has_more'] ) {
				break;
			}
		}//end while

		if ( ! $done && ! $resynced && array() !== $imported && Imported_Copies::serving_changed( $imported, $this->galleries() ) ) {
			$applied = $this->reconcile( array_column( $imported, 'id' ), null );
			if ( is_wp_error( $applied ) ) {
				return $this->fail( $result, $applied );
			}
			$result['applied'] += $applied;
		}

		if ( $this->resync_pending() ) {
			$this->schedule_batch();
		}

		$status = array_merge(
			$this->status(),
			array(
				'error'   => '',
				'applied' => $result['applied'],
				'pages'   => $result['pages'],
				'at'      => $this->now(),
			)
		);
		update_option( self::OPTION_STATUS, $status, false );
		update_option( self::OPTION_LAST_OK, $this->now(), false );
		return $result;
	}

	/**
	 * Reconciles one batch of the resync.
	 *
	 * @param int $offset First imported copy of the batch.
	 */
	private function batch( int $offset ): void {
		$imported = Imported_Copies::all();
		$slice    = array_slice( $imported, $offset, self::BATCH_SIZE );
		$status   = $this->status();

		if ( 0 === $offset && ! $status['verified'] && array() !== $imported ) {
			$catalogue = $this->catalogue();
			if ( ! is_wp_error( $catalogue ) && Imported_Copies::account_mismatch( $catalogue, $imported ) ) {
				// A different account lists none of the copies. Flag nothing and wait for the owner.
				$status['paused'] = true;
				update_option( self::OPTION_STATUS, $status, false );
				delete_option( self::OPTION_RESYNC );
				Logger::warning( 'The change feed poll paused: the account lists none of the imported photos.' );
				return;
			}
		}

		if ( array() !== $slice ) {
			$applied = $this->reconcile( array_column( $slice, 'id' ), null );
			if ( is_wp_error( $applied ) ) {
				$this->fail( array(), $applied );
				$this->schedule_batch();
				return;
			}
			$status['applied'] += $applied;
		}

		$offset += self::BATCH_SIZE;
		if ( $offset >= count( $imported ) ) {
			delete_option( self::OPTION_RESYNC );
			$status['error']    = '';
			$status['verified'] = false;
			$status['at']       = $this->now();
			update_option( self::OPTION_STATUS, $status, false );
			update_option( self::OPTION_LAST_OK, $this->now(), false );
			return;
		}
		update_option( self::OPTION_RESYNC, $offset, false );
		update_option( self::OPTION_STATUS, $status, false );
		$this->schedule_batch();
	}

	/**
	 * Plans and applies the state changes of the given copies.
	 *
	 * @param array<int,int>           $attachment_ids Attachments to decide on.
	 * @param array<string,mixed>|null $feed           Feed entries filtered to imported copies, null for none.
	 * @return int|WP_Error Number of changes written.
	 */
	private function reconcile( array $attachment_ids, ?array $feed ) {
		$catalogue = $this->catalogue();
		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}
		$galleries = $this->galleries();

		$listed = array();
		foreach ( $catalogue['photos'] as $photo ) {
			if ( isset( $photo['id'] ) ) {
				$listed[ (string) $photo['id'] ] = $photo;
			}
		}
		$input = array(
			'rows'      => Reconcile::rows( $attachment_ids, $listed ),
			'catalogue' => $catalogue,
			'galleries' => $galleries,
			'feed'      => $feed,
			'now'       => $this->now(),
		);
		$plan  = Reconcile::plan( $input );

		if ( array() !== $plan['needs_lookup'] ) {
			$lookups = array();
			foreach ( array_slice( $plan['needs_lookup'], 0, self::LOOKUP_CAP ) as $gallery_id ) {
				$ids = $this->api->gallery_photo_ids( (string) $gallery_id );
				if ( ! is_wp_error( $ids ) ) {
					$lookups[ (string) $gallery_id ] = $ids;
				}
			}
			if ( array() !== $lookups ) {
				$input['gallery_photos'] = $lookups;
				$plan                    = Reconcile::plan( $input );
			}
		}

		Reconcile::apply( $plan['changes'] );
		return count( $plan['changes'] );
	}

	/**
	 * The catalogue of this run, refreshed once. A stale one is an error: a
	 * decision from it could flag copies that the platform still lists.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}|WP_Error
	 */
	private function catalogue() {
		if ( null === $this->run_catalogue ) {
			$fresh               = $this->catalogue->recent( self::CATALOGUE_MAX_AGE );
			$this->run_catalogue = null !== $fresh && $this->resync_pending() ? $fresh : $this->catalogue->refresh_for_sync();
			if ( ! is_wp_error( $this->run_catalogue ) && $this->run_catalogue['stale'] ) {
				$this->run_catalogue = Api_Errors::make( 'profotograaf_catalogue_stale', __( 'The photo list could not be refreshed.', 'profotograaf' ), 0, true );
			}
		}
		return $this->run_catalogue;
	}

	/**
	 * The gallery list of this run, read once. Null when the request failed.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function galleries(): ?array {
		if ( ! $this->galleries_read ) {
			$this->galleries_read = true;
			$rows                 = $this->api->list_galleries();
			$this->run_galleries  = is_wp_error( $rows ) ? null : $rows;
		}
		return $this->run_galleries;
	}

	/**
	 * Records a failed run. The cursor and the last-success time stay.
	 *
	 * @param array<string,mixed> $result Result so far, empty when none.
	 * @param WP_Error            $error  Error.
	 * @return array{ok:bool,pages:int,applied:int,error:string,paused:bool,resync:bool,busy:bool}
	 */
	private function fail( array $result, WP_Error $error ): array {
		$data   = $error->get_error_data();
		$status = array_merge(
			$this->status(),
			array(
				'error' => (string) $error->get_error_code(),
				'at'    => $this->now(),
			)
		);
		update_option( self::OPTION_STATUS, $status, false );
		Logger::warning(
			'The change feed poll failed.',
			array(
				'code'   => $error->get_error_code(),
				'status' => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
			)
		);
		return array_merge(
			array(
				'ok'      => false,
				'pages'   => 0,
				'applied' => 0,
				'paused'  => false,
				'resync'  => false,
				'busy'    => false,
			),
			$result,
			array(
				'ok'    => false,
				'error' => (string) $error->get_error_code(),
			)
		);
	}

	/**
	 * Schedules the next resync batch.
	 */
	private function schedule_batch(): void {
		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( $this->now() + 5, self::BATCH_HOOK );
		}
	}

	/**
	 * Takes the run lock, replacing one older than LOCK_TTL.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::OPTION_LOCK, $this->now(), '', false ) ) {
			return true;
		}
		$since = get_option( self::OPTION_LOCK, 0 );
		if ( is_numeric( $since ) && $this->now() - (int) $since >= self::LOCK_TTL ) {
			delete_option( self::OPTION_LOCK );
			return (bool) add_option( self::OPTION_LOCK, $this->now(), '', false );
		}
		return false;
	}

	/**
	 * Clears what one run remembers of the lists it read.
	 */
	private function reset_run(): void {
		$this->run_catalogue  = null;
		$this->run_galleries  = null;
		$this->galleries_read = false;
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return null !== $this->clock ? (int) ( $this->clock )() : time();
	}
}
