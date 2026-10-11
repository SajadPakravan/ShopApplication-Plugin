<?php

namespace AppAPI\Controllers;

use AppAPI\Services\CategoryPageBuilder;
use AppAPI\Support\Response;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class CategoryController {
	public function show( \WP_REST_Request $request ) {
		$category_id = absint( $request['id'] );
		$term = get_term( $category_id, 'product_cat' );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'app_api_category_not_found', __( 'Category not found.', 'application-api' ), array( 'status' => 404 ) );
		}
		$counts = Taxonomy::published_product_counts( 'product_cat', array( $category_id ) );
		$data = array(
			'id'       => $category_id,
			'name'     => (string) $term->name,
			'image'    => Taxonomy::term_image_url( $term ),
			'count'    => (int) ( $counts[ $category_id ] ?? $term->count ),
			'sections' => ( new CategoryPageBuilder() )->build( $category_id ),
		);
		return Response::success( array( 'success' => true, 'data' => $data ) );
	}
}
