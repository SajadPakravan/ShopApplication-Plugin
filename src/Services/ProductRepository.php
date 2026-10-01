<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductRepository {
	private $context = array();
	private $query_token = '';

	public function query( array $params ): array {
		$this->context     = $params;
		$this->query_token = wp_generate_uuid4();

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => (int) $params['per_page'],
			'paged'                  => (int) $params['page'],
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
			'orderby'                => 'none',
			'app_api_query_token'    => $this->query_token,
		);

		$tax_query = $this->build_tax_query( $params );
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		add_filter( 'posts_clauses', array( $this, 'filter_clauses' ), 20, 2 );

		try {
			$query = new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_clauses', array( $this, 'filter_clauses' ), 20 );
			$this->context     = array();
			$this->query_token = '';
		}

		$products = array();
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products[] = $product;
			}
		}

		return array(
			'products' => $products,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	public function filter_clauses( array $clauses, \WP_Query $query ): array {
		if ( $query->get( 'app_api_query_token' ) !== $this->query_token ) {
			return $clauses;
		}

		global $wpdb;

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$lookup_alias = 'app_api_lookup';

		if ( false === strpos( $clauses['join'], $lookup_alias ) ) {
			$clauses['join'] .= " LEFT JOIN {$lookup_table} {$lookup_alias} ON {$wpdb->posts}.ID = {$lookup_alias}.product_id ";
		}

		$search = isset( $this->context['search'] ) ? trim( (string) $this->context['search'] ) : '';
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses['where'] .= $wpdb->prepare(
				" AND (
					{$wpdb->posts}.post_title LIKE %s
					OR {$lookup_alias}.sku LIKE %s
					OR EXISTS (
						SELECT 1
						FROM {$wpdb->posts} app_api_variation
						INNER JOIN {$lookup_table} app_api_variation_lookup
							ON app_api_variation_lookup.product_id = app_api_variation.ID
						WHERE app_api_variation.post_parent = {$wpdb->posts}.ID
							AND app_api_variation.post_type = 'product_variation'
							AND app_api_variation_lookup.sku LIKE %s
					)
				) ",
				$like,
				$like,
				$like
			);
		}

		if ( null !== $this->context['on_sale'] ) {
			$clauses['where'] .= $wpdb->prepare( " AND {$lookup_alias}.onsale = %d ", $this->context['on_sale'] ? 1 : 0 );
		}

		if ( null !== $this->context['min_price'] ) {
			$clauses['where'] .= $wpdb->prepare( " AND {$lookup_alias}.max_price >= %f ", $this->context['min_price'] );
		}

		if ( null !== $this->context['max_price'] ) {
			$clauses['where'] .= $wpdb->prepare( " AND {$lookup_alias}.min_price <= %f ", $this->context['max_price'] );
		}

		$order   = 'asc' === $this->context['order'] ? 'ASC' : 'DESC';
		$orderby = $this->context['orderby'];

		switch ( $orderby ) {
			case 'price':
				$primary = "CASE WHEN {$lookup_alias}.min_price IS NULL THEN 1 ELSE 0 END ASC, {$lookup_alias}.min_price {$order}";
				break;
			case 'rating':
				$primary = "{$lookup_alias}.average_rating {$order}";
				break;
			case 'popularity':
				$primary = "{$lookup_alias}.total_sales {$order}";
				break;
			case 'id':
				$primary = "{$wpdb->posts}.ID {$order}";
				break;
			case 'title':
				$primary = "{$wpdb->posts}.post_title {$order}";
				break;
			case 'date':
			default:
				$primary = "{$wpdb->posts}.post_date {$order}";
				break;
		}

		$clauses['orderby'] = $primary . ", {$wpdb->posts}.ID {$order}";
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}

	private function build_tax_query( array $params ): array {
		$queries = array();

		if ( $params['categories'] ) {
			$queries[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $params['categories'],
				'operator'         => 'IN',
				'include_children' => true,
			);
		}

		if ( $params['tags'] ) {
			$queries[] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $params['tags'],
				'operator' => 'IN',
			);
		}

		if ( $params['brands'] ) {
			$brand_taxonomy = Taxonomy::brand_taxonomy();
			if ( $brand_taxonomy ) {
				$queries[] = array(
					'taxonomy' => $brand_taxonomy,
					'field'    => 'term_id',
					'terms'    => $params['brands'],
					'operator' => 'IN',
				);
			}
		}

		if ( $params['types'] ) {
			$queries[] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $params['types'],
				'operator' => 'IN',
			);
		}

		if ( count( $queries ) > 1 ) {
			$queries['relation'] = 'AND';
		}

		return $queries;
	}
}
