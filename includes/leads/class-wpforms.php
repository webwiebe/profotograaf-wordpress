<?php
/**
 * WPForms bridge.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `wpforms_process_complete( $fields, $entry, $form_data,
 * $entry_id )`, which WPForms fires after a submission was validated and
 * processed. The form key is `wpforms:<form id>`. The entry id (when the form
 * stores entries) makes the job idempotent.
 */
final class Wpforms implements Bridge {

	private const SKIP = array( 'pagebreak', 'divider', 'html', 'content', 'password', 'captcha', 'hcaptcha', 'turnstile', 'recaptcha', 'layout', 'repeater', 'entry-preview', 'stripe-credit-card', 'payment-single', 'payment-multiple', 'payment-checkbox', 'payment-dropdown', 'payment-total' );

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
		return 'wpforms';
	}

	/**
	 * Name shown to the photographer.
	 */
	public function label(): string {
		return 'WPForms';
	}

	/**
	 * Adds the submission hook.
	 */
	public function register(): void {
		add_action( 'wpforms_process_complete', array( $this, 'on_complete' ), 10, 4 );
	}

	/**
	 * Whether WPForms is active.
	 */
	public function is_active(): bool {
		return function_exists( 'wpforms' );
	}

	/**
	 * Handles a processed form. Never throws.
	 *
	 * @param mixed $fields    Submitted fields by id.
	 * @param mixed $entry     Raw entry.
	 * @param mixed $form_data Form settings and fields.
	 * @param mixed $entry_id  Entry id, or 0 when entries are not stored.
	 */
	public function on_complete( $fields = array(), $entry = array(), $form_data = array(), $entry_id = 0 ): void {
		try {
			if ( ! is_array( $fields ) || ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
				return;
			}

			$list = array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || in_array( (string) ( $field['type'] ?? '' ), self::SKIP, true ) ) {
					continue;
				}
				$id     = (string) ( $field['id'] ?? '' );
				$label  = trim( (string) ( $field['name'] ?? '' ) );
				$list[] = array(
					'id'    => $id,
					'label' => '' !== $label ? $label : 'Field ' . $id,
					'type'  => self::type( (string) ( $field['type'] ?? 'text' ) ),
					'value' => Submission::text( $field['value'] ?? '' ),
				);
			}

			$title = trim( (string) ( $form_data['settings']['form_title'] ?? '' ) );
			$this->dispatcher->submit(
				new Submission(
					'wpforms:' . (int) $form_data['id'],
					'WPForms: ' . $title,
					$list,
					self::page_url( $entry ),
					(int) $entry_id > 0 ? (string) (int) $entry_id : ''
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
		foreach ( (array) wpforms()->obj( 'form' )->get( '', array( 'orderby' => 'title' ) ) as $post ) {
			$data   = wpforms_decode( $post->post_content );
			$fields = array();
			foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
				if ( in_array( (string) ( $field['type'] ?? '' ), self::SKIP, true ) ) {
					continue;
				}
				$fields[] = array(
					'id'    => (string) $field['id'],
					'label' => '' !== trim( (string) ( $field['label'] ?? '' ) ) ? (string) $field['label'] : 'Field ' . $field['id'],
					'type'  => self::type( (string) ( $field['type'] ?? 'text' ) ),
				);
			}
			$forms[] = array(
				'key'    => 'wpforms:' . (int) $post->ID,
				'title'  => (string) $post->post_title,
				'fields' => $fields,
			);
		}
		return $forms;
	}

	/**
	 * Maps a WPForms field type to the plain types Mapping knows.
	 *
	 * @param string $type WPForms type.
	 */
	private static function type( string $type ): string {
		switch ( $type ) {
			case 'date-time':
				return 'date';
			case 'textarea':
			case 'email':
			case 'phone':
			case 'name':
				return $type;
			default:
				return 'text';
		}
	}

	/**
	 * The page the form was sent from.
	 *
	 * @param mixed $entry Raw entry.
	 */
	private static function page_url( $entry ): string {
		if ( is_array( $entry ) && ! empty( $entry['post_id'] ) && function_exists( 'get_permalink' ) ) {
			$link = get_permalink( (int) $entry['post_id'] );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		$referer = function_exists( 'wp_get_raw_referer' ) ? wp_get_raw_referer() : '';
		return is_string( $referer ) ? $referer : '';
	}
}
