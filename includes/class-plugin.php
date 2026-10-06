<?php
/**
 * Main plugin coordinator.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

use YorkScoutsEvents\Admin\Admin;
use YorkScoutsEvents\Frontend\Booking_Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots plugin services once dependencies are available.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Get plugin instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot hooks.
	 */
	public function boot() {
		Activator::maybe_upgrade();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
			return;
		}

		if ( ! post_type_exists( 'tribe_events' ) && ! class_exists( 'Tribe__Events__Main' ) ) {
			add_action( 'admin_notices', array( $this, 'events_calendar_notice' ) );
			return;
		}

		$repository = new Booking_Repository();
		$stats      = new Stats();
		$products   = new Product_Manager();
		$reports    = new Participant_Report_Service( $repository );
		$email_exports = new Email_Export_Service( $repository );

		( new Admin( $repository, $stats, $products ) )->hooks();
		( new Booking_Form( $stats, $products ) )->hooks();
		( new WooCommerce_Integration( $repository, $stats ) )->hooks();
		( new Legacy_Importer( $repository, $stats ) )->hooks();
		$reports->hooks();
		$email_exports->hooks();
		( new Report_Scheduler( $reports ) )->hooks();
	}

	/**
	 * Dependency notice for WooCommerce.
	 */
	public function woocommerce_notice() {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'York Scouts Events', 'york-scouts-events' ) . '</strong> ' . esc_html__( 'requires WooCommerce to be active.', 'york-scouts-events' ) . '</p></div>';
	}

	/**
	 * Dependency notice for The Events Calendar.
	 */
	public function events_calendar_notice() {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'York Scouts Events', 'york-scouts-events' ) . '</strong> ' . esc_html__( 'requires The Events Calendar to be active. The Events Calendar remains the master event system.', 'york-scouts-events' ) . '</p></div>';
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
