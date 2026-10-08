<?php

namespace AppAPI\Controllers;

use AppAPI\Config;
use AppAPI\Services\HomeBuilder;
use AppAPI\Support\Cache;
use AppAPI\Support\Response;

defined( 'ABSPATH' ) || exit;

final class HomeController {
	private $builder;

	public function __construct( ?HomeBuilder $builder = null ) {
		$this->builder = $builder ?: new HomeBuilder();
	}

	public function index( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return Response::woocommerce_unavailable();
		}

		$ttl       = Config::DEFAULT_HOME_CACHE;
		$key       = Cache::home_key();
		$payload   = $ttl > 0 ? get_transient( $key ) : false;
		$cache_hit = is_array( $payload );

		if ( ! $cache_hit ) {
			$payload = array(
				'success'  => true,
				'sections' => $this->builder->build(),
			);

			if ( $ttl > 0 ) {
				set_transient( $key, $payload, $ttl );
			}
		}

		$response = Response::success( $payload );
		$response->header( 'X-Application-API-Cache', $cache_hit ? 'HIT' : 'MISS' );
		$response->header( 'Cache-Control', $ttl > 0 ? 'public, max-age=' . $ttl : 'no-cache, must-revalidate' );

		return $response;
	}
}
