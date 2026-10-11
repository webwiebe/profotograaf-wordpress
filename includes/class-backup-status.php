<?php
/**
 * Where the backup stands, for the settings page, Site Health and the notices.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the backup state of the whole site (docs/backup-and-pwa.md, "What
 * the photographer sees"): the items in each Backup_State state, the bytes
 * still to send, the pause of Backup_Worker and whether WP-Cron runs the
 * worker.
 *
 * Every WP-Cron run of the worker records a heartbeat (RUN_OPTION, removed on
 * uninstall with the other `profotograaf_` options): when it ran, how many
 * files it sent, why it stopped, when it last sent a file, and since when the
 * runs have sent nothing while files wait (`idle_since`).
 *
 * - Cron health: the backup counts as not running when the last heartbeat is
 *   older than STALE_AFTER, or, before the first heartbeat, when the upload
 *   event is overdue by that much or the site sets `DISABLE_WP_CRON` and no
 *   cron heartbeat of the plugin was ever seen.
 * - A stuck queue: files wait, the runs sent nothing for STUCK_AFTER, and no
 *   other reason (full storage, a missing permission, no cron) explains it.
 */
final class Backup_Status {

	/** Heartbeat of the upload run. */
	public const RUN_OPTION = 'profotograaf_backup_last_run';

	/** Seconds after the last run when cron counts as not running. The run is due every five minutes. */
	public const STALE_AFTER = 3600;

	/** Seconds without a file sent, while files wait, after which the queue counts as stuck. */
	public const STUCK_AFTER = 21600;

	/** Queued files whose size is added up at most. */
	public const BYTES_SCAN = 1000;

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
	 * Backup state.
	 *
	 * @var Backup_State
	 */
	private Backup_State $state;

	/**
	 * Current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Returns the number of items per state.
	 *
	 * @var callable
	 */
	private $counter;

	/**
	 * Returns whether WP-Cron is disabled.
	 *
	 * @var callable
	 */
	private $cron_disabled;

	/**
	 * Counts, read once per request.
	 *
	 * @var array<string,int>|null
	 */
	private ?array $counts = null;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings      Settings.
	 * @param Connection        $connection    Connection state.
	 * @param Backup_State|null $state         Backup state.
	 * @param callable|null     $clock         Returns the current unix time.
	 * @param callable|null     $counter       Returns state => number of items, a database query by default.
	 * @param callable|null     $cron_disabled Returns whether WP-Cron is disabled, reads `DISABLE_WP_CRON` by default.
	 */
	public function __construct( Settings $settings, Connection $connection, ?Backup_State $state = null, ?callable $clock = null, ?callable $counter = null, ?callable $cron_disabled = null ) {
		$this->settings      = $settings;
		$this->connection    = $connection;
		$this->state         = $state ?? new Backup_State();
		$this->clock         = $clock ?? static fn(): int => time();
		$this->counter       = $counter ?? array( self::class, 'query_counts' );
		$this->cron_disabled = $cron_disabled ?? static fn(): bool => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Records one WP-Cron run of the worker.
	 *
	 * @param array{sent:int,stop:string} $result Result of Backup_Worker::run().
	 * @param int                         $now    Unix time.
	 */
	public static function record_run( array $result, int $now ): void {
		$previous = self::stored_run();
		$idle     = $previous['idle_since'];
		if ( $result['sent'] > 0 || in_array( $result['stop'], array( 'empty', 'off' ), true ) ) {
			$idle = 0;
		} elseif ( 'busy' !== $result['stop'] && 0 === $idle ) {
			$idle = $now;
		}
		update_option(
			self::RUN_OPTION,
			array(
				'at'         => $now,
				'sent'       => $result['sent'],
				'stop'       => $result['stop'],
				'last_sent'  => $result['sent'] > 0 ? $now : $previous['last_sent'],
				'idle_since' => $idle,
			),
			false
		);
	}

	/**
	 * The last heartbeat. Zero times when there was none.
	 *
	 * @return array{at:int,sent:int,stop:string,last_sent:int,idle_since:int}
	 */
	public function last_run(): array {
		return self::stored_run();
	}

	/**
	 * Whether the backup is on and the site connected, so the worker may send.
	 */
	public function active(): bool {
		return $this->settings->backup_enabled() && $this->connection->is_connected();
	}

	/**
	 * Whether the connection lacks the permission to create galleries and send files.
	 */
	public function needs_permission(): bool {
		return $this->connection->needs_write_reconnect( $this->settings->backup_enabled() );
	}

	/**
	 * Items in each state of Backup_State::STATES.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		if ( null === $this->counts ) {
			$found        = (array) ( $this->counter )();
			$this->counts = array();
			foreach ( Backup_State::STATES as $state ) {
				$this->counts[ $state ] = max( 0, (int) ( $found[ $state ] ?? 0 ) );
			}
		}
		return $this->counts;
	}

	/**
	 * Files still to send: queued, or being sent now.
	 */
	public function remaining(): int {
		$counts = $this->counts();
		return $counts[ Backup_State::QUEUED ] + $counts[ Backup_State::UPLOADING ];
	}

	/**
	 * Files that wait for the photographer: failed, or perhaps sent without an answer.
	 */
	public function waiting_for_you(): int {
		$counts = $this->counts();
		return $counts[ Backup_State::FAILED ] + $counts[ Backup_State::NEEDS_CHECK ];
	}

	/**
	 * Every file the backup takes along, skipped files left out.
	 */
	public function total(): int {
		return array_sum( $this->counts() ) - $this->counts()[ Backup_State::SKIPPED ];
	}

	/**
	 * The pause of the worker while it lasts: `storage_full` or `backoff`,
	 * and until when. An empty reason when the backup does not wait.
	 *
	 * @return array{reason:string,until:int}
	 */
	public function pause(): array {
		$stored = get_option( Backup_Worker::PAUSE, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$until  = (int) ( $stored['until'] ?? 0 );
		$reason = (string) ( $stored['reason'] ?? '' );
		if ( '' === $reason || $until <= $this->now() ) {
			return array(
				'reason' => '',
				'until'  => 0,
			);
		}
		return array(
			'reason' => $reason,
			'until'  => $until,
		);
	}

	/**
	 * Whether WP-Cron runs the upload worker.
	 *
	 * @return array{ok:bool,disabled:bool,last_run:int}
	 */
	public function cron(): array {
		$now      = $this->now();
		$last     = $this->last_run()['at'];
		$disabled = (bool) ( $this->cron_disabled )();
		if ( $last > 0 ) {
			$stale = $now - $last > self::STALE_AFTER;
		} else {
			$next  = wp_next_scheduled( Backup_Worker::HOOK );
			$beat  = get_option( Cron_Health::OPTION, 0 );
			$stale = ( false !== $next && $now - (int) $next > self::STALE_AFTER )
				|| ( $disabled && ( ! is_numeric( $beat ) || $now - (int) $beat > Cron_Health::STALE_AFTER ) );
		}
		return array(
			'ok'       => ! $stale,
			'disabled' => $disabled,
			'last_run' => $last,
		);
	}

	/**
	 * Whether files wait and nothing went out for STUCK_AFTER, with no other
	 * reason that explains it: the backup is active, has the permission, is
	 * not paused for a full storage, and cron runs.
	 */
	public function stuck(): bool {
		$idle = $this->last_run()['idle_since'];
		if ( 0 === $idle || $this->now() - $idle <= self::STUCK_AFTER || $this->remaining() < 1 ) {
			return false;
		}
		return $this->active() && ! $this->needs_permission() && 'storage_full' !== $this->pause()['reason'] && $this->cron()['ok'];
	}

	/**
	 * Bytes of the files still to send, from their size on disk. `partial`
	 * is true when more than BYTES_SCAN files wait, and the sum covers the
	 * first BYTES_SCAN.
	 *
	 * @param Backup_Eligibility $eligibility Finds the file the backup sends.
	 * @return array{bytes:int,partial:bool}
	 */
	public function bytes_queued( Backup_Eligibility $eligibility ): array {
		$bytes = 0;
		$ids   = $this->state->in_state( Backup_State::QUEUED, self::BYTES_SCAN );
		foreach ( $ids as $id ) {
			$file = $eligibility->original_file( $id );
			if ( '' === $file ) {
				continue;
			}
			clearstatcache( true, $file );
			$size   = filesize( $file );
			$bytes += false === $size ? 0 : $size;
		}
		return array(
			'bytes'   => $bytes,
			'partial' => $this->counts()[ Backup_State::QUEUED ] > count( $ids ),
		);
	}

	/**
	 * The files that wait for the photographer: failed first, then the ones
	 * that may be on Profotograaf.
	 *
	 * @param int $limit How many at most.
	 * @return array<int,int>
	 */
	public function failures( int $limit ): array {
		$ids = $this->state->in_state( Backup_State::FAILED, $limit );
		if ( count( $ids ) < $limit ) {
			$ids = array_merge( $ids, $this->state->in_state( Backup_State::NEEDS_CHECK, $limit - count( $ids ) ) );
		}
		return $ids;
	}

	/**
	 * Counts the attachments per backup state in one query.
	 *
	 * @return array<string,int>
	 */
	public static function query_counts(): array {
		global $wpdb;
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped count for the status screens, which WP_Query cannot express.
			$wpdb->prepare(
				"SELECT m.meta_value AS state, COUNT(*) AS n FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment' GROUP BY m.meta_value",
				Backup_State::META_STATE
			)
		);
		$counts = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) && isset( $row->state, $row->n ) ) {
				$counts[ (string) $row->state ] = (int) $row->n;
			}
		}
		return $counts;
	}

	/**
	 * The stored heartbeat.
	 *
	 * @return array{at:int,sent:int,stop:string,last_sent:int,idle_since:int}
	 */
	private static function stored_run(): array {
		$stored = get_option( self::RUN_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'at'         => (int) ( $stored['at'] ?? 0 ),
			'sent'       => (int) ( $stored['sent'] ?? 0 ),
			'stop'       => (string) ( $stored['stop'] ?? '' ),
			'last_sent'  => (int) ( $stored['last_sent'] ?? 0 ),
			'idle_since' => (int) ( $stored['idle_since'] ?? 0 ),
		);
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
