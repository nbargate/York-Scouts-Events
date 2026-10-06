<?php
/**
 * Admin coordinator.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents\Admin;

use YorkScoutsEvents\Booking_Repository;
use YorkScoutsEvents\Product_Manager;
use YorkScoutsEvents\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all admin hooks.
 */
final class Admin {
	/**
	 * Event meta box handler.
	 *
	 * @var Event_Meta_Box
	 */
	private $meta_box;

	/**
	 * Bookings page handler.
	 *
	 * @var Bookings_Page
	 */
	private $bookings_page;

	/**
	 * Constructor.
	 *
	 * @param Booking_Repository $repository Booking repository.
	 * @param Stats              $stats Counter service.
	 * @param Product_Manager    $products Product manager.
	 */
	public function __construct( Booking_Repository $repository, Stats $stats, Product_Manager $products ) {
		$this->meta_box      = new Event_Meta_Box( $stats, $products );
		$this->bookings_page = new Bookings_Page( $repository );
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( $this->meta_box, 'add_meta_boxes' ) );
		add_action( 'save_post_tribe_events', array( $this->meta_box, 'save' ), 10, 2 );
		add_filter( 'manage_tribe_events_posts_columns', array( $this->meta_box, 'event_admin_columns' ) );
		add_action( 'manage_tribe_events_posts_custom_column', array( $this->meta_box, 'render_event_admin_column' ), 10, 2 );
		add_action( 'admin_menu', array( $this->bookings_page, 'register_page' ) );
		add_action( 'admin_post_ys_delete_orphaned_bookings', array( $this->bookings_page, 'delete_orphaned_bookings' ) );
	}

	/**
	 * Enqueue admin CSS/JS only on plugin-related admin screens.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$is_event_editor  = 'tribe_events' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_bookings_page = in_array( $screen->id, array( 'toplevel_page_ys-event-bookings', 'tribe_events_page_ys-event-bookings' ), true );
		$is_event_list    = 'edit-tribe_events' === $screen->id;

		if ( $is_event_editor || $is_bookings_page || $is_event_list ) {
			wp_enqueue_style( 'ys-events-admin', YS_EVENTS_URL . 'assets/css/admin.css', array(), YS_EVENTS_VERSION );
		}

		if ( $is_event_editor ) {
			wp_enqueue_script( 'ys-events-admin-event', YS_EVENTS_URL . 'assets/js/admin-event.js', array(), YS_EVENTS_VERSION, true );
		}
	}
}
