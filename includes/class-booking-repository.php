<?php
/**
 * Bookings table data access.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes wp_ys_bookings without scanning WooCommerce orders.
 */
final class Booking_Repository {
	/**
	 * Get the bookings table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'ys_bookings';
	}

	/**
	 * Create or upgrade the bookings table.
	 */
	public static function install_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			slot_id varchar(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending',
			participant_count int(10) unsigned NOT NULL DEFAULT 0,
			contact_name varchar(190) NOT NULL DEFAULT '',
			contact_email varchar(190) NOT NULL DEFAULT '',
			contact_phone varchar(80) NOT NULL DEFAULT '',
			scout_group varchar(190) NOT NULL DEFAULT '',
			booking_data longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_item (order_item_id),
			KEY event_status (event_id,status),
			KEY event_slot_status (event_id,slot_id,status),
			KEY order_id (order_id),
			KEY status_created (status,created_at),
			KEY created_at (created_at),
			KEY event_created (event_id,created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert or update booking rows for a WooCommerce order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int[] Event IDs touched.
	 */
	public function sync_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array();
		}

		$event_ids = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$event_id = $this->upsert_from_order_item( $order, $item_id, $item );

			if ( $event_id ) {
				$event_ids[] = $event_id;
			}
		}

		return array_values( array_unique( array_map( 'absint', $event_ids ) ) );
	}

	/**
	 * Update all rows for an order to a new order status.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status New status without wc- prefix.
	 * @return int[] Event IDs touched.
	 */
	public function update_order_status( $order_id, $status ) {
		global $wpdb;

		$event_ids = $this->get_event_ids_for_order( $order_id );

		if ( ! $event_ids ) {
			$event_ids = $this->sync_order( $order_id );
		}

		if ( ! $event_ids ) {
			return array();
		}

		$wpdb->update(
			self::table_name(),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'order_id' => absint( $order_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $event_ids;
	}

	/**
	 * Insert or update a row from one WooCommerce line item.
	 *
	 * @param WC_Order      $order WooCommerce order.
	 * @param int           $item_id Order item ID.
	 * @param WC_Order_Item $item Order item.
	 * @return int Event ID or 0.
	 */
	public function upsert_from_order_item( $order, $item_id, $item ) {
		global $wpdb;

		$event_id = absint( $item->get_meta( '_ys_event_id', true ) );

		if ( ! $event_id ) {
			return 0;
		}

		$booking = $this->extract_booking_data( $item );
		$date    = $order->get_date_created();
		$created = $date ? $date->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		$data    = array(
			'event_id'           => $event_id,
			'order_id'           => absint( $order->get_id() ),
			'order_item_id'      => absint( $item_id ),
			'slot_id'            => sanitize_text_field( (string) $item->get_meta( '_ys_slot', true ) ),
			'status'             => sanitize_key( $order->get_status() ),
			'participant_count'  => $this->participant_count( $item, $booking ),
			'contact_name'       => sanitize_text_field( $booking['contact_name'] ?? (string) $item->get_meta( 'Booking contact', true ) ),
			'contact_email'      => sanitize_email( $booking['contact_email'] ?? (string) $item->get_meta( 'Contact email', true ) ),
			'contact_phone'      => sanitize_text_field( $booking['contact_phone'] ?? (string) $item->get_meta( 'Contact phone', true ) ),
			'scout_group'        => sanitize_text_field( $booking['scout_group'] ?? (string) $item->get_meta( 'Scout Group', true ) ),
			'booking_data'       => wp_json_encode( is_array( $booking ) ? $booking : array() ),
			'updated_at'         => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		$existing_id = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table_name() . ' WHERE order_item_id = %d LIMIT 1',
					absint( $item_id )
				)
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				self::table_name(),
				$data,
				array( 'id' => $existing_id ),
				$formats,
				array( '%d' )
			);
		} else {
			$data['created_at'] = $created;
			$formats[]          = '%s';
			$wpdb->insert( self::table_name(), $data, $formats );
		}

		return $event_id;
	}

	/**
	 * Get booking rows for an admin list.
	 *
	 * @param array $args Query args.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function query( array $args ) {
		global $wpdb;

		$defaults = array(
			'event_id'  => 0,
			'status'    => '',
			'date_from' => '',
			'date_to'   => '',
			'search'    => '',
			'per_page'  => 20,
			'paged'     => 1,
		);
		$args     = wp_parse_args( $args, $defaults );
		$where    = array( '1=1' );
		$params   = array();

		if ( $args['event_id'] ) {
			$where[]  = 'event_id = %d';
			$params[] = absint( $args['event_id'] );
		}

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		if ( $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}

		if ( $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(CAST(id AS CHAR) LIKE %s OR CAST(order_id AS CHAR) LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s OR contact_phone LIKE %s OR scout_group LIKE %s OR slot_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . $where_sql;
		$total     = (int) $this->prepare_and_get_var( $count_sql, $params );
		$per_page  = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$paged     = max( 1, absint( $args['paged'] ) );
		$offset    = ( $paged - 1 ) * $per_page;
		$list_sql  = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . $where_sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$list_args = array_merge( $params, array( $per_page, $offset ) );
		$items     = $this->prepare_and_get_results( $list_sql, $list_args );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Get one booking by ID.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string,mixed>|null
	 */
	public function get( $booking_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
				absint( $booking_id )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get all active booking rows for a participant report.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function report_rows_for_event( $event_id ) {
		global $wpdb;

		$statuses     = Stats::reserved_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = 'SELECT * FROM ' . self::table_name() . " WHERE event_id = %d AND status IN ({$placeholders}) ORDER BY slot_id ASC, scout_group ASC, contact_name ASC, id ASC";
		$params       = array_merge( array( absint( $event_id ) ), $statuses );

		return $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) ), ARRAY_A );
	}

	/**
	 * Get unique active booking and participant parent emails for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string[]
	 */
	public function emails_for_event( $event_id ) {
		global $wpdb;

		$statuses     = Stats::reserved_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$page_size    = 200;
		$offset       = 0;
		$emails       = array();

		do {
			$sql    = 'SELECT contact_email, booking_data FROM ' . self::table_name() . " WHERE event_id = %d AND status IN ({$placeholders}) ORDER BY contact_email ASC, id ASC LIMIT %d OFFSET %d";
			$params = array_merge( array( absint( $event_id ) ), $statuses, array( $page_size, $offset ) );
			$rows   = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) ), ARRAY_A );

			foreach ( $rows as $row ) {
				$this->collect_row_emails( $emails, $row );
			}

			$offset += $page_size;
		} while ( count( $rows ) === $page_size );

		$emails = array_values( array_unique( $emails ) );
		sort( $emails );

		return $emails;
	}

	/**
	 * Collect email addresses from a booking row.
	 *
	 * @param string[] $emails Email accumulator.
	 * @param array    $row Booking row.
	 */
	private function collect_row_emails( array &$emails, array $row ) {
		$this->add_email_to_list( $emails, $row['contact_email'] ?? '' );

		if ( empty( $row['booking_data'] ) ) {
			return;
		}

		$booking = json_decode( (string) $row['booking_data'], true );

		if ( ! is_array( $booking ) ) {
			return;
		}

		$this->add_email_to_list( $emails, $booking['contact_email'] ?? '' );

		if ( empty( $booking['participants'] ) || ! is_array( $booking['participants'] ) ) {
			return;
		}

		foreach ( $booking['participants'] as $participant ) {
			if ( is_array( $participant ) ) {
				$this->add_email_to_list( $emails, $participant['parent_email'] ?? '' );
			}
		}
	}

	/**
	 * Add a valid email address to the accumulator.
	 *
	 * @param string[] $emails Email accumulator.
	 * @param string   $email Candidate email address.
	 */
	private function add_email_to_list( array &$emails, $email ) {
		$email = sanitize_email( $email );

		if ( is_email( $email ) ) {
			$emails[] = strtolower( $email );
		}
	}

	/**
	 * Get event IDs that have booking rows.
	 *
	 * @param int $limit Maximum event IDs to return.
	 * @return int[]
	 */
	public function event_ids_with_bookings( $limit = 500 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT event_id FROM ' . self::table_name() . ' GROUP BY event_id ORDER BY MAX(created_at) DESC LIMIT %d',
				min( 1000, max( 1, absint( $limit ) ) )
			)
		);

		return array_values( array_map( 'absint', $ids ) );
	}

	/**
	 * Get booking event references that are not current The Events Calendar events.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function orphaned_event_summaries( $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.event_id, COUNT(*) AS booking_count, SUM(b.participant_count) AS participant_count, MAX(b.created_at) AS last_booking_at, p.post_title, p.post_type, p.post_status
				FROM ' . self::table_name() . " b
				LEFT JOIN {$wpdb->posts} p ON p.ID = b.event_id
				WHERE p.ID IS NULL OR p.post_type <> %s
				GROUP BY b.event_id, p.post_title, p.post_type, p.post_status
				ORDER BY MAX(b.created_at) DESC
				LIMIT %d",
				'tribe_events',
				min( 200, max( 1, absint( $limit ) ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Delete booking rows for an orphaned/non-TEC event reference.
	 *
	 * @param int $event_id Referenced event ID.
	 * @return int Deleted row count.
	 */
	public function delete_orphaned_event_bookings( $event_id ) {
		global $wpdb;

		$event_id = absint( $event_id );

		if ( ! $event_id || ! $this->is_orphaned_event_id( $event_id ) ) {
			return 0;
		}

		return (int) $wpdb->delete(
			self::table_name(),
			array( 'event_id' => $event_id ),
			array( '%d' )
		);
	}

	/**
	 * Whether an event ID is missing or points to a non-TEC post type.
	 *
	 * @param int $event_id Referenced event ID.
	 * @return bool
	 */
	public function is_orphaned_event_id( $event_id ) {
		$post = get_post( absint( $event_id ) );

		return ! $post || 'tribe_events' !== $post->post_type;
	}

	/**
	 * Return event IDs for an order already present in the bookings table.
	 *
	 * @param int $order_id Order ID.
	 * @return int[]
	 */
	public function get_event_ids_for_order( $order_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT event_id FROM ' . self::table_name() . ' WHERE order_id = %d',
				absint( $order_id )
			)
		);

		return array_values( array_map( 'absint', $ids ) );
	}

	/**
	 * Decode structured booking data from an order item.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return array<string,mixed>
	 */
	private function extract_booking_data( $item ) {
		$booking = json_decode( (string) $item->get_meta( '_ys_booking_json', true ), true );

		return is_array( $booking ) ? $booking : array();
	}

	/**
	 * Determine participant count from structured data or order quantity.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @param array         $booking Booking data.
	 * @return int
	 */
	private function participant_count( $item, array $booking ) {
		if ( isset( $booking['participants'] ) && is_array( $booking['participants'] ) && count( $booking['participants'] ) > 0 ) {
			return count( $booking['participants'] );
		}

		return max( 1, absint( $item->get_quantity() ) );
	}

	/**
	 * Prepare SQL and fetch rows.
	 *
	 * @param string $sql SQL with placeholders.
	 * @param array  $params Parameters.
	 * @return array<int,array<string,mixed>>
	 */
	private function prepare_and_get_results( $sql, array $params ) {
		global $wpdb;

		if ( $params ) {
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Prepare SQL and fetch one value.
	 *
	 * @param string $sql SQL with placeholders.
	 * @param array  $params Parameters.
	 * @return string|null
	 */
	private function prepare_and_get_var( $sql, array $params ) {
		global $wpdb;

		if ( $params ) {
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		}

		return $wpdb->get_var( $sql );
	}
}
