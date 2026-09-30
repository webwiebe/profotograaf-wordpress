<?php
/**
 * Per form lead settings.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, per form, whether its submissions go to Profotograaf and the field
 * mapping. Nothing is sent for a form that has no entry here.
 *
 * The option is `profotograaf_leads` (`forms` maps a form key such as
 * `cf7:12` to `enabled` and `map`), not autoloaded.
 */
final class Form_Settings {

	public const OPTION = 'profotograaf_leads';

	/**
	 * Settings of one form.
	 *
	 * @param string $form_key Form key.
	 * @return array{enabled:bool,map:array<string,string>}
	 */
	public function get( string $form_key ): array {
		$stored = get_option( self::OPTION, array() );
		$forms  = is_array( $stored ) && isset( $stored['forms'] ) && is_array( $stored['forms'] ) ? $stored['forms'] : array();
		$form   = isset( $forms[ $form_key ] ) && is_array( $forms[ $form_key ] ) ? $forms[ $form_key ] : array();
		$map    = array();
		foreach ( Mapping::TARGETS as $target ) {
			if ( isset( $form['map'][ $target ] ) && is_string( $form['map'][ $target ] ) ) {
				$map[ $target ] = $form['map'][ $target ];
			}
		}
		return array(
			'enabled' => ! empty( $form['enabled'] ),
			'map'     => $map,
		);
	}

	/**
	 * Whether a form sends its submissions.
	 *
	 * @param string $form_key Form key.
	 */
	public function enabled( string $form_key ): bool {
		return $this->get( $form_key )['enabled'];
	}

	/**
	 * Validates a submitted settings form and stores it.
	 *
	 * Only the given form keys are stored, so a form that was removed drops out.
	 *
	 * @param array<mixed>      $raw        Submitted `forms` array, unslashed.
	 * @param array<int,string> $known_keys Keys of the forms that exist.
	 * @return array<string,array{enabled:bool,map:array<string,string>}> What was stored.
	 */
	public function save( array $raw, array $known_keys ): array {
		$forms = array();
		foreach ( $known_keys as $key ) {
			$row = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			$map = array();
			foreach ( Mapping::TARGETS as $target ) {
				$choice = isset( $row['map'][ $target ] ) && is_string( $row['map'][ $target ] ) ? trim( $row['map'][ $target ] ) : '';
				if ( '' !== $choice && ( Mapping::NONE === $choice || 1 === preg_match( '/^[A-Za-z0-9_.\-]{1,100}$/', $choice ) ) ) {
					$map[ $target ] = $choice;
				}
			}
			$forms[ $key ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'map'     => $map,
			);
		}
		update_option( self::OPTION, array( 'forms' => $forms ), false );
		return $forms;
	}
}
