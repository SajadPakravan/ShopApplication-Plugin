<?php

namespace AppAPI\Support;

defined( 'ABSPATH' ) || exit;

final class Taxonomy {
	public static function brand_taxonomy(): ?string {
		$preferred = apply_filters( 'app_api_brand_taxonomy', '' );

		if ( is_string( $preferred ) && $preferred && taxonomy_exists( $preferred ) ) {
			return $preferred;
		}

		$candidates = array(
			'product_brand',
			'pwb-brand',
			'yith_product_brand',
			'berocket_brand',
			'pa_brand',
			'pa_brands',
		);

		foreach ( $candidates as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return null;
	}

	public static function terms( int $product_id, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $product_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( $term ) {
				return array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
				);
			},
			$terms
		);
	}
}
