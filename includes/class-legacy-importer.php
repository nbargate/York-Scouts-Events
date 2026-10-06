<?php
/**
 * Legacy order-item importer.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports pre-table bookings from WooCommerce order item meta in small batches.
 */
final class Legacy_Importer {
	const HOOK = 'ys_events_legacy_import_batch';

	/**
	 * Booking repository.
	 *
	 * @var Booking_Repository
	 */
	private $repository;

	/**
	 * Counter service.
	 *
	 * @var Stats
	 */
	private $stats;

	/**
	 * Constructor.
	 *
	 * @param Booking_Repository $repository Booking repository.
	 * @param Stats              $stats Counter service.
	 */
	public function __construct( Booking_Repository $repository, Stats $stats ) {
		$this->repository = $repository;
		$this->stats      = $stats;
	}

	/**
	 * Register importer hooks.
	 */
	public function hooks() {
		if ( 'yes' === get_option( 'ys_events_legacy_import_complete', 'no' ) ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'admin_tick' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( self::HOOK, array( $this, 'cron_tick' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Process a small batch during admin requests.
	 */
	public function admin_tick() {
		if ( ! Capabilities::can_run_legacy_import() ) {
			return;
		}

		$this->process_batch( 25 );
	}

	/**
	 * Process a cron batch.
	 */
	public function cron_tick() {
		$this->process_batch( 50 );

		if ( 'yes' !== get_option( 'ys_events_legacy_import_complete', 'no' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Import legacy booking line items without loading all orders.
	 *
	 * @param int $limit Batch size.
	 */
	public function process_batch( $limit = 50 ) {
		global $wpdb;

		$item_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$items_table     = $wpdb->prefix . 'woocommerce_order_items';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $item_meta_table ) ) !== $item_meta_table ) {
			return;
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items_table ) ) !== $items_table ) {
			return;
		}

		$cursor = absint( get_option( 'ys_events_legacy_import_cursor', 0 ) );
		$limit  = min( 100, max( 1, absint( $limit ) ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT m.order_item_id, i.order_id
				FROM {$item_meta_table} m
				INNER JOIN {$items_table} i ON i.order_item_id = m.order_item_id
				WHERE m.meta_key = %s AND m.order_item_id > %d
				ORDER BY m.order_item_id ASC
				LIMIT %d",
				'_ys_event_id',
				$cursor,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			update_option( 'ys_events_legacy_import_complete', 'yes', false );
			return;
		}

		$event_ids = array();
		$max_id    = $cursor;

		foreach ( $rows as $row ) {
			$item_id = absint( $row['order_item_id'] );
			$order   = wc_get_order( absint( $row['order_id'] ) );
			$max_id  = max( $max_id, $item_id );

			if ( ! $order ) {
				continue;
			}

			$item = $order->get_item( $item_id );

			if ( ! $item ) {
				continue;
			}

			$event_id = $this->repository->upsert_from_order_item( $order, $item_id, $item );

			if ( $event_id ) {
				$event_ids[] = $event_id;
			}
		}

		update_option( 'ys_events_legacy_import_cursor', $max_id, false );
		$this->stats->rebuild_events( $event_ids );

		if ( count( $rows ) < $limit ) {
			update_option( 'ys_events_legacy_import_complete', 'yes', false );
		}
	}

	/**
	 * Show a quiet migration notice while legacy imports are running.
	 */
	public function admin_notice() {
		if ( ! Capabilities::can_run_legacy_import() ) {
			return;
		}

		if ( 'yes' === get_option( 'ys_events_legacy_import_complete', 'no' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>' . esc_html__( 'York Scouts Events is importing existing booking records in small batches. Ticket sales remain available while this runs.', 'york-scouts-events' ) . '</p></div>';
	}
}
