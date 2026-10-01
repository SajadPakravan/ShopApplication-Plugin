<?php

namespace AppAPI\Support;

use AppAPI\Config;

defined( 'ABSPATH' ) || exit;

final class Cache {
	public static function home_version(): int {
		$version = (int) get_option( Config::OPTION_CACHE_VERSION, 1 );
		return max( 1, $version );
	}

	public static function bump_home_version(): void {
		update_option( Config::OPTION_CACHE_VERSION, self::home_version() + 1, false );
	}

	public static function home_key(): string {
		$context = array(
			'locale'   => get_locale(),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'version'  => self::home_version(),
		);

		$context = apply_filters( 'app_api_home_cache_context', $context );

		return 'app_api_home_' . md5( wp_json_encode( $context ) );
	}

	public static function bump_for_product_term( $term_id = 0, $term_taxonomy_id = 0, $taxonomy = '' ): void {
		if ( $taxonomy && is_object_in_taxonomy( 'product', $taxonomy ) ) {
			self::bump_home_version();
		}
	}
}
