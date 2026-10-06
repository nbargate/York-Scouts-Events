<?php
/**
 * Event booking email exports.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams per-event booking contact email lists for admins.
 */
final class Email_Export_Service {
	/**
	 * Booking repository.
	 *
	 * @var Booking_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Booking_Repository $repository Booking repository.
	 */
	public function __construct( Booking_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register admin download hook.
	 */
	public function hooks() {
		add_action( 'admin_post_ys_export_event_emails', array( $this, 'download' ) );
	}

	/**
	 * Stream an email list download for admins.
	 */
	public function download() {
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$nonce    = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! $event_id || 'tribe_events' !== get_post_type( $event_id ) || ! wp_verify_nonce( $nonce, 'ys_export_event_emails_' . $event_id ) ) {
			wp_die( esc_html__( 'Invalid email export request.', 'york-scouts-events' ) );
		}

		if ( ! Capabilities::can_export_emails() ) {
			wp_die( esc_html__( 'You do not have permission to export emails for this event.', 'york-scouts-events' ) );
		}

		$emails = $this->repository->emails_for_event( $event_id );
		$body   = $emails ? implode( "\n", $emails ) . "\n" : '';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->filename( $event_id ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body;
		exit;
	}

	/**
	 * Build download URL for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function download_url( $event_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'ys_export_event_emails',
					'event_id' => absint( $event_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'ys_export_event_emails_' . absint( $event_id )
		);
	}

	/**
	 * Build an export filename.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	private function filename( $event_id ) {
		$title = sanitize_title( get_the_title( $event_id ) ?: 'event-' . absint( $event_id ) );

		return 'booking-emails-' . $title . '.txt';
	}
}
