<?php
/**
 * Booking overview admin page.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents\Admin;

use YorkScoutsEvents\Booking_Repository;
use YorkScoutsEvents\Capabilities;
use YorkScoutsEvents\Email_Export_Service;
use YorkScoutsEvents\Fields;
use YorkScoutsEvents\Meta;
use YorkScoutsEvents\Participant_Report_Service;
use YorkScoutsEvents\Slots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a paginated bookings page backed by wp_ys_bookings.
 */
final class Bookings_Page {
	/**
	 * Booking repository.
	 *
	 * @var Booking_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Booking_Repository $repository Booking repository.
	 */
	public function __construct( Booking_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Add top-level Bookings admin page.
	 */
	public function register_page() {
		if ( ! Capabilities::can_view_bookings() ) {
			return;
		}

		add_menu_page(
			__( 'Bookings', 'york-scouts-events' ),
			__( 'Bookings', 'york-scouts-events' ),
			Capabilities::bookings_menu_capability(),
			'ys-event-bookings',
			array( $this, 'render' ),
			'dashicons-tickets-alt',
			56
		);
	}

	/**
	 * Build a URL for the top-level bookings page.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'ys-event-bookings',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render list or detail view.
	 */
	public function render() {
		if ( ! Capabilities::can_view_bookings() ) {
			wp_die( esc_html__( 'You do not have permission to view booking records.', 'york-scouts-events' ) );
		}

		$booking_id = absint( $_GET['ys_booking'] ?? 0 );

		if ( $booking_id ) {
			$this->render_detail( $booking_id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render paginated bookings table.
	 */
	private function render_list() {
		$filters  = $this->current_filters();
		$events   = $this->events_for_filter( $filters['event_id'] );
		$per_page = 20;
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		?>
			<div class="wrap ys-bookings-page">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'york-scouts-events' ); ?></h1>

				<?php $this->render_cleanup_notice(); ?>
				<?php $this->render_report_email_notice(); ?>
				<?php $this->render_event_picker( $events, $filters['event_id'] ); ?>
				<?php $this->render_orphaned_cleanup_panel(); ?>

			<?php if ( ! $filters['event_id'] ) : ?>
				<div class="ys-bookings-empty-state">
					<h2><?php esc_html_e( 'Select an event to view bookings', 'york-scouts-events' ); ?></h2>
					<p><?php esc_html_e( 'Bookings are shown one event at a time so participant lists stay clear and easy to manage.', 'york-scouts-events' ); ?></p>
				</div>
			</div>
				<?php
				return;
			endif;

		$result   = $this->repository->query(
			array(
				'event_id'  => $filters['event_id'],
				'status'    => $filters['status'],
				'date_from' => $filters['date_from'],
				'date_to'   => $filters['date_to'],
				'search'    => $filters['search'],
				'per_page'  => $per_page,
				'paged'     => $paged,
			)
		);
		$total    = absint( $result['total'] );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		?>
			<h2 class="ys-selected-event-title"><?php echo esc_html( get_the_title( $filters['event_id'] ) ?: sprintf( __( 'Event #%d', 'york-scouts-events' ), $filters['event_id'] ) ); ?></h2>

			<form method="get" class="ys-bookings-filters ys-bookings-secondary-filters">
				<input type="hidden" name="page" value="ys-event-bookings">
				<input type="hidden" name="ys_event_filter" value="<?php echo esc_attr( $filters['event_id'] ); ?>">

				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Filter by booking status', 'york-scouts-events' ); ?></span>
					<select name="ys_status_filter">
						<option value=""><?php esc_html_e( 'All statuses', 'york-scouts-events' ); ?></option>
						<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
							<?php $status = str_replace( 'wc-', '', $key ); ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Booked from', 'york-scouts-events' ); ?></span>
					<input type="date" name="ys_date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
				</label>

				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Booked to', 'york-scouts-events' ); ?></span>
					<input type="date" name="ys_date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
				</label>

				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Search bookings', 'york-scouts-events' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search bookings', 'york-scouts-events' ); ?>">
				</label>

				<button class="button"><?php esc_html_e( 'Filter', 'york-scouts-events' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url( array( 'ys_event_filter' => $filters['event_id'] ) ) ); ?>"><?php esc_html_e( 'Clear filters', 'york-scouts-events' ); ?></a>
			</form>

			<div class="tablenav top">
				<div class="tablenav-pages">
					<span class="displaying-num"><?php echo esc_html( number_format_i18n( $total ) ); ?> <?php esc_html_e( 'items', 'york-scouts-events' ); ?></span>
					<?php echo wp_kses_post( $this->pagination_links( $paged, $pages ) ); ?>
				</div>
			</div>

			<div class="ys-table-scroll">
				<table class="widefat striped ys-bookings-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Booking', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Slot', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Contact', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Scout Group', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Participants', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Status', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Total', 'york-scouts-events' ); ?></th>
							<th><?php esc_html_e( 'Booked', 'york-scouts-events' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'york-scouts-events' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $result['items'] ) ) : ?>
							<tr><td colspan="9"><?php esc_html_e( 'No bookings found for this event.', 'york-scouts-events' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $result['items'] as $row ) : ?>
								<?php $this->render_row( $row, false ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one booking row.
	 *
	 * @param array $row Booking row.
	 * @param bool  $show_event Whether to render the event column.
	 */
	private function render_row( array $row, $show_event = true ) {
		$order      = wc_get_order( absint( $row['order_id'] ) );
		$event_id   = absint( $row['event_id'] );
		$status     = sanitize_key( $row['status'] );
		$detail_url = add_query_arg(
			array(
				'ys_event_filter' => $event_id,
				'ys_booking'      => absint( $row['id'] ),
			),
			self::url()
		);
		$email      = $row['contact_email'];
		$name       = $row['contact_name'];

		if ( $order ) {
			$email = $email ?: $order->get_billing_email();
			$name  = $name ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		?>
		<tr>
			<td><strong>#<?php echo esc_html( $row['id'] ); ?></strong><br><span class="ys-muted"><?php esc_html_e( 'Order', 'york-scouts-events' ); ?> #<?php echo esc_html( $row['order_id'] ); ?></span></td>
			<?php if ( $show_event ) : ?>
				<td><strong><?php echo esc_html( get_the_title( $event_id ) ?: sprintf( __( 'Event #%d', 'york-scouts-events' ), $event_id ) ); ?></strong></td>
			<?php endif; ?>
			<td><?php echo '' !== $row['slot_id'] ? esc_html( Slots::label( $event_id, $row['slot_id'] ) ) : '&mdash;'; ?></td>
			<td>
				<?php echo esc_html( $name ?: __( 'Not recorded', 'york-scouts-events' ) ); ?>
				<?php if ( $email ) : ?>
					<br><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
				<?php endif; ?>
			</td>
			<td><?php echo $row['scout_group'] ? esc_html( $row['scout_group'] ) : '&mdash;'; ?></td>
			<td><?php echo esc_html( $row['participant_count'] ); ?></td>
			<td><mark class="order-status status-<?php echo esc_attr( $status ); ?>"><span><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></span></mark></td>
			<td><?php echo $order ? wp_kses_post( $order->get_formatted_order_total() ) : '&mdash;'; ?></td>
			<td><?php echo esc_html( mysql2date( 'j M Y H:i', $row['created_at'] ) ); ?></td>
			<td><a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View', 'york-scouts-events' ); ?></a></td>
		</tr>
		<?php
	}

	/**
	 * Render booking detail view.
	 *
	 * @param int $booking_id Booking ID.
	 */
	private function render_detail( $booking_id ) {
		$row = $this->repository->get( $booking_id );

		if ( ! $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Booking not found', 'york-scouts-events' ) . '</h1></div>';
			return;
		}

		$order      = wc_get_order( absint( $row['order_id'] ) );
		$booking    = json_decode( (string) $row['booking_data'], true );
		$booking    = is_array( $booking ) ? $booking : array();
		$event_id   = absint( $row['event_id'] );
		$back       = add_query_arg(
			array(
				'ys_event_filter' => $event_id,
			),
			self::url()
		);
		$order_url  = $order && method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '';
		$event_date = Meta::event_date( $event_id );
		?>
		<div class="wrap ys-booking-detail">
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to bookings', 'york-scouts-events' ); ?></a></p>
			<h1><?php echo esc_html( sprintf( __( 'Booking #%d', 'york-scouts-events' ), $booking_id ) ); ?></h1>

			<div class="ys-detail-grid">
				<div>
					<div class="postbox"><div class="inside">
						<h2><?php echo esc_html( get_the_title( $event_id ) ?: sprintf( __( 'Event #%d', 'york-scouts-events' ), $event_id ) ); ?></h2>
						<table class="widefat striped"><tbody>
							<tr><th><?php esc_html_e( 'Event date', 'york-scouts-events' ); ?></th><td><?php echo $event_date ? esc_html( date_i18n( 'j F Y', strtotime( $event_date ) ) ) : '&mdash;'; ?></td></tr>
							<tr><th><?php esc_html_e( 'Time slot', 'york-scouts-events' ); ?></th><td><?php echo $row['slot_id'] ? esc_html( Slots::label( $event_id, $row['slot_id'] ) ) : '&mdash;'; ?></td></tr>
							<tr><th><?php esc_html_e( 'Participants', 'york-scouts-events' ); ?></th><td><?php echo esc_html( $row['participant_count'] ); ?></td></tr>
						</tbody></table>
					</div></div>

					<div class="postbox"><div class="inside">
						<h2><?php esc_html_e( 'Booking contact', 'york-scouts-events' ); ?></h2>
						<table class="widefat striped"><tbody>
							<tr><th><?php esc_html_e( 'Name', 'york-scouts-events' ); ?></th><td><?php echo esc_html( $row['contact_name'] ?: ( $booking['contact_name'] ?? '' ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Email', 'york-scouts-events' ); ?></th><td><?php $email = $row['contact_email'] ?: ( $booking['contact_email'] ?? '' ); echo $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '&mdash;'; ?></td></tr>
							<tr><th><?php esc_html_e( 'Phone', 'york-scouts-events' ); ?></th><td><?php echo esc_html( $row['contact_phone'] ?: ( $booking['contact_phone'] ?? '' ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Scout Group', 'york-scouts-events' ); ?></th><td><?php echo esc_html( $row['scout_group'] ?: ( $booking['scout_group'] ?? '' ) ); ?></td></tr>
							<?php if ( ! empty( $booking['emergency_contact'] ) ) : ?>
								<tr><th><?php esc_html_e( 'Legacy emergency contact', 'york-scouts-events' ); ?></th><td><?php echo esc_html( $booking['emergency_contact'] ); ?></td></tr>
							<?php endif; ?>
							<tr><th><?php esc_html_e( 'Consent', 'york-scouts-events' ); ?></th><td><?php echo ! empty( $booking['consent'] ) ? esc_html__( 'Yes', 'york-scouts-events' ) : esc_html__( 'Not recorded', 'york-scouts-events' ); ?></td></tr>
							<?php $this->render_custom_booking_fields( $event_id, $booking ); ?>
						</tbody></table>
					</div></div>

					<div class="postbox"><div class="inside">
						<h2><?php esc_html_e( 'Participants', 'york-scouts-events' ); ?></h2>
						<?php $this->render_participants( $event_id, $booking ); ?>
					</div></div>
				</div>

				<div>
					<div class="postbox"><div class="inside">
						<h2><?php esc_html_e( 'Payment / order', 'york-scouts-events' ); ?></h2>
						<p><strong><?php esc_html_e( 'Status:', 'york-scouts-events' ); ?></strong> <?php echo esc_html( wc_get_order_status_name( $row['status'] ) ); ?></p>
						<p><strong><?php esc_html_e( 'Order:', 'york-scouts-events' ); ?></strong> #<?php echo esc_html( $row['order_id'] ); ?></p>
						<?php if ( $order ) : ?>
							<p><strong><?php esc_html_e( 'Total:', 'york-scouts-events' ); ?></strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
							<p><strong><?php esc_html_e( 'Payment method:', 'york-scouts-events' ); ?></strong> <?php echo esc_html( $order->get_payment_method_title() ?: __( 'Not recorded', 'york-scouts-events' ) ); ?></p>
							<p><strong><?php esc_html_e( 'Order date:', 'york-scouts-events' ); ?></strong> <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'j F Y H:i' ) : __( 'Not recorded', 'york-scouts-events' ) ); ?></p>
							<?php if ( $order_url ) : ?>
								<p><a class="button button-primary" href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'Open WooCommerce order', 'york-scouts-events' ); ?></a></p>
							<?php endif; ?>
						<?php else : ?>
							<p><?php esc_html_e( 'The WooCommerce order could not be loaded. The booking table row is retained.', 'york-scouts-events' ); ?></p>
						<?php endif; ?>
					</div></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render participant detail tables.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $booking Booking data.
	 */
	private function render_participants( $event_id, array $booking ) {
		if ( empty( $booking['participants'] ) || ! is_array( $booking['participants'] ) ) {
			echo '<p>' . esc_html__( 'Participant details were not found in structured booking data. Check the WooCommerce order item for original metadata.', 'york-scouts-events' ) . '</p>';
			return;
		}

		$custom_fields = Fields::custom_definitions_from_booking( $event_id, $booking, 'participant' );

		foreach ( $booking['participants'] as $index => $participant ) {
			if ( ! is_array( $participant ) ) {
				continue;
			}

			$parent_email = isset( $participant['parent_email'] ) ? sanitize_email( $participant['parent_email'] ) : '';
			$parent_phone = isset( $participant['parent_phone'] ) ? sanitize_text_field( (string) $participant['parent_phone'] ) : '';
			?>
			<h3><?php echo esc_html( sprintf( __( 'Participant %d', 'york-scouts-events' ), $index + 1 ) ); ?><?php echo ! empty( $participant['name'] ) ? ' - ' . esc_html( $participant['name'] ) : ''; ?></h3>
			<table class="widefat striped ys-participant-table"><tbody>
				<tr><th><?php esc_html_e( 'Age', 'york-scouts-events' ); ?></th><td><?php echo esc_html( $participant['age'] ?? '' ); ?></td></tr>
				<?php if ( Fields::is_enabled( $event_id, 'parent_email' ) || '' !== $parent_email ) : ?>
					<tr><th><?php esc_html_e( 'Parent email', 'york-scouts-events' ); ?></th><td>
						<?php if ( '' !== $parent_email ) : ?>
							<a href="mailto:<?php echo esc_attr( $parent_email ); ?>"><?php echo esc_html( $parent_email ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Not recorded', 'york-scouts-events' ); ?>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<?php if ( Fields::is_enabled( $event_id, 'parent_phone' ) || '' !== $parent_phone ) : ?>
					<tr><th><?php esc_html_e( 'Parent tel number', 'york-scouts-events' ); ?></th><td>
						<?php echo '' !== $parent_phone ? esc_html( $parent_phone ) : esc_html__( 'Not recorded', 'york-scouts-events' ); ?>
					</td></tr>
				<?php endif; ?>
				<?php if ( ! empty( $participant['emergency_contact'] ) || ( Fields::is_enabled( $event_id, 'emergency_contact' ) && empty( $booking['emergency_contact'] ) ) ) : ?>
					<tr><th><?php esc_html_e( 'Emergency contact', 'york-scouts-events' ); ?></th><td><?php echo ! empty( $participant['emergency_contact'] ) ? esc_html( $participant['emergency_contact'] ) : esc_html__( 'Not recorded', 'york-scouts-events' ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Dietary requirements', 'york-scouts-events' ); ?></th><td><?php echo ! empty( $participant['dietary'] ) ? nl2br( esc_html( $participant['dietary'] ) ) : esc_html__( 'None supplied', 'york-scouts-events' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Please tell us about any Accessibility, Medical or support accommodations required', 'york-scouts-events' ); ?></th><td><?php echo ! empty( $participant['accessibility'] ) ? nl2br( esc_html( $participant['accessibility'] ) ) : esc_html__( 'None supplied', 'york-scouts-events' ); ?></td></tr>
				<?php $this->render_custom_participant_fields( $participant, $custom_fields ); ?>
			</tbody></table>
			<?php
		}
	}

	/**
	 * Render custom booking-level answers.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $booking Booking data.
	 */
	private function render_custom_booking_fields( $event_id, array $booking ) {
		$fields = Fields::custom_definitions_from_booking( $event_id, $booking, 'booking' );
		$values = isset( $booking['custom_booking'] ) && is_array( $booking['custom_booking'] ) ? $booking['custom_booking'] : array();

		foreach ( $fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );

			if ( ! $key ) {
				continue;
			}

			echo '<tr><th>' . esc_html( $field['label'] ) . '</th><td>' . wp_kses_post( $this->format_custom_value( $values[ $key ] ?? '' ) ) . '</td></tr>';
		}
	}

	/**
	 * Render custom participant answers.
	 *
	 * @param array $participant Participant data.
	 * @param array $fields Custom field definitions.
	 */
	private function render_custom_participant_fields( array $participant, array $fields ) {
		$values = isset( $participant['custom'] ) && is_array( $participant['custom'] ) ? $participant['custom'] : array();

		foreach ( $fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );

			if ( ! $key ) {
				continue;
			}

			echo '<tr><th>' . esc_html( $field['label'] ) . '</th><td>' . wp_kses_post( $this->format_custom_value( $values[ $key ] ?? '' ) ) . '</td></tr>';
		}
	}

	/**
	 * Format custom answers for admin display.
	 *
	 * @param mixed $value Custom answer.
	 * @return string
	 */
	private function format_custom_value( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return esc_html__( 'Not recorded', 'york-scouts-events' );
		}

		return nl2br( esc_html( $value ) );
	}

	/**
	 * Get current filters from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function current_filters() {
		$event_id = absint( $_GET['ys_event_filter'] ?? 0 );

		if ( $event_id && 'tribe_events' !== get_post_type( $event_id ) ) {
			$event_id = 0;
		}

		return array(
			'event_id'  => $event_id,
			'status'    => sanitize_key( wp_unslash( $_GET['ys_status_filter'] ?? '' ) ),
			'date_from' => $this->sanitize_date( wp_unslash( $_GET['ys_date_from'] ?? '' ) ),
			'date_to'   => $this->sanitize_date( wp_unslash( $_GET['ys_date_to'] ?? '' ) ),
			'search'    => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
		);
	}

	/**
	 * Build a bounded event filter list.
	 *
	 * @param int $selected_event_id Selected event ID.
	 * @return array<int,string>
	 */
	private function events_for_filter( $selected_event_id ) {
		$ids = get_posts(
			array(
				'post_type'              => 'tribe_events',
				'post_status'            => array( 'publish', 'future', 'draft', 'private' ),
				'posts_per_page'         => 250,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => Meta::ENABLED,
						'value' => 'yes',
					),
				),
			)
		);
		$ids = array_map( 'absint', $ids );

		foreach ( $this->repository->event_ids_with_bookings() as $event_id ) {
			if ( 'tribe_events' === get_post_type( $event_id ) ) {
				$ids[] = absint( $event_id );
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( $selected_event_id && 'tribe_events' === get_post_type( $selected_event_id ) && ! in_array( $selected_event_id, $ids, true ) ) {
			$ids[] = $selected_event_id;
		}

		$events = array();

		foreach ( $ids as $event_id ) {
			$events[ absint( $event_id ) ] = get_the_title( $event_id ) ?: sprintf( __( 'Event #%d', 'york-scouts-events' ), $event_id );
		}

		asort( $events );

		return $events;
	}

	/**
	 * Pagination markup.
	 *
	 * @param int $paged Current page.
	 * @param int $pages Total pages.
	 * @return string
	 */
	private function pagination_links( $paged, $pages ) {
		if ( $pages <= 1 ) {
			return '';
		}

		return paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			)
		);
	}

	/**
	 * Validate a request date.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	private function sanitize_date( $date ) {
		$date = sanitize_text_field( $date );

		return preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $date ) ? $date : '';
	}

	/**
	 * Render the required event selector.
	 *
	 * @param array<int,string> $events Events available for selection.
	 * @param int               $selected_event_id Selected event ID.
	 */
	private function render_event_picker( array $events, $selected_event_id ) {
		?>
		<form method="get" class="ys-event-picker-panel">
			<input type="hidden" name="page" value="ys-event-bookings">

			<label for="ys_event_filter"><strong><?php esc_html_e( 'Event', 'york-scouts-events' ); ?></strong></label>
			<select id="ys_event_filter" name="ys_event_filter" required>
				<option value=""><?php esc_html_e( 'Choose an event', 'york-scouts-events' ); ?></option>
				<?php foreach ( $events as $event_id => $title ) : ?>
					<option value="<?php echo esc_attr( $event_id ); ?>" <?php selected( $selected_event_id, $event_id ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button button-primary"><?php esc_html_e( 'View bookings', 'york-scouts-events' ); ?></button>
			<?php if ( $selected_event_id ) : ?>
				<?php if ( Capabilities::can_download_reports() ) : ?>
					<a class="button" href="<?php echo esc_url( Participant_Report_Service::download_url( $selected_event_id ) ); ?>"><?php esc_html_e( 'Download participant PDF', 'york-scouts-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( Participant_Report_Service::manual_email_url( $selected_event_id ) ); ?>"><?php esc_html_e( 'Send participant PDF email', 'york-scouts-events' ); ?></a>
				<?php endif; ?>
				<?php if ( Capabilities::can_export_emails() ) : ?>
					<a class="button" href="<?php echo esc_url( Email_Export_Service::download_url( $selected_event_id ) ); ?>"><?php esc_html_e( 'Download email list', 'york-scouts-events' ); ?></a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Choose different event', 'york-scouts-events' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Handle deletion of orphaned booking rows.
	 */
	public function delete_orphaned_bookings() {
		if ( ! Capabilities::can_manage_cleanup() ) {
			wp_die( esc_html__( 'You do not have permission to clean up booking records.', 'york-scouts-events' ) );
		}

		$event_id = absint( $_POST['event_id'] ?? 0 );

		check_admin_referer( 'ys_delete_orphaned_bookings_' . $event_id );

		$deleted = $this->repository->delete_orphaned_event_bookings( $event_id );
		$url     = add_query_arg(
			array(
				'ys_deleted_orphaned_event' => $event_id,
				'ys_deleted_orphaned_count' => $deleted,
			),
			self::url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render cleanup success notice.
	 */
	private function render_cleanup_notice() {
		$event_id = absint( $_GET['ys_deleted_orphaned_event'] ?? 0 );

		if ( ! $event_id ) {
			return;
		}

		$count = absint( $_GET['ys_deleted_orphaned_count'] ?? 0 );
		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: 1: Row count, 2: Event ID. */
				__( 'Removed %1$d legacy booking record(s) for event ID %2$d.', 'york-scouts-events' ),
				$count,
				$event_id
			)
		) . '</p></div>';
	}

	/**
	 * Render orphaned legacy booking cleanup panel.
	 */
	private function render_orphaned_cleanup_panel() {
		if ( ! Capabilities::can_manage_cleanup() ) {
			return;
		}

		$rows = $this->repository->orphaned_event_summaries();

		if ( ! $rows ) {
			return;
		}
		?>
		<div class="ys-orphaned-bookings-panel">
			<h2><?php esc_html_e( 'Legacy booking records', 'york-scouts-events' ); ?></h2>
			<p><?php esc_html_e( 'These booking rows point at missing posts or old non-Events-Calendar event records. Removing them clears them from this plugin without deleting WooCommerce orders or payments.', 'york-scouts-events' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Referenced event', 'york-scouts-events' ); ?></th>
						<th><?php esc_html_e( 'Source', 'york-scouts-events' ); ?></th>
						<th><?php esc_html_e( 'Bookings', 'york-scouts-events' ); ?></th>
						<th><?php esc_html_e( 'Participants', 'york-scouts-events' ); ?></th>
						<th><?php esc_html_e( 'Last booking', 'york-scouts-events' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'york-scouts-events' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$event_id = absint( $row['event_id'] );
						$title    = $row['post_title'] ? $row['post_title'] : sprintf( __( 'Event ID %d', 'york-scouts-events' ), $event_id );
						$source   = $row['post_type'] ? sprintf( __( 'Legacy post type: %s', 'york-scouts-events' ), $row['post_type'] ) : __( 'Missing post', 'york-scouts-events' );
						?>
						<tr>
							<td><strong><?php echo esc_html( $title ); ?></strong><br><span class="ys-muted">#<?php echo esc_html( $event_id ); ?></span></td>
							<td><?php echo esc_html( $source ); ?></td>
							<td><?php echo esc_html( number_format_i18n( absint( $row['booking_count'] ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( absint( $row['participant_count'] ) ) ); ?></td>
							<td><?php echo $row['last_booking_at'] ? esc_html( mysql2date( 'j M Y H:i', $row['last_booking_at'] ) ) : '&mdash;'; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="ys_delete_orphaned_bookings">
									<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
									<?php wp_nonce_field( 'ys_delete_orphaned_bookings_' . $event_id ); ?>
									<?php
									submit_button(
										__( 'Remove records', 'york-scouts-events' ),
										'delete small',
										'submit',
										false,
										array( 'onclick' => 'return confirm("' . esc_js( __( 'Remove these legacy booking records from York Scouts Events? WooCommerce orders will not be deleted.', 'york-scouts-events' ) ) . '");' )
									);
									?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
		}

	/**
	 * Render manual participant report email status notice.
	 */
	private function render_report_email_notice() {
		if ( ! isset( $_GET['ys_report_emailed'] ) ) {
			return;
		}

		$sent = '1' === sanitize_text_field( wp_unslash( $_GET['ys_report_emailed'] ) );
		?>
		<div class="notice <?php echo esc_attr( $sent ? 'notice-success' : 'notice-error' ); ?> is-dismissible">
			<p>
				<?php
				echo $sent
					? esc_html__( 'Participant PDF email sent to the event report recipients.', 'york-scouts-events' )
					: esc_html__( 'Participant PDF email could not be sent. Check report recipients and mail delivery settings.', 'york-scouts-events' );
				?>
			</p>
		</div>
		<?php
	}
	}
