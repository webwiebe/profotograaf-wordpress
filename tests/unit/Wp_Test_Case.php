<?php
/**
 * Base test case: Brain Monkey plus an in-memory options table.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Stubs the WordPress functions the plugin uses. Options and transients live in
 * $options and $transients, and every write records its autoload flag.
 */
abstract class Wp_Test_Case extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = array();

	/**
	 * Autoload flag of every option written.
	 *
	 * @var array<string,mixed>
	 */
	protected array $autoload = array();

	/**
	 * In-memory transients.
	 *
	 * @var array<string,mixed>
	 */
	protected array $transients = array();

	/**
	 * Current unix time for the test.
	 *
	 * @var int
	 */
	protected int $now = 1000000;

	/**
	 * Sets up Brain Monkey and the stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		Functions\when( 'get_option' )->alias(
			fn( $name, $fallback = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'maybe_serialize' )->alias( fn( $value ) => is_array( $value ) ? serialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stub of the WordPress helper.
		Functions\when( 'maybe_unserialize' )->alias( fn( $value ) => is_string( $value ) && 0 === strpos( $value, 'a:' ) ? unserialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stub of the WordPress helper.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fake database over $this->options.
		$GLOBALS['wpdb'] = $this->options_db();
		Functions\when( 'wp_json_encode' )->alias( fn( $value ) => json_encode( $value ) );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'home_url' )->justReturn( 'https://photos.example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Photography' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'is_email' )->alias( fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-2222-4333-8444-555555555555' );
		Functions\when( 'sanitize_key' )->alias( fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
	}

	/**
	 * A $wpdb whose options table is $this->options: get_var, update and delete
	 * with an old-value condition, which is what the compare-and-swap code uses.
	 */
	private function options_db(): object {
		$options = &$this->options;
		return new class( $options ) {
			public $options = 'wp_options';

			/**
			 * Called once after the next get_var, to change the table between a read and a write.
			 *
			 * @var callable|null
			 */
			public $on_read = null;

			private $rows;

			public function __construct( array &$rows ) {
				$this->rows = &$rows;
			}

			public function prepare( $sql, ...$args ) {
				return array( $sql, $args );
			}

			private static function raw( $value ) {
				return is_array( $value ) ? serialize( $value ) : (string) $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- compares the stored text the way the database holds it.
			}

			public function get_var( $query ) {
				$name   = $query[1][0] ?? '';
				$result = array_key_exists( $name, $this->rows ) ? self::raw( $this->rows[ $name ] ) : null;
				if ( null !== $this->on_read ) {
					$hook          = $this->on_read;
					$this->on_read = null;
					$hook();
				}
				return $result;
			}

			public function update( $table, $data, $where ) {
				$name = $where['option_name'];
				if ( ! array_key_exists( $name, $this->rows ) || self::raw( $this->rows[ $name ] ) !== (string) $where['option_value'] ) {
					return 0;
				}
				$new                 = $data['option_value'];
				$this->rows[ $name ] = is_string( $new ) && 0 === strpos( $new, 'a:' ) ? unserialize( $new ) : $new; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the fake options table stores arrays as PHP values.
				return 1;
			}

			public function delete( $table, $where ) {
				$name = $where['option_name'];
				if ( ! array_key_exists( $name, $this->rows ) || self::raw( $this->rows[ $name ] ) !== (string) $where['option_value'] ) {
					return 0;
				}
				unset( $this->rows[ $name ] );
				return 1;
			}
		};
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stores a connected token pair.
	 *
	 * @param int    $expires_in Seconds until the access token expires.
	 * @param string $access     Access token.
	 * @param string $refresh    Refresh token.
	 * @param string|null $scope Granted scope list, null for a connection that predates it.
	 */
	protected function connect( int $expires_in = 900, string $access = 'access-1', string $refresh = 'refresh-1', ?string $scope = 'galleries:read leads:write galleries:embed' ): void {
		$this->options['profotograaf_connection'] = array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_at'    => $this->now + $expires_in,
			'device_id'     => 'device-1',
			'connected_at'  => $this->now - 100,
			'last_error'    => '',
		);
		if ( null !== $scope ) {
			$this->options['profotograaf_connection']['scopes'] = explode( ' ', $scope );
		}
	}

	/**
	 * Clock for the code under test.
	 */
	protected function clock(): callable {
		return fn() => $this->now;
	}
}
