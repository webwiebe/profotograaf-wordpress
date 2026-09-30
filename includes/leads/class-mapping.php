<?php
/**
 * Field mapping and lead payload.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which form field feeds which lead field and builds the payload for
 * the leads API (docs/features.md in the platform repository: the caps below
 * are the platform's).
 *
 * A mapping is `target => field id`. A target left empty is detected from the
 * field types and names, and the value `-` switches the target off. Fields
 * that no target uses travel as `extra_fields` label and value pairs.
 */
final class Mapping {

	public const TARGETS = array( 'name', 'email', 'phone', 'event_date', 'message' );

	/**
	 * The value that switches a target off.
	 */
	public const NONE = '-';

	private const DETECT_ORDER = array( 'email', 'phone', 'event_date', 'name', 'message' );

	private const TYPES = array(
		'email'      => array( 'email' ),
		'phone'      => array( 'phone' ),
		'event_date' => array( 'date' ),
		'name'       => array( 'name' ),
		'message'    => array( 'textarea' ),
	);

	private const PATTERNS = array(
		'email'      => '/e-?mail/',
		'phone'      => '/(phone|tel|telefoon|mobile|mobiel|gsm)/',
		'event_date' => '/(date|datum)/',
		'name'       => '/(^|[^a-z])(name|naam|voornaam|fullname)([^a-z]|$)/',
		'message'    => '/(message|bericht|comment|opmerking|vraag|question|wishes|wensen)/',
	);

	private const MAX_NAME        = 200;
	private const MAX_EMAIL       = 320;
	private const MAX_PHONE       = 60;
	private const MAX_EVENT_DATE  = 40;
	private const MAX_MESSAGE     = 8000;
	private const MAX_SOURCE_FORM = 200;
	private const MAX_PAGE_URL    = 2000;
	private const MAX_EXTRA       = 30;
	private const MAX_LABEL       = 100;
	private const MAX_VALUE       = 2000;
	private const MAX_BODY_BYTES  = 30000;

	/**
	 * Detects the target of each field by type, then by name.
	 *
	 * @param array<int,array{id:string,label:string,type:string,value?:string}> $fields Fields in form order.
	 * @param array<int,string>                                                  $taken  Field ids that are not available.
	 * @param array<int,string>                                                  $only   Targets to detect, all by default.
	 * @return array<string,string> Target to field id.
	 */
	public static function detect( array $fields, array $taken = array(), array $only = array() ): array {
		$found = array();
		foreach ( self::DETECT_ORDER as $target ) {
			if ( array() !== $only && ! in_array( $target, $only, true ) ) {
				continue;
			}
			$id = self::detect_one( $target, $fields, $taken );
			if ( '' !== $id ) {
				$found[ $target ] = $id;
				$taken[]          = $id;
			}
		}
		return $found;
	}

	/**
	 * Resolves a stored mapping against the fields of a form.
	 *
	 * An explicit choice that names a field the form no longer has falls back
	 * to detection.
	 *
	 * @param array<int,array{id:string,label:string,type:string,value?:string}> $fields Fields.
	 * @param array<string,string>                                               $map    Stored mapping.
	 * @return array<string,string> Target to field id, only for targets that have one.
	 */
	public static function resolve( array $fields, array $map ): array {
		$ids      = array_column( $fields, 'id' );
		$resolved = array();
		$open     = array();
		foreach ( self::TARGETS as $target ) {
			$choice = isset( $map[ $target ] ) ? (string) $map[ $target ] : '';
			if ( self::NONE === $choice ) {
				continue;
			}
			if ( '' !== $choice && in_array( $choice, $ids, true ) && ! in_array( $choice, $resolved, true ) ) {
				$resolved[ $target ] = $choice;
				continue;
			}
			$open[] = $target;
		}
		return $resolved + self::detect( $fields, array_values( $resolved ), $open );
	}

	/**
	 * Builds the payload for the leads API.
	 *
	 * @param Submission           $submission Submission.
	 * @param array<string,string> $map        Stored mapping.
	 * @return array<string,mixed>|null Null when the submission has no usable email address.
	 */
	public static function payload( Submission $submission, array $map ): ?array {
		$resolved = self::resolve( $submission->fields, $map );
		$values   = array();
		foreach ( $submission->fields as $field ) {
			$values[ $field['id'] ] = Submission::text( $field['value'] ?? '' );
		}

		$email = isset( $resolved['email'] ) ? $values[ $resolved['email'] ] : '';
		if ( ! is_email( $email ) ) {
			return null;
		}

		$name = isset( $resolved['name'] ) ? $values[ $resolved['name'] ] : '';
		if ( '' === $name ) {
			$name = (string) strstr( $email, '@', true );
		}

		$lead = array(
			'name'        => self::cut( $name, self::MAX_NAME ),
			'email'       => self::cut( $email, self::MAX_EMAIL ),
			'phone'       => self::cut( isset( $resolved['phone'] ) ? $values[ $resolved['phone'] ] : '', self::MAX_PHONE ),
			'event_date'  => self::cut( isset( $resolved['event_date'] ) ? $values[ $resolved['event_date'] ] : '', self::MAX_EVENT_DATE ),
			'message'     => self::cut( isset( $resolved['message'] ) ? $values[ $resolved['message'] ] : '', self::MAX_MESSAGE ),
			'source'      => 'wordpress',
			'source_form' => self::cut( $submission->form_label, self::MAX_SOURCE_FORM ),
			'page_url'    => self::page_url( $submission->page_url ),
		);

		$used  = array_values( $resolved );
		$extra = array();
		foreach ( $submission->fields as $field ) {
			$value = $values[ $field['id'] ];
			if ( in_array( $field['id'], $used, true ) || '' === $value ) {
				continue;
			}
			$label = self::cut( '' !== trim( $field['label'] ) ? $field['label'] : $field['id'], self::MAX_LABEL );
			if ( '' === $label ) {
				continue;
			}
			$extra[] = array(
				'label' => $label,
				'value' => self::cut( $value, self::MAX_VALUE ),
			);
			if ( count( $extra ) >= self::MAX_EXTRA ) {
				break;
			}
		}
		$lead['extra_fields'] = $extra;

		// The platform refuses a body over 32 KB: drop extras from the end first.
		$size = strlen( (string) wp_json_encode( $lead ) );
		while ( array() !== $lead['extra_fields'] && $size > self::MAX_BODY_BYTES ) {
			array_pop( $lead['extra_fields'] );
			$size = strlen( (string) wp_json_encode( $lead ) );
		}

		return array_filter(
			$lead,
			static fn( $value ): bool => '' !== $value && array() !== $value
		);
	}

	/**
	 * Finds the field for one target.
	 *
	 * @param string                                                             $target Target.
	 * @param array<int,array{id:string,label:string,type:string,value?:string}> $fields Fields.
	 * @param array<int,string>                                                  $taken  Unavailable field ids.
	 */
	private static function detect_one( string $target, array $fields, array $taken ): string {
		foreach ( $fields as $field ) {
			if ( ! in_array( $field['id'], $taken, true ) && in_array( $field['type'], self::TYPES[ $target ], true ) ) {
				return $field['id'];
			}
		}
		foreach ( $fields as $field ) {
			if ( in_array( $field['id'], $taken, true ) ) {
				continue;
			}
			if ( 'email' !== $target && 'email' === $field['type'] ) {
				continue;
			}
			$haystack = strtolower( $field['id'] . ' ' . $field['label'] );
			if ( preg_match( self::PATTERNS[ $target ], $haystack ) ) {
				return $field['id'];
			}
		}
		return '';
	}

	/**
	 * Cuts a string to a number of characters.
	 *
	 * @param string $value Text.
	 * @param int    $max   Most characters.
	 */
	private static function cut( string $value, int $max ): string {
		return mb_strlen( $value ) > $max ? mb_substr( $value, 0, $max ) : $value;
	}

	/**
	 * The page URL when it is a usable http or https address.
	 *
	 * @param string $url URL.
	 */
	private static function page_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || mb_strlen( $url ) > self::MAX_PAGE_URL || ! preg_match( '#^https?://[^\s/]+#i', $url ) ) {
			return '';
		}
		return $url;
	}
}
