<?php
/**
 * The WP-CLI symbols the commands use. Output is recorded in WP_CLI::$output.
 *
 * @package Profotograaf
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI that records what the commands print.
	 */
	class WP_CLI {

		/**
		 * Lines printed: [ level, message ].
		 *
		 * @var array<int,array{0:string,1:string}>
		 */
		public static array $output = array();

		/**
		 * Commands registered by name.
		 *
		 * @var array<string,mixed>
		 */
		public static array $commands = array();

		/**
		 * Clears the recorded state.
		 */
		public static function reset(): void {
			self::$output   = array();
			self::$commands = array();
		}

		/**
		 * Registers a command.
		 *
		 * @param string $name Command name.
		 * @param mixed  $impl Class name or object.
		 */
		public static function add_command( $name, $impl ): void {
			self::$commands[ $name ] = $impl;
		}

		/**
		 * Prints a line.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ): void {
			self::$output[] = array( 'log', $message );
		}

		/**
		 * Prints a success line.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ): void {
			self::$output[] = array( 'success', $message );
		}

		/**
		 * Prints a warning.
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message ): void {
			self::$output[] = array( 'warning', $message );
		}
	}
}
