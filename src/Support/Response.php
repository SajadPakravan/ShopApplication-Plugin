<?php

namespace AppAPI\Support;

defined( 'ABSPATH' ) || exit;

final class Response {
	public static function success( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'X-Application-API-Version', APP_API_VERSION );
		return $response;
	}

	public static function woocommerce_unavailable(): \WP_Error {
		return new \WP_Error(
			'app_api_woocommerce_unavailable',
			__( 'WooCommerce is not available.', 'application-api' ),
			array( 'status' => 503 )
		);
	}
}
