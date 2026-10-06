<?php
/**
 * Participant report scheduling.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and sends automatic participant reports.
 */
final class Report_Scheduler {
	const SEND_HOOK = 'ys_events_send_participant_report';

	/**
	 * Participant report service.
	 *
	 * @var Participant_Report_Service
	 */
	private $reports;

	/**
	 * Constructor.
	 *
	 * @param Participant_Report_Service $reports Report service.
	 */
	public function __construct( Participant_Report_Service $reports ) {
		$this->reports = $reports;
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( self::SEND_HOOK, array( $this, 'send_scheduled_report' ), 10, 2 );
		add_action( 'save_post_tribe_events', array( $this, 'schedule_event_reports' ), 99, 2 );
		add_action( 'ys_events_stats_rebuilt', array( $this, 'maybe_send_sold_out_report' ), 10, 3 );
	}

	/**
	 * Schedule report emails for an event.
	 *
	 * @param int     $event_id Event post ID.
	 * @param WP_Post $post Post object.
	 */
	public function schedule_event_reports( $event_id, $post ) {
		unset( $post );

		if ( ! Meta::bookings_enabled( $event_id ) ) {
			$this->clear_event_report( $event_id, 'event_day' );
			$this->clear_event_report( $event_id, 'booking_closed' );
			return;
		}

		$this->schedule_event_day_report( $event_id );
		$this->schedule_booking_close_report( $event_id );
	}

	/**
	 * Send a report from WP-Cron.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $trigger Trigger key.
	 */
	public function send_scheduled_report( $event_id, $trigger ) {
		$this->reports->send_event_report( absint( $event_id ), sanitize_key( $trigger ) );
	}

	/**
	 * Send the final report when the event sells out.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $event_stats Event stats.
	 * @param array $slot_stats Slot stats.
	 */
	public function maybe_send_sold_out_report( $event_id, array $event_stats, array $slot_stats ) {
		unset( $slot_stats );

		if ( ! Meta::bookings_enabled( $event_id ) ) {
			return;
		}

		if ( absint( $event_stats['capacity'] ?? 0 ) > 0 && 0 === absint( $event_stats['remaining'] ?? 0 ) ) {
			$this->reports->send_event_report( $event_id, 'sold_out' );
		}
	}

	/**
	 * Schedule the 00:01 event-day report.
	 *
	 * @param int $event_id Event post ID.
	 */
	private function schedule_event_day_report( $event_id ) {
		$this->clear_event_report( $event_id, 'event_day' );

		if ( get_post_meta( $event_id, Meta::REPORT_SENT_AT, true ) ) {
			return;
		}

		$event_date = Meta::event_date( $event_id );

		if ( ! $event_date ) {
			return;
		}

		$timestamp = $this->local_timestamp( substr( $event_date, 0, 10 ) . ' 00:01:00' );

		if ( $timestamp && $timestamp > time() ) {
			wp_schedule_single_event( $timestamp, self::SEND_HOOK, array( absint( $event_id ), 'event_day' ) );
		}
	}

	/**
	 * Schedule the booking-close report.
	 *
	 * @param int $event_id Event post ID.
	 */
	private function schedule_booking_close_report( $event_id ) {
		$this->clear_event_report( $event_id, 'booking_closed' );

		if ( get_post_meta( $event_id, Meta::REPORT_SENT_AT, true ) ) {
			return;
		}

		$close_at = Meta::booking_close_at( $event_id );

		if ( ! $close_at ) {
			return;
		}

		$timestamp = $this->local_timestamp( $close_at );

		if ( $timestamp && $timestamp > time() ) {
			wp_schedule_single_event( $timestamp, self::SEND_HOOK, array( absint( $event_id ), 'booking_closed' ) );
		}
	}

	/**
	 * Clear a scheduled event report.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $trigger Trigger key.
	 */
	private function clear_event_report( $event_id, $trigger ) {
		wp_clear_scheduled_hook( self::SEND_HOOK, array( absint( $event_id ), $trigger ) );
	}

	/**
	 * Parse local site datetime to a timestamp.
	 *
	 * @param string $datetime Local datetime.
	 * @return int
	 */
	private function local_timestamp( $datetime ) {
		$timezone = wp_timezone();
		$date     = \DateTime::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );

		if ( ! $date ) {
			$date = \DateTime::createFromFormat( 'Y-m-d H:i', $datetime, $timezone );
		}

		return $date ? $date->getTimestamp() : 0;
	}
}
