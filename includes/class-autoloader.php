<?php
/**
 * Lightweight class autoloader.
 *
 * @package YorkScoutsEvents
 */

namespace YorkScoutsEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps plugin namespaces to the plugin folder structure.
 */
final class Autoloader {
	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load a plugin class file.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$basename = array_pop( $parts );
		$segment  = $parts ? array_shift( $parts ) : '';
		$dir      = 'includes';

		if ( 'Admin' === $segment ) {
			$dir = 'admin';
		} elseif ( 'Frontend' === $segment ) {
			$dir = 'public';
		}

		$file = YS_EVENTS_PATH . $dir . '/class-' . strtolower( str_replace( '_', '-', $basename ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
