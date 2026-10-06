<?php
/**
 * Public booking form.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents\Frontend;

use YorkScoutsEvents\Fields;
use YorkScoutsEvents\Meta;
use YorkScoutsEvents\Product_Manager;
use YorkScoutsEvents\Slots;
use YorkScoutsEvents\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the event booking form.
 */
final class Booking_Form {
	/**
	 * Counter service.
	 *
	 * @var Stats
	 */
	private $stats;

	/**
	 * Product manager.
	 *
	 * @var Product_Manager
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param Stats           $stats Counter service.
	 * @param Product_Manager $products Product manager.
	 */
	public function __construct( Stats $stats, Product_Manager $products ) {
		$this->stats    = $stats;
		$this->products = $products;
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'the_content', array( $this, 'append_booking_form' ) );
		add_action( 'template_redirect', array( $this, 'handle_submission' ) );
		add_action( 'wp_ajax_ys_event_availability', array( $this, 'ajax_availability' ) );
		add_action( 'wp_ajax_nopriv_ys_event_availability', array( $this, 'ajax_availability' ) );
	}

	/**
	 * Enqueue public CSS/JS on bookable event pages.
	 */
	public function enqueue_assets() {
		if ( ! is_singular( 'tribe_events' ) ) {
			return;
		}

		$event_id = get_queried_object_id();

		if ( ! $event_id || ! Meta::bookings_enabled( $event_id ) ) {
			return;
		}

		wp_enqueue_style( 'ys-events-public', YS_EVENTS_URL . 'assets/css/public.css', array(), YS_EVENTS_VERSION );
		wp_enqueue_script( 'ys-events-public', YS_EVENTS_URL . 'assets/js/public-booking.js', array(), YS_EVENTS_VERSION, true );
		wp_localize_script(
			'ys-events-public',
			'ysEventsPublic',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Append booking form to the main event content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_booking_form( $content ) {
		if ( ! is_singular( 'tribe_events' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$event_id = get_the_ID();

		if ( ! Meta::bookings_enabled( $event_id ) ) {
			return $content;
		}

		if ( Meta::bookings_closed( $event_id ) ) {
			return $content . '<div class="ys-booking-box"><h2>' . esc_html__( 'Book this event', 'york-scouts-events' ) . '</h2><p class="ys-full">' . esc_html__( 'Bookings are now closed for this event.', 'york-scouts-events' ) . '</p></div>';
		}

		$type      = Meta::event_type( $event_id );
		$price     = (float) Meta::price( $event_id );
		$date      = Meta::event_date( $event_id );
		$slots     = Meta::TYPE_TIMED === $type ? Slots::generate( $event_id ) : array();
		$remaining = Meta::TYPE_GENERAL === $type ? $this->remaining_for_request( $event_id, '' ) : null;
		$fields    = Fields::enabled_for_event( $event_id );

		ob_start();
		include YS_EVENTS_PATH . 'templates/booking-form.php';

		return $content . ob_get_clean();
	}

	/**
	 * Handle booking form submission and send the customer to checkout.
	 */
	public function handle_submission() {
		if ( empty( $_POST['ys_action'] ) || 'book_event' !== sanitize_key( wp_unslash( $_POST['ys_action'] ) ) ) {
			return;
		}

		$this->ensure_cart_loaded();

		$event_id = absint( $_POST['ys_event_id'] ?? 0 );
		$nonce    = sanitize_text_field( wp_unslash( $_POST['ys_booking_nonce'] ?? '' ) );

		if ( ! $event_id || 'tribe_events' !== get_post_type( $event_id ) || ! Meta::bookings_enabled( $event_id ) || ! wp_verify_nonce( $nonce, 'ys_book_event_' . $event_id ) ) {
			wp_die( esc_html__( 'Invalid booking request.', 'york-scouts-events' ) );
		}

		if ( Meta::bookings_closed( $event_id ) ) {
			wc_add_notice( __( 'Bookings are now closed for this event.', 'york-scouts-events' ), 'error' );
			return;
		}

		$quantity = min( 20, max( 1, absint( $_POST['ys_quantity'] ?? 1 ) ) );
		$type     = Meta::event_type( $event_id );
		$slot_id  = '';

		if ( Meta::TYPE_TIMED === $type ) {
			$slot_id = sanitize_text_field( wp_unslash( $_POST['ys_slot'] ?? '' ) );

			if ( ! $slot_id || ! Slots::is_valid_slot( $event_id, $slot_id ) ) {
				wc_add_notice( __( 'Please choose a valid time slot.', 'york-scouts-events' ), 'error' );
				return;
			}
		}

		if ( $this->remaining_for_request( $event_id, $slot_id ) < $quantity ) {
			wc_add_notice( __( 'There are not enough places remaining for that selection.', 'york-scouts-events' ), 'error' );
			return;
		}

		$booking = $this->build_booking_payload( $event_id, $slot_id, $quantity );

		if ( is_wp_error( $booking ) ) {
			wc_add_notice( $booking->get_error_message(), 'error' );
			return;
		}

		$product_id = $this->products->sync_for_event( $event_id );

		if ( ! $product_id ) {
			wc_add_notice( __( 'This event is not currently available for checkout.', 'york-scouts-events' ), 'error' );
			return;
		}

		WC()->session->set( 'ys_booking_contact', $booking );
		$cart_key = WC()->cart->add_to_cart(
			$product_id,
			$quantity,
			0,
			array(),
			array(
				'ys_event_id' => $event_id,
				'ys_slot'     => $slot_id,
				'ys_booking'  => $booking,
				'ys_unique'   => wp_generate_uuid4(),
			)
		);

		if ( ! $cart_key ) {
			wc_add_notice( __( 'Could not add this booking to the basket.', 'york-scouts-events' ), 'error' );
			return;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Return live availability for cached public booking forms.
	 */
	public function ajax_availability() {
		$this->ensure_cart_loaded();
		nocache_headers();

		$event_id = absint( wp_unslash( $_REQUEST['event_id'] ?? 0 ) );

		if ( ! $event_id || 'tribe_events' !== get_post_type( $event_id ) || ! Meta::bookings_enabled( $event_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This event is not available for booking.', 'york-scouts-events' ),
				),
				404
			);
		}

		$type   = Meta::event_type( $event_id );
		$closed = Meta::bookings_closed( $event_id );
		$data   = array(
			'event_id'      => $event_id,
			'type'          => $type,
			'bookings_open' => ! $closed,
			'nonce'         => wp_create_nonce( 'ys_book_event_' . $event_id ),
			'messages'      => array(
				'closed'   => __( 'Bookings are now closed for this event.', 'york-scouts-events' ),
				'sold_out' => __( 'Sold out', 'york-scouts-events' ),
			),
		);

		if ( Meta::TYPE_TIMED === $type ) {
			$data['slots'] = $this->availability_slots( $event_id, $closed );
		} else {
			$stats     = $this->stats->get_event_stats( $event_id );
			$remaining = $this->remaining_for_request( $event_id, '' );

			$data['event'] = array(
				'sold'            => absint( $stats['sold'] ?? 0 ),
				'reserved'        => absint( $stats['reserved'] ?? 0 ),
				'capacity'        => absint( $stats['capacity'] ?? 0 ),
				'remaining'       => $remaining,
				'available'       => ! $closed && $remaining > 0,
				'remaining_label' => $closed ? __( 'Bookings are now closed for this event.', 'york-scouts-events' ) : $this->remaining_label( $remaining ),
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * Build and validate structured booking data.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot ID.
	 * @param int    $quantity Participant count.
	 * @return array|\WP_Error
	 */
	private function build_booking_payload( $event_id, $slot_id, $quantity ) {
		$enabled                   = Fields::enabled_for_event( $event_id );
		$custom_fields             = Fields::custom_definitions( $event_id );
		$custom_booking_fields     = Fields::custom_definitions( $event_id, 'booking' );
		$custom_participant_fields = Fields::custom_definitions( $event_id, 'participant' );
		$booking                   = array(
			'event_id'          => $event_id,
			'slot'              => $slot_id,
			'enabled_fields'    => $enabled,
			'custom_fields'     => $custom_fields,
			'contact_name'      => '',
			'contact_email'     => '',
			'contact_phone'     => '',
			'scout_group'       => '',
			'custom_booking'   => array(),
			'participants'     => array(),
			'consent'          => Fields::is_enabled( $event_id, 'consent' ) ? 1 : 0,
		);

		$booking_fields = array(
			'contact_name'      => __( 'Booking contact name', 'york-scouts-events' ),
			'contact_email'     => __( 'Email address', 'york-scouts-events' ),
			'contact_phone'     => __( 'Phone number', 'york-scouts-events' ),
			'scout_group'       => __( 'Scout Group', 'york-scouts-events' ),
		);

		foreach ( $booking_fields as $key => $label ) {
			if ( ! Fields::is_enabled( $event_id, $key ) ) {
				continue;
			}

			$post_key = 'ys_' . $key;
			$value    = isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '';

			if ( '' === trim( $value ) ) {
				return new \WP_Error(
					'ys_missing_booking_field',
					sprintf(
						/* translators: %s: Field label. */
						__( 'Please complete %s.', 'york-scouts-events' ),
						$label
					)
				);
			}

			$booking[ $key ] = 'contact_email' === $key ? sanitize_email( $value ) : $value;
		}

		if ( Fields::is_enabled( $event_id, 'contact_email' ) && ! is_email( $booking['contact_email'] ) ) {
			return new \WP_Error( 'ys_invalid_email', __( 'Please enter a valid email address.', 'york-scouts-events' ) );
		}

		if ( Fields::is_enabled( $event_id, 'consent' ) && empty( $_POST['ys_consent'] ) ) {
			return new \WP_Error( 'ys_missing_consent', __( 'You must confirm the consent statement.', 'york-scouts-events' ) );
		}

		$custom_booking_values = isset( $_POST['ys_custom_booking'] ) && is_array( $_POST['ys_custom_booking'] ) ? (array) wp_unslash( $_POST['ys_custom_booking'] ) : array();

		foreach ( $custom_booking_fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );

			if ( ! $key ) {
				continue;
			}

			$value = Fields::sanitize_custom_value( $custom_booking_values[ $key ] ?? '', $field );

			if ( ! empty( $field['required'] ) && ! Fields::custom_value_present( $value, $field ) ) {
				return new \WP_Error(
					'ys_missing_custom_booking_field',
					sprintf(
						/* translators: %s: Field label. */
						__( 'Please complete %s.', 'york-scouts-events' ),
						$field['label']
					)
				);
			}

			if ( 'email' === ( $field['type'] ?? '' ) && '' !== $value && ! is_email( $value ) ) {
				return new \WP_Error(
					'ys_invalid_custom_booking_email',
					sprintf(
						/* translators: %s: Field label. */
						__( 'Please enter a valid email address for %s.', 'york-scouts-events' ),
						$field['label']
					)
				);
			}

			$booking['custom_booking'][ $key ] = $value;
		}

		$raw_ages      = isset( $_POST['ys_participant_age'] ) ? (array) wp_unslash( $_POST['ys_participant_age'] ) : array();
		$names         = $this->posted_array( 'ys_participant_name', 'sanitize_text_field' );
		$ages          = array_map( 'absint', $raw_ages );
		$parent_emails = $this->posted_array( 'ys_participant_parent_email', 'sanitize_email' );
		$parent_phones = $this->posted_array( 'ys_participant_parent_phone', 'sanitize_text_field' );
		$emergency_contacts = $this->posted_array( 'ys_participant_emergency_contact', 'sanitize_text_field' );
		$emergency_same     = isset( $_POST['ys_participant_emergency_same_as_parent'] ) && is_array( $_POST['ys_participant_emergency_same_as_parent'] ) ? (array) wp_unslash( $_POST['ys_participant_emergency_same_as_parent'] ) : array();
		$dietary       = $this->posted_array( 'ys_participant_dietary', 'sanitize_textarea_field' );
		$accessibility = $this->posted_array( 'ys_participant_accessibility', 'sanitize_textarea_field' );
		$custom_participant_values = isset( $_POST['ys_custom_participant'] ) && is_array( $_POST['ys_custom_participant'] ) ? (array) wp_unslash( $_POST['ys_custom_participant'] ) : array();

		if ( Fields::is_enabled( $event_id, 'participant_name' ) && count( array_filter( $names, 'strlen' ) ) < $quantity ) {
			return new \WP_Error( 'ys_missing_participant_names', __( 'Please enter a name for every participant.', 'york-scouts-events' ) );
		}

		if ( Fields::is_enabled( $event_id, 'participant_age' ) && count( array_filter( $raw_ages, array( $this, 'has_posted_value' ) ) ) < $quantity ) {
			return new \WP_Error( 'ys_missing_participant_ages', __( 'Please enter an age for every participant.', 'york-scouts-events' ) );
		}

		for ( $index = 0; $index < $quantity; $index++ ) {
			$participant_parent_email       = Fields::is_enabled( $event_id, 'parent_email' ) ? ( $parent_emails[ $index ] ?? '' ) : '';
			$participant_parent_phone       = Fields::is_enabled( $event_id, 'parent_phone' ) ? ( $parent_phones[ $index ] ?? '' ) : '';
			$participant_emergency_contact  = '';
			$participant_emergency_same     = 0;

			if ( Fields::is_enabled( $event_id, 'parent_email' ) ) {
				if ( '' === trim( (string) $participant_parent_email ) || ! is_email( $participant_parent_email ) ) {
					return new \WP_Error(
						'ys_invalid_participant_parent_email',
						sprintf(
							/* translators: %d: Participant number. */
							__( 'Please enter a valid parent email for participant %d.', 'york-scouts-events' ),
							$index + 1
						)
					);
				}
			}

			if ( Fields::is_enabled( $event_id, 'parent_phone' ) && '' === trim( (string) $participant_parent_phone ) ) {
				return new \WP_Error(
					'ys_missing_participant_parent_phone',
					sprintf(
						/* translators: %d: Participant number. */
						__( 'Please complete parent tel number for participant %d.', 'york-scouts-events' ),
						$index + 1
					)
				);
			}

			if ( Fields::is_enabled( $event_id, 'emergency_contact' ) ) {
				$participant_emergency_same = ! empty( $emergency_same[ $index ] ) ? 1 : 0;

				if ( $participant_emergency_same ) {
					$participant_emergency_contact = $this->parent_contact_summary( $participant_parent_email, $participant_parent_phone );

					if ( '' === $participant_emergency_contact ) {
						return new \WP_Error(
							'ys_missing_participant_emergency_contact',
							sprintf(
								/* translators: %d: Participant number. */
								__( 'Please complete parent contact details for participant %d before using them as emergency contact details.', 'york-scouts-events' ),
								$index + 1
							)
						);
					}
				} else {
					$participant_emergency_contact = $emergency_contacts[ $index ] ?? '';

					if ( '' === trim( (string) $participant_emergency_contact ) ) {
						return new \WP_Error(
							'ys_missing_participant_emergency_contact',
							sprintf(
								/* translators: %d: Participant number. */
								__( 'Please complete emergency contact for participant %d.', 'york-scouts-events' ),
								$index + 1
							)
						);
					}
				}
			}

			$participant = array(
				'name'                     => Fields::is_enabled( $event_id, 'participant_name' ) ? ( $names[ $index ] ?? '' ) : '',
				'age'                      => Fields::is_enabled( $event_id, 'participant_age' ) ? ( $ages[ $index ] ?? '' ) : '',
				'parent_email'             => $participant_parent_email,
				'parent_phone'             => $participant_parent_phone,
				'emergency_contact'        => Fields::is_enabled( $event_id, 'emergency_contact' ) ? $participant_emergency_contact : '',
				'emergency_same_as_parent' => $participant_emergency_same,
				'dietary'                  => Fields::is_enabled( $event_id, 'dietary' ) ? ( $dietary[ $index ] ?? '' ) : '',
				'accessibility'            => Fields::is_enabled( $event_id, 'accessibility' ) ? ( $accessibility[ $index ] ?? '' ) : '',
				'custom'                   => array(),
			);

			foreach ( $custom_participant_fields as $field ) {
				$key = sanitize_key( $field['key'] ?? '' );

				if ( ! $key ) {
					continue;
				}

				$values = isset( $custom_participant_values[ $key ] ) && is_array( $custom_participant_values[ $key ] ) ? $custom_participant_values[ $key ] : array();
				$value  = Fields::sanitize_custom_value( $values[ $index ] ?? '', $field );

				if ( ! empty( $field['required'] ) && ! Fields::custom_value_present( $value, $field ) ) {
					return new \WP_Error(
						'ys_missing_custom_participant_field',
						sprintf(
							/* translators: 1: Field label, 2: Participant number. */
							__( 'Please complete %1$s for participant %2$d.', 'york-scouts-events' ),
							$field['label'],
							$index + 1
						)
					);
				}

				if ( 'email' === ( $field['type'] ?? '' ) && '' !== $value && ! is_email( $value ) ) {
					return new \WP_Error(
						'ys_invalid_custom_participant_email',
						sprintf(
							/* translators: 1: Field label, 2: Participant number. */
							__( 'Please enter a valid email address for %1$s on participant %2$d.', 'york-scouts-events' ),
							$field['label'],
							$index + 1
						)
					);
				}

				$participant['custom'][ $key ] = $value;
			}

			$booking['participants'][] = $participant;
		}

		return $booking;
	}

	/**
	 * Get remaining capacity minus current cart reservations.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot ID.
	 * @return int
	 */
	private function remaining_for_request( $event_id, $slot_id ) {
		return max( 0, $this->stats->remaining_capacity( $event_id, $slot_id ) - $this->cart_quantity( $event_id, $slot_id ) );
	}

	/**
	 * Build live slot availability from stored counters.
	 *
	 * @param int  $event_id Event post ID.
	 * @param bool $closed Whether bookings are closed.
	 * @return array<int,array<string,mixed>>
	 */
	private function availability_slots( $event_id, $closed ) {
		$availability = array();

		foreach ( Slots::generate( $event_id ) as $slot ) {
			$key       = (string) ( $slot['key'] ?? '' );
			$label     = (string) ( $slot['label'] ?? $key );
			$remaining = $this->remaining_for_request( $event_id, $key );

			$availability[] = array(
				'key'                => $key,
				'label'              => $label,
				'capacity'           => absint( $slot['capacity'] ?? Slots::capacity_for( $event_id, $key ) ),
				'remaining'          => $remaining,
				'available'          => ! $closed && $remaining > 0,
				'availability_label' => $this->slot_availability_label( $remaining ),
				'option_label'        => $label . ' - ' . $this->slot_availability_label( $remaining ),
			);
		}

		return $availability;
	}

	/**
	 * Human-readable remaining places label.
	 *
	 * @param int $remaining Remaining capacity.
	 * @return string
	 */
	private function remaining_label( $remaining ) {
		$remaining = absint( $remaining );

		if ( $remaining < 1 ) {
			return __( 'Sold out', 'york-scouts-events' );
		}

		return sprintf(
			/* translators: %d: Number of remaining places. */
			_n( '%d place remaining', '%d places remaining', $remaining, 'york-scouts-events' ),
			$remaining
		);
	}

	/**
	 * Human-readable slot availability label.
	 *
	 * @param int $remaining Remaining capacity.
	 * @return string
	 */
	private function slot_availability_label( $remaining ) {
		$remaining = absint( $remaining );

		if ( $remaining < 1 ) {
			return __( 'Full', 'york-scouts-events' );
		}

		return sprintf(
			/* translators: %d: Number of remaining places. */
			_n( '%d place left', '%d places left', $remaining, 'york-scouts-events' ),
			$remaining
		);
	}

	/**
	 * Ensure WooCommerce cart state is available before cart-aware capacity checks.
	 */
	private function ensure_cart_loaded() {
		if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) || ! WC() ) {
			return;
		}

		if ( ! WC()->cart || ! WC()->session ) {
			wc_load_cart();
		}
	}

	/**
	 * Count matching current cart quantity.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $slot_id Slot ID.
	 * @return int
	 */
	private function cart_quantity( $event_id, $slot_id ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return 0;
		}

		$quantity = 0;

		foreach ( WC()->cart->get_cart() as $item ) {
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
	 * Read a sanitized posted array.
	 *
	 * @param string   $key Request key without [].
	 * @param callable $sanitizer Sanitizer callback.
	 * @return array
	 */
	private function posted_array( $key, $sanitizer ) {
		$values = isset( $_POST[ $key ] ) ? (array) wp_unslash( $_POST[ $key ] ) : array();

		return array_map( $sanitizer, $values );
	}

	/**
	 * Build a readable emergency contact value from participant parent contact fields.
	 *
	 * @param string $email Parent email address.
	 * @param string $phone Parent telephone number.
	 * @return string
	 */
	private function parent_contact_summary( $email, $phone ) {
		$parts = array_filter(
			array(
				$email,
				$phone,
			),
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		return implode( ' / ', array_map( 'sanitize_text_field', $parts ) );
	}

	/**
	 * Check whether a posted value is non-empty while allowing numeric zero.
	 *
	 * @param mixed $value Posted value.
	 * @return bool
	 */
	private function has_posted_value( $value ) {
		return '' !== trim( (string) $value );
	}
}
