<?php
/**
 * Event and slot counter service.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintains sold/reserved/remaining counters in post meta.
 */
final class Stats {
	/**
	 * Statuses counted as paid/sold.
	 *
	 * @return string[]
	 */
	public static function sold_statuses() {
		return array( 'processing', 'completed' );
	}

	/**
	 * Statuses counted as reserving capacity.
	 *
	 * @return string[]
	 */
	public static function reserved_statuses() {
		return array( 'pending', 'on-hold', 'processing', 'completed' );
	}

	/**
	 * Rebuild counters for one event from the bookings table.
	 *
	 * This runs on writes/status changes, not on page rendering.
	 *
	 * @param int $event_id Event post ID.
	 */
	public function rebuild_event( $event_id ) {
		global $wpdb;

		$event_id = absint( $event_id );

		if ( ! $event_id ) {
			return;
		}

		$capacity    = Slots::event_capacity( $event_id );
		$event_stats = array(
			'sold'       => 0,
			'reserved'   => 0,
			'capacity'   => $capacity,
			'remaining'  => $capacity,
			'updated_at' => current_time( 'mysql' ),
		);
		$slot_stats  = $this->initial_slot_stats( $event_id );
		$statuses    = array_values( array_unique( array_merge( self::reserved_statuses(), self::sold_statuses() ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql         = 'SELECT slot_id, status, SUM(participant_count) AS qty FROM ' . Booking_Repository::table_name() . " WHERE event_id = %d AND status IN ({$placeholders}) GROUP BY slot_id, status";
		$params      = array_merge( array( $event_id ), $statuses );
		$rows        = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) ), ARRAY_A );

		foreach ( $rows as $row ) {
			$slot_id = (string) $row['slot_id'];
			$status  = (string) $row['status'];
			$qty     = absint( $row['qty'] );

			if ( in_array( $status, self::reserved_statuses(), true ) ) {
				$event_stats['reserved'] += $qty;
			}

			if ( in_array( $status, self::sold_statuses(), true ) ) {
				$event_stats['sold'] += $qty;
			}

			if ( '' !== $slot_id ) {
				if ( ! isset( $slot_stats[ $slot_id ] ) ) {
					$slot_stats[ $slot_id ] = array(
						'label'     => Slots::label( $event_id, $slot_id ),
						'sold'      => 0,
						'reserved'  => 0,
						'capacity'  => Slots::capacity_for( $event_id, $slot_id ),
						'remaining' => Slots::capacity_for( $event_id, $slot_id ),
					);
				}

				if ( in_array( $status, self::reserved_statuses(), true ) ) {
					$slot_stats[ $slot_id ]['reserved'] += $qty;
				}

				if ( in_array( $status, self::sold_statuses(), true ) ) {
					$slot_stats[ $slot_id ]['sold'] += $qty;
				}
			}
		}

		$event_stats['remaining'] = max( 0, $event_stats['capacity'] - $event_stats['reserved'] );

		foreach ( $slot_stats as $slot_id => $stats ) {
			$slot_stats[ $slot_id ]['remaining'] = max( 0, absint( $stats['capacity'] ) - absint( $stats['reserved'] ) );
		}

		update_post_meta( $event_id, Meta::EVENT_STATS, $event_stats );
		update_post_meta( $event_id, Meta::SLOT_STATS, $slot_stats );

		/**
		 * Fires after stored event and slot counters are rebuilt.
		 *
		 * @param int   $event_id Event post ID.
		 * @param array $event_stats Event-level counters.
		 * @param array $slot_stats Slot-level counters.
		 */
		do_action( 'ys_events_stats_rebuilt', $event_id, $event_stats, $slot_stats );
	}

	/**
	 * Rebuild all unique event IDs.
	 *
	 * @param int[] $event_ids Event IDs.
	 */
	public function rebuild_events( array $event_ids ) {
		foreach ( array_unique( array_map( 'absint', $event_ids ) ) as $event_id ) {
			$this->rebuild_event( $event_id );
		}
	}

	/**
	 * Read event stats without querying bookings.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string,int|string>
	 */
	public function get_event_stats( $event_id ) {
		$stored   = get_post_meta( $event_id, Meta::EVENT_STATS, true );
		$capacity = Slots::event_capacity( $event_id );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$sold     = absint( $stored['sold'] ?? 0 );
		$reserved = absint( $stored['reserved'] ?? 0 );

		return array(
			'sold'       => $sold,
			'reserved'   => $reserved,
			'capacity'   => $capacity,
			'remaining'  => max( 0, $capacity - $reserved ),
			'updated_at' => (string) ( $stored['updated_at'] ?? '' ),
		);
	}

	/**
	 * Read slot stats without querying bookings.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string,array<string,int|string>>
	 */
	public function get_slot_stats( $event_id ) {
		$stored = get_post_meta( $event_id, Meta::SLOT_STATS, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$slots = $this->initial_slot_stats( $event_id );

		foreach ( $stored as $slot_id => $stats ) {
			if ( ! is_array( $stats ) ) {
				continue;
			}

			if ( ! isset( $slots[ $slot_id ] ) ) {
				$slots[ $slot_id ] = array(
					'label'     => Slots::label( $event_id, $slot_id ),
					'sold'      => 0,
					'reserved'  => 0,
					'capacity'  => Slots::capacity_for( $event_id, $slot_id ),
					'remaining' => Slots::capacity_for( $event_id, $slot_id ),
				);
			}

			$slots[ $slot_id ]['sold']      = absint( $stats['sold'] ?? 0 );
			$slots[ $slot_id ]['reserved']  = absint( $stats['reserved'] ?? 0 );
			$slots[ $slot_id ]['remaining'] = max( 0, absint( $slots[ $slot_id ]['capacity'] ) - absint( $slots[ $slot_id ]['reserved'] ) );
		}

		return $slots;
	}

	/**
	 * Get remaining capacity from stored counters.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot ID.
	 * @return int
	 */
	public function remaining_capacity( $event_id, $slot_id = '' ) {
		if ( '' !== $slot_id ) {
			$slots = $this->get_slot_stats( $event_id );

			return isset( $slots[ $slot_id ] ) ? absint( $slots[ $slot_id ]['remaining'] ) : 0;
		}

		$stats = $this->get_event_stats( $event_id );

		return absint( $stats['remaining'] );
	}

	/**
	 * Initialise slot stats from the current event settings.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string,array<string,int|string>>
	 */
	private function initial_slot_stats( $event_id ) {
		$stats = array();

		if ( Meta::TYPE_TIMED !== Meta::event_type( $event_id ) ) {
			return $stats;
		}

		foreach ( Slots::generate( $event_id ) as $slot ) {
			$capacity = absint( $slot['capacity'] ?? get_post_meta( $event_id, Meta::SLOT_CAPACITY, true ) );

			$stats[ $slot['key'] ] = array(
				'label'     => $slot['label'],
				'sold'      => 0,
				'reserved'  => 0,
				'capacity'  => $capacity,
				'remaining' => $capacity,
			);
		}

		return $stats;
	}
}
