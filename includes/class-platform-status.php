<?php
/**
 * The platform status call.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the platform which plugin, WordPress and PHP version this site runs
 * and keeps what the platform answers: whether to ask for a review
 * (`review_prompt`) and where to send error reports (`error_reporting`).
 *
 * A failed call, including the 404 a token gets when it does not belong to a
 * WordPress device, leaves the last stored answer in place.
 */
class Platform_Status {

	public const OPTION = 'profotograaf_platform_status';

	public const PATH = '/api/v1/auth/devices/status';

	/**
	 * Values the platform accepts for `review_event`.
	 */
	public const REVIEW_EVENTS = array( 'shown', 'clicked', 'later', 'dismissed' );

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Api_Client    $api   API client.
	 * @param callable|null $clock Returns the current unix time, `time` by default.
	 */
	public function __construct( Api_Client $api, ?callable $clock = null ) {
		$this->api   = $api;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Sends the stats and stores the answer.
	 *
	 * @param string|null $review_event One of REVIEW_EVENTS to report back, if any.
	 * @return true|WP_Error
	 */
	public function refresh( ?string $review_event = null ) {
		$body = array(
			'plugin_version' => defined( 'PROFOTOGRAAF_VERSION' ) ? PROFOTOGRAAF_VERSION : '',
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'site_url'       => (string) home_url(),
		);
		if ( null !== $review_event && in_array( $review_event, self::REVIEW_EVENTS, true ) ) {
			$body['review_event'] = $review_event;
		}

		$result = $this->api->request( 'POST', self::PATH, $body );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			Logger::warning(
				'The platform status call failed. The last stored answer stays in use.',
				array(
					'code'   => $result->get_error_code(),
					'status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0,
				)
			);
			return $result;
		}

		$answer = is_array( $result ) ? $result : array();
		$this->save(
			array(
				'fetched_at'      => $this->now(),
				'review_prompt'   => $this->review_prompt( $answer['review_prompt'] ?? null ),
				'error_reporting' => self::error_reporting( $answer['error_reporting'] ?? null ),
			)
		);
		return true;
	}

	/**
	 * The stored answer, with defaults before the first successful call.
	 *
	 * @return array{fetched_at:int,attempted_at:int,review_prompt:array{eligible:bool,reason:string,at:string},error_reporting:array{endpoint:string,project:string,key:string,environment:string}|null}
	 */
	public function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'fetched_at'      => (int) ( $stored['fetched_at'] ?? 0 ),
			'attempted_at'    => (int) ( $stored['attempted_at'] ?? 0 ),
			'review_prompt'   => $this->review_prompt( $stored['review_prompt'] ?? null ),
			'error_reporting' => self::error_reporting( $stored['error_reporting'] ?? null ),
		);
	}

	/**
	 * Claims the right to queue a call from the settings page: true at most
	 * once per `$interval` seconds.
	 *
	 * @param int $interval Minimum seconds between two claims.
	 */
	public function claim( int $interval ): bool {
		$attempted = $this->stored()['attempted_at'];
		$now       = $this->now();
		if ( $attempted > 0 && $now - $attempted < $interval ) {
			return false;
		}
		$this->save( array( 'attempted_at' => $now ) );
		return true;
	}

	/**
	 * Removes the stored answer.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Merges values into the stored option.
	 *
	 * @param array<string,mixed> $values Values to write.
	 */
	private function save( array $values ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = array_merge( is_array( $stored ) ? $stored : array(), $values );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $stored, '', false );
			return;
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Reads the review prompt block.
	 *
	 * @param mixed $block Block from the platform or the option.
	 * @return array{eligible:bool,reason:string,at:string}
	 */
	private function review_prompt( $block ): array {
		$block = is_array( $block ) ? $block : array();
		return array(
			'eligible' => true === ( $block['eligible'] ?? false ),
			'reason'   => is_string( $block['reason'] ?? null ) ? $block['reason'] : '',
			'at'       => is_string( $block['at'] ?? null ) ? $block['at'] : '',
		);
	}

	/**
	 * Reads the error reporting block. Null when it is missing or incomplete,
	 * which means nothing is reported.
	 *
	 * @param mixed $block Block from the platform or the option.
	 * @return array{endpoint:string,project:string,key:string,environment:string}|null
	 */
	public static function error_reporting( $block ): ?array {
		if ( ! is_array( $block ) ) {
			return null;
		}
		$block = array(
			'endpoint'    => is_string( $block['endpoint'] ?? null ) ? trim( $block['endpoint'] ) : '',
			'project'     => is_string( $block['project'] ?? null ) ? trim( $block['project'] ) : '',
			'key'         => is_string( $block['key'] ?? null ) ? trim( $block['key'] ) : '',
			'environment' => is_string( $block['environment'] ?? null ) ? trim( $block['environment'] ) : '',
		);

		$scheme = strtolower( (string) wp_parse_url( $block['endpoint'], PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $block['endpoint'], PHP_URL_HOST );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || '' === $block['project'] || '' === $block['key'] ) {
			return null;
		}
		return $block;
	}

	/**
	 * The stored error reporting block without a Platform_Status instance, for
	 * code that only reads it.
	 *
	 * @return array{endpoint:string,project:string,key:string,environment:string}|null
	 */
	public static function stored_error_reporting(): ?array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? self::error_reporting( $stored['error_reporting'] ?? null ) : null;
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
