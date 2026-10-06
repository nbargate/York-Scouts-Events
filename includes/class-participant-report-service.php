<?php
/**
 * Participant report emails and downloads.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds participant report PDFs, emails them, and streams downloads.
 */
final class Participant_Report_Service {
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
		add_action( 'admin_post_ys_download_participant_report', array( $this, 'download_report' ) );
		add_action( 'admin_post_ys_send_participant_report_email', array( $this, 'send_manual_report_email' ) );
	}

	/**
	 * Send the participant report once for the event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $trigger Trigger key.
	 * @param bool   $force Send even if the automatic report has already been sent.
	 * @return bool
	 */
	public function send_event_report( $event_id, $trigger, $force = false ) {
		$event_id = absint( $event_id );

		if ( ! $event_id || ! Meta::bookings_enabled( $event_id ) ) {
			return false;
		}

		if ( ! $force && get_post_meta( $event_id, Meta::REPORT_SENT_AT, true ) ) {
			return false;
		}

		$emails = $this->organiser_emails( $event_id );

		if ( ! $emails ) {
			return false;
		}

		$pdf        = $this->pdf_bytes( $event_id, $trigger );
		$filename   = $this->filename( $event_id );
		$attachment = $this->temporary_pdf_attachment( $filename, $pdf );

		if ( ! $attachment ) {
			return false;
		}

		$title   = $this->email_event_title( $event_id );
		$subject = sprintf(
			/* translators: %s: Event title. */
			__( 'Participant list for %s', 'york-scouts-events' ),
			$title
		);
		$message = sprintf(
			/* translators: 1: Event title, 2: Report trigger. */
			__( "Attached is the participant information PDF for %1\$s.\n\nReason: %2\$s", 'york-scouts-events' ),
			$title,
			self::trigger_label( $trigger )
		);

		$sent = wp_mail(
			$emails,
			$subject,
			$message,
			array( 'Content-Type: text/plain; charset=UTF-8' ),
			array( $attachment['path'] )
		);

		$this->delete_temporary_pdf_attachment( $attachment );

		if ( $sent && ! $force ) {
			update_post_meta( $event_id, Meta::REPORT_SENT_AT, current_time( 'mysql' ) );
			update_post_meta( $event_id, Meta::REPORT_SENT_BY, sanitize_key( $trigger ) );
		}

		return (bool) $sent;
	}

	/**
	 * Send a report email manually from wp-admin.
	 */
	public function send_manual_report_email() {
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$nonce    = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! $event_id || 'tribe_events' !== get_post_type( $event_id ) || ! Meta::bookings_enabled( $event_id ) || ! wp_verify_nonce( $nonce, 'ys_send_participant_report_email_' . $event_id ) ) {
			wp_die( esc_html__( 'Invalid report email request.', 'york-scouts-events' ) );
		}

		if ( ! Capabilities::can_download_reports() ) {
			wp_die( esc_html__( 'You do not have permission to send this report.', 'york-scouts-events' ) );
		}

		$sent = $this->send_event_report( $event_id, 'manual_email', true );
		$url  = add_query_arg(
			array(
				'page'              => 'ys-event-bookings',
				'ys_event_filter'   => $event_id,
				'ys_report_emailed' => $sent ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Stream a report download for admins.
	 */
	public function download_report() {
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$nonce    = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! $event_id || ! wp_verify_nonce( $nonce, 'ys_download_participant_report_' . $event_id ) ) {
			wp_die( esc_html__( 'Invalid report request.', 'york-scouts-events' ) );
		}

		if ( ! Capabilities::can_download_reports() ) {
			wp_die( esc_html__( 'You do not have permission to download this report.', 'york-scouts-events' ) );
		}

		$pdf      = $this->pdf_bytes( $event_id, 'manual' );
		$filename = $this->filename( $event_id );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf;
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
					'action'   => 'ys_download_participant_report',
					'event_id' => absint( $event_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'ys_download_participant_report_' . absint( $event_id )
		);
	}

	/**
	 * Build manual report email URL for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function manual_email_url( $event_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'ys_send_participant_report_email',
					'event_id' => absint( $event_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'ys_send_participant_report_email_' . absint( $event_id )
		);
	}

	/**
	 * Get organiser report recipients.
	 *
	 * @param int $event_id Event post ID.
	 * @return string[]
	 */
	public function organiser_emails( $event_id ) {
		$stored = (string) get_post_meta( $event_id, Meta::REPORT_EMAILS, true );
		$emails = $stored ? preg_split( '/[\s,;]+/', $stored ) : array();
		$emails = array_filter( array_map( 'sanitize_email', (array) $emails ), 'is_email' );

		if ( ! $emails ) {
			$emails = $this->tec_organiser_emails( $event_id );
		}

		if ( ! $emails ) {
			$emails = array( get_option( 'admin_email' ) );
		}

		return array_values( array_unique( array_filter( $emails, 'is_email' ) ) );
	}

	/**
	 * Human-readable trigger label.
	 *
	 * @param string $trigger Trigger key.
	 * @return string
	 */
	public static function trigger_label( $trigger ) {
		$labels = array(
			'event_day'      => __( '00:01 on the event day', 'york-scouts-events' ),
			'booking_closed' => __( 'Bookings closed', 'york-scouts-events' ),
			'sold_out'       => __( 'Event sold out', 'york-scouts-events' ),
			'manual'         => __( 'Manual download', 'york-scouts-events' ),
			'manual_email'   => __( 'Manual email', 'york-scouts-events' ),
		);

		return $labels[ $trigger ] ?? sanitize_text_field( $trigger );
	}

	/**
	 * Write PDF bytes to a temporary path that keeps the intended .pdf filename.
	 *
	 * @param string $filename Attachment filename.
	 * @param string $pdf PDF bytes.
	 * @return array{path:string,dir:string}|false
	 */
	private function temporary_pdf_attachment( $filename, $pdf ) {
		$base_dir = trailingslashit( get_temp_dir() );
		$dir      = $base_dir . 'ys-events-report-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$path = trailingslashit( $dir ) . sanitize_file_name( $filename );

		if ( false === file_put_contents( $path, $pdf ) ) {
			$this->delete_temporary_pdf_attachment(
				array(
					'path' => $path,
					'dir'  => $dir,
				)
			);

			return false;
		}

		return array(
			'path' => $path,
			'dir'  => $dir,
		);
	}

	/**
	 * Remove temporary report attachment files.
	 *
	 * @param array<string,string> $attachment Temporary attachment details.
	 */
	private function delete_temporary_pdf_attachment( array $attachment ) {
		if ( ! empty( $attachment['path'] ) ) {
			@unlink( $attachment['path'] );
		}

		if ( ! empty( $attachment['dir'] ) ) {
			@rmdir( $attachment['dir'] );
		}
	}

	/**
	 * Build a plain-text event title for email subjects and bodies.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	private function email_event_title( $event_id ) {
		$title   = get_the_title( $event_id );
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

		$title = wp_strip_all_tags( (string) $title );
		$title = html_entity_decode( $title, ENT_QUOTES, $charset );

		return '' !== trim( $title ) ? $title : sprintf( __( 'Event #%d', 'york-scouts-events' ), absint( $event_id ) );
	}

	/**
	 * Build PDF bytes.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $trigger Trigger key.
	 * @return string
	 */
	private function pdf_bytes( $event_id, $trigger ) {
		$rows = $this->repository->report_rows_for_event( $event_id );

		return ( new Participant_Report_Pdf() )->render( $event_id, $rows, $trigger );
	}

	/**
	 * Build a report filename.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	private function filename( $event_id ) {
		$title = sanitize_title( $this->email_event_title( $event_id ) );

		return 'participant-list-' . $title . '.pdf';
	}

	/**
	 * Try to read organiser emails from The Events Calendar.
	 *
	 * @param int $event_id Event post ID.
	 * @return string[]
	 */
	private function tec_organiser_emails( $event_id ) {
		$emails = array();

		if ( function_exists( 'tribe_get_organizer_ids' ) ) {
			foreach ( (array) tribe_get_organizer_ids( $event_id ) as $organizer_id ) {
				if ( function_exists( 'tribe_get_organizer_email' ) ) {
					$email = tribe_get_organizer_email( $organizer_id );
					if ( is_email( $email ) ) {
						$emails[] = $email;
					}
				}
			}
		}

		$legacy_organizer = absint( get_post_meta( $event_id, '_EventOrganizerID', true ) );

		if ( $legacy_organizer && function_exists( 'tribe_get_organizer_email' ) ) {
			$email = tribe_get_organizer_email( $legacy_organizer );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}
}
