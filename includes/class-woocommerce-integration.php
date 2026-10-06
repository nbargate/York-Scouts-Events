<?php
/**
 * WooCommerce hooks.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects cart, checkout, orders and counters.
 */
final class WooCommerce_Integration {
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
	 * Register WooCommerce hooks.
	 */
	public function hooks() {
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'create_booking_rows' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'recheck_capacity_in_cart' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'adjust_checkout_fields' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'prefill_checkout_value' ), 10, 2 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'complete_paid_event_ticket_orders' ), 10, 3 );
	}

	/**
	 * Show event metadata in the cart.
	 *
	 * @param array $data Cart item display data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $data, $cart_item ) {
		if ( empty( $cart_item['ys_booking'] ) || ! is_array( $cart_item['ys_booking'] ) ) {
			return $data;
		}

		$booking  = $cart_item['ys_booking'];
		$event_id = absint( $booking['event_id'] ?? 0 );

		if ( $event_id ) {
			$data[] = array(
				'key'   => __( 'Event', 'york-scouts-events' ),
				'value' => esc_html( get_the_title( $event_id ) ),
			);
		}

		if ( ! empty( $booking['slot'] ) ) {
			$data[] = array(
				'key'   => __( 'Time slot', 'york-scouts-events' ),
				'value' => esc_html( Slots::label( $event_id, (string) $booking['slot'] ) ),
			);
		}

		if ( ! empty( $booking['scout_group'] ) ) {
			$data[] = array(
				'key'   => __( 'Scout Group', 'york-scouts-events' ),
				'value' => esc_html( $booking['scout_group'] ),
			);
		}

		return $data;
	}

	/**
	 * Use the event title for event cart line items.
	 *
	 * @param string $name Product name.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function cart_item_name( $name, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		if ( ! empty( $cart_item['ys_event_id'] ) ) {
			return esc_html( get_the_title( absint( $cart_item['ys_event_id'] ) ) );
		}

		return $name;
	}

	/**
	 * Persist booking metadata to the WooCommerce order item.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart item data.
	 * @param WC_Order              $order Order.
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );

		if ( empty( $values['ys_booking'] ) || ! is_array( $values['ys_booking'] ) ) {
			return;
		}

		$booking  = $values['ys_booking'];
		$event_id = absint( $booking['event_id'] ?? 0 );
		$slot     = sanitize_text_field( (string) ( $booking['slot'] ?? '' ) );

		if ( ! $event_id ) {
			return;
		}

		$item->add_meta_data( '_ys_event_id', $event_id, true );
		$item->add_meta_data( '_ys_slot', $slot, true );
		$item->add_meta_data( '_ys_booking_json', wp_json_encode( $booking ), true );
		$item->add_meta_data( __( 'Event', 'york-scouts-events' ), get_the_title( $event_id ), true );

		if ( '' !== $slot ) {
			$item->add_meta_data( __( 'Time slot', 'york-scouts-events' ), Slots::label( $event_id, $slot ), true );
		}

		$this->add_visible_booking_meta( $item, $booking );
	}

	/**
	 * Create booking rows after checkout has created the order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function create_booking_rows( $order_id ) {
		$event_ids = $this->repository->sync_order( $order_id );
		$this->stats->rebuild_events( $event_ids );
	}

	/**
	 * Update booking row status and counters on every order status change.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @param WC_Order $order Order.
	 */
	public function order_status_changed( $order_id, $old_status, $new_status, $order ) {
		unset( $old_status, $order );

		$event_ids = $this->repository->update_order_status( $order_id, $new_status );
		$this->stats->rebuild_events( $event_ids );
	}

	/**
	 * Mark paid event-ticket-only orders as completed instead of processing.
	 *
	 * @param string   $status WooCommerce's proposed paid order status.
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public function complete_paid_event_ticket_orders( $status, $order_id, $order = null ) {
		if ( 'processing' !== $status ) {
			return $status;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
			return $status;
		}

		return $this->order_contains_only_event_tickets( $order ) ? 'completed' : $status;
	}

	/**
	 * Keep checkout lean when the basket only contains event tickets.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function adjust_checkout_fields( $fields ) {
		if ( ! $this->cart_has_only_events() ) {
			return $fields;
		}

		$remove = array(
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
		);

		foreach ( $remove as $key ) {
			unset( $fields['billing'][ $key ] );
		}

		if ( isset( $fields['billing']['billing_first_name'] ) ) {
			$fields['billing']['billing_first_name']['label'] = __( 'Booking contact first name', 'york-scouts-events' );
		}

		if ( isset( $fields['billing']['billing_last_name'] ) ) {
			$fields['billing']['billing_last_name']['label'] = __( 'Booking contact last name', 'york-scouts-events' );
		}

		return $fields;
	}

	/**
	 * Prefill checkout fields from booking form contact details.
	 *
	 * @param mixed  $value Field value.
	 * @param string $input Field key.
	 * @return mixed
	 */
	public function prefill_checkout_value( $value, $input ) {
		if ( ! WC()->session ) {
			return $value;
		}

		$booking = WC()->session->get( 'ys_booking_contact' );

		if ( ! is_array( $booking ) ) {
			return $value;
		}

		if ( 'billing_email' === $input ) {
			return $booking['contact_email'] ?? $value;
		}

		if ( 'billing_phone' === $input ) {
			return $booking['contact_phone'] ?? $value;
		}

		if ( in_array( $input, array( 'billing_first_name', 'billing_last_name' ), true ) ) {
			$parts = preg_split( '/\s+/', trim( (string) ( $booking['contact_name'] ?? '' ) ), 2 );

			return 'billing_first_name' === $input ? ( $parts[0] ?? '' ) : ( $parts[1] ?? '' );
		}

		return $value;
	}

	/**
	 * Recheck stored counter capacity before checkout.
	 */
	public function recheck_capacity_in_cart() {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['ys_event_id'] ) ) {
				continue;
			}

			$event_id  = absint( $item['ys_event_id'] );
			$slot_id   = sanitize_text_field( (string) ( $item['ys_slot'] ?? '' ) );
			$quantity  = absint( $item['quantity'] ?? 0 );
			$available = $this->stats->remaining_capacity( $event_id, $slot_id ) - $this->cart_quantity( $event_id, $slot_id, $key );

			if ( $quantity > $available ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: Event title, 2: Slot label. */
						__( 'There are no longer enough places available for %1$s%2$s.', 'york-scouts-events' ),
						get_the_title( $event_id ),
						$slot_id ? ' at ' . Slots::label( $event_id, $slot_id ) : ''
					),
					'error'
				);
			}
		}
	}

	/**
	 * Count matching items currently in cart.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot key.
	 * @param string $ignore_key Cart item key to ignore.
	 * @return int
	 */
	public function cart_quantity( $event_id, $slot_id = '', $ignore_key = '' ) {
		if ( ! WC()->cart ) {
			return 0;
		}

		$quantity = 0;

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $ignore_key && $key === $ignore_key ) {
				continue;
			}

			if ( absint( $item['ys_event_id'] ?? 0 ) !== absint( $event_id ) ) {
				continue;
			}

			if ( sanitize_text_field( (string) ( $item['ys_slot'] ?? '' ) ) !== (string) $slot_id ) {
				continue;
			}

			$quantity += absint( $item['quantity'] ?? 0 );
		}

		return $quantity;
	}

	/**
	 * Determine whether the cart only contains York Scouts event tickets.
	 *
	 * @return bool
	 */
	private function cart_has_only_events() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['ys_event_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether every line item in an order is a York Scouts event ticket.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function order_contains_only_event_tickets( $order ) {
		$items = $order->get_items( 'line_item' );

		if ( empty( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ! $this->order_item_is_event_ticket( $item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an order item is a York Scouts event ticket.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function order_item_is_event_ticket( $item ) {
		if ( absint( $item->get_meta( '_ys_event_id', true ) ) ) {
			return true;
		}

		$product_id = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;

		return $product_id && 'yes' === get_post_meta( $product_id, '_ys_event_ticket_product', true );
	}

	/**
	 * Add readable booking data to the WooCommerce order item.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param array                 $booking Booking data.
	 */
	private function add_visible_booking_meta( $item, array $booking ) {
		$map = array(
			'contact_name'      => __( 'Booking contact', 'york-scouts-events' ),
			'contact_email'     => __( 'Contact email', 'york-scouts-events' ),
			'contact_phone'     => __( 'Contact phone', 'york-scouts-events' ),
			'scout_group'       => __( 'Scout Group', 'york-scouts-events' ),
		);

		foreach ( $map as $key => $label ) {
			if ( ! empty( $booking[ $key ] ) ) {
				$item->add_meta_data( $label, sanitize_text_field( $booking[ $key ] ), true );
			}
		}

		if ( ! empty( $booking['emergency_contact'] ) ) {
			$item->add_meta_data( __( 'Legacy emergency contact', 'york-scouts-events' ), sanitize_text_field( $booking['emergency_contact'] ), true );
		}

		$this->add_custom_booking_meta( $item, $booking );

		if ( empty( $booking['participants'] ) || ! is_array( $booking['participants'] ) ) {
			return;
		}

		$event_id                  = absint( $booking['event_id'] ?? 0 );
		$custom_participant_fields = Fields::custom_definitions_from_booking( $event_id, $booking, 'participant' );

		foreach ( $booking['participants'] as $index => $participant ) {
			if ( ! is_array( $participant ) ) {
				continue;
			}

			$number = $index + 1;
			$name   = sanitize_text_field( $participant['name'] ?? '' );
			$age    = sanitize_text_field( (string) ( $participant['age'] ?? '' ) );

			if ( '' !== $name || '' !== $age ) {
				$description = '' !== $name ? $name : sprintf(
					/* translators: %d: Participant number. */
					__( 'Participant %d', 'york-scouts-events' ),
					$number
				);
				$description .= '' !== $age ? ' (' . __( 'Age', 'york-scouts-events' ) . ' ' . $age . ')' : '';
				$item->add_meta_data(
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Participant %d', 'york-scouts-events' ),
						$number
					),
					$description,
					false
				);
			}

			if ( ! empty( $participant['parent_email'] ) ) {
				$item->add_meta_data(
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Participant %d parent email', 'york-scouts-events' ),
						$number
					),
					sanitize_email( $participant['parent_email'] ),
					false
				);
			}

			if ( ! empty( $participant['parent_phone'] ) ) {
				$item->add_meta_data(
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Participant %d parent tel number', 'york-scouts-events' ),
						$number
					),
					sanitize_text_field( $participant['parent_phone'] ),
					false
				);
			}

			if ( ! empty( $participant['dietary'] ) ) {
				$item->add_meta_data(
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Participant %d dietary', 'york-scouts-events' ),
						$number
					),
					sanitize_textarea_field( $participant['dietary'] ),
					false
				);
			}

			if ( ! empty( $participant['emergency_contact'] ) ) {
				$item->add_meta_data(
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Participant %d emergency contact', 'york-scouts-events' ),
						$number
					),
					sanitize_text_field( $participant['emergency_contact'] ),
					false
				);
			}

			if ( ! empty( $participant['accessibility'] ) ) {
				$item->add_meta_data(
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Participant %d: Please tell us about any Accessibility, Medical or support accommodations required', 'york-scouts-events' ),
						$number
					),
					sanitize_textarea_field( $participant['accessibility'] ),
					false
				);
			}

			$this->add_custom_participant_meta( $item, $number, $participant, $custom_participant_fields );
		}
	}

	/**
	 * Add custom booking-level answers to the order item.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param array                 $booking Booking data.
	 */
	private function add_custom_booking_meta( $item, array $booking ) {
		$event_id = absint( $booking['event_id'] ?? 0 );
		$fields   = Fields::custom_definitions_from_booking( $event_id, $booking, 'booking' );
		$values   = isset( $booking['custom_booking'] ) && is_array( $booking['custom_booking'] ) ? $booking['custom_booking'] : array();

		foreach ( $fields as $field ) {
			$key   = sanitize_key( $field['key'] ?? '' );
			$value = $this->display_custom_value( $values[ $key ] ?? '' );

			if ( ! $key || '' === $value ) {
				continue;
			}

			$item->add_meta_data( sanitize_text_field( $field['label'] ), $value, false );
		}
	}

	/**
	 * Add custom participant answers to the order item.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param int                   $number Participant number.
	 * @param array                 $participant Participant data.
	 * @param array                 $fields Custom field definitions.
	 */
	private function add_custom_participant_meta( $item, $number, array $participant, array $fields ) {
		$values = isset( $participant['custom'] ) && is_array( $participant['custom'] ) ? $participant['custom'] : array();

		foreach ( $fields as $field ) {
			$key   = sanitize_key( $field['key'] ?? '' );
			$value = $this->display_custom_value( $values[ $key ] ?? '' );

			if ( ! $key || '' === $value ) {
				continue;
			}

			$item->add_meta_data(
				sprintf(
					/* translators: 1: Participant number, 2: Field label. */
					__( 'Participant %1$d %2$s', 'york-scouts-events' ),
					absint( $number ),
					sanitize_text_field( $field['label'] )
				),
				$value,
				false
			);
		}
	}

	/**
	 * Normalize custom values for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function display_custom_value( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' === $value ? '' : $value;
	}
}
