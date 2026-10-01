<?php
/**
 * Settings screen fields, drawn from the schema.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Settings;
use Profotograaf\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the form of one settings tab. Every field comes from a
 * Settings_Schema entry, so a new option appears here without code.
 */
final class Settings_Fields {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Prints the form of a tab. It posts to options.php and names the tab, so
	 * saving one tab leaves the others as they are.
	 *
	 * @param string $tab Tab slug.
	 */
	public function render_form( string $tab ): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( Settings::GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>" />
			<table class="form-table" role="presentation">
				<?php foreach ( Settings_Schema::in_tab( $tab ) as $key => $entry ) : ?>
					<tr>
						<th scope="row"><?php $this->render_label( $key, $entry ); ?></th>
						<td>
							<?php $this->render_control( $key, $entry ); ?>
							<p class="description"><?php echo esc_html( $entry['description'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Prints the label of a field.
	 *
	 * @param string              $key   Schema key.
	 * @param array<string,mixed> $entry Schema entry.
	 */
	private function render_label( string $key, array $entry ): void {
		if ( 'bool' === $entry['type'] ) {
			echo esc_html( $entry['label'] );
			return;
		}
		?>
		<label for="<?php echo esc_attr( $this->field_id( $key ) ); ?>"><?php echo esc_html( $entry['label'] ); ?></label>
		<?php
	}

	/**
	 * Prints the input of a field.
	 *
	 * @param string              $key   Schema key.
	 * @param array<string,mixed> $entry Schema entry.
	 */
	private function render_control( string $key, array $entry ): void {
		$id    = $this->field_id( $key );
		$name  = Settings::OPTION . '[' . $key . ']';
		$value = $this->settings->get( $key );

		if ( 'enum' === $entry['type'] ) {
			?>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( (array) ( $entry['choices'] ?? array() ) as $choice => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $choice ); ?>" <?php selected( $value, $choice ); ?>><?php echo esc_html( (string) $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		} elseif ( 'bool' === $entry['type'] ) {
			?>
			<label for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( true, (bool) $value ); ?> />
				<?php echo esc_html( (string) $entry['label'] ); ?>
			</label>
			<?php
		} elseif ( 'int' === $entry['type'] ) {
			?>
			<input type="number" class="small-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) ( $entry['min'] ?? '' ) ); ?>" max="<?php echo esc_attr( (string) ( $entry['max'] ?? '' ) ); ?>" step="1" />
			<?php
		} else {
			?>
			<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php
		}//end if
	}

	/**
	 * Element id of a field.
	 *
	 * @param string $key Schema key.
	 */
	private function field_id( string $key ): string {
		return 'profotograaf-' . str_replace( '_', '-', $key );
	}
}
