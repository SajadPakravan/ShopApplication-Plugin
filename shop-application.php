<?php
/**
 * Plugin Name: اپلیکیشن فروشگاه
 * Plugin URI: https://yademansystem.ir
 * Description: زیرساخت وب‌سرویس و مدیریت اپلیکیشن فروشگاه برای وردپرس و ووکامرس.
 * Version: 3.0.1
 * Author: Sajad Pakravan
 * Author URI: https://yademansystem.ir
 * License: GPL-2.0-or-later
 * Text Domain: shop-application
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

// When this renamed package is activated over the legacy Application API package,
// deactivate the legacy plugin first so both copies never boot at the same time.
register_activation_hook(
	__FILE__,
	static function () {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$legacy_plugins = array(
			'application-api/application_api.php',
			'shop_application/shop_application.php',
		);

		if ( function_exists( 'is_plugin_active' ) ) {
			foreach ( $legacy_plugins as $legacy ) {
				if ( is_plugin_active( $legacy ) ) {
					deactivate_plugins( $legacy, true );
				}
			}
		}
	}
);

// If the legacy copy is still loaded in the current request, do not bootstrap
// this copy. The next request will load only Shop Application.
if ( defined( 'APP_API_VERSION' ) ) {
	return;
}

define( 'APP_API_VERSION', '3.0.1' );
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
