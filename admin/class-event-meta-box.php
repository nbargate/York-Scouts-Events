<?php
/**
 * Events Calendar event editor integration.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents\Admin;

use YorkScoutsEvents\Capabilities;
use YorkScoutsEvents\Email_Export_Service;
use YorkScoutsEvents\Fields;
use YorkScoutsEvents\Meta;
use YorkScoutsEvents\Participant_Report_Service;
use YorkScoutsEvents\Product_Manager;
use YorkScoutsEvents\Slots;
use YorkScoutsEvents\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds booking settings and sales counters to tribe_events.
 */
final class Event_Meta_Box {
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
	 * Register meta boxes on The Events Calendar events only.
	 */
	public function add_meta_boxes() {
		if ( current_user_can( Capabilities::MANAGE_BOOKING_SETTINGS ) ) {
			add_meta_box(
				'ys_event_settings',
				__( 'York Scouts Ticket Sales', 'york-scouts-events' ),
				array( $this, 'render_settings' ),
				'tribe_events',
				'normal',
				'high'
			);
		}

		add_meta_box(
			'ys_event_sales',
			__( 'Ticket Sales', 'york-scouts-events' ),
			array( $this, 'render_sales' ),
			'tribe_events',
			'side',
			'high'
		);
	}

	/**
	 * Add sales columns to the Events Calendar admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function event_admin_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['ys_tickets_sold'] = __( 'Tickets sold', 'york-scouts-events' );
				$new['ys_remaining']    = __( 'Places remaining', 'york-scouts-events' );
			}
		}

		return $new;
	}

	/**
	 * Render sales columns using stored counters.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_event_admin_column( $column, $post_id ) {
		if ( ! in_array( $column, array( 'ys_tickets_sold', 'ys_remaining' ), true ) ) {
			return;
		}

		if ( ! Meta::bookings_enabled( $post_id ) ) {
			echo '&mdash;';
			return;
		}

		$stats = $this->stats->get_event_stats( $post_id );

		if ( 'ys_tickets_sold' === $column ) {
			echo '<strong>' . esc_html( $stats['sold'] ) . '</strong>';

			if ( absint( $stats['reserved'] ) > absint( $stats['sold'] ) ) {
				echo '<br><span class="ys-muted">' . esc_html( absint( $stats['reserved'] ) - absint( $stats['sold'] ) ) . ' ' . esc_html__( 'awaiting payment', 'york-scouts-events' ) . '</span>';
			}
			return;
		}

		echo '<strong>' . esc_html( $stats['remaining'] ) . '</strong>';
		echo '<br><span class="ys-muted">' . esc_html__( 'of', 'york-scouts-events' ) . ' ' . esc_html( $stats['capacity'] ) . '</span>';
	}

	/**
	 * Render booking settings.
	 *
	 * @param WP_Post $post Event post.
	 */
	public function render_settings( $post ) {
		if ( ! Capabilities::can_manage_booking_settings( $post->ID ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage York Scouts ticket sales for this event.', 'york-scouts-events' ) . '</p>';
			return;
		}

		wp_nonce_field( 'ys_save_event', 'ys_event_nonce' );

		$enabled        = Meta::bookings_enabled( $post->ID );
		$type           = Meta::event_type( $post->ID );
		$price          = get_post_meta( $post->ID, Meta::PRICE, true );
		$capacity       = get_post_meta( $post->ID, Meta::CAPACITY, true );
		$start          = get_post_meta( $post->ID, Meta::SLOT_START, true ) ?: '09:00';
		$end            = get_post_meta( $post->ID, Meta::SLOT_END, true ) ?: '17:00';
		$length         = get_post_meta( $post->ID, Meta::SLOT_LENGTH, true ) ?: '30';
		$slot_capacity  = get_post_meta( $post->ID, Meta::SLOT_CAPACITY, true ) ?: '10';
		$slot_mode      = get_post_meta( $post->ID, Meta::SLOT_MODE, true ) ?: Meta::SLOT_MODE_AUTO;
		$slot_mode      = Meta::SLOT_MODE_CUSTOM === $slot_mode ? Meta::SLOT_MODE_CUSTOM : Meta::SLOT_MODE_AUTO;
		$custom_slots   = Slots::custom_slots( $post->ID );
		$custom_rows    = $custom_slots ? $custom_slots : Slots::generate( $post->ID );
		$close_at       = get_post_meta( $post->ID, Meta::BOOKING_CLOSE_AT, true );
		$close_value    = $close_at ? str_replace( ' ', 'T', substr( $close_at, 0, 16 ) ) : '';
		$report_emails  = get_post_meta( $post->ID, Meta::REPORT_EMAILS, true );
		$enabled_fields = Fields::enabled_for_event( $post->ID );
		$field_defs     = Fields::definitions();
		$custom_fields  = Fields::custom_definitions( $post->ID );
		$custom_types   = Fields::custom_types();
		$custom_scopes  = Fields::custom_scopes();
		?>
		<p class="ys-admin-notice">
			<label>
				<input type="checkbox" name="ys_bookings_enabled" value="1" <?php checked( $enabled ); ?>>
				<strong><?php esc_html_e( 'Enable York Scouts ticket sales for this event', 'york-scouts-events' ); ?></strong>
			</label>
		</p>

		<div id="ys-booking-settings-wrap" class="ys-settings-wrap">
			<input type="hidden" name="ys_information_fields_present" value="1">
			<div class="ys-admin-grid">
				<div class="ys-admin-field">
					<label for="ys_event_type"><?php esc_html_e( 'Event type', 'york-scouts-events' ); ?></label>
					<select id="ys_event_type" name="ys_event_type">
						<option value="general" <?php selected( $type, Meta::TYPE_GENERAL ); ?>><?php esc_html_e( 'Capacity only', 'york-scouts-events' ); ?></option>
						<option value="timed" <?php selected( $type, Meta::TYPE_TIMED ); ?>><?php esc_html_e( 'Timed slots', 'york-scouts-events' ); ?></option>
					</select>
				</div>
				<div class="ys-admin-field">
					<label for="ys_event_price"><?php esc_html_e( 'Ticket price', 'york-scouts-events' ); ?></label>
					<input id="ys_event_price" name="ys_event_price" type="number" min="0" step="0.01" value="<?php echo esc_attr( $price ); ?>">
				</div>
				<div class="ys-admin-field ys-general-field">
					<label for="ys_event_capacity"><?php esc_html_e( 'Capacity', 'york-scouts-events' ); ?></label>
					<input id="ys_event_capacity" name="ys_event_capacity" type="number" min="1" step="1" value="<?php echo esc_attr( $capacity ); ?>">
				</div>
			</div>

			<div class="ys-admin-panel" id="ys-timed-fields">
				<div class="ys-admin-grid ys-slot-mode-grid">
					<div class="ys-admin-field">
						<label for="ys_slot_mode"><?php esc_html_e( 'Slot timings', 'york-scouts-events' ); ?></label>
						<select id="ys_slot_mode" name="ys_slot_mode">
							<option value="auto" <?php selected( $slot_mode, Meta::SLOT_MODE_AUTO ); ?>><?php esc_html_e( 'Automatic evenly spaced slots', 'york-scouts-events' ); ?></option>
							<option value="custom" <?php selected( $slot_mode, Meta::SLOT_MODE_CUSTOM ); ?>><?php esc_html_e( 'Custom slots', 'york-scouts-events' ); ?></option>
						</select>
					</div>
					<div class="ys-admin-field">
						<label for="ys_slot_capacity"><?php esc_html_e( 'Default people per slot', 'york-scouts-events' ); ?></label>
						<input id="ys_slot_capacity" name="ys_slot_capacity" type="number" min="1" step="1" value="<?php echo esc_attr( $slot_capacity ); ?>">
					</div>
				</div>

				<div id="ys-auto-slot-fields" class="ys-slot-mode-panel">
					<div class="ys-admin-grid">
						<div class="ys-admin-field">
							<label for="ys_slot_start"><?php esc_html_e( 'First slot starts', 'york-scouts-events' ); ?></label>
							<input id="ys_slot_start" name="ys_slot_start" type="time" value="<?php echo esc_attr( $start ); ?>">
						</div>
						<div class="ys-admin-field">
							<label for="ys_slot_end"><?php esc_html_e( 'Final slot ends', 'york-scouts-events' ); ?></label>
							<input id="ys_slot_end" name="ys_slot_end" type="time" value="<?php echo esc_attr( $end ); ?>">
						</div>
						<div class="ys-admin-field">
							<label for="ys_slot_length"><?php esc_html_e( 'Slot length in minutes', 'york-scouts-events' ); ?></label>
							<input id="ys_slot_length" name="ys_slot_length" type="number" min="5" step="5" value="<?php echo esc_attr( $length ); ?>">
						</div>
					</div>
				</div>

				<div id="ys-custom-slot-fields" class="ys-slot-mode-panel">
					<input type="hidden" name="ys_custom_slots_present" value="1">
					<table class="widefat striped ys-custom-slots-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Start', 'york-scouts-events' ); ?></th>
								<th><?php esc_html_e( 'End', 'york-scouts-events' ); ?></th>
								<th><?php esc_html_e( 'Capacity', 'york-scouts-events' ); ?></th>
								<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'york-scouts-events' ); ?></span></th>
							</tr>
						</thead>
						<tbody id="ys-custom-slots-body">
							<?php if ( $custom_rows ) : ?>
								<?php foreach ( $custom_rows as $slot ) : ?>
									<tr>
										<td><input type="time" name="ys_custom_slot_start[]" value="<?php echo esc_attr( $slot['start'] ?? '' ); ?>"></td>
										<td><input type="time" name="ys_custom_slot_end[]" value="<?php echo esc_attr( $slot['end'] ?? '' ); ?>"></td>
										<td><input type="number" min="1" step="1" name="ys_custom_slot_capacity[]" value="<?php echo esc_attr( $slot['capacity'] ?? $slot_capacity ); ?>"></td>
										<td><button type="button" class="button-link-delete ys-remove-slot"><?php esc_html_e( 'Remove', 'york-scouts-events' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td><input type="time" name="ys_custom_slot_start[]" value=""></td>
									<td><input type="time" name="ys_custom_slot_end[]" value=""></td>
									<td><input type="number" min="1" step="1" name="ys_custom_slot_capacity[]" value="<?php echo esc_attr( $slot_capacity ); ?>"></td>
									<td><button type="button" class="button-link-delete ys-remove-slot"><?php esc_html_e( 'Remove', 'york-scouts-events' ); ?></button></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
					<p><button type="button" class="button" id="ys-add-custom-slot"><?php esc_html_e( 'Add slot', 'york-scouts-events' ); ?></button></p>
				</div>
			</div>

			<div class="ys-admin-panel">
				<h3><?php esc_html_e( 'Participant report', 'york-scouts-events' ); ?></h3>
				<div class="ys-admin-grid">
					<div class="ys-admin-field">
						<label for="ys_booking_close_at"><?php esc_html_e( 'Bookings close', 'york-scouts-events' ); ?></label>
						<input id="ys_booking_close_at" name="ys_booking_close_at" type="datetime-local" value="<?php echo esc_attr( $close_value ); ?>">
					</div>
					<div class="ys-admin-field">
						<label for="ys_report_recipient_emails"><?php esc_html_e( 'Organiser report email recipients', 'york-scouts-events' ); ?></label>
						<input id="ys_report_recipient_emails" name="ys_report_recipient_emails" type="text" value="<?php echo esc_attr( $report_emails ); ?>">
					</div>
				</div>
				<?php if ( 'auto-draft' !== $post->post_status && Capabilities::can_download_reports() ) : ?>
					<p><a class="button" href="<?php echo esc_url( Participant_Report_Service::download_url( $post->ID ) ); ?>"><?php esc_html_e( 'Download participant PDF', 'york-scouts-events' ); ?></a></p>
				<?php endif; ?>
				<?php if ( 'auto-draft' !== $post->post_status && Capabilities::can_export_emails() ) : ?>
					<p><a class="button" href="<?php echo esc_url( Email_Export_Service::download_url( $post->ID ) ); ?>"><?php esc_html_e( 'Download email list', 'york-scouts-events' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="ys-admin-panel">
				<h3><?php esc_html_e( 'Information fields', 'york-scouts-events' ); ?></h3>
				<div class="ys-admin-grid">
					<div>
						<strong><?php esc_html_e( 'Booking details', 'york-scouts-events' ); ?></strong>
						<?php foreach ( $field_defs as $key => $definition ) : ?>
							<?php if ( 'booking' !== $definition['scope'] ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<p>
								<label>
									<input type="checkbox" name="ys_information_fields[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $enabled_fields, true ) ); ?>>
									<?php echo esc_html( $definition['label'] ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					</div>
					<div>
						<strong><?php esc_html_e( 'Per participant', 'york-scouts-events' ); ?></strong>
						<?php foreach ( $field_defs as $key => $definition ) : ?>
							<?php if ( 'participant' !== $definition['scope'] ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<p>
								<label>
									<input type="checkbox" name="ys_information_fields[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $enabled_fields, true ) ); ?>>
									<?php echo esc_html( $definition['label'] ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					</div>
				</div>
				<h4><?php esc_html_e( 'Custom fields', 'york-scouts-events' ); ?></h4>
				<input type="hidden" name="ys_custom_fields_present" value="1">
				<table class="widefat striped ys-custom-fields-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Question', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Applies to', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Answer type', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Required', 'york-scouts-events' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'york-scouts-events' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="ys-custom-fields-body">
						<?php foreach ( $custom_fields as $field ) : ?>
							<tr>
								<td>
									<input type="hidden" name="ys_custom_field_key[]" value="<?php echo esc_attr( $field['key'] ); ?>">
									<input type="text" name="ys_custom_field_label[]" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Swimming ability', 'york-scouts-events' ); ?>">
								</td>
								<td>
									<select name="ys_custom_field_scope[]">
										<?php foreach ( $custom_scopes as $scope_key => $scope_label ) : ?>
											<option value="<?php echo esc_attr( $scope_key ); ?>" <?php selected( $field['scope'], $scope_key ); ?>><?php echo esc_html( $scope_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="ys_custom_field_type[]">
										<?php foreach ( $custom_types as $type_key => $type_label ) : ?>
											<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $field['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="ys_custom_field_required[]">
										<option value="0" <?php selected( empty( $field['required'] ) ); ?>><?php esc_html_e( 'Optional', 'york-scouts-events' ); ?></option>
										<option value="1" <?php selected( ! empty( $field['required'] ) ); ?>><?php esc_html_e( 'Required', 'york-scouts-events' ); ?></option>
									</select>
								</td>
								<td><button type="button" class="button-link-delete ys-remove-custom-field"><?php esc_html_e( 'Remove', 'york-scouts-events' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="ys-add-custom-field"><?php esc_html_e( 'Add custom field', 'york-scouts-events' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render stored sales counters.
	 *
	 * @param WP_Post $post Event post.
	 */
	public function render_sales( $post ) {
		if ( ! Meta::bookings_enabled( $post->ID ) ) {
			echo '<p>' . esc_html__( 'Ticket sales are not enabled for this event.', 'york-scouts-events' ) . '</p>';
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the event to begin tracking ticket sales.', 'york-scouts-events' ) . '</p>';
			return;
		}

		$stats = $this->stats->get_event_stats( $post->ID );
		?>
		<p class="ys-sales-big"><strong><?php echo esc_html( $stats['sold'] ); ?></strong> <span><?php esc_html_e( 'tickets sold', 'york-scouts-events' ); ?></span></p>
		<p><strong><?php echo esc_html( $stats['remaining'] ); ?></strong> <?php esc_html_e( 'places remaining of', 'york-scouts-events' ); ?> <?php echo esc_html( $stats['capacity'] ); ?>.</p>
		<?php if ( absint( $stats['reserved'] ) > absint( $stats['sold'] ) ) : ?>
			<p><strong><?php echo esc_html( absint( $stats['reserved'] ) - absint( $stats['sold'] ) ); ?></strong> <?php esc_html_e( 'additional places are pending or on hold.', 'york-scouts-events' ); ?></p>
		<?php endif; ?>

		<?php if ( Meta::TYPE_TIMED === Meta::event_type( $post->ID ) ) : ?>
			<?php $slot_stats = $this->stats->get_slot_stats( $post->ID ); ?>
			<?php if ( $slot_stats ) : ?>
				<hr>
				<p><strong><?php esc_html_e( 'By time slot', 'york-scouts-events' ); ?></strong></p>
				<table class="ys-slot-sales">
					<?php foreach ( $slot_stats as $slot ) : ?>
						<tr>
							<td><?php echo esc_html( $slot['label'] ); ?></td>
							<td><strong><?php echo esc_html( $slot['sold'] ); ?></strong> <?php esc_html_e( 'sold', 'york-scouts-events' ); ?> / <?php echo esc_html( $slot['remaining'] ); ?> <?php esc_html_e( 'left', 'york-scouts-events' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		$url = Bookings_Page::url(
			array(
				'ys_event_filter' => $post->ID,
			)
		);
		if ( Capabilities::can_view_bookings() ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'View bookings', 'york-scouts-events' ) . '</a></p>';
		}

		if ( Capabilities::can_download_reports() ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( Participant_Report_Service::download_url( $post->ID ) ) . '">' . esc_html__( 'Download participant PDF', 'york-scouts-events' ) . '</a></p>';
		}

		if ( Capabilities::can_export_emails() ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( Email_Export_Service::download_url( $post->ID ) ) . '">' . esc_html__( 'Download email list', 'york-scouts-events' ) . '</a></p>';
		}
	}

	/**
	 * Save booking settings against the tribe_events post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['ys_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ys_event_nonce'] ) ), 'ys_save_event' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! Capabilities::can_manage_booking_settings( $post_id ) ) {
			return;
		}

		$booking_enabled = ! empty( $_POST['ys_bookings_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, Meta::ENABLED, $booking_enabled );

		if ( isset( $_POST['ys_event_type'] ) ) {
			$type = sanitize_key( wp_unslash( $_POST['ys_event_type'] ) );
			$type = Meta::TYPE_TIMED === $type ? Meta::TYPE_TIMED : Meta::TYPE_GENERAL;
			update_post_meta( $post_id, Meta::TYPE, $type );
		}

		$this->save_scalar_meta( $post_id, Meta::PRICE, 'ys_event_price', 'wc_format_decimal' );
		$this->save_scalar_meta( $post_id, Meta::CAPACITY, 'ys_event_capacity', 'absint' );
		$this->save_scalar_meta( $post_id, Meta::SLOT_START, 'ys_slot_start', 'sanitize_text_field' );
		$this->save_scalar_meta( $post_id, Meta::SLOT_END, 'ys_slot_end', 'sanitize_text_field' );
		$this->save_scalar_meta( $post_id, Meta::SLOT_LENGTH, 'ys_slot_length', 'absint' );
		$this->save_scalar_meta( $post_id, Meta::SLOT_CAPACITY, 'ys_slot_capacity', 'absint' );
		$this->save_datetime_meta( $post_id, Meta::BOOKING_CLOSE_AT, 'ys_booking_close_at' );
		$this->save_email_list_meta( $post_id, Meta::REPORT_EMAILS, 'ys_report_recipient_emails' );

		if ( isset( $_POST['ys_slot_mode'] ) ) {
			$slot_mode = sanitize_key( wp_unslash( $_POST['ys_slot_mode'] ) );
			$slot_mode = Meta::SLOT_MODE_CUSTOM === $slot_mode ? Meta::SLOT_MODE_CUSTOM : Meta::SLOT_MODE_AUTO;
			update_post_meta( $post_id, Meta::SLOT_MODE, $slot_mode );
		}

		if ( isset( $_POST['ys_custom_slots_present'] ) ) {
			$default_capacity = absint( get_post_meta( $post_id, Meta::SLOT_CAPACITY, true ) );
			update_post_meta( $post_id, Meta::CUSTOM_SLOTS, Slots::sanitize_custom_slots( $this->posted_custom_slots(), $default_capacity ) );
		}

		if ( isset( $_POST['ys_information_fields_present'] ) ) {
			$selected_fields = isset( $_POST['ys_information_fields'] ) ? (array) wp_unslash( $_POST['ys_information_fields'] ) : array();
			update_post_meta( $post_id, Meta::INFORMATION, Fields::sanitize_selection( $selected_fields ) );
		}

		if ( isset( $_POST['ys_custom_fields_present'] ) ) {
			update_post_meta( $post_id, Meta::CUSTOM_INFORMATION, Fields::sanitize_custom_fields( $this->posted_custom_fields() ) );
		}

		if ( 'yes' === $booking_enabled ) {
			$this->products->sync_for_event( $post_id );
		}

		$this->stats->rebuild_event( $post_id );
	}

	/**
	 * Save one scalar meta value if present.
	 *
	 * @param int      $post_id Post ID.
	 * @param string   $meta_key Meta key.
	 * @param string   $field Request key.
	 * @param callable $sanitizer Sanitizer callback.
	 */
	private function save_scalar_meta( $post_id, $meta_key, $field, $sanitizer ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		update_post_meta( $post_id, $meta_key, call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) ) );
	}

	/**
	 * Save a datetime-local field as a local MySQL datetime.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $field Request key.
	 */
	private function save_datetime_meta( $post_id, $meta_key, $field ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		if ( preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value ) ) {
			update_post_meta( $post_id, $meta_key, str_replace( 'T', ' ', $value ) . ':00' );
		}
	}

	/**
	 * Save a comma-separated email list.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $field Request key.
	 */
	private function save_email_list_meta( $post_id, $meta_key, $field ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		$raw    = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' );

		if ( $emails ) {
			update_post_meta( $post_id, $meta_key, implode( ', ', array_values( array_unique( $emails ) ) ) );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	/**
	 * Build raw custom slot rows from submitted arrays.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function posted_custom_slots() {
		$starts     = isset( $_POST['ys_custom_slot_start'] ) ? (array) wp_unslash( $_POST['ys_custom_slot_start'] ) : array();
		$ends       = isset( $_POST['ys_custom_slot_end'] ) ? (array) wp_unslash( $_POST['ys_custom_slot_end'] ) : array();
		$capacities = isset( $_POST['ys_custom_slot_capacity'] ) ? (array) wp_unslash( $_POST['ys_custom_slot_capacity'] ) : array();
		$rows       = array();
		$count      = max( count( $starts ), count( $ends ), count( $capacities ) );

		for ( $index = 0; $index < $count; $index++ ) {
			$rows[] = array(
				'start'    => sanitize_text_field( $starts[ $index ] ?? '' ),
				'end'      => sanitize_text_field( $ends[ $index ] ?? '' ),
				'capacity' => absint( $capacities[ $index ] ?? 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Build raw custom information field rows from submitted arrays.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function posted_custom_fields() {
		$keys      = isset( $_POST['ys_custom_field_key'] ) ? (array) wp_unslash( $_POST['ys_custom_field_key'] ) : array();
		$labels    = isset( $_POST['ys_custom_field_label'] ) ? (array) wp_unslash( $_POST['ys_custom_field_label'] ) : array();
		$scopes    = isset( $_POST['ys_custom_field_scope'] ) ? (array) wp_unslash( $_POST['ys_custom_field_scope'] ) : array();
		$types     = isset( $_POST['ys_custom_field_type'] ) ? (array) wp_unslash( $_POST['ys_custom_field_type'] ) : array();
		$required  = isset( $_POST['ys_custom_field_required'] ) ? (array) wp_unslash( $_POST['ys_custom_field_required'] ) : array();
		$rows      = array();
		$count     = max( count( $keys ), count( $labels ), count( $scopes ), count( $types ), count( $required ) );

		for ( $index = 0; $index < $count; $index++ ) {
			$rows[] = array(
				'key'      => sanitize_key( $keys[ $index ] ?? '' ),
				'label'    => sanitize_text_field( $labels[ $index ] ?? '' ),
				'scope'    => sanitize_key( $scopes[ $index ] ?? 'booking' ),
				'type'     => sanitize_key( $types[ $index ] ?? 'text' ),
				'required' => ! empty( $required[ $index ] ) ? 1 : 0,
			);
		}

		return $rows;
	}
}
