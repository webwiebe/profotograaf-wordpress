<?php
/**
 * Forms the photographer has not reviewed yet.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * A form that has no saved entry sends its enquiries by default. This lists
 * those forms, so the photographer can see which ones started sending and
 * review them on the Enquiry forms screen. Saving that screen stores an entry
 * for every form on it, which ends the list.
 */
final class New_Forms {

	/**
	 * Bridges.
	 *
	 * @var Bridge[]
	 */
	private array $bridges;

	/**
	 * Per form settings.
	 *
	 * @var Form_Settings
	 */
	private Form_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Bridge[]      $bridges  Bridges.
	 * @param Form_Settings $settings Per form settings.
	 */
	public function __construct( array $bridges, Form_Settings $settings ) {
		$this->bridges  = $bridges;
		$this->settings = $settings;
	}

	/**
	 * Names of the forms without a saved entry, such as `Contact Form 7: Wedding`.
	 *
	 * @return string[]
	 */
	public function labels(): array {
		$saved  = $this->settings->saved_keys();
		$labels = array();
		foreach ( $this->bridges as $bridge ) {
			if ( ! $bridge->is_active() ) {
				continue;
			}
			foreach ( $bridge->forms() as $form ) {
				if ( Enquiry_Form::FORM_KEY === $form['key'] || in_array( $form['key'], $saved, true ) ) {
					continue;
				}
				$labels[] = $bridge->label() . ': ' . $form['title'];
			}
		}
		return $labels;
	}
}
