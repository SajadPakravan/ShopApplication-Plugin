<?php
/**
 * Plugin Name: Application API for WooCommerce
 * Plugin URI: https://yademansystem.ir
 * Description: A compact, app-oriented REST API for WooCommerce product cards and configurable application home pages.
 * Version: 2.0.5
 * Author: Sajad Pakravan
 * Author URI: https://yademansystem.ir
 * License: GPL-2.0-or-later
 * Text Domain: application-api
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'APP_API_VERSION', '2.0.5' );
define( 'APP_API_FILE', __FILE__ );
define( 'APP_API_PATH', plugin_dir_path( __FILE__ ) );
define( 'APP_API_URL', plugin_dir_url( __FILE__ ) );

require_once APP_API_PATH . 'config.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'AppAPI\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = APP_API_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		AppAPI\Plugin::instance()->boot();
	},
	20
);
