<?php

namespace AppAPI\Rest;

use AppAPI\Config;
use AppAPI\Controllers\HomeController;
use AppAPI\Controllers\ProductController;
use AppAPI\Controllers\CategoryController;
use AppAPI\Controllers\AuthController;
use AppAPI\Controllers\CustomerController;

defined( 'ABSPATH' ) || exit;

final class Routes {
	public function register(): void {
		$product_controller  = new ProductController();
		$home_controller     = new HomeController();
		$category_controller = new CategoryController();
		$auth_controller     = new AuthController();
		$customer_controller = new CustomerController();

		register_rest_route(
			Config::REST_NAMESPACE,
			'/' . Config::products_endpoint(),
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $product_controller, 'index' ),
				'permission_callback' => '__return_true',
				'args'                => $this->product_collection_args(),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/' . Config::product_endpoint() . '/(?P<id>\d+)',
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

		// Configurable categories landing page.
		register_rest_route(
			Config::REST_NAMESPACE,
			'/categories',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $category_controller, 'index' ),
				'permission_callback' => '__return_true',
			)
		);

		// Fixed category-detail endpoint.
		register_rest_route(
			Config::REST_NAMESPACE,
			'/categories/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $category_controller, 'show' ),
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
			'/auth/login',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $auth_controller, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => $this->auth_args(),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/auth/register',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $auth_controller, 'register' ),
				'permission_callback' => '__return_true',
				'args'                => $this->auth_args(),
			)
		);


		register_rest_route(
			Config::REST_NAMESPACE,
			'/customers/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $customer_controller, 'show' ),
					'permission_callback' => array( $customer_controller, 'permissions_check' ),
				),
				array(
					'methods'             => array( 'PUT', 'PATCH' ),
					'callback'            => array( $customer_controller, 'update' ),
					'permission_callback' => array( $customer_controller, 'permissions_check' ),
				),
			)
		);

		// Authenticated customer account collections. The bearer token identifies the customer.
		register_rest_route(
			Config::REST_NAMESPACE,
			'/orders',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $customer_controller, 'orders' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $customer_controller, 'create_order' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => array( 'PUT', 'PATCH' ),
				'callback'            => array( $customer_controller, 'update_order' ),
				'permission_callback' => array( $customer_controller, 'account_permissions_check' ),
				'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/cart',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $customer_controller, 'cart' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $customer_controller, 'add_cart' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => array( 'PUT', 'PATCH' ), 'callback' => array( $customer_controller, 'update_cart' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $customer_controller, 'remove_cart' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/favorites',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $customer_controller, 'favorites' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $customer_controller, 'add_favorite' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => array( 'PUT', 'PATCH' ), 'callback' => array( $customer_controller, 'replace_favorites' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $customer_controller, 'remove_favorite' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/viewed-products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $customer_controller, 'viewed_products' ),
				'permission_callback' => array( $customer_controller, 'account_permissions_check' ),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/comments',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $customer_controller, 'comments' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $customer_controller, 'add_comment' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/comments/(?P<id>\d+)',
			array(
				array( 'methods' => array( 'PUT', 'PATCH' ), 'callback' => array( $customer_controller, 'update_comment' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $customer_controller, 'delete_comment' ), 'permission_callback' => array( $customer_controller, 'account_permissions_check' ) ),
				'args' => array( 'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			Config::REST_NAMESPACE,
			'/' . Config::home_endpoint(),
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $home_controller, 'index' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function auth_args(): array {
		return array(
			'identifier' => array(
				'type'     => 'string',
				'required' => true,
			),
			'password' => array(
				'type'     => 'string',
				'required' => true,
			),
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
			'on_sale' => array(
				'description' => 'true or false.',
			),
			'category' => array(
				'description' => 'One category ID, a comma-separated list, or an array of category IDs.',
			),
			'brand' => array(
				'description' => 'One brand ID, a comma-separated list, or an array of brand IDs.',
			),
			'attributes' => array(
				'description' => 'Attribute filters. Example: attributes[1]=118,119&attributes[18]=209,238.',
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
				'enum'    => array( 'price', 'date', 'rating', 'id', 'title', 'popularity', 'count_sales' ),
			),
			'order' => array(
				'default' => 'desc',
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
			),
		);
	}
}
