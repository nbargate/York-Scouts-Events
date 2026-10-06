<?php
/**
 * Booking information field definitions.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles per-event booking field switches.
 */
final class Fields {
	/**
	 * Custom field types.
	 *
	 * @return array<string,string>
	 */
	public static function custom_types() {
		return array(
			'text'     => __( 'Short text', 'york-scouts-events' ),
			'textarea' => __( 'Long text', 'york-scouts-events' ),
			'email'    => __( 'Email address', 'york-scouts-events' ),
			'tel'      => __( 'Phone number', 'york-scouts-events' ),
			'number'   => __( 'Number', 'york-scouts-events' ),
			'date'     => __( 'Date', 'york-scouts-events' ),
			'checkbox' => __( 'Checkbox', 'york-scouts-events' ),
		);
	}

	/**
	 * Custom field scopes.
	 *
	 * @return array<string,string>
	 */
	public static function custom_scopes() {
		return array(
			'booking'     => __( 'Booking details', 'york-scouts-events' ),
			'participant' => __( 'Each participant', 'york-scouts-events' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function definitions() {
		return array(
			'contact_name'      => array(
				'label' => __( 'Booking contact name', 'york-scouts-events' ),
				'scope' => 'booking',
			),
			'contact_email'     => array(
				'label' => __( 'Email address', 'york-scouts-events' ),
				'scope' => 'booking',
			),
			'contact_phone'     => array(
				'label' => __( 'Phone number', 'york-scouts-events' ),
				'scope' => 'booking',
			),
			'scout_group'       => array(
				'label' => __( 'Scout Group', 'york-scouts-events' ),
				'scope' => 'booking',
			),
			'consent'           => array(
				'label' => __( 'Consent checkbox', 'york-scouts-events' ),
				'scope' => 'booking',
			),
			'participant_name'  => array(
				'label' => __( 'Participant names', 'york-scouts-events' ),
				'scope' => 'participant',
			),
			'participant_age'   => array(
				'label' => __( 'Participant ages', 'york-scouts-events' ),
				'scope' => 'participant',
			),
			'parent_email'      => array(
				'label' => __( 'Parent email', 'york-scouts-events' ),
				'scope' => 'participant',
			),
			'parent_phone'      => array(
				'label' => __( 'Parent tel number', 'york-scouts-events' ),
				'scope' => 'participant',
			),
			'emergency_contact' => array(
				'label' => __( 'Emergency contact', 'york-scouts-events' ),
				'scope' => 'participant',
			),
			'dietary'           => array(
				'label' => __( 'Dietary requirements', 'york-scouts-events' ),
				'scope' => 'participant',
			),
			'accessibility'     => array(
				'label' => __( 'Please tell us about any Accessibility, Medical or support accommodations required', 'york-scouts-events' ),
				'scope' => 'participant',
			),
		);
	}

	/**
	 * Get enabled fields for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string[]
	 */
	public static function enabled_for_event( $event_id ) {
		$stored = get_post_meta( $event_id, Meta::INFORMATION, true );

		if ( ! is_array( $stored ) ) {
			return array_keys( self::definitions() );
		}

		return self::sanitize_selection( $stored );
	}

	/**
	 * Check a single field switch.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function is_enabled( $event_id, $field ) {
		return in_array( $field, self::enabled_for_event( $event_id ), true );
	}

	/**
	 * Sanitize selected field keys.
	 *
	 * @param array $selected Raw selected field keys.
	 * @return string[]
	 */
	public static function sanitize_selection( array $selected ) {
		$allowed = array_keys( self::definitions() );
		$keys    = array_map( 'sanitize_key', $selected );

		return array_values( array_intersect( $allowed, $keys ) );
	}

	/**
	 * Get custom fields configured for an event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $scope Optional booking or participant scope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function custom_definitions( $event_id, $scope = '' ) {
		$stored = get_post_meta( $event_id, Meta::CUSTOM_INFORMATION, true );
		$fields = is_array( $stored ) ? self::sanitize_custom_fields( $stored ) : array();

		return self::filter_custom_fields_by_scope( $fields, $scope );
	}

	/**
	 * Get custom field definitions saved with a booking, falling back to the event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param array  $booking Booking data payload.
	 * @param string $scope Optional booking or participant scope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function custom_definitions_from_booking( $event_id, array $booking, $scope = '' ) {
		if ( isset( $booking['custom_fields'] ) && is_array( $booking['custom_fields'] ) ) {
			return self::filter_custom_fields_by_scope( self::sanitize_custom_fields( $booking['custom_fields'] ), $scope );
		}

		return self::custom_definitions( $event_id, $scope );
	}

	/**
	 * Sanitize custom field rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_custom_fields( array $rows ) {
		$types  = array_keys( self::custom_types() );
		$scopes = array_keys( self::custom_scopes() );
		$fields = array();
		$used   = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$key = isset( $row['key'] ) && is_scalar( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			$key = $key ? $key : self::key_from_label( $label );

			if ( '' === $key ) {
				continue;
			}

			$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
			$key = trim( $key, '_' );

			if ( '' === $key ) {
				$key = self::key_from_label( $label );
			}

			if ( '' === $key ) {
				continue;
			}

			$key = 0 === strpos( $key, 'cf_' ) ? $key : 'cf_' . $key;
			$key = self::unique_key( $key, $used );

			$type = isset( $row['type'] ) && is_scalar( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'text';
			$type = in_array( $type, $types, true ) ? $type : 'text';

			$scope = isset( $row['scope'] ) && is_scalar( $row['scope'] ) ? sanitize_key( (string) $row['scope'] ) : 'booking';
			$scope = in_array( $scope, $scopes, true ) ? $scope : 'booking';

			$fields[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'scope'    => $scope,
				'required' => ! empty( $row['required'] ) ? 1 : 0,
			);

			if ( count( $fields ) >= 40 ) {
				break;
			}
		}

		return $fields;
	}

	/**
	 * Sanitize a submitted custom value.
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Field definition.
	 * @return string
	 */
	public static function sanitize_custom_value( $value, array $field ) {
		$type = sanitize_key( $field['type'] ?? 'text' );
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( 'checkbox' === $type ) {
			return ! empty( $value ) ? 'Yes' : '';
		}

		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $value );
		}

		if ( 'email' === $type ) {
			return sanitize_email( $value );
		}

		if ( 'number' === $type ) {
			return sanitize_text_field( preg_replace( '/[^0-9.\-]/', '', (string) $value ) );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Whether a custom value satisfies required validation.
	 *
	 * @param string $value Sanitized value.
	 * @param array  $field Field definition.
	 * @return bool
	 */
	public static function custom_value_present( $value, array $field ) {
		if ( 'checkbox' === sanitize_key( $field['type'] ?? '' ) ) {
			return '' !== $value;
		}

		return '' !== trim( (string) $value );
	}

	/**
	 * Filter custom fields by scope.
	 *
	 * @param array  $fields Custom field rows.
	 * @param string $scope Optional scope.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_custom_fields_by_scope( array $fields, $scope ) {
		$scope = sanitize_key( $scope );

		if ( '' === $scope ) {
			return array_values( $fields );
		}

		return array_values(
			array_filter(
				$fields,
				static function ( $field ) use ( $scope ) {
					return is_array( $field ) && $scope === ( $field['scope'] ?? '' );
				}
			)
		);
	}

	/**
	 * Build a stable-ish custom key from a label.
	 *
	 * @param string $label Field label.
	 * @return string
	 */
	private static function key_from_label( $label ) {
		$key = strtolower( remove_accents( $label ) );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( $key, '_' );

		return sanitize_key( $key );
	}

	/**
	 * Ensure keys are unique within one event configuration.
	 *
	 * @param string $key Proposed key.
	 * @param array  $used Used keys.
	 * @return string
	 */
	private static function unique_key( $key, array &$used ) {
		$base   = $key;
		$suffix = 2;

		while ( in_array( $key, $used, true ) ) {
			$key = $base . '_' . $suffix;
			$suffix++;
		}

		$used[] = $key;

		return $key;
	}
}
