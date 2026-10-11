<?php
/**
 * The backup upload worker.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the queued backup files to Profotograaf (docs/backup-and-pwa.md,
 * "How often" and "Failure handling").
 *
 * A recurring WP-Cron event (HOOK, every five minutes) calls run(). A run
 * holds a lock, so two never overlap, and works through the `queued` items
 * lowest id first, one file per request, until the time budget (BUDGET
 * seconds), MAX_FILES or the memory check ends it. It never runs inside a
 * page request: only WP-Cron and WP-CLI may start it.
 *
 * Per file: the eligibility rules are applied again (the file may be gone or
 * out of scope by now), the SHA-256 is compared with earlier copies so the
 * same bytes are never stored twice, the month's gallery is created when
 * needed (Backup_Galleries), and the file is sent with its original name.
 *
 * Outcomes, see Backup_Worker_Outcome:
 *
 * - 202: `done`, with the photo and gallery ids and the checksum.
 * - 413 (storage full): the file stays `queued` and the whole backup pauses
 *   for a day, or until resume(). Nothing retries in a loop.
 * - 429, 503: the file stays `queued` and the backup waits for Retry-After,
 *   else 5, 15 and then 60 minutes.
 * - A network error, a timeout or another 5xx after the request left: the
 *   platform may have stored the file, so it becomes `needs_check` and is not
 *   sent again on its own. The backup then waits like after a 503.
 * - 403 (missing galleries:write) and a lost connection: the file stays
 *   `queued` and the run ends. A new pairing resumes the backup.
 * - 404 (gallery deleted on Profotograaf): the gallery is forgotten and the
 *   file stays `queued`; the next run creates a new gallery.
 * - 400: `skipped` with reason `rejected`. Other errors: `failed`, which
 *   waits for the photographer.
 */
class Backup_Worker {

	use Backup_Worker_Outcome;

	public const HOOK     = 'profotograaf_backup_upload';
	public const SCHEDULE = 'profotograaf_five_minutes';
	public const INTERVAL = 300;

	/** Run lock option, removed on uninstall with the other `profotograaf_` options. */
	public const LOCK = 'profotograaf_backup_lock';

	/** Seconds after which a lock counts as abandoned. Longer than one upload may take. */
	public const LOCK_TTL = 600;

	/** Pause option: why the backup waits and until when. */
	public const PAUSE = 'profotograaf_backup_pause';

	/** Seconds a run may start new requests for. */
	public const BUDGET = 20;

	/** Files per run at most. */
	public const MAX_FILES = 50;

	/** Waits after a 429, 503 or lost answer without Retry-After: 5, 15, 60 minutes. */
	public const BACKOFF = array( 300, 900, 3600 );

	/** The longest Retry-After the backup honours. */
	public const MAX_WAIT = 86400;

	/** How long a full storage pauses the backup. */
	public const STORAGE_PAUSE = 86400;

	/** Memory a file needs: the file read, the request body and the copy the transport makes. */
	public const MEMORY_FACTOR = 3;

	/** Memory kept free for everything else, in bytes. */
	public const MEMORY_RESERVE = 16 * 1024 * 1024;

	public const REASON_REJECTED    = 'rejected';
	public const REASON_NO_ANSWER   = 'no_answer';
	public const REASON_INTERRUPTED = 'interrupted';
	public const REASON_HOST_MEMORY = 'too_large_for_host';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Eligibility rules.
	 *
	 * @var Backup_Eligibility
	 */
	private Backup_Eligibility $eligibility;

	/**
	 * Backup state.
	 *
	 * @var Backup_State
	 */
	private Backup_State $state;

	/**
	 * Backup galleries.
	 *
	 * @var Backup_Galleries
	 */
	private Backup_Galleries $galleries;

	/**
	 * Current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Seconds since some fixed point, for the time budget.
	 *
	 * @var callable
	 */
	private $timer;

	/**
	 * Free memory in bytes.
	 *
	 * @var callable
	 */
	private $memory;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings    Settings.
	 * @param Connection         $connection  Connection state.
	 * @param Api_Client         $api         API client.
	 * @param Backup_Eligibility $eligibility Eligibility rules.
	 * @param Backup_State       $state       Backup state.
	 * @param Backup_Galleries   $galleries   Backup galleries.
	 * @param callable|null      $clock       Returns the current unix time.
	 * @param callable|null      $timer       Returns seconds as a float, for the budget.
	 * @param callable|null      $memory      Returns the free memory in bytes.
	 */
	public function __construct( Settings $settings, Connection $connection, Api_Client $api, Backup_Eligibility $eligibility, Backup_State $state, Backup_Galleries $galleries, ?callable $clock = null, ?callable $timer = null, ?callable $memory = null ) {
		$this->settings    = $settings;
		$this->connection  = $connection;
		$this->api         = $api;
		$this->eligibility = $eligibility;
		$this->state       = $state;
		$this->galleries   = $galleries;
		$this->clock       = $clock ?? static fn(): int => time();
		$this->timer       = $timer ?? static fn(): float => microtime( true );
		$this->memory      = $memory ?? array( self::class, 'free_memory' );
	}

	/**
	 * Sends queued files until the budget, the queue or an error ends the run.
	 *
	 * @param float $budget Seconds the run may start new requests for.
	 * @return array{sent:int,stop:string} Files sent, and why the run ended:
	 *         `off`, `foreground`, `not_connected`, `reconnect`, `paused`,
	 *         `busy`, `empty`, `budget`, `limit`, `memory`, `storage_full`,
	 *         `backoff` or `gallery_missing`.
	 */
	public function run( float $budget = self::BUDGET ): array {
		$sent = 0;
		$stop = $this->blocked();
		if ( '' !== $stop ) {
			return compact( 'sent', 'stop' );
		}
		if ( ! $this->acquire_lock() ) {
			$stop = 'busy';
			return compact( 'sent', 'stop' );
		}
		try {
			$this->settle_interrupted();
			return $this->drain( $budget );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Sends queued files while the lock is held.
	 *
	 * @param float $budget Seconds the run may start new requests for.
	 * @return array{sent:int,stop:string}
	 */
	private function drain( float $budget ): array {
		$start = (float) ( $this->timer )();
		$sent  = 0;
		$ids   = $this->state->queued( 10 );
		while ( array() !== $ids ) {
			foreach ( $ids as $id ) {
				$stop = $this->limit_reached( $start, $budget, $sent );
				if ( '' === $stop ) {
					$outcome = $this->process( $id, 0 === $sent );
					$sent   += 'sent' === $outcome ? 1 : 0;
					$stop    = in_array( $outcome, array( 'sent', 'next' ), true ) ? '' : $outcome;
				}
				if ( '' !== $stop ) {
					return compact( 'sent', 'stop' );
				}
			}
			$ids = $this->state->queued( 10 );
		}
		$stop = 'empty';
		return compact( 'sent', 'stop' );
	}

	/**
	 * Why the run must stop before the next file, empty when it may go on.
	 *
	 * @param float $start  Timer value at the start of the run.
	 * @param float $budget Seconds the run may start new requests for.
	 * @param int   $sent   Files sent so far.
	 */
	private function limit_reached( float $start, float $budget, int $sent ): string {
		if ( (float) ( $this->timer )() - $start >= $budget ) {
			return 'budget';
		}
		return $sent >= self::MAX_FILES ? 'limit' : '';
	}

	/**
	 * Lifts a pause or a wait, for "Copy now" and after a reconnect.
	 */
	public function resume(): void {
		delete_option( self::PAUSE );
	}

	/**
	 * Why the backup waits, empty when it does not.
	 *
	 * @return array{reason:string,until:int,count:int}
	 */
	public function pause_state(): array {
		$stored = get_option( self::PAUSE, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'reason' => (string) ( $stored['reason'] ?? '' ),
			'until'  => (int) ( $stored['until'] ?? 0 ),
			'count'  => (int) ( $stored['count'] ?? 0 ),
		);
	}

	/**
	 * Why a run may not start, empty when it may.
	 */
	private function blocked(): string {
		if ( ! $this->settings->backup_enabled() ) {
			return 'off';
		}
		if ( ! $this->in_background() ) {
			return 'foreground';
		}
		if ( ! $this->connection->is_connected() ) {
			return 'not_connected';
		}
		if ( $this->connection->needs_write_reconnect( true ) ) {
			return 'reconnect';
		}
		$pause = $this->pause_state();
		if ( '' !== $pause['reason'] && $pause['until'] > $this->now() ) {
			return 'paused';
		}
		return '';
	}

	/**
	 * Whether this request is WP-Cron or WP-CLI. A page request never uploads.
	 */
	private function in_background(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) );
	}

	/**
	 * Items left in `uploading` by a run that died during its request. The
	 * lock is held, so no request of theirs is open, and the answer is lost.
	 */
	private function settle_interrupted(): void {
		foreach ( $this->state->in_state( Backup_State::UPLOADING, 50 ) as $id ) {
			$this->state->needs_check( $id, self::REASON_INTERRUPTED );
			Logger::warning( 'A backup upload was interrupted; the file may be on Profotograaf.', array( 'attachment' => $id ) );
		}
	}

	/**
	 * Handles one queued item.
	 *
	 * @param int  $id    Attachment id.
	 * @param bool $first Whether it is the first file of this run.
	 * @return string `sent`, `next` for an item settled without an upload, or the reason the run stops.
	 */
	private function process( int $id, bool $first ): string {
		$check = $this->eligibility->check( $id );
		if ( Backup_Eligibility::QUEUE !== $check['decision'] ) {
			$this->eligibility->apply( $id );
			return 'next';
		}
		$file  = $check['file'];
		$bytes = (int) filesize( $file );
		if ( $bytes * self::MEMORY_FACTOR + self::MEMORY_RESERVE > (int) ( $this->memory )() ) {
			if ( ! $first ) {
				// Memory used by earlier files of this run; the next run starts fresh.
				return 'memory';
			}
			$this->state->skip( $id, self::REASON_HOST_MEMORY );
			Logger::warning(
				'A backup file is too large for the memory of this site.',
				array(
					'attachment' => $id,
					'bytes'      => $bytes,
				)
			);
			return 'next';
		}

		$sha256 = (string) hash_file( 'sha256', $file );
		$copy   = '' === $sha256 ? null : $this->state->find_copy( $id, $sha256 );
		if ( null !== $copy ) {
			// Copied before: by this site before a reinstall, or as another attachment with the same bytes.
			$this->state->done( $id, $copy['photo_id'], $copy['gallery_id'], $sha256 );
			$this->state->record_copy( $id, $sha256, $copy['photo_id'], $copy['gallery_id'] );
			return 'next';
		}

		$gallery = $this->galleries->id_for( Backup_Galleries::month_of( $id, $this->now() ) );
		if ( is_wp_error( $gallery ) ) {
			return $this->gallery_failed( $gallery );
		}

		$this->state->uploading( $id );
		$result = $this->api->upload_photo( $gallery, $file, wp_basename( $file ), (string) get_post_mime_type( $id ) );
		if ( is_wp_error( $result ) ) {
			return $this->upload_failed( $id, $gallery, $result );
		}
		$this->state->done( $id, $result['id'], $gallery, $sha256 );
		$this->state->record_copy( $id, $sha256, $result['id'], $gallery );
		$this->clear_wait();
		return 'sent';
	}

	/**
	 * Free memory: the PHP memory limit minus what is in use. Unlimited when
	 * the limit is -1.
	 */
	public static function free_memory(): int {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$limit = (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}
		return $limit - memory_get_usage( true );
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
