<?php
/**
 * Slot and capacity helpers.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates timed slots from event meta and calculates configured capacity.
 */
final class Slots {
	/**
	 * Generate configured slots.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int,array<string,string>>
	 */
	public static function generate( $event_id ) {
		if ( self::uses_custom_slots( $event_id ) ) {
			return self::custom_slots( $event_id );
		}

		$start = self::valid_time( get_post_meta( $event_id, Meta::SLOT_START, true ) ) ?: '09:00';
		$end   = self::valid_time( get_post_meta( $event_id, Meta::SLOT_END, true ) ) ?: '17:00';
		$len   = max( 5, absint( get_post_meta( $event_id, Meta::SLOT_LENGTH, true ) ?: 30 ) );
		$capacity = absint( get_post_meta( $event_id, Meta::SLOT_CAPACITY, true ) );

		$start_ts = strtotime( '2000-01-01 ' . $start );
		$end_ts   = strtotime( '2000-01-01 ' . $end );
		$slots    = array();

		if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
			return $slots;
		}

		for ( $time = $start_ts; $time + ( $len * MINUTE_IN_SECONDS ) <= $end_ts; $time += $len * MINUTE_IN_SECONDS ) {
			$slot_end = $time + ( $len * MINUTE_IN_SECONDS );
			$key      = gmdate( 'H:i', $time );
			$label    = gmdate( 'H:i', $time ) . '-' . gmdate( 'H:i', $slot_end );

			$slots[] = array(
				'key'      => $key,
				'label'    => $label,
				'start'    => gmdate( 'H:i', $time ),
				'end'      => gmdate( 'H:i', $slot_end ),
				'capacity' => $capacity,
			);
		}

		return $slots;
	}

	/**
	 * Whether the event uses explicit custom slots.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public static function uses_custom_slots( $event_id ) {
		return Meta::SLOT_MODE_CUSTOM === get_post_meta( $event_id, Meta::SLOT_MODE, true );
	}

	/**
	 * Get sanitized custom slots.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function custom_slots( $event_id ) {
		$stored = get_post_meta( $event_id, Meta::CUSTOM_SLOTS, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return self::sanitize_custom_slots( $stored, absint( get_post_meta( $event_id, Meta::SLOT_CAPACITY, true ) ) );
	}

	/**
	 * Sanitize custom slot rows.
	 *
	 * @param array $rows Raw rows.
	 * @param int   $default_capacity Fallback capacity.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_custom_slots( array $rows, $default_capacity = 0 ) {
		$slots = array();
		$seen  = array();
		$index = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$start = self::valid_time( $row['start'] ?? '' );
			$end   = self::valid_time( $row['end'] ?? '' );

			if ( ! $start || ! $end || strtotime( '2000-01-01 ' . $end ) <= strtotime( '2000-01-01 ' . $start ) ) {
				continue;
			}

			$capacity = absint( $row['capacity'] ?? 0 );
			$capacity = $capacity > 0 ? $capacity : max( 1, absint( $default_capacity ) );
			$key      = $start;

			if ( isset( $seen[ $key ] ) ) {
				$key = $start . '-' . $end;
			}

			while ( isset( $seen[ $key ] ) ) {
				$index++;
				$key = $start . '-' . $end . '-' . $index;
			}

			$seen[ $key ] = true;
			$slots[]      = array(
				'key'      => $key,
				'start'    => $start,
				'end'      => $end,
				'label'    => $start . '-' . $end,
				'capacity' => $capacity,
			);
		}

		usort(
			$slots,
			static function ( $left, $right ) {
				return strcmp( $left['start'], $right['start'] );
			}
		);

		return $slots;
	}

	/**
	 * Get total configured capacity for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	public static function event_capacity( $event_id ) {
		if ( Meta::TYPE_TIMED === Meta::event_type( $event_id ) ) {
			$capacity = 0;

			foreach ( self::generate( $event_id ) as $slot ) {
				$capacity += absint( $slot['capacity'] ?? get_post_meta( $event_id, Meta::SLOT_CAPACITY, true ) );
			}

			return $capacity;
		}

		return absint( get_post_meta( $event_id, Meta::CAPACITY, true ) );
	}

	/**
	 * Get configured capacity for a slot or general admission event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot key.
	 * @return int
	 */
	public static function capacity_for( $event_id, $slot_id = '' ) {
		if ( Meta::TYPE_TIMED === Meta::event_type( $event_id ) ) {
			foreach ( self::generate( $event_id ) as $slot ) {
				if ( $slot['key'] === $slot_id ) {
					return absint( $slot['capacity'] ?? get_post_meta( $event_id, Meta::SLOT_CAPACITY, true ) );
				}
			}

			return absint( get_post_meta( $event_id, Meta::SLOT_CAPACITY, true ) );
		}

		return absint( get_post_meta( $event_id, Meta::CAPACITY, true ) );
	}

	/**
	 * Is the submitted slot currently configured for the event?
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot key.
	 * @return bool
	 */
	public static function is_valid_slot( $event_id, $slot_id ) {
		foreach ( self::generate( $event_id ) as $slot ) {
			if ( $slot['key'] === $slot_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find a readable slot label.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot key.
	 * @return string
	 */
	public static function label( $event_id, $slot_id ) {
		foreach ( self::generate( $event_id ) as $slot ) {
			if ( $slot['key'] === $slot_id ) {
				return $slot['label'];
			}
		}

		return $slot_id;
	}

	/**
	 * Validate HH:MM values.
	 *
	 * @param mixed $time Raw time.
	 * @return string
	 */
	private static function valid_time( $time ) {
		$time = (string) $time;

		return preg_match( '/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time ) ? $time : '';
	}
}
