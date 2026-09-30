<?php
/**
 * Turns a submission into a queued lead.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * The one place bridges hand submissions to. It never throws and never talks
 * to the network: the worst it does during a form submission is one database
 * write and one cron registration. Whatever goes wrong here is swallowed, so a
 * form never fails because of Profotograaf.
 */
final class Dispatcher {

	/**
	 * Per form settings.
	 *
	 * @var Form_Settings
	 */
	private Form_Settings $settings;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Constructor.
	 *
	 * @param Form_Settings $settings Per form settings.
	 * @param Queue         $queue    Queue.
	 */
	public function __construct( Form_Settings $settings, Queue $queue ) {
		$this->settings = $settings;
		$this->queue    = $queue;
	}

	/**
	 * Queues a submission when its form is switched on.
	 *
	 * @param Submission $submission Submission.
	 * @return string `queued`, or why not: `off`, `no_email`, `vetoed`, `exists`, `full`, `error`.
	 */
	public function submit( Submission $submission ): string {
		try {
			$config = $this->settings->get( $submission->form_key );
			if ( ! $config['enabled'] ) {
				return 'off';
			}

			$payload = Mapping::payload( $submission, $config['map'] );
			if ( null === $payload ) {
				/**
				 * Fires when a submission has no usable email address and is not sent.
				 *
				 * @param Submission $submission Submission.
				 */
				do_action( 'profotograaf_lead_skipped', $submission );
				return 'no_email';
			}

			/**
			 * Filters the lead before it is queued.
			 *
			 * @param array<string,mixed>|null $payload    Leads API body. Return null to drop the lead.
			 * @param Submission               $submission Submission.
			 */
			$payload = apply_filters( 'profotograaf_lead_payload', $payload, $submission );
			if ( ! is_array( $payload ) ) {
				return 'vetoed';
			}

			$result = $this->queue->enqueue( $this->job_id( $submission ), $payload );
			if ( 'queued' === $result ) {
				Delivery::schedule( $this->queue->now() );
			} else {
				do_action( 'profotograaf_lead_not_queued', $result, $submission );
			}
			return $result;
		} catch ( \Throwable $e ) {
			return 'error';
		}//end try
	}

	/**
	 * Id of the job for a submission.
	 *
	 * The form plugin's entry id makes a repeated hook call for the same entry
	 * the same job. Without one, every submission gets a fresh id.
	 *
	 * @param Submission $submission Submission.
	 */
	private function job_id( Submission $submission ): string {
		$entry = '' !== $submission->entry_id ? $submission->entry_id : wp_generate_uuid4();
		return sha1( $submission->form_key . '|' . $entry );
	}
}
