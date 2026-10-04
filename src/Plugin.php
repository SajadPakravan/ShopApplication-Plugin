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

	private function maybe_upgrade_home_preset(): void {
		if ( ! Config::uses_yademan_preset() ) {
			return;
		}

		$raw             = get_option( Config::OPTION_HOME_SECTIONS, '' );
		$sections        = is_string( $raw ) && $raw ? json_decode( $raw, true ) : null;
		$preset_version  = (string) get_option( Config::OPTION_HOME_PRESET_VERSION, '' );
		$is_legacy       = $this->is_legacy_home_configuration( $sections );
		$is_old_preset   = '' !== $preset_version && version_compare( $preset_version, APP_API_VERSION, '<' );

		// Empty/legacy configurations and plugin-managed older Yademan presets are
		// refreshed so the installed endpoint immediately matches the new contract.
		// A custom configuration with no preset marker is left untouched.
		if ( $raw && ! $is_legacy && ! $is_old_preset ) {
			return;
		}

		$encoded = wp_json_encode(
			Config::default_home_sections(),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);

		if ( is_string( $encoded ) ) {
			update_option( Config::OPTION_HOME_SECTIONS, $encoded, false );
			update_option( Config::OPTION_HOME_PRESET_VERSION, APP_API_VERSION, false );
			Cache::bump_home_version();
		}
	}

	private function is_legacy_home_configuration( $sections ): bool {
		if ( ! is_array( $sections ) || 5 !== count( $sections ) ) {
			return false;
		}

		$ids = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || empty( $section['id'] ) ) {
				return false;
			}
			$ids[] = sanitize_key( $section['id'] );
		}

		return array(
			'banner_slider',
			'main_menu',
			'amazing_offers',
			'special_categories',
			'latest_products',
		) === $ids;
	}

	public function bump_home_for_front_page( int $post_id, $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || (int) get_option( 'page_on_front' ) !== $post_id ) {
			return;
		}

		Cache::bump_home_version();
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
		add_action( 'save_post_page', array( $this, 'bump_home_for_front_page' ), 10, 3 );
		add_action( 'update_option_' . Config::OPTION_HOME_SECTIONS, array( Cache::class, 'bump_home_version' ) );
		add_action( 'update_option_' . Config::OPTION_HOME_CONFIG, array( Cache::class, 'bump_home_version' ) );
		add_action( 'update_option_' . Config::OPTION_HOME_BANNERS, array( Cache::class, 'bump_home_version' ) );
	}
}
