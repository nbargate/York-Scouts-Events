<?php
/**
 * Activation and upgrade tasks.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database installation.
 */
final class Activator {
	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		Capabilities::install_role_caps();
		Booking_Repository::install_schema();
		update_option( 'ys_events_db_version', YS_EVENTS_DB_VERSION, false );

		if ( false === get_option( 'ys_events_legacy_import_complete', false ) ) {
			update_option( 'ys_events_legacy_import_cursor', 0, false );
			update_option( 'ys_events_legacy_import_complete', 'no', false );
		}
	}

	/**
	 * Upgrade schema when plugin files are updated.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ys_events_db_version' ) !== YS_EVENTS_DB_VERSION ) {
			self::activate();
			return;
		}

		Capabilities::maybe_install_role_caps();
	}
}
