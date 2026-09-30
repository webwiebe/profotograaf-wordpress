<?php
/**
 * A form submission, normalised across form plugins.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * What a bridge hands to the dispatcher: which form, the fields in form order
 * and where it was sent from.
 *
 * Each field is an array with `id` (the form plugin's own key), `label`
 * (shown to the photographer), `type` (one of email, phone, date, name,
 * textarea, text or another plain type) and `value` (a string).
 */
final class Submission {

	/**
	 * Stable key of the form, such as `cf7:12`.
	 *
	 * @var string
	 */
	public string $form_key;

	/**
	 * Text for the lead's source_form, such as "Contact Form 7: Wedding inquiry".
	 *
	 * @var string
	 */
	public string $form_label;

	/**
	 * Fields in form order.
	 *
	 * @var array<int,array{id:string,label:string,type:string,value:string}>
	 */
	public array $fields;

	/**
	 * Page the form was sent from.
	 *
	 * @var string
	 */
	public string $page_url;

	/**
	 * The form plugin's entry id, empty when it has none.
	 *
	 * @var string
	 */
	public string $entry_id;

	/**
	 * Constructor.
	 *
	 * @param string                                                            $form_key   Form key.
	 * @param string                                                            $form_label Source label.
	 * @param array<int,array{id:string,label:string,type:string,value:string}> $fields     Fields.
	 * @param string                                                            $page_url   Page URL.
	 * @param string                                                            $entry_id   Entry id.
	 */
	public function __construct( string $form_key, string $form_label, array $fields, string $page_url = '', string $entry_id = '' ) {
		$this->form_key   = $form_key;
		$this->form_label = $form_label;
		$this->fields     = $fields;
		$this->page_url   = $page_url;
		$this->entry_id   = $entry_id;
	}

	/**
	 * Turns a posted value into one string.
	 *
	 * Lists (checkboxes, multiple selects) are joined with a comma, control
	 * characters are removed and the result is trimmed.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function text( $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				$part = self::text( $item );
				if ( '' !== $part ) {
					$parts[] = $part;
				}
			}
			return implode( ', ', $parts );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value );
		return trim( (string) $clean );
	}
}
