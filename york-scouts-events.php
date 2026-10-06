<?php
/**
 * Plugin Name: York Scouts Events
 * Description: Ticket sales and booking management for The Events Calendar using WooCommerce checkout.
 * Version: 1.0.15
 * Author: York Scouts
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, the-events-calendar
 * Text Domain: york-scouts-events
 *
 * @package YorkScoutsEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YS_EVENTS_VERSION', '1.0.15' );
define( 'YS_EVENTS_DB_VERSION', '1.0.0' );
define( 'YS_EVENTS_FILE', __FILE__ );
define( 'YS_EVENTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'YS_EVENTS_URL', plugin_dir_url( __FILE__ ) );

require_once YS_EVENTS_PATH . 'includes/class-autoloader.php';

YorkScoutsEvents\Autoloader::register();

register_activation_hook( __FILE__, array( 'YorkScoutsEvents\Activator', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		YorkScoutsEvents\Plugin::instance()->boot();
	}
);
