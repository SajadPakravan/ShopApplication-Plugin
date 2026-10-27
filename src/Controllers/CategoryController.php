<?php

namespace AppAPI\Controllers;

use AppAPI\Services\CategoryPageBuilder;
use AppAPI\Services\CategoryDetailBuilder;
use AppAPI\Support\Response;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class CategoryController {
	/**
	 * Configurable categories landing page: /categories.
	 */
	public function index( \WP_REST_Request $request ) {
		unset( $request );
		return Response::success(
			array(
				'success'  => true,
				'sections' => ( new CategoryPageBuilder() )->build(),
			)
		);
	}

	/**
	 * Category details only: /categories/{id}.
	 */
	public function show( \WP_REST_Request $request ) {
		$category_id = absint( $request['id'] );
		$term        = get_term( $category_id, 'product_cat' );

		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error(
				'app_api_category_not_found',
				__( 'Category not found.', 'application-api' ),
				array( 'status' => 404 )
			);
		}

		$data = array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'description' => wp_kses_post( (string) $term->description ),
			'image'       => Taxonomy::term_image_url( $term ),
			'count'       => $this->category_count( $term ),
			'parents'     => $this->parent_path( $term ),
			'children'    => $this->direct_children( (int) $term->term_id ),
		);

		// Fixed semantic order after direct children: products first, brands second.
		foreach ( ( new CategoryDetailBuilder() )->build( $term ) as $key => $value ) {
			$data[ $key ] = $value;
		}

		return Response::success( array( 'success' => true, 'data' => $data ) );
	}

	private function category_count( \WP_Term $term ): int {
		$counts = Taxonomy::published_product_counts( 'product_cat', array( (int) $term->term_id ) );
		return (int) ( $counts[ (int) $term->term_id ] ?? $term->count );
	}

	/**
	 * Returns category ancestors from the top-level category down to the direct parent.
	 */
	private function parent_path( \WP_Term $term ): array {
		$ancestor_ids = get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' );
		if ( ! is_array( $ancestor_ids ) || ! $ancestor_ids ) {
			return array();
		}

		$ancestor_ids = array_reverse( array_map( 'absint', $ancestor_ids ) );
		$counts       = Taxonomy::published_product_counts( 'product_cat', $ancestor_ids );
		$result       = array();

		foreach ( $ancestor_ids as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( ! $ancestor instanceof \WP_Term ) {
				continue;
			}
			$result[] = $this->term_payload( $ancestor, $counts );
		}

		return $result;
	}

	/**
	 * Returns only the direct children of the selected category.
	 * Every item keeps the stable category-list contract and therefore includes
	 * an empty children array; deeper descendants are intentionally not nested here.
	 */
	private function direct_children( int $parent_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => $parent_id,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) || ! $terms ) {
			return array();
		}

		$this->sort_terms( $terms );
		$ids    = array_map( static fn( $item ) => (int) $item->term_id, $terms );
		$counts = Taxonomy::published_product_counts( 'product_cat', $ids );
		$result = array();

		foreach ( $terms as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$item             = $this->term_payload( $child, $counts );
			$item['children'] = array();
			$result[]         = $item;
		}

		return $result;
	}

	private function term_payload( \WP_Term $term, array $counts = array() ): array {
		$id = (int) $term->term_id;
		return array(
			'id'    => $id,
			'name'  => (string) $term->name,
			'image' => Taxonomy::term_image_url( $term ),
			'count' => (int) ( $counts[ $id ] ?? $term->count ),
		);
	}

	private function sort_terms( array &$terms ): void {
		usort(
			$terms,
			static function ( $a, $b ) {
				$a_order = $a instanceof \WP_Term ? (int) get_term_meta( $a->term_id, 'order', true ) : 0;
				$b_order = $b instanceof \WP_Term ? (int) get_term_meta( $b->term_id, 'order', true ) : 0;
				if ( $a_order === $b_order ) {
					$a_name = $a instanceof \WP_Term ? (string) $a->name : '';
					$b_name = $b instanceof \WP_Term ? (string) $b->name : '';
					return strnatcasecmp( $a_name, $b_name );
				}
				return $a_order <=> $b_order;
			}
		);
	}
}
