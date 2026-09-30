<?php
/**
 * The few WordPress symbols the plugin needs that Brain Monkey cannot stub
 * (classes) or that are simpler as plain functions.
 *
 * @package Profotograaf
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {

		/**
		 * Code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Data.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Code.
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Message.
		 *
		 * @param string $code Ignored.
		 */
		public function get_error_message( $code = '' ) {
			return $this->message;
		}

		/**
		 * Data.
		 *
		 * @param string $code Ignored.
		 */
		public function get_error_data( $code = '' ) {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Checks for a WP_Error.
	 *
	 * @param mixed $thing Value.
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
