<?php
/**
 * Event meta accessors.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises event meta keys so old data remains readable.
 */
final class Meta {
	const ENABLED          = '_ys_bookings_enabled';
	const PRODUCT_ID       = '_ys_wc_product_id';
	const TYPE             = '_ys_event_type';
	const PRICE            = '_ys_event_price';
	const LEGACY_DATE      = '_ys_event_date';
	const CAPACITY         = '_ys_event_capacity';
	const SLOT_START       = '_ys_slot_start';
	const SLOT_END         = '_ys_slot_end';
	const SLOT_LENGTH      = '_ys_slot_length';
	const SLOT_CAPACITY    = '_ys_slot_capacity';
	const SLOT_MODE        = '_ys_slot_mode';
	const CUSTOM_SLOTS     = '_ys_custom_slots';
	const INFORMATION      = '_ys_information_fields';
	const CUSTOM_INFORMATION = '_ys_custom_information_fields';
	const EVENT_STATS      = '_ys_booking_stats';
	const SLOT_STATS       = '_ys_slot_booking_stats';
	const BOOKING_CLOSE_AT = '_ys_booking_close_at';
	const REPORT_EMAILS    = '_ys_report_recipient_emails';
	const REPORT_SENT_AT   = '_ys_participant_report_sent_at';
	const REPORT_SENT_BY   = '_ys_participant_report_sent_by';
	const TYPE_GENERAL     = 'general';
	const TYPE_TIMED       = 'timed';
	const SLOT_MODE_AUTO   = 'auto';
	const SLOT_MODE_CUSTOM = 'custom';

	/**
	 * Whether booking is enabled for a The Events Calendar event.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public static function bookings_enabled( $event_id ) {
		return 'yes' === get_post_meta( $event_id, self::ENABLED, true );
	}

	/**
	 * Get the stored event type.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function event_type( $event_id ) {
		$type = sanitize_key( (string) get_post_meta( $event_id, self::TYPE, true ) );

		return self::TYPE_TIMED === $type ? self::TYPE_TIMED : self::TYPE_GENERAL;
	}

	/**
	 * Get the configured ticket price.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function price( $event_id ) {
		$price = get_post_meta( $event_id, self::PRICE, true );

		return '' === $price ? '0' : (string) $price;
	}

	/**
	 * Get the canonical The Events Calendar start date, falling back to legacy meta.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function event_date( $event_id ) {
		$tec_date = get_post_meta( $event_id, '_EventStartDate', true );

		if ( $tec_date ) {
			return (string) $tec_date;
		}

		return (string) get_post_meta( $event_id, self::LEGACY_DATE, true );
	}

	/**
	 * Get the local booking close date/time.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function booking_close_at( $event_id ) {
		return (string) get_post_meta( $event_id, self::BOOKING_CLOSE_AT, true );
	}

	/**
	 * Whether bookings have closed for the event.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public static function bookings_closed( $event_id ) {
		$close_at = self::booking_close_at( $event_id );

		if ( '' === $close_at ) {
			return false;
		}

		$date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $close_at, wp_timezone() );

		return $date && current_datetime()->getTimestamp() >= $date->getTimestamp();
	}
}
