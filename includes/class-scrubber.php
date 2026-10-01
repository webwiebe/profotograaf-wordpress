<?php
/**
 * Removes secrets and personal data from text before it leaves the site.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The scrubbing rules of docs/telemetry.md, "Error Collection":
 *
 * - tokens and API keys become `[TOKEN]`,
 * - email addresses become `[EMAIL]`,
 * - URLs and domains become `[URL]`,
 * - paths outside the plugin directory become `[PATH]`. A path inside it turns
 *   relative, for example `includes/class-api-client.php`.
 */
final class Scrubber {

	private const MAX_LENGTH = 500;

	/**
	 * File extensions that look like a top-level domain and are not one.
	 */
	private const FILE_EXTENSIONS = 'php|js|json|css|txt|md|html|log|inc|ts|tsx|mjs|po|pot|mo|xml|yml|yaml|lock';

	/**
	 * Scrubs one string.
	 *
	 * @param string $text Text.
	 */
	public static function string( string $text ): string {
		$dir = defined( 'PROFOTOGRAAF_DIR' ) ? (string) PROFOTOGRAAF_DIR : '';
		if ( '' !== $dir ) {
			$text = str_replace( $dir, '', $text );
		}

		$rules = array(
			array( '#\b(?:https?|ftp)://[^\s"\'<>]+#i', '[URL]' ),
			array( '#\bwww\.[^\s"\'<>]+#i', '[URL]' ),
			array( '#[^\s@<>"\']+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+#', '[EMAIL]' ),
			array( '#\bBearer\s+\S+#i', '[TOKEN]' ),
			array( '#\b((?:api[_-]?)?(?:token|key|secret|password))=\S+#i', '$1=[TOKEN]' ),
			array( '#\b[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b#', '[TOKEN]' ),
			array( '#\b[A-Za-z]:\\\\[^\s"\'<>:]+#', '[PATH]' ),
			array( '#(?<![\w:/.])/(?:[\w.\-@+~]+/)+[\w.\-@+~]*#', '[PATH]' ),
			array( '#\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?!(?:' . self::FILE_EXTENSIONS . ')\b)[a-z]{2,}\b#i', '[URL]' ),
		);
		foreach ( $rules as list( $pattern, $replacement ) ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}

		$text = (string) preg_replace_callback(
			'#[A-Za-z0-9_\-+=]{24,}#',
			fn( array $found ) => self::looks_like_token( $found[0] ) ? '[TOKEN]' : $found[0],
			$text
		);
		return mb_substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * Scrubs an error code and reduces it to slug characters.
	 *
	 * @param string $code Error code.
	 */
	public static function code( string $code ): string {
		$slug = (string) preg_replace( '/[^A-Za-z0-9_.\-]+/', '_', self::string( $code ) );
		$slug = (string) preg_replace( '/_{2,}/', '_', $slug );
		$slug = substr( trim( $slug, '_' ), 0, 64 );
		return '' === $slug ? 'unknown' : $slug;
	}

	/**
	 * Scrubs every string in an event. Numbers, booleans and null pass. Objects
	 * and resources are dropped.
	 *
	 * @param array<mixed> $event Event.
	 * @return array<mixed>
	 */
	public static function event( array $event ): array {
		$clean = array();
		foreach ( $event as $key => $value ) {
			if ( is_string( $value ) ) {
				$clean[ $key ] = self::string( $value );
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = self::event( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Whether a long run of characters is a secret rather than a snake_case
	 * name: it holds a digit, or mixes upper and lower case.
	 *
	 * @param string $run Run of token characters.
	 */
	private static function looks_like_token( string $run ): bool {
		return 1 === preg_match( '/\d/', $run ) || ( 1 === preg_match( '/[a-z]/', $run ) && 1 === preg_match( '/[A-Z]/', $run ) );
	}
}
