<?php

namespace AppAPI;

use AppAPI\Admin\SettingsPage;
use AppAPI\Rest\Routes;
use AppAPI\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static $instance;
	private $booted = false;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'register_menu_location' ) );
		add_action( 'rest_api_init', array( new Routes(), 'register' ) );

		if ( is_admin() ) {
			( new SettingsPage() )->register();
		}

		$this->register_cache_invalidation_hooks();

		if ( ! $this->woocommerce_available() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
		}
	}

	public function register_menu_location(): void {
		register_nav_menu(
			Config::HOME_MENU_LOCATION,
			__( 'Application API home menu', 'application-api' )
		);
	}

	public function woocommerce_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public function woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Application API requires WooCommerce to be installed and active.', 'application-api' ); ?></p>
		</div>
		<?php
	}

	private function register_cache_invalidation_hooks(): void {
		$events = array(
			'woocommerce_new_product',
			'woocommerce_update_product',
			'woocommerce_delete_product',
			'woocommerce_new_product_variation',
			'woocommerce_update_product_variation',
			'woocommerce_delete_product_variation',
		);

		foreach ( $events as $event ) {
			add_action( $event, array( Cache::class, 'bump_home_version' ) );
		}

		add_action( 'created_term', array( Cache::class, 'bump_for_product_term' ), 10, 3 );
		add_action( 'edited_term', array( Cache::class, 'bump_for_product_term' ), 10, 3 );
		add_action( 'delete_term', array( Cache::class, 'bump_for_product_term' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( Cache::class, 'bump_home_version' ) );
		add_action( 'update_option_' . Config::OPTION_HOME_SECTIONS, array( Cache::class, 'bump_home_version' ) );
		add_action( 'update_option_' . Config::OPTION_HOME_BANNERS, array( Cache::class, 'bump_home_version' ) );
	}
}
