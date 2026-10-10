<?php

namespace AppAPI\Controllers;

use AppAPI\Services\CategoryPageBuilder;
use AppAPI\Support\Response;
use AppAPI\Support\Taxonomy;


defined( 'ABSPATH' ) || exit;

final class CategoryController {
	public function show( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return Response::woocommerce_unavailable();
		}

		$term = get_term( absint( $request['id'] ), 'product_cat' );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return new \WP_Error(
				'app_api_category_not_found',
				__( 'Category not found.', 'application-api' ),
				array( 'status' => 404 )
			);
		}

		$counts = Taxonomy::published_product_counts( 'product_cat', array( (int) $term->term_id ) );
		$data = array(
			'id'       => (int) $term->term_id,
			'name'     => (string) $term->name,
			'count'    => (int) ( $counts[ (int) $term->term_id ] ?? $term->count ),
			'image'    => Taxonomy::term_image_url( $term ),
			'sections' => ( new CategoryPageBuilder() )->build( $term ),
		);

		return Response::success(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}
}
