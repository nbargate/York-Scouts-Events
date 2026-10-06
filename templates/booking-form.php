<?php
/**
 * Public booking form template.
 *
 * @package YorkScoutsEvents
 *
 * @var int      $event_id Event post ID.
 * @var string   $type Event type.
 * @var float    $price Ticket price.
 * @var string   $date Event date.
 * @var array    $slots Timed slot data.
 * @var int|null $remaining General admission remaining places.
 * @var array    $fields Enabled fields.
 */

use YorkScoutsEvents\Fields;
use YorkScoutsEvents\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$participant_fields = array(
	'participant_name'  => in_array( 'participant_name', $fields, true ),
	'participant_age'   => in_array( 'participant_age', $fields, true ),
	'parent_email'      => in_array( 'parent_email', $fields, true ),
	'parent_phone'      => in_array( 'parent_phone', $fields, true ),
	'emergency_contact' => in_array( 'emergency_contact', $fields, true ),
	'dietary'           => in_array( 'dietary', $fields, true ),
	'accessibility'     => in_array( 'accessibility', $fields, true ),
);
$custom_booking_fields     = Fields::custom_definitions( $event_id, 'booking' );
$custom_participant_fields = Fields::custom_definitions( $event_id, 'participant' );
$quantity_max              = null === $remaining ? 20 : max( 1, min( 20, absint( $remaining ) ) );
$has_parent_contact        = Fields::is_enabled( $event_id, 'parent_email' ) || Fields::is_enabled( $event_id, 'parent_phone' );
$event_type                = $type;
$is_general_sold_out       = Meta::TYPE_GENERAL === $event_type && null !== $remaining && $remaining < 1;
$availability_url          = add_query_arg(
	array(
		'action'   => 'ys_event_availability',
		'event_id' => absint( $event_id ),
	),
	admin_url( 'admin-ajax.php' )
);
$slot_field_id             = 'ys_slot_' . absint( $event_id );
$quantity_field_id         = 'ys_quantity_' . absint( $event_id );
?>
<div class="ys-booking-box">
	<h2><?php esc_html_e( 'Book this event', 'york-scouts-events' ); ?></h2>
	<p class="ys-booking-summary">
		<strong><?php echo $date ? esc_html( date_i18n( 'j F Y', strtotime( $date ) ) ) : esc_html__( 'Date to be confirmed', 'york-scouts-events' ); ?></strong>
		<span><?php echo wp_kses_post( wc_price( $price ) ); ?> <?php esc_html_e( 'per participant', 'york-scouts-events' ); ?></span>
	</p>

	<?php if ( Meta::TYPE_GENERAL === $event_type && null !== $remaining ) : ?>
		<p class="ys-remaining <?php echo $remaining < 1 ? 'ys-full' : ''; ?>" data-ys-remaining aria-live="polite"><?php echo $remaining > 0 ? esc_html( sprintf( _n( '%d place remaining', '%d places remaining', $remaining, 'york-scouts-events' ), $remaining ) ) : esc_html__( 'Sold out', 'york-scouts-events' ); ?></p>
	<?php endif; ?>

	<form method="post" class="ys-booking-form" data-ys-booking-form data-event-id="<?php echo esc_attr( $event_id ); ?>" data-event-type="<?php echo esc_attr( $event_type ); ?>" data-availability-url="<?php echo esc_url( $availability_url ); ?>" data-participant-fields="<?php echo esc_attr( wp_json_encode( $participant_fields ) ); ?>" data-participant-custom-fields="<?php echo esc_attr( wp_json_encode( $custom_participant_fields ) ); ?>" data-has-parent-contact="<?php echo esc_attr( $has_parent_contact ? '1' : '0' ); ?>"<?php echo $is_general_sold_out ? ' aria-disabled="true"' : ''; ?>>
		<?php wp_nonce_field( 'ys_book_event_' . $event_id, 'ys_booking_nonce' ); ?>
		<input type="hidden" name="ys_event_id" value="<?php echo esc_attr( $event_id ); ?>">
		<input type="hidden" name="ys_action" value="book_event">

		<div class="ys-booking-grid">
			<?php if ( Meta::TYPE_TIMED === $event_type ) : ?>
				<div class="ys-booking-field">
					<label for="<?php echo esc_attr( $slot_field_id ); ?>"><?php esc_html_e( 'Time slot', 'york-scouts-events' ); ?></label>
					<select name="ys_slot" id="<?php echo esc_attr( $slot_field_id ); ?>" data-ys-slot-select required>
						<option value=""><?php esc_html_e( 'Choose a time', 'york-scouts-events' ); ?></option>
						<?php foreach ( $slots as $slot ) : ?>
							<?php $left = $this->remaining_for_request( $event_id, $slot['key'] ); ?>
							<option value="<?php echo esc_attr( $slot['key'] ); ?>" data-ys-slot-option data-ys-slot-key="<?php echo esc_attr( $slot['key'] ); ?>" data-ys-slot-label="<?php echo esc_attr( $slot['label'] ); ?>" data-ys-remaining="<?php echo esc_attr( $left ); ?>" <?php disabled( $left < 1 ); ?>>
								<?php echo esc_html( $slot['label'] . ' - ' . ( $left > 0 ? sprintf( _n( '%d place left', '%d places left', $left, 'york-scouts-events' ), $left ) : __( 'Full', 'york-scouts-events' ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<div class="ys-booking-field">
				<label for="<?php echo esc_attr( $quantity_field_id ); ?>"><?php esc_html_e( 'Number of participants', 'york-scouts-events' ); ?></label>
				<input type="number" id="<?php echo esc_attr( $quantity_field_id ); ?>" name="ys_quantity" data-ys-quantity min="1" max="<?php echo esc_attr( $quantity_max ); ?>" value="1" required<?php echo $is_general_sold_out ? ' data-ys-disabled-by-availability="1"' : ''; ?><?php disabled( $is_general_sold_out ); ?>>
			</div>

			<?php if ( Fields::is_enabled( $event_id, 'contact_name' ) ) : ?>
				<div class="ys-booking-field"><label for="ys_contact_name"><?php esc_html_e( 'Booking contact name', 'york-scouts-events' ); ?></label><input id="ys_contact_name" name="ys_contact_name" required></div>
			<?php endif; ?>
			<?php if ( Fields::is_enabled( $event_id, 'contact_email' ) ) : ?>
				<div class="ys-booking-field"><label for="ys_contact_email"><?php esc_html_e( 'Email address', 'york-scouts-events' ); ?></label><input id="ys_contact_email" name="ys_contact_email" type="email" required></div>
			<?php endif; ?>
			<?php if ( Fields::is_enabled( $event_id, 'contact_phone' ) ) : ?>
				<div class="ys-booking-field"><label for="ys_contact_phone"><?php esc_html_e( 'Phone number', 'york-scouts-events' ); ?></label><input id="ys_contact_phone" name="ys_contact_phone" type="tel" required></div>
			<?php endif; ?>
			<?php if ( Fields::is_enabled( $event_id, 'scout_group' ) ) : ?>
				<div class="ys-booking-field"><label for="ys_scout_group"><?php esc_html_e( 'Scout Group', 'york-scouts-events' ); ?></label><input id="ys_scout_group" name="ys_scout_group" required></div>
			<?php endif; ?>
			<?php foreach ( $custom_booking_fields as $custom_field ) : ?>
				<?php
				$field_key  = sanitize_key( $custom_field['key'] ?? '' );
				$field_type = sanitize_key( $custom_field['type'] ?? 'text' );
				$required   = ! empty( $custom_field['required'] ) ? ' required' : '';

				if ( ! $field_key ) {
					continue;
				}
				?>
				<div class="ys-booking-field">
					<?php if ( 'checkbox' === $field_type ) : ?>
						<label><input type="checkbox" name="ys_custom_booking[<?php echo esc_attr( $field_key ); ?>]" value="1"<?php echo esc_attr( $required ); ?>> <?php echo esc_html( $custom_field['label'] ); ?></label>
					<?php else : ?>
						<label for="ys_custom_booking_<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $custom_field['label'] ); ?></label>
						<?php if ( 'textarea' === $field_type ) : ?>
							<textarea id="ys_custom_booking_<?php echo esc_attr( $field_key ); ?>" name="ys_custom_booking[<?php echo esc_attr( $field_key ); ?>]" rows="3"<?php echo esc_attr( $required ); ?>></textarea>
						<?php else : ?>
							<?php $input_type = in_array( $field_type, array( 'email', 'tel', 'number', 'date' ), true ) ? $field_type : 'text'; ?>
							<input id="ys_custom_booking_<?php echo esc_attr( $field_key ); ?>" name="ys_custom_booking[<?php echo esc_attr( $field_key ); ?>]" type="<?php echo esc_attr( $input_type ); ?>"<?php echo 'number' === $input_type ? ' step="any"' : ''; ?><?php echo esc_attr( $required ); ?>>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="ys-participants" data-participants></div>

		<?php if ( Fields::is_enabled( $event_id, 'consent' ) ) : ?>
			<p class="ys-consent"><label><input type="checkbox" name="ys_consent" value="1" required> <?php esc_html_e( 'I confirm that the information supplied is accurate and I consent to it being used to administer this booking.', 'york-scouts-events' ); ?></label></p>
		<?php endif; ?>

		<div class="ys-booking-actions">
			<button type="submit" data-ys-submit<?php echo $is_general_sold_out ? ' data-ys-disabled-by-availability="1"' : ''; ?><?php disabled( $is_general_sold_out ); ?>><?php esc_html_e( 'Continue to checkout', 'york-scouts-events' ); ?></button>
		</div>
	</form>
</div>
