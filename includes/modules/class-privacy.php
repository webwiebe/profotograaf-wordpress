<?php
/**
 * Privacy policy text, personal data exporter and eraser.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Leads\Job_Store;
use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the plugin into the WordPress privacy tools.
 *
 * Lead jobs in the queue hold the visitor's name, email, phone and message
 * until they are delivered or pruned. Exporter and eraser find them by email
 * address, compared without regard to case, and work in batches so a large
 * queue stays within the request time limit.
 */
class Privacy implements Module {

	public const EXPORTER = 'profotograaf-leads';
	public const ERASER   = 'profotograaf-leads';
	public const PER_PAGE = 50;

	/**
	 * Lead storage.
	 *
	 * @var Job_Store|null
	 */
	private ?Job_Store $store;

	/**
	 * Constructor. Tests pass their own store.
	 *
	 * @param Job_Store|null $store Lead storage, the options table by default.
	 */
	public function __construct( ?Job_Store $store = null ) {
		$this->store = $store;
	}

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Adds the suggested text to Settings, Privacy, Policy Guide.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'Profotograaf', 'profotograaf' ), wp_kses_post( wpautop( $this->policy_text() ) ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param mixed $exporters Exporters by id.
	 * @return mixed
	 */
	public function add_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			return $exporters;
		}
		$exporters[ self::EXPORTER ] = array(
			'exporter_friendly_name' => __( 'Profotograaf enquiries', 'profotograaf' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param mixed $erasers Erasers by id.
	 * @return mixed
	 */
	public function add_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}
		$erasers[ self::ERASER ] = array(
			'eraser_friendly_name' => __( 'Profotograaf enquiries', 'profotograaf' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the queued enquiries of an email address, one batch per page.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		$matches = $this->matching( (string) $email );
		$offset  = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;
		$data    = array();

		foreach ( array_slice( $matches, $offset, self::PER_PAGE, true ) as $id => $job ) {
			$data[] = array(
				'group_id'          => self::EXPORTER,
				'group_label'       => __( 'Profotograaf enquiries', 'profotograaf' ),
				'group_description' => __( 'Enquiries sent through your forms that are waiting for delivery to Profotograaf, or could not be delivered.', 'profotograaf' ),
				'item_id'           => 'profotograaf-lead-' . $id,
				'data'              => $this->rows( $job ),
			);
		}
		return array(
			'data' => $data,
			'done' => $offset + self::PER_PAGE >= count( $matches ),
		);
	}

	/**
	 * Erases the queued enquiries of an email address.
	 *
	 * Removed jobs drop out of the match list, so every call takes the first
	 * batch and the page number is not needed.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$store   = $this->store();
		$matches = $this->matching( (string) $email );
		$batch   = array_slice( $matches, 0, self::PER_PAGE, true );

		foreach ( array_keys( $batch ) as $id ) {
			$store->delete( (string) $id );
		}
		return array(
			'items_removed'  => array() !== $batch,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $matches ) <= self::PER_PAGE,
		);
	}

	/**
	 * Jobs whose lead email equals the address, ordered by id.
	 *
	 * @param string $email Email address.
	 * @return array<string,array<string,mixed>>
	 */
	private function matching( string $email ): array {
		$needle = strtolower( trim( $email ) );
		if ( '' === $needle ) {
			return array();
		}
		$found = array();
		foreach ( $this->store()->all() as $id => $job ) {
			$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
			if ( strtolower( trim( (string) ( $payload['email'] ?? '' ) ) ) === $needle ) {
				$found[ (string) $id ] = $job;
			}
		}
		ksort( $found, SORT_STRING );
		return $found;
	}

	/**
	 * Name and value rows for one job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<int,array{name:string,value:string}>
	 */
	private function rows( array $job ): array {
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$labels  = array(
			'name'        => __( 'Name', 'profotograaf' ),
			'email'       => __( 'Email', 'profotograaf' ),
			'phone'       => __( 'Phone', 'profotograaf' ),
			'event_date'  => __( 'Date', 'profotograaf' ),
			'message'     => __( 'Message', 'profotograaf' ),
			'source_form' => __( 'Form', 'profotograaf' ),
			'page_url'    => __( 'Page', 'profotograaf' ),
		);
		$rows    = array();
		foreach ( $labels as $key => $label ) {
			if ( isset( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				$rows[] = array(
					'name'  => $label,
					'value' => (string) $payload[ $key ],
				);
			}
		}
		foreach ( isset( $payload['extra_fields'] ) && is_array( $payload['extra_fields'] ) ? $payload['extra_fields'] : array() as $extra ) {
			if ( is_array( $extra ) && isset( $extra['label'], $extra['value'] ) ) {
				$rows[] = array(
					'name'  => (string) $extra['label'],
					'value' => (string) $extra['value'],
				);
			}
		}
		$rows[] = array(
			'name'  => __( 'Delivery status', 'profotograaf' ),
			'value' => 'failed' === ( $job['status'] ?? '' ) ? __( 'Failed', 'profotograaf' ) : __( 'Waiting', 'profotograaf' ),
		);
		if ( ! empty( $job['created_at'] ) ) {
			$rows[] = array(
				'name'  => __( 'Received', 'profotograaf' ),
				'value' => gmdate( 'Y-m-d H:i', (int) $job['created_at'] ) . ' UTC',
			);
		}
		return $rows;
	}

	/**
	 * Suggested privacy policy text.
	 */
	private function policy_text(): string {
		$text  = '<h2>' . esc_html__( 'Galleries', 'profotograaf' ) . '</h2>';
		$text .= '<p>' . esc_html__( 'Pages that show a Profotograaf gallery make the visitor\'s browser load a script, the gallery data and the photos from profotograaf.nl, a service operated by the plugin author. Profotograaf receives the visitor\'s IP address and browser details, as any web server does. When a gallery scrolls into view the script reports one anonymous view, with the gallery and the host name of this site. No view is reported when the browser sends Do Not Track or Global Privacy Control. The plugin sets no cookies.', 'profotograaf' ) . '</p>';
		$text .= '<h2>' . esc_html__( 'Enquiries', 'profotograaf' ) . '</h2>';
		$text .= '<p>' . esc_html__( 'When a contact form is switched on for Profotograaf, each submission is stored on this site and then sent to profotograaf.nl: the name, email address, phone number, date, message and other fields of the form, the address of the page and the name of the form. A submission waits in the queue until it is delivered. One that cannot be delivered is kept for a limited time, so the site owner can export or retry it. The personal data tools export and erase these submissions by the email address of the sender.', 'profotograaf' ) . '</p>';
		$text .= '<h2>' . esc_html__( 'Connection and diagnostics', 'profotograaf' ) . '</h2>';
		$text .= '<p>' . esc_html__( 'When the site owner connects the site, the plugin sends the site title, the host name, the plugin version and a random identifier of this installation to profotograaf.nl. The plugin sends no usage statistics or error reports.', 'profotograaf' ) . '</p>';
		$text .= '<p>' . esc_html__( 'The Profotograaf privacy policy is at https://profotograaf.nl/privacy.', 'profotograaf' ) . '</p>';
		return $text;
	}

	/**
	 * Lead storage.
	 */
	private function store(): Job_Store {
		if ( null === $this->store ) {
			$this->store = new Option_Job_Store();
		}
		return $this->store;
	}
}
