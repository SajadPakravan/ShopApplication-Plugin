<?php

namespace AppAPI\Rest;

use AppAPI\Config;
use AppAPI\Controllers\HomeController;
use AppAPI\Controllers\ProductController;

defined( 'ABSPATH' ) || exit;

final class Routes {
	public function register(): void {
		$product_controller = new ProductController();
		$home_controller    = new HomeController();

		register_rest_route(
			Config::REST_NAMESPACE,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $product_controller, 'index' ),
				'permission_callback' => '__return_true',
				'args'                => $this->product_collection_args(),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $product_controller, 'show' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/home',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $home_controller, 'index' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function product_collection_args(): array {
		return array(
			'page' => array(
				'default'           => 1,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'default'           => Config::DEFAULT_PER_PAGE,
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => Config::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
			'search' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type' => array(
				'type'              => 'string',
				'description'       => 'One product type slug, for example simple or variable. Omit it to include every published product type.',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => array( $this, 'validate_product_type' ),
			),
			'on_sale' => array(
				'description' => 'true or false.',
			),
			'category' => array(
				'description' => 'One category ID, a comma-separated list, or an array of category IDs.',
			),
			'brand' => array(
				'description' => 'One brand ID, a comma-separated list, or an array of brand IDs.',
			),
			'tag' => array(
				'description' => 'One tag ID, a comma-separated list, or an array of tag IDs.',
			),
			'min_price' => array(
				'type'    => 'number',
				'minimum' => 0,
			),
			'max_price' => array(
				'type'    => 'number',
				'minimum' => 0,
			),
			'orderby' => array(
				'default' => 'date',
				'type'    => 'string',
				'enum'    => array( 'price', 'date', 'rating', 'id', 'title', 'popularity' ),
			),
			'order' => array(
				'default' => 'desc',
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
			),
		);
	}

	public function validate_product_type( $value ): bool {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return true;
		}

		if ( is_array( $value ) || false !== strpos( (string) $value, ',' ) ) {
			return false;
		}

		$type = sanitize_key( (string) $value );
		return '' !== $type && $type === (string) $value;
	}
}
