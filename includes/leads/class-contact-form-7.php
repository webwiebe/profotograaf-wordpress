<?php
/**
 * Contact Form 7 bridge.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `wpcf7_mail_sent`, which Contact Form 7 fires after a
 * submission passed validation and its mail went out. The form key is
 * `cf7:<form id>`.
 *
 * Contact Form 7 has no field labels, so the field name (`your-name`) is the
 * label, made readable.
 */
final class Contact_Form_7 implements Bridge {

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
		return 'cf7';
	}

	/**
	 * Name shown to the photographer.
	 */
	public function label(): string {
		return 'Contact Form 7';
	}

	/**
	 * Adds the submission hook.
	 */
	public function register(): void {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ) );
	}

	/**
	 * Whether Contact Form 7 is active.
	 */
	public function is_active(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Handles a sent form. Never throws.
	 *
	 * @param object $contact_form The WPCF7_ContactForm.
	 */
	public function on_mail_sent( $contact_form ): void {
		try {
			if ( ! is_object( $contact_form ) || ! class_exists( 'WPCF7_Submission' ) ) {
				return;
			}
			$submission = \WPCF7_Submission::get_instance();
			if ( ! is_object( $submission ) ) {
				return;
			}

			$types = array();
			foreach ( self::tags( $contact_form ) as $tag ) {
				$types[ (string) $tag->name ] = self::type( (string) $tag->basetype );
			}
			$fields = array();
			foreach ( (array) $submission->get_posted_data() as $name => $value ) {
				$name = (string) $name;
				if ( '' === $name || '_' === $name[0] ) {
					continue;
				}
				$fields[] = array(
					'id'    => $name,
					'label' => self::humanize( $name ),
					'type'  => $types[ $name ] ?? 'text',
					'value' => Submission::text( $value ),
				);
			}

			$this->dispatcher->submit(
				new Submission(
					'cf7:' . (int) $contact_form->id(),
					'Contact Form 7: ' . (string) $contact_form->title(),
					$fields,
					self::page_url( $submission )
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
		foreach ( \WPCF7_ContactForm::find() as $form ) {
			$fields = array();
			foreach ( self::tags( $form ) as $tag ) {
				$fields[] = array(
					'id'    => (string) $tag->name,
					'label' => self::humanize( (string) $tag->name ),
					'type'  => self::type( (string) $tag->basetype ),
				);
			}
			$forms[] = array(
				'key'    => 'cf7:' . (int) $form->id(),
				'title'  => (string) $form->title(),
				'fields' => $fields,
			);
		}
		return $forms;
	}

	/**
	 * The page the form was sent from.
	 *
	 * Contact Form 7 submits through the REST API, so its own `url` meta can be
	 * the endpoint. The post that holds the form is the reliable answer, then
	 * the meta when it is an absolute address, then the referer.
	 *
	 * @param object $submission The WPCF7_Submission.
	 */
	private static function page_url( $submission ): string {
		$post_id = (int) $submission->get_meta( 'container_post_id' );
		if ( $post_id > 0 ) {
			$link = get_permalink( $post_id );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		$url = $submission->get_meta( 'url' );
		if ( is_string( $url ) && preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$referer = function_exists( 'wp_get_raw_referer' ) ? wp_get_raw_referer() : '';
		return is_string( $referer ) ? $referer : '';
	}

	/**
	 * The input tags of a form, without buttons and other tags with no value.
	 *
	 * @param object $form The WPCF7_ContactForm.
	 * @return array<int,object>
	 */
	private static function tags( $form ): array {
		$tags = array();
		foreach ( (array) $form->scan_form_tags() as $tag ) {
			if ( is_object( $tag ) && '' !== (string) $tag->name && ! in_array( (string) $tag->basetype, array( 'submit', 'quiz', 'captchac', 'captchar', 'acceptance', 'recaptcha', 'turnstile', 'hcaptcha' ), true ) ) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}

	/**
	 * Maps a Contact Form 7 field type to the plain types Mapping knows.
	 *
	 * @param string $basetype Tag base type.
	 */
	private static function type( string $basetype ): string {
		return 'tel' === $basetype ? 'phone' : $basetype;
	}

	/**
	 * `your-event_date` becomes "Your event date".
	 *
	 * @param string $name Field name.
	 */
	private static function humanize( string $name ): string {
		$text = trim( (string) preg_replace( '/[-_\s]+/', ' ', $name ) );
		return ucfirst( $text );
	}
}
