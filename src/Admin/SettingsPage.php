<?php

namespace AppAPI\Admin;

use AppAPI\Config;
use AppAPI\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Application API', 'application-api' ),
			__( 'Application API', 'application-api' ),
			'manage_woocommerce',
			'application-api',
			array( $this, 'render' )
		);
	}

	public function settings(): void {
		register_setting(
			'app_api_settings',
			Config::OPTION_HOME_SECTIONS,
			array( 'sanitize_callback' => array( $this, 'sanitize_json' ) )
		);
		register_setting(
			'app_api_settings',
			Config::OPTION_HOME_BANNERS,
			array( 'sanitize_callback' => array( $this, 'sanitize_json' ) )
		);
		register_setting(
			'app_api_settings',
			Config::OPTION_HOME_CACHE,
			array(
				'type'              => 'integer',
				'default'           => Config::DEFAULT_HOME_CACHE,
				'sanitize_callback' => static function ( $value ) {
					return min( DAY_IN_SECONDS, max( 0, absint( $value ) ) );
				},
			)
		);
	}

	public function sanitize_json( $value ): string {
		$value   = wp_unslash( (string) $value );
		$decoded = json_decode( $value, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			add_settings_error(
				'application-api',
				'app_api_invalid_json',
				__( 'The JSON configuration is invalid. The previous value was kept.', 'application-api' ),
				'error'
			);
			return (string) get_option( current_filter() === 'sanitize_option_' . Config::OPTION_HOME_BANNERS ? Config::OPTION_HOME_BANNERS : Config::OPTION_HOME_SECTIONS, '[]' );
		}

		Cache::bump_home_version();
		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$sections = get_option( Config::OPTION_HOME_SECTIONS, '' );
		if ( ! $sections ) {
			$sections = wp_json_encode( Config::default_home_sections(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		}

		$banners = get_option( Config::OPTION_HOME_BANNERS, "[]" );
		$ttl     = (int) get_option( Config::OPTION_HOME_CACHE, Config::DEFAULT_HOME_CACHE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Application API', 'application-api' ); ?></h1>
			<p><?php esc_html_e( 'The order of items in the sections JSON is exactly the order returned by the home API and rendered by the app.', 'application-api' ); ?></p>
			<p><code><?php echo esc_html( rest_url( Config::REST_NAMESPACE . '/home' ) ); ?></code></p>
			<?php settings_errors( 'application-api' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'app_api_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="app-api-home-sections"><?php esc_html_e( 'Home sections JSON', 'application-api' ); ?></label></th>
						<td><textarea id="app-api-home-sections" name="<?php echo esc_attr( Config::OPTION_HOME_SECTIONS ); ?>" rows="30" class="large-text code" dir="ltr"><?php echo esc_textarea( $sections ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="app-api-home-banners"><?php esc_html_e( 'Banners JSON', 'application-api' ); ?></label></th>
						<td>
							<textarea id="app-api-home-banners" name="<?php echo esc_attr( Config::OPTION_HOME_BANNERS ); ?>" rows="12" class="large-text code" dir="ltr"><?php echo esc_textarea( $banners ); ?></textarea>
							<p class="description"><code>[{"id":"banner-1","title":"...","image":"https://...","url":"https://...","action":{"type":"category","id":12}}]</code></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="app-api-home-cache"><?php esc_html_e( 'Home cache duration (seconds)', 'application-api' ); ?></label></th>
						<td><input id="app-api-home-cache" name="<?php echo esc_attr( Config::OPTION_HOME_CACHE ); ?>" type="number" min="0" max="86400" value="<?php echo esc_attr( $ttl ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
