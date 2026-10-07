<?php

namespace AppAPI\Controllers;

use AppAPI\Config;
use AppAPI\Services\ProductFormatter;
use AppAPI\Services\ProductRepository;
use AppAPI\Support\Request;
use AppAPI\Support\Response;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductController {
	private $repository;
	private $formatter;

	public function __construct( ?ProductRepository $repository = null, ?ProductFormatter $formatter = null ) {
		$this->repository = $repository ?: new ProductRepository();
		$this->formatter  = $formatter ?: new ProductFormatter();
	}

	public function index( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return Response::woocommerce_unavailable();
		}

		$params = $this->params_from_request( $request );

		if ( null !== $params['min_price'] && null !== $params['max_price'] && $params['min_price'] > $params['max_price'] ) {
			return new \WP_Error(
				'app_api_invalid_price_range',
				__( 'min_price cannot be greater than max_price.', 'application-api' ),
				array( 'status' => 400 )
			);
		}

		if ( $params['brand'] && ! Taxonomy::brand_taxonomy() ) {
			return new \WP_Error(
				'app_api_brand_taxonomy_unavailable',
				__( 'No supported product brand taxonomy was found on this store.', 'application-api' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->repository->query( $params );
		$data   = array();

		foreach ( $result['products'] as $product ) {
			$product_id = $product->get_id();
			$data[]     = $this->formatter->format_card( $product, $result['lookup'][ $product_id ] ?? array() );
		}

		$total_site_products = wp_count_posts( 'product' );
		$total_site_products = isset( $total_site_products->publish ) ? (int) $total_site_products->publish : 0;

		$payload = array(
			'success'    => true,
			'pagination' => array(
				'current_page'        => $params['page'],
				'per_page'            => $params['per_page'],
				'total_items'         => $result['total'],
				'total_pages'         => $result['pages'],
				'total_site_products' => $total_site_products,
				'has_next'            => $params['page'] < $result['pages'],
				'has_previous'        => $params['page'] > 1,
			),
			'filters'    => array(
				'search'         => $params['search'] ?: null,
				'category'       => $params['category'],
				'brand'          => $params['brand'],
				'tag'            => $params['tag'],
				'min_price'      => $params['min_price'],
				'max_price'      => $params['max_price'],
				'orderby'        => $params['orderby'],
				'order'          => $params['order'],
				'brand_taxonomy' => Taxonomy::brand_taxonomy(),
			),
			'data'       => $data,
		);

		return Response::success( $payload );
	}

	public function show( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return Response::woocommerce_unavailable();
		}

		$product = wc_get_product( absint( $request['id'] ) );

		if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) || $product->is_type( 'variation' ) ) {
			return new \WP_Error(
				'app_api_product_not_found',
				__( 'Product not found.', 'application-api' ),
				array( 'status' => 404 )
			);
		}

		return Response::success(
			array(
				'success' => true,
				'data'    => $this->formatter->format_detail( $product, $this->repository->lookup( $product->get_id() ) ),
			)
		);
	}

	public function params_from_request( \WP_REST_Request $request ): array {
		$orderby = sanitize_key( (string) ( $request->get_param( 'orderby' ) ?: 'date' ) );
		$order   = strtolower( sanitize_key( (string) ( $request->get_param( 'order' ) ?: 'desc' ) ) );

		$allowed_orderby = array( 'price', 'date', 'rating', 'id', 'title', 'popularity', 'cout_sales', 'count_sales' );
		if ( 'count_sales' === $orderby ) {
			$orderby = 'cout_sales';
		}
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		}

		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'desc';
		}

		$per_page = absint( $request->get_param( 'per_page' ) ?: Config::DEFAULT_PER_PAGE );
		$per_page = min( Config::MAX_PER_PAGE, max( 1, $per_page ) );

		$min_price = $request->get_param( 'min_price' );
		$max_price = $request->get_param( 'max_price' );
		$type      = trim( (string) $request->get_param( 'type' ) );

		return array(
			'page'       => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
			'per_page'   => $per_page,
			'search'     => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'type'        => '' === $type ? null : sanitize_key( $type ),
			'on_sale'    => Request::nullable_boolean( $request->get_param( 'on_sale' ) ),
			'category'   => Request::ids( $this->canonical_or_legacy_param( $request, 'category', 'categories' ) ),
			'brand'      => Request::ids( $this->canonical_or_legacy_param( $request, 'brand', 'brands' ) ),
			'tag'        => Request::ids( $this->canonical_or_legacy_param( $request, 'tag', 'tags' ) ),
			'min_price'  => null === $min_price || '' === $min_price ? null : max( 0, (float) $min_price ),
			'max_price'  => null === $max_price || '' === $max_price ? null : max( 0, (float) $max_price ),
			'orderby'    => $orderby,
			'order'      => $order,
		);
	}

	/**
	 * Singular public parameters are canonical. Plural aliases are accepted to
	 * avoid breaking an already-installed application build during migration.
	 */
	private function canonical_or_legacy_param( \WP_REST_Request $request, string $canonical, string $legacy ) {
		if ( $request->has_param( $canonical ) ) {
			return $request->get_param( $canonical );
		}

		return $request->get_param( $legacy );
	}
}
