<?php
/**
 * Stand-ins for the Contact Form 7 classes the bridge calls.
 *
 * @package Profotograaf
 */

if ( ! class_exists( 'WPCF7_Submission' ) ) {
	/**
	 * Submission stand-in.
	 */
	class WPCF7_Submission {

		/**
		 * The submission get_instance() returns.
		 *
		 * @var WPCF7_Submission|null
		 */
		public static $current = null;

		/**
		 * Posted data.
		 *
		 * @var array<string,mixed>
		 */
		public $posted = array();

		/**
		 * Meta.
		 *
		 * @var array<string,string>
		 */
		public $meta = array();

		/**
		 * The current submission.
		 */
		public static function get_instance() {
			return self::$current;
		}

		/**
		 * Posted data.
		 */
		public function get_posted_data() {
			return $this->posted;
		}

		/**
		 * Meta value.
		 *
		 * @param string $name Name.
		 */
		public function get_meta( $name ) {
			return $this->meta[ $name ] ?? null;
		}
	}
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	/**
	 * Form stand-in.
	 */
	class WPCF7_ContactForm {

		/**
		 * Forms find() returns.
		 *
		 * @var array<int,WPCF7_ContactForm>
		 */
		public static $all = array();

		/**
		 * Id.
		 *
		 * @var int
		 */
		public $form_id = 0;

		/**
		 * Title.
		 *
		 * @var string
		 */
		public $form_title = '';

		/**
		 * Tags as [ name, basetype ].
		 *
		 * @var array<int,array{0:string,1:string}>
		 */
		public $tags = array();

		/**
		 * All forms.
		 */
		public static function find() {
			return self::$all;
		}

		/**
		 * Id.
		 */
		public function id() {
			return $this->form_id;
		}

		/**
		 * Title.
		 */
		public function title() {
			return $this->form_title;
		}

		/**
		 * Tags.
		 */
		public function scan_form_tags() {
			return array_map(
				static function ( $tag ) {
					return (object) array(
						'name'     => $tag[0],
						'basetype' => $tag[1],
					);
				},
				$this->tags
			);
		}
	}
}
