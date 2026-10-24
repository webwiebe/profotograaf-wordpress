<?php
/**
 * The settings of the enquiry block on the forms screen.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Leads\Enquiry_Form;
use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\Skip_Counter;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the switch and source tag of this plugin's own enquiry block, and the
 * note about submissions that were skipped for lack of an email address.
 */
final class Leads_Block_Form {

	/**
	 * Per form settings.
	 *
	 * @var Form_Settings
	 */
	private Form_Settings $settings;

	/**
	 * Submissions skipped for lack of an email address.
	 *
	 * @var Skip_Counter
	 */
	private Skip_Counter $skips;

	/**
	 * Constructor.
	 *
	 * @param Form_Settings $settings Per form settings.
	 * @param Skip_Counter  $skips    Skipped submissions.
	 */
	public function __construct( Form_Settings $settings, Skip_Counter $skips ) {
		$this->settings = $settings;
		$this->skips    = $skips;
	}

	/**
	 * Renders the switch of this plugin's own enquiry block.
	 */
	public function render(): void {
		$config = $this->settings->get( Enquiry_Form::FORM_KEY );
		$base   = 'forms[' . Enquiry_Form::FORM_KEY . ']';
		?>
		<fieldset class="profotograaf-leads-form">
			<legend><?php esc_html_e( 'Profotograaf enquiry block', 'profotograaf' ); ?></legend>
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( $config['enabled'] ); ?>>
					<?php esc_html_e( 'Send enquiries from the enquiry block to Profotograaf', 'profotograaf' ); ?>
				</label>
			</p>
			<p>
				<label for="profotograaf-native-enquiry-source-tag"><?php esc_html_e( 'Source tag', 'profotograaf' ); ?></label>
				<input type="text" class="regular-text" maxlength="<?php echo esc_attr( (string) Form_Settings::MAX_TAG ); ?>" id="profotograaf-native-enquiry-source-tag" name="<?php echo esc_attr( $base . '[source_tag]' ); ?>" value="<?php echo esc_attr( $config['source_tag'] ); ?>">
			</p>
			<p class="description"><?php esc_html_e( 'The block is part of this plugin, so it needs no field mapping. Add it from the block inserter, or start from the contact page pattern.', 'profotograaf' ); ?></p>
			<?php $this->render_skips( Enquiry_Form::FORM_KEY ); ?>
		</fieldset>
		<?php
	}

	/**
	 * Says how many submissions of a form were not sent for lack of an email address.
	 *
	 * @param string $form_key Form key.
	 */
	public function render_skips( string $form_key ): void {
		$skipped = $this->skips->all()[ $form_key ] ?? null;
		if ( null === $skipped || $skipped['count'] < 1 ) {
			return;
		}
		?>
		<p class="notice notice-warning inline">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of submissions. */
					_n( '%s submission was not sent because it had no email address. Check that the form has an email field.', '%s submissions were not sent because they had no email address. Check that the form has an email field.', $skipped['count'], 'profotograaf' ),
					number_format_i18n( $skipped['count'] )
				)
			);
			?>
		</p>
		<?php
	}
}
