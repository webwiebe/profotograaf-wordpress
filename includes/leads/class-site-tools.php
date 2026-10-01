<?php
/**
 * Maintenance actions of the Enquiry forms screen.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

use Profotograaf\Api_Client;
use Profotograaf\Embed_Script;
use Profotograaf\Gallery_Index;
use Profotograaf\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Refreshes the gallery index and the embed script version on request, and
 * keeps the translated result for the next page load. Each action returns a
 * result with a `type` (success or error) and a `message`.
 */
final class Site_Tools {

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Embed script.
	 *
	 * @var Embed_Script
	 */
	private Embed_Script $embed;

	/**
	 * Constructor.
	 *
	 * @param Api_Client   $api   API client.
	 * @param Embed_Script $embed Embed script.
	 */
	public function __construct( Api_Client $api, Embed_Script $embed ) {
		$this->api   = $api;
		$this->embed = $embed;
	}

	/**
	 * Fetches the gallery list now and stores it in the gallery index.
	 *
	 * @return array{type:string,message:string}
	 */
	public function sync_galleries(): array {
		$rows = $this->api->list_galleries();
		if ( is_wp_error( $rows ) ) {
			Logger::warning( 'The gallery list could not be synced from the settings screen.', array( 'code' => $rows->get_error_code() ) );
			return $this->result(
				'error',
				sprintf(
					/* translators: %s: reason the galleries could not be loaded. */
					__( 'The galleries could not be synced: %s', 'profotograaf' ),
					$rows->get_error_message()
				)
			);
		}
		( new Gallery_Index( $this->api ) )->remember( $rows );
		return $this->result(
			'success',
			sprintf(
				/* translators: %s: number of galleries. */
				_n( 'Synced %s gallery.', 'Synced %s galleries.', count( $rows ), 'profotograaf' ),
				number_format_i18n( count( $rows ) )
			)
		);
	}

	/**
	 * Forgets the remembered gallery titles and links.
	 *
	 * @return array{type:string,message:string}
	 */
	public function clear_gallery_index(): array {
		delete_option( Gallery_Index::OPTION );
		delete_transient( Gallery_Index::LOOKUP_GUARD );
		return $this->result( 'success', __( 'The gallery index was cleared. It fills again when a gallery is shown or synced.', 'profotograaf' ) );
	}

	/**
	 * Reads the current embed script version from the platform now.
	 *
	 * @return array{type:string,message:string}
	 */
	public function check_embed_version(): array {
		$this->embed->refresh();
		$version = $this->embed->version();
		if ( '' === $version ) {
			Logger::warning( 'The embed script version could not be read from the settings screen.' );
			return $this->result( 'error', __( 'The embed script version could not be read. Galleries keep using the address without a version. Try again later.', 'profotograaf' ) );
		}
		return $this->result(
			'success',
			sprintf(
				/* translators: %s: version of the embed script, 12 characters. */
				__( 'The embed script is at version %s.', 'profotograaf' ),
				$version
			)
		);
	}

	/**
	 * Keeps a result for the current user's next page load.
	 *
	 * @param array{type:string,message:string} $result Result of an action.
	 */
	public static function remember( array $result ): void {
		set_transient( self::key(), $result, MINUTE_IN_SECONDS );
	}

	/**
	 * Returns and clears the result left for the current user.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function take(): ?array {
		$stored = get_transient( self::key() );
		if ( ! is_array( $stored ) || empty( $stored['message'] ) ) {
			return null;
		}
		delete_transient( self::key() );
		return array(
			'type'    => 'error' === ( $stored['type'] ?? '' ) ? 'error' : 'success',
			'message' => (string) $stored['message'],
		);
	}

	/**
	 * Transient name for the current user's result.
	 */
	private static function key(): string {
		return 'profotograaf_leads_notice_' . get_current_user_id();
	}

	/**
	 * Builds a result.
	 *
	 * @param string $type    success or error.
	 * @param string $message Text.
	 * @return array{type:string,message:string}
	 */
	private function result( string $type, string $message ): array {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
