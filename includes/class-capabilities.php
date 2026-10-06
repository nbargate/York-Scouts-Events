<?php
/**
 * Plugin-specific capabilities.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises York Scouts Events capability names and role grants.
 */
final class Capabilities {
	const VERSION                 = '1.0.0';
	const VIEW_BOOKINGS           = 'ys_events_view_bookings';
	const MANAGE_BOOKINGS         = 'ys_events_manage_bookings';
	const DOWNLOAD_REPORTS        = 'ys_events_download_reports';
	const EXPORT_EMAILS           = 'ys_events_export_emails';
	const MANAGE_BOOKING_SETTINGS = 'ys_events_manage_booking_settings';
	const MANAGE_CLEANUP          = 'ys_events_manage_cleanup';

	/**
	 * Get every plugin capability.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::VIEW_BOOKINGS,
			self::MANAGE_BOOKINGS,
			self::DOWNLOAD_REPORTS,
			self::EXPORT_EMAILS,
			self::MANAGE_BOOKING_SETTINGS,
			self::MANAGE_CLEANUP,
		);
	}

	/**
	 * Grant default role capabilities on activation or upgrade.
	 */
	public static function install_role_caps() {
		$administrator = get_role( 'administrator' );

		if ( $administrator ) {
			foreach ( self::all() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		$shop_manager = get_role( 'shop_manager' );

		if ( $shop_manager ) {
			foreach ( self::shop_manager_caps() as $capability ) {
				$shop_manager->add_cap( $capability );
			}
		}

		update_option( 'ys_events_capabilities_version', self::VERSION, false );
	}

	/**
	 * Ensure existing installs receive new capabilities after a plugin update.
	 */
	public static function maybe_install_role_caps() {
		if ( get_option( 'ys_events_capabilities_version' ) !== self::VERSION ) {
			self::install_role_caps();
		}
	}

	/**
	 * Whether the current user can view booking records.
	 *
	 * @return bool
	 */
	public static function can_view_bookings() {
		return current_user_can( self::VIEW_BOOKINGS ) || current_user_can( self::MANAGE_BOOKINGS );
	}

	/**
	 * Whether the current user can manage booking settings for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public static function can_manage_booking_settings( $event_id ) {
		return current_user_can( self::MANAGE_BOOKING_SETTINGS ) && current_user_can( 'edit_post', absint( $event_id ) );
	}

	/**
	 * Whether the current user can download participant reports.
	 *
	 * @return bool
	 */
	public static function can_download_reports() {
		return current_user_can( self::DOWNLOAD_REPORTS );
	}

	/**
	 * Whether the current user can export participant email lists.
	 *
	 * @return bool
	 */
	public static function can_export_emails() {
		return current_user_can( self::EXPORT_EMAILS );
	}

	/**
	 * Whether the current user can clean up legacy booking rows.
	 *
	 * @return bool
	 */
	public static function can_manage_cleanup() {
		return current_user_can( self::MANAGE_CLEANUP );
	}

	/**
	 * Whether the current user can run or monitor legacy import batches.
	 *
	 * @return bool
	 */
	public static function can_run_legacy_import() {
		return current_user_can( 'manage_woocommerce' ) || self::can_manage_cleanup();
	}

	/**
	 * Pick the concrete capability WordPress should use for the Bookings menu.
	 *
	 * @return string
	 */
	public static function bookings_menu_capability() {
		if ( current_user_can( self::VIEW_BOOKINGS ) ) {
			return self::VIEW_BOOKINGS;
		}

		return self::MANAGE_BOOKINGS;
	}

	/**
	 * Capabilities granted to WooCommerce shop managers by default.
	 *
	 * @return string[]
	 */
	private static function shop_manager_caps() {
		return array(
			self::VIEW_BOOKINGS,
			self::DOWNLOAD_REPORTS,
			self::EXPORT_EMAILS,
		);
	}
}
