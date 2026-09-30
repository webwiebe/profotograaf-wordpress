<?php
/**
 * Gravity Forms bridge.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `gform_after_submission( $entry, $form )`, which Gravity Forms
 * fires once the entry is saved and its notifications were sent. The form key
 * is `gf:<form id>` and the entry id makes the job idempotent.
 *
 * Fields with several inputs (name, address, checkboxes) are read from their
 * input ids (`1.3`, `1.6`) and joined into one value.
 */
final class Gravity_Forms implements Bridge {

	private const SKIP = array( 'page', 'section', 'html', 'captcha', 'password', 'honeypot', 'submit', 'hidden', 'creditcard' );

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Dispatcher $dispatcher Dispatcher.
	 */
	public function __construct( Dispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Short id.
	 */
	public function id(): string {
		return 'gf';
	}

	/**
	 * Name shown to the photographer.
	 */
	public function label(): string {
		return 'Gravity Forms';
	}

	/**
	 * Adds the submission hook.
	 */
	public function register(): void {
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 10, 2 );
	}

	/**
	 * Whether Gravity Forms is active.
	 */
	public function is_active(): bool {
		return class_exists( 'GFAPI' );
	}

	/**
	 * Handles a saved entry. Never throws.
	 *
	 * @param mixed $entry Entry, an array keyed by field and input id.
	 * @param mixed $form  Form, an array with `fields` (GF_Field objects).
	 */
	public function on_submission( $entry = array(), $form = array() ): void {
		try {
			if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['id'] ) ) {
				return;
			}

			$list = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				if ( ! is_object( $field ) || in_array( (string) $field->type, self::SKIP, true ) ) {
					continue;
				}
				$list[] = array(
					'id'    => (string) $field->id,
					'label' => self::label_of( $field ),
					'type'  => self::type( (string) $field->type ),
					'value' => self::value( $entry, $field ),
				);
			}

			$this->dispatcher->submit(
				new Submission(
					'gf:' . (int) $form['id'],
					'Gravity Forms: ' . trim( (string) ( $form['title'] ?? '' ) ),
					$list,
					isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '',
					isset( $entry['id'] ) ? (string) (int) $entry['id'] : ''
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}//end try
	}

	/**
	 * The forms of the site.
	 *
	 * @return array<int,array{key:string,title:string,fields:array<int,array{id:string,label:string,type:string}>}>
	 */
	public function forms(): array {
		if ( ! $this->is_active() ) {
			return array();
		}
		$forms = array();
		foreach ( (array) \GFAPI::get_forms() as $form ) {
			$fields = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				if ( ! is_object( $field ) || in_array( (string) $field->type, self::SKIP, true ) ) {
					continue;
				}
				$fields[] = array(
					'id'    => (string) $field->id,
					'label' => self::label_of( $field ),
					'type'  => self::type( (string) $field->type ),
				);
			}
			$forms[] = array(
				'key'    => 'gf:' . (int) $form['id'],
				'title'  => (string) ( $form['title'] ?? '' ),
				'fields' => $fields,
			);
		}
		return $forms;
	}

	/**
	 * The value of a field in an entry.
	 *
	 * @param array<mixed> $entry Entry.
	 * @param object       $field GF_Field.
	 */
	private static function value( array $entry, $field ): string {
		$inputs = isset( $field->inputs ) && is_array( $field->inputs ) ? $field->inputs : array();
		if ( array() === $inputs ) {
			return Submission::text( $entry[ (string) $field->id ] ?? '' );
		}
		$parts = array();
		foreach ( $inputs as $input ) {
			if ( ! empty( $input['isHidden'] ) || ! isset( $input['id'] ) ) {
				continue;
			}
			$part = Submission::text( $entry[ (string) $input['id'] ] ?? '' );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( 'checkbox' === (string) $field->type ? ', ' : ' ', $parts );
	}

	/**
	 * The label of a field.
	 *
	 * @param object $field GF_Field.
	 */
	private static function label_of( $field ): string {
		$label = trim( (string) ( $field->label ?? '' ) );
		if ( '' === $label ) {
			$label = trim( (string) ( $field->adminLabel ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms names this property.
		}
		return '' !== $label ? $label : 'Field ' . $field->id;
	}

	/**
	 * Maps a Gravity Forms field type to the plain types Mapping knows.
	 *
	 * @param string $type Gravity Forms type.
	 */
	private static function type( string $type ): string {
		switch ( $type ) {
			case 'textarea':
			case 'email':
			case 'phone':
			case 'name':
			case 'date':
				return $type;
			default:
				return 'text';
		}
	}
}
