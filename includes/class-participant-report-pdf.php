<?php
/**
 * Participant report PDF writer.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a readable participant report PDF without external dependencies.
 */
final class Participant_Report_Pdf {
	/**
	 * PDF page width in points.
	 *
	 * @var int
	 */
	private $width = 595;

	/**
	 * PDF page height in points.
	 *
	 * @var int
	 */
	private $height = 842;

	/**
	 * Page margin in points.
	 *
	 * @var int
	 */
	private $margin = 36;

	/**
	 * Current page streams.
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Current page content.
	 *
	 * @var string
	 */
	private $content = '';

	/**
	 * Current y-position.
	 *
	 * @var int
	 */
	private $y = 806;

	/**
	 * Event title for page headers.
	 *
	 * @var string
	 */
	private $event_title = '';

	/**
	 * Render an event report to PDF bytes.
	 *
	 * @param int    $event_id Event post ID.
	 * @param array  $rows Booking rows.
	 * @param string $trigger Trigger key.
	 * @return string
	 */
	public function render( $event_id, array $rows, $trigger = 'manual' ) {
		$this->pages       = array();
		$this->content     = '';
		$this->event_title = get_the_title( $event_id ) ?: sprintf( __( 'Event #%d', 'york-scouts-events' ), $event_id );

		$this->add_page();
		$this->cover_header();

		$event_date = Meta::event_date( $event_id );
		$this->summary_grid(
			array(
				__( 'Event date', 'york-scouts-events' ) => $event_date ? date_i18n( 'j F Y H:i', strtotime( $event_date ) ) : __( 'Not recorded', 'york-scouts-events' ),
				__( 'Generated', 'york-scouts-events' )  => date_i18n( 'j F Y H:i', current_time( 'timestamp' ) ),
				__( 'Reason', 'york-scouts-events' )     => Participant_Report_Service::trigger_label( $trigger ),
				__( 'Bookings', 'york-scouts-events' )   => number_format_i18n( count( $rows ) ),
			)
		);

		$this->space( 10 );

		if ( ! $rows ) {
			$this->notice( __( 'No active bookings were found for this event.', 'york-scouts-events' ) );
			return $this->finish();
		}

		foreach ( $rows as $row ) {
			$this->booking_row( $event_id, $row );
		}

		return $this->finish();
	}

	/**
	 * Add a page.
	 */
	private function add_page() {
		if ( '' !== $this->content ) {
			$this->pages[] = $this->content;
		}

		$this->content = '';
		$this->y       = 806;
		$this->rectangle( 0, 0, $this->width, $this->height, array( 1, 1, 1 ), null );

		if ( $this->pages && $this->event_title ) {
			$this->continued_header();
		}
	}

	/**
	 * Render the first page header.
	 */
	private function cover_header() {
		$this->rectangle( 0, 760, $this->width, 82, array( 0.455, 0.075, 0.863 ), null );
		$this->rectangle( 0, 760, $this->width, 7, array( 1.000, 0.902, 0.153 ), null );
		$this->write( __( 'Participant Information Report', 'york-scouts-events' ), $this->margin, 812, 11, false, array( 1.000, 0.902, 0.153 ) );

		$lines = $this->wrap( $this->event_title, 62 );
		foreach ( array_slice( $lines, 0, 2 ) as $index => $line ) {
			$this->write( $line, $this->margin, 790 - ( $index * 19 ), 16, true, array( 1, 1, 1 ) );
		}

		$this->y = 730;
	}

	/**
	 * Render a compact continuation header.
	 */
	private function continued_header() {
		$this->rectangle( 0, 798, $this->width, 44, array( 0.455, 0.075, 0.863 ), null );
		$this->rectangle( 0, 798, $this->width, 5, array( 1.000, 0.902, 0.153 ), null );
		$this->write( __( 'Participant Information Report', 'york-scouts-events' ), $this->margin, 822, 9, true, array( 1, 1, 1 ) );
		$this->write( $this->truncate( $this->event_title, 70 ), $this->margin, 809, 8, false, array( 1.000, 0.902, 0.153 ) );
		$this->y = 772;
	}

	/**
	 * Finish and return PDF bytes.
	 *
	 * @return string
	 */
	private function finish() {
		if ( '' !== $this->content ) {
			$this->pages[] = $this->content;
			$this->content = '';
		}

		$objects     = array();
		$objects[1]  = '<< /Type /Catalog /Pages 2 0 R >>';
		$font_normal = 3;
		$font_bold   = 4;
		$objects[ $font_normal ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[ $font_bold ]   = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$kids        = array();
		$object_id   = 5;
		$total_pages = count( $this->pages );

		foreach ( $this->pages as $index => $page_content ) {
			$page_id      = $object_id++;
			$content_id   = $object_id++;
			$page_number  = $index + 1;
			$page_content .= $this->footer_stream( $page_number, $total_pages );
			$kids[]       = $page_id . ' 0 R';

			$objects[ $page_id ]    = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->width . ' ' . $this->height . '] /Resources << /Font << /F1 ' . $font_normal . ' 0 R /F2 ' . $font_bold . ' 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
			$objects[ $content_id ] = "<< /Length " . strlen( $page_content ) . " >>\nstream\n" . $page_content . "endstream";
		}

		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array( 0 );

		foreach ( $objects as $id => $object ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf          .= $id . " 0 obj\n" . $object . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$count       = max( array_keys( $objects ) ) + 1;
		$pdf        .= "xref\n0 " . $count . "\n";
		$pdf        .= "0000000000 65535 f \n";

		for ( $id = 1; $id < $count; $id++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] ?? 0 );
		}

		$pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Render a summary grid.
	 *
	 * @param array<string,string> $items Summary items.
	 */
	private function summary_grid( array $items ) {
		$column_width = 254;
		$row_height   = 45;
		$gap          = 14;
		$index        = 0;

		foreach ( $items as $label => $value ) {
			if ( 0 === $index % 2 ) {
				$this->ensure_space( $row_height + 8 );
			}

			$x = $this->margin + ( ( $index % 2 ) * ( $column_width + $gap ) );
			$y = $this->y - $row_height;

			$this->rectangle( $x, $y, $column_width, $row_height, array( 0.945, 0.945, 0.945 ), array( 0.000, 0.427, 0.875 ) );
			$this->rectangle( $x, $y, 6, $row_height, array( 1.000, 0.902, 0.153 ), null );
			$this->write( $label, $x + 14, $y + 27, 7, true, array( 0.000, 0.224, 0.510 ) );
			$this->write( $this->truncate( $value, 42 ), $x + 14, $y + 12, 10, false, array( 0.080, 0.100, 0.120 ) );

			if ( 1 === $index % 2 ) {
				$this->y -= $row_height + 10;
			}

			$index++;
		}

		if ( 1 === $index % 2 ) {
			$this->y -= $row_height + 10;
		}
	}

	/**
	 * Render a readable notice box.
	 *
	 * @param string $text Notice text.
	 */
	private function notice( $text ) {
		$this->ensure_space( 44 );
		$this->rectangle( $this->margin, $this->y - 34, 523, 34, array( 1.000, 0.980, 0.800 ), array( 1.000, 0.902, 0.153 ) );
		$this->rectangle( $this->margin, $this->y - 34, 6, 34, array( 1.000, 0.902, 0.153 ), null );
		$this->write( $text, $this->margin + 14, $this->y - 21, 10, false, array( 0.000, 0.224, 0.510 ) );
		$this->y -= 44;
	}

	/**
	 * Render a booking and its participants.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $row Booking row.
	 */
	private function booking_row( $event_id, array $row ) {
		$booking      = json_decode( (string) ( $row['booking_data'] ?? '' ), true );
		$booking      = is_array( $booking ) ? $booking : array();
		$participants = isset( $booking['participants'] ) && is_array( $booking['participants'] ) ? $booking['participants'] : array();
		$custom_booking_fields     = Fields::custom_definitions_from_booking( $event_id, $booking, 'booking' );
		$custom_participant_fields = Fields::custom_definitions_from_booking( $event_id, $booking, 'participant' );

		if ( ! $participants ) {
			for ( $index = 0; $index < absint( $row['participant_count'] ?? 0 ); $index++ ) {
				$participants[] = array();
			}
		}

		$this->ensure_space( 130 );
		$this->booking_heading( $row );

		if ( ! empty( $row['slot_id'] ) ) {
			$this->field( __( 'Time slot', 'york-scouts-events' ), Slots::label( $event_id, (string) $row['slot_id'] ) );
		}

		$this->section_title( __( 'Booking contact', 'york-scouts-events' ) );
		$this->standard_field( $booking, 'contact_name', __( 'Contact', 'york-scouts-events' ), $this->fallback( $row['contact_name'] ?? '', $booking['contact_name'] ?? '' ) );
		$this->standard_field( $booking, 'contact_email', __( 'Email', 'york-scouts-events' ), $this->fallback( $row['contact_email'] ?? '', $booking['contact_email'] ?? '' ) );
		$this->standard_field( $booking, 'contact_phone', __( 'Phone', 'york-scouts-events' ), $this->fallback( $row['contact_phone'] ?? '', $booking['contact_phone'] ?? '' ) );
		$this->standard_field( $booking, 'scout_group', __( 'Scout Group', 'york-scouts-events' ), $this->fallback( $row['scout_group'] ?? '', $booking['scout_group'] ?? '' ) );

		if ( ! empty( $booking['emergency_contact'] ) ) {
			$this->field( __( 'Legacy emergency contact', 'york-scouts-events' ), $booking['emergency_contact'] );
		}

		if ( $this->standard_enabled( $booking, 'consent' ) || ! empty( $booking['consent'] ) ) {
			$this->field( __( 'Consent', 'york-scouts-events' ), ! empty( $booking['consent'] ) ? __( 'Yes', 'york-scouts-events' ) : __( 'Not recorded', 'york-scouts-events' ) );
		}

		$this->custom_booking_lines( $booking, $custom_booking_fields );

		$this->section_title( __( 'Participants', 'york-scouts-events' ) );

		foreach ( $participants as $index => $participant ) {
			$participant = is_array( $participant ) ? $participant : array();
			$this->participant_block( $booking, $participant, $custom_participant_fields, $index + 1 );
		}

		$this->space( 10 );
	}

	/**
	 * Render the booking heading.
	 *
	 * @param array $row Booking row.
	 */
	private function booking_heading( array $row ) {
		$this->rectangle( $this->margin, $this->y - 34, 523, 34, array( 0.000, 0.224, 0.510 ), null );
		$this->rectangle( $this->margin, $this->y - 34, 6, 34, array( 1.000, 0.902, 0.153 ), null );
		$this->write(
			sprintf(
				/* translators: 1: Booking ID, 2: Order ID. */
				__( 'Booking #%1$d - Order #%2$d', 'york-scouts-events' ),
				absint( $row['id'] ?? 0 ),
				absint( $row['order_id'] ?? 0 )
			),
			$this->margin + 14,
			$this->y - 14,
			11,
			true,
			array( 1, 1, 1 )
		);
		$this->write( wc_get_order_status_name( sanitize_key( $row['status'] ?? '' ) ), 444, $this->y - 14, 9, true, array( 1.000, 0.902, 0.153 ) );
		$this->write(
			sprintf(
				/* translators: %d: Participant count. */
				_n( '%d participant', '%d participants', absint( $row['participant_count'] ?? 0 ), 'york-scouts-events' ),
				absint( $row['participant_count'] ?? 0 )
			),
			$this->margin + 14,
			$this->y - 28,
			8,
			false,
			array( 0.925, 0.945, 1.000 )
		);
		$this->y -= 46;
	}

	/**
	 * Render a participant block.
	 *
	 * @param array $booking Booking payload.
	 * @param array $participant Participant data.
	 * @param array $custom_fields Custom participant field definitions.
	 * @param int   $number Participant number.
	 */
	private function participant_block( array $booking, array $participant, array $custom_fields, $number ) {
		$this->ensure_space( 84 );
		$this->rectangle( $this->margin + 10, $this->y - 24, 503, 24, array( 0.455, 0.075, 0.863 ), null );
		$this->rectangle( $this->margin + 10, $this->y - 24, 5, 24, array( 1.000, 0.902, 0.153 ), null );
		$this->write(
			sprintf(
				/* translators: %d: Participant number. */
				__( 'Participant %d', 'york-scouts-events' ),
				absint( $number )
			),
			$this->margin + 22,
			$this->y - 16,
			9,
			true,
			array( 1, 1, 1 )
		);
		$this->y -= 34;

		$this->participant_standard_field( $booking, $participant, 'participant_name', 'name', __( 'Name', 'york-scouts-events' ) );
		$this->participant_standard_field( $booking, $participant, 'participant_age', 'age', __( 'Age', 'york-scouts-events' ) );
		$this->participant_standard_field( $booking, $participant, 'parent_email', 'parent_email', __( 'Parent email', 'york-scouts-events' ) );
		$this->participant_standard_field( $booking, $participant, 'parent_phone', 'parent_phone', __( 'Parent tel number', 'york-scouts-events' ) );

		if ( ! empty( $participant['emergency_contact'] ) || empty( $booking['emergency_contact'] ) ) {
			$this->participant_standard_field( $booking, $participant, 'emergency_contact', 'emergency_contact', __( 'Emergency contact', 'york-scouts-events' ) );
		}

		$this->participant_standard_field( $booking, $participant, 'dietary', 'dietary', __( 'Dietary requirements', 'york-scouts-events' ), __( 'None supplied', 'york-scouts-events' ) );
		$this->participant_standard_field( $booking, $participant, 'accessibility', 'accessibility', __( 'Please tell us about any Accessibility, Medical or support accommodations required', 'york-scouts-events' ), __( 'None supplied', 'york-scouts-events' ) );
		$this->custom_participant_lines( $participant, $custom_fields );
		$this->space( 4 );
	}

	/**
	 * Render a section title.
	 *
	 * @param string $title Section title.
	 */
	private function section_title( $title ) {
		$this->ensure_space( 24 );
		$this->space( 4 );
		$this->write( $title, $this->margin, $this->y, 10, true, array( 0.000, 0.224, 0.510 ) );
		$this->y -= 16;
	}

	/**
	 * Render a standard booking field when useful.
	 *
	 * @param array  $booking Booking payload.
	 * @param string $key Standard enabled field key.
	 * @param string $label Field label.
	 * @param string $value Field value.
	 */
	private function standard_field( array $booking, $key, $label, $value ) {
		if ( $this->standard_enabled( $booking, $key ) || '' !== trim( (string) $value ) ) {
			$this->field( $label, $this->display_value( $value ) );
		}
	}

	/**
	 * Render a participant standard field when useful.
	 *
	 * @param array  $booking Booking payload.
	 * @param array  $participant Participant data.
	 * @param string $enabled_key Enabled field key.
	 * @param string $value_key Participant value key.
	 * @param string $label Field label.
	 * @param string $empty Empty display value.
	 */
	private function participant_standard_field( array $booking, array $participant, $enabled_key, $value_key, $label, $empty = '' ) {
		$value = $participant[ $value_key ] ?? '';

		if ( $this->standard_enabled( $booking, $enabled_key ) || '' !== trim( (string) $value ) ) {
			$this->field( $label, $this->display_value( $value, $empty ), 22 );
		}
	}

	/**
	 * Add one wrapped key/value row.
	 *
	 * @param string $label Field label.
	 * @param string $value Field value.
	 * @param int    $indent Left indent.
	 */
	private function field( $label, $value, $indent = 0 ) {
		$x           = $this->margin + $indent;
		$label_width = 118;
		$value_x     = $x + $label_width;
		$value_width = 523 - $indent - $label_width;
		$label_lines = $this->wrap( $label, max( 12, (int) floor( $label_width / 5.2 ) ) );
		$lines       = $this->wrap( $this->display_value( $value ), max( 22, (int) floor( $value_width / 5.2 ) ) );
		$row_lines   = max( count( $label_lines ), count( $lines ) );
		$height      = max( 14, $row_lines * 13 );

		$this->ensure_space( $height + 2 );

		foreach ( $label_lines as $index => $line ) {
			$this->write( $line, $x, $this->y - ( $index * 13 ), 9, true, array( 0.000, 0.224, 0.510 ) );
		}

		foreach ( $lines as $index => $line ) {
			$this->write( $line, $value_x, $this->y - ( $index * 13 ), 9, false, array( 0.080, 0.100, 0.120 ) );
		}

		$this->y -= $height;
	}

	/**
	 * Render custom booking-level fields.
	 *
	 * @param array $booking Booking data.
	 * @param array $fields Custom field definitions.
	 */
	private function custom_booking_lines( array $booking, array $fields ) {
		$values = isset( $booking['custom_booking'] ) && is_array( $booking['custom_booking'] ) ? $booking['custom_booking'] : array();

		foreach ( $fields as $field ) {
			$key   = sanitize_key( $field['key'] ?? '' );
			$value = $values[ $key ] ?? '';

			if ( ! $key || ( empty( $field['required'] ) && ! $this->has_value( $value ) ) ) {
				continue;
			}

			$this->field( $field['label'], $this->display_value( $value ) );
		}
	}

	/**
	 * Render custom participant fields.
	 *
	 * @param array $participant Participant data.
	 * @param array $fields Custom field definitions.
	 */
	private function custom_participant_lines( array $participant, array $fields ) {
		$values = isset( $participant['custom'] ) && is_array( $participant['custom'] ) ? $participant['custom'] : array();

		foreach ( $fields as $field ) {
			$key   = sanitize_key( $field['key'] ?? '' );
			$value = $values[ $key ] ?? '';

			if ( ! $key || ( empty( $field['required'] ) && ! $this->has_value( $value ) ) ) {
				continue;
			}

			$this->field( $field['label'], $this->display_value( $value ), 22 );
		}
	}

	/**
	 * Add vertical whitespace.
	 *
	 * @param int $points Space in points.
	 */
	private function space( $points ) {
		$this->y -= absint( $points );
	}

	/**
	 * Ensure enough room remains on the current page.
	 *
	 * @param int $needed Needed points.
	 */
	private function ensure_space( $needed ) {
		if ( $this->y - $needed < 58 ) {
			$this->add_page();
		}
	}

	/**
	 * Draw a rectangle.
	 *
	 * @param int        $x X position.
	 * @param int        $y Y position.
	 * @param int        $w Width.
	 * @param int        $h Height.
	 * @param array|null $fill Fill RGB.
	 * @param array|null $stroke Stroke RGB.
	 */
	private function rectangle( $x, $y, $w, $h, $fill = null, $stroke = null ) {
		$this->content .= "q\n";

		if ( is_array( $fill ) ) {
			$this->content .= sprintf( "%.3F %.3F %.3F rg\n", $fill[0], $fill[1], $fill[2] );
		}

		if ( is_array( $stroke ) ) {
			$this->content .= sprintf( "%.3F %.3F %.3F RG\n0.6 w\n", $stroke[0], $stroke[1], $stroke[2] );
		}

		$operator       = is_array( $fill ) && is_array( $stroke ) ? 'B' : ( is_array( $fill ) ? 'f' : 'S' );
		$this->content .= sprintf( "%.2F %.2F %.2F %.2F re %s\nQ\n", $x, $y, $w, $h, $operator );
	}

	/**
	 * Write text at an absolute position.
	 *
	 * @param string $text Text.
	 * @param int    $x X position.
	 * @param int    $y Y position.
	 * @param int    $size Font size.
	 * @param bool   $bold Use bold font.
	 * @param array  $color RGB color.
	 */
	private function write( $text, $x, $y, $size, $bold = false, array $color = array( 0, 0, 0 ) ) {
		$this->content .= $this->text_stream( $text, $x, $y, $size, $bold, $color );
	}

	/**
	 * Build a text drawing command.
	 *
	 * @param string $text Text.
	 * @param int    $x X position.
	 * @param int    $y Y position.
	 * @param int    $size Font size.
	 * @param bool   $bold Use bold font.
	 * @param array  $color RGB color.
	 * @return string
	 */
	private function text_stream( $text, $x, $y, $size, $bold = false, array $color = array( 0, 0, 0 ) ) {
		$font = $bold ? 'F2' : 'F1';

		return sprintf(
			"BT %.3F %.3F %.3F rg /%s %d Tf %d %d Td (%s) Tj ET\n",
			$color[0],
			$color[1],
			$color[2],
			$font,
			$size,
			$x,
			$y,
			$this->escape( $text )
		);
	}

	/**
	 * Build footer stream for one page.
	 *
	 * @param int $page Page number.
	 * @param int $total Total pages.
	 * @return string
	 */
	private function footer_stream( $page, $total ) {
		$stream  = "q\n0.455 0.075 0.863 RG\n0.8 w\n36 38 m 559 38 l S\nQ\n";
		$stream .= $this->text_stream( __( 'York Scouts Events participant report', 'york-scouts-events' ), 36, 24, 7, false, array( 0.000, 0.224, 0.510 ) );
		$stream .= $this->text_stream(
			sprintf(
				/* translators: 1: Page number, 2: Total pages. */
				__( 'Page %1$d of %2$d', 'york-scouts-events' ),
				absint( $page ),
				absint( $total )
			),
			500,
			24,
			7,
			false,
			array( 0.000, 0.224, 0.510 )
		);

		return $stream;
	}

	/**
	 * Wrap text to a character count.
	 *
	 * @param string $text Text.
	 * @param int    $max_chars Max characters.
	 * @return string[]
	 */
	private function wrap( $text, $max_chars ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		if ( '' === $text ) {
			return array( '' );
		}

		return explode( "\n", wordwrap( $text, $max_chars, "\n", true ) );
	}

	/**
	 * Escape PDF string content.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = remove_accents( $text );

		if ( function_exists( 'iconv' ) ) {
			$encoded = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );

			if ( false !== $encoded ) {
				$text = $encoded;
			}
		}

		$text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', $text );

		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $text );
	}

	/**
	 * Format display values.
	 *
	 * @param mixed  $value Value.
	 * @param string $empty Empty display value.
	 * @return string
	 */
	private function display_value( $value, $empty = '' ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' !== $value ) {
			return $value;
		}

		return '' !== $empty ? $empty : __( 'Not recorded', 'york-scouts-events' );
	}

	/**
	 * Whether a stored value is displayable.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function has_value( $value ) {
		return is_scalar( $value ) && '' !== trim( (string) $value );
	}

	/**
	 * Pick a fallback display value.
	 *
	 * @param string $primary Primary value.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function fallback( $primary, $fallback ) {
		$value = '' !== trim( (string) $primary ) ? $primary : $fallback;

		return '' !== trim( (string) $value ) ? (string) $value : '';
	}

	/**
	 * Whether a standard field was enabled when the booking was made.
	 *
	 * @param array  $booking Booking data.
	 * @param string $key Field key.
	 * @return bool
	 */
	private function standard_enabled( array $booking, $key ) {
		if ( empty( $booking['enabled_fields'] ) || ! is_array( $booking['enabled_fields'] ) ) {
			return true;
		}

		return in_array( $key, $booking['enabled_fields'], true );
	}

	/**
	 * Truncate a one-line label/value.
	 *
	 * @param string $text Text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function truncate( $text, $length ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, max( 0, $length - 3 ) ) . '...';
	}
}
