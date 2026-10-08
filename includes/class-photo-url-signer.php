<?php
/**
 * Signed, short-lived photo file URLs.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies the query of a photo file URL.
 *
 * The signature is an HMAC over the photo id, the user id and the expiry, keyed
 * with the site's auth salt. A URL signed for one user or one photo fails for
 * any other, and it stops working at the expiry time.
 */
final class Photo_Url_Signer {

	/** Seconds a signed URL stays valid. */
	public const LIFETIME = 900;

	/**
	 * Clock override for tests.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current unix time.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock;
	}

	/**
	 * Signs a photo for a user.
	 *
	 * @param string $photo_id Platform photo id.
	 * @param int    $user_id  WordPress user id.
	 * @return array{exp:int,sig:string}
	 */
	public function sign( string $photo_id, int $user_id ): array {
		$expires = $this->now() + self::LIFETIME;
		return array(
			'exp' => $expires,
			'sig' => $this->mac( $photo_id, $user_id, $expires ),
		);
	}

	/**
	 * Checks a signature.
	 *
	 * @param string $photo_id Platform photo id.
	 * @param int    $user_id  WordPress user id of the current request.
	 * @param int    $expires  Expiry from the URL.
	 * @param string $sig      Signature from the URL.
	 * @return bool True when the signature is genuine and has not expired.
	 */
	public function verify( string $photo_id, int $user_id, int $expires, string $sig ): bool {
		if ( $user_id <= 0 || $expires < $this->now() ) {
			return false;
		}
		return hash_equals( $this->mac( $photo_id, $user_id, $expires ), $sig );
	}

	/**
	 * The HMAC of the signed values.
	 *
	 * @param string $photo_id Photo id.
	 * @param int    $user_id  User id.
	 * @param int    $expires  Expiry.
	 */
	private function mac( string $photo_id, int $user_id, int $expires ): string {
		return hash_hmac( 'sha256', implode( '|', array( 'profotograaf-photo-file', $photo_id, $user_id, $expires ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return null !== $this->clock ? (int) ( $this->clock )() : time();
	}
}
