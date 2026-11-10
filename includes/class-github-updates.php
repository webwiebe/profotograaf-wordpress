<?php
/**
 * Update source that reads the GitHub releases of the plugin.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the WordPress update check and the plugin details modal from the
 * latest GitHub release. The plugin header carries an Update URI on github.com,
 * so WordPress asks the `update_plugins_github.com` filter about it.
 *
 * One release request per twelve hours, a failed one included, so a rate-limited
 * host keeps a working update screen. The readme.txt of the release tag is read
 * only when the details modal opens, and cached the same way.
 */
class Github_Updates {

	public const RELEASE_URL = 'https://api.github.com/repos/webwiebe/profotograaf-wordpress/releases/latest';
	public const SLUG        = 'profotograaf';

	private const REPO_URL       = 'https://github.com/webwiebe/profotograaf-wordpress';
	private const RELEASE_CACHE  = 'profotograaf_github_release';
	private const README_CACHE   = 'profotograaf_github_readme';
	private const TTL            = 43200;
	private const ZIP_NAME       = 'profotograaf.zip';
	private const VERSION_FORMAT = '/^\d+\.\d+\.\d+$/';

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private string $installed;

	/**
	 * Plugin basename, such as profotograaf/profotograaf.php.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Fetches a URL and returns [status, body], null when the request failed.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string        $installed   Installed version.
	 * @param string        $plugin_file Plugin basename.
	 * @param callable|null $http        fn( string $url ): ?array{int,string}.
	 * @param callable|null $clock       Returns the current unix time.
	 */
	public function __construct( string $installed, string $plugin_file, ?callable $http = null, ?callable $clock = null ) {
		$this->installed   = $installed;
		$this->plugin_file = $plugin_file;
		$this->http        = $http ?? array( $this, 'remote_get' );
		$this->clock       = $clock ?? 'time';
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
	}

	/**
	 * URL of readme.txt at a release tag.
	 *
	 * @param string $version Release version without the v.
	 */
	public static function readme_url( string $version ): string {
		return 'https://raw.githubusercontent.com/webwiebe/profotograaf-wordpress/v' . $version . '/readme.txt';
	}

	/**
	 * The update entry for this plugin, false when the release is not newer.
	 *
	 * @param mixed               $update      Entry so far.
	 * @param array<string,mixed> $plugin_data Plugin header data.
	 * @param string              $plugin_file Plugin basename being checked.
	 * @return mixed
	 */
	public function filter_update( $update, array $plugin_data, string $plugin_file ) {
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}
		$release = $this->latest();
		if ( null === $release || ! version_compare( $release['version'], $this->installed, '>' ) ) {
			return false;
		}
		$entry = array(
			'id'      => 'github.com/webwiebe/profotograaf-wordpress',
			'slug'    => self::SLUG,
			'plugin'  => $this->plugin_file,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
		if ( ! empty( $plugin_data['RequiresPHP'] ) ) {
			$entry['requires_php'] = (string) $plugin_data['RequiresPHP'];
		}
		$readme = $this->cached( self::README_CACHE . '_' . $release['version'] );
		if ( is_array( $readme ) && ! empty( $readme['tested'] ) ) {
			$entry['tested'] = $readme['tested'];
		}
		return $entry;
	}

	/**
	 * The details modal of this plugin.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Requested API action.
	 * @param mixed  $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = $this->latest();
		if ( null === $release ) {
			return $result;
		}
		$readme = $this->readme( $release['version'] );
		$info   = (object) array(
			'name'          => 'Profotograaf',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'homepage'      => self::REPO_URL,
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => '' !== $readme['changelog'] ? $readme['changelog'] : '<a href="' . esc_url( $release['url'] ) . '">' . esc_html__( 'Release notes on GitHub', 'profotograaf' ) . '</a>',
			),
		);
		foreach ( array( 'tested', 'requires', 'requires_php' ) as $key ) {
			if ( '' !== $readme[ $key ] ) {
				$info->$key = $readme[ $key ];
			}
		}
		return $info;
	}

	/**
	 * The latest release, from the cache when it is fresh.
	 *
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function latest(): ?array {
		$cached = $this->cached( self::RELEASE_CACHE );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $this->shape_release( $cached ) : null;
		}
		$release = $this->parse_release( ( $this->http )( $this->release_url() ) );
		$this->remember( self::RELEASE_CACHE, $release ?? '' );
		return $release;
	}

	/**
	 * The release endpoint, which the E2E stack points at a local stand-in.
	 */
	private function release_url(): string {
		/**
		 * Filters the URL of the latest release the update check reads.
		 *
		 * @param string $url GitHub API URL of the latest release.
		 */
		$url = (string) apply_filters( 'profotograaf_github_release_url', self::RELEASE_URL );
		return '' !== $url ? $url : self::RELEASE_URL;
	}

	/**
	 * Where the release zip must be served from: the repository on GitHub, or
	 * the origin of the endpoint when that was filtered.
	 */
	private function package_prefix(): string {
		$url = $this->release_url();
		if ( self::RELEASE_URL === $url ) {
			return self::REPO_URL . '/';
		}
		$parts = (array) wp_parse_url( $url );
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/';
	}

	/**
	 * A cached release with its three fields as strings.
	 *
	 * @param array<mixed> $release Cached release.
	 * @return array{version:string,package:string,url:string}
	 */
	private function shape_release( array $release ): array {
		return array(
			'version' => (string) ( $release['version'] ?? '' ),
			'package' => (string) ( $release['package'] ?? '' ),
			'url'     => (string) ( $release['url'] ?? '' ),
		);
	}

	/**
	 * Release data from the API answer, null when it is unusable.
	 *
	 * @param array{0:int,1:string}|null $answer Status and body.
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function parse_release( ?array $answer ): ?array {
		if ( null === $answer || 200 !== $answer[0] ) {
			return null;
		}
		$data = json_decode( $answer[1], true );
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'v' );
		if ( 1 !== preg_match( self::VERSION_FORMAT, $version ) ) {
			return null;
		}
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$package = (string) ( $asset['browser_download_url'] ?? '' );
			if ( self::ZIP_NAME === ( $asset['name'] ?? '' ) && 0 === strpos( $package, $this->package_prefix() ) ) {
				return array(
					'version' => $version,
					'package' => $package,
					'url'     => self::REPO_URL . '/releases/tag/v' . $version,
				);
			}
		}
		return null;
	}

	/**
	 * Changelog and header fields of readme.txt at a release tag.
	 *
	 * @param string $version Release version.
	 * @return array{changelog:string,tested:string,requires:string,requires_php:string}
	 */
	private function readme( string $version ): array {
		$key    = self::README_CACHE . '_' . $version;
		$cached = $this->cached( $key );
		if ( false === $cached ) {
			$answer = ( $this->http )( self::readme_url( $version ) );
			$cached = null !== $answer && 200 === $answer[0] ? $this->parse_readme( $answer[1], $version ) : '';
			$this->remember( $key, $cached );
		}
		$cached = is_array( $cached ) ? $cached : array();
		return array(
			'changelog'    => (string) ( $cached['changelog'] ?? '' ),
			'tested'       => (string) ( $cached['tested'] ?? '' ),
			'requires'     => (string) ( $cached['requires'] ?? '' ),
			'requires_php' => (string) ( $cached['requires_php'] ?? '' ),
		);
	}

	/**
	 * Reads the fields the details modal shows.
	 *
	 * @param string $text    readme.txt.
	 * @param string $version Release version.
	 * @return array{changelog:string,tested:string,requires:string,requires_php:string}
	 */
	private function parse_readme( string $text, string $version ): array {
		$field = static function ( string $label ) use ( $text ): string {
			return 1 === preg_match( '/^' . $label . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : '';
		};
		$items = array();
		if ( 1 === preg_match( '/^== Changelog ==\s*$(.*?)(?=^== |\z)/ms', $text, $section )
			&& 1 === preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\s*$(.*?)(?=^= |\z)/ms', $section[1], $block ) ) {
			$lines = preg_split( '/\R/', $block[1] );
			foreach ( false === $lines ? array() : $lines as $line ) {
				if ( 0 === strpos( $line, '* ' ) ) {
					$items[] = '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
				}
			}
		}
		return array(
			'changelog'    => array() === $items ? '' : '<ul>' . implode( '', $items ) . '</ul>',
			'tested'       => $field( 'Tested up to' ),
			'requires'     => $field( 'Requires at least' ),
			'requires_php' => $field( 'Requires PHP' ),
		);
	}

	/**
	 * A cached value, false when missing or older than the TTL. A stored
	 * failure comes back as the empty string.
	 *
	 * @param string $key Transient name.
	 * @return mixed
	 */
	private function cached( string $key ) {
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || ( ( $this->clock )() - (int) ( $stored['at'] ?? 0 ) ) >= self::TTL ) {
			return false;
		}
		return $stored['value'];
	}

	/**
	 * Stores a value, or the empty string for a failure, for the TTL.
	 *
	 * @param string $key   Transient name.
	 * @param mixed  $value Value.
	 */
	private function remember( string $key, $value ): void {
		set_transient(
			$key,
			array(
				'at'    => ( $this->clock )(),
				'value' => $value,
			),
			self::TTL
		);
	}

	/**
	 * Default HTTP layer.
	 *
	 * @param string $url URL.
	 * @return array{0:int,1:string}|null
	 */
	private function remote_get( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Profotograaf-WordPress/' . $this->installed,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
	}
}
