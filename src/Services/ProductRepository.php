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

		$product_ids = array_map( 'absint', $query->posts );
		$lookup      = $this->lookup_for_ids( $product_ids );
		$products    = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products[] = $product;
			}
		}

		return array(
			'products' => $products,
			'lookup'   => $lookup,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	/**
	 * Returns one parent-product lookup row without loading its variations.
	 */
	public function lookup( int $product_id ): array {
		$rows = $this->lookup_for_ids( array( $product_id ) );
		return $rows[ $product_id ] ?? array();
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
							AND app_api_variation.post_status = 'publish'
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

		/*
		 * Availability is always the first sort key. This must happen in SQL,
		 * before LIMIT/OFFSET, so unavailable products are placed after all
		 * available products across the complete result set and not merely at the
		 * bottom of the current page.
		 */
		$stock_bucket = "CASE
			WHEN {$lookup_alias}.product_id IS NULL THEN 1
			WHEN {$lookup_alias}.stock_status = 'outofstock' THEN 1
			WHEN {$lookup_alias}.stock_quantity IS NOT NULL AND {$lookup_alias}.stock_quantity <= 0 THEN 1
			ELSE 0
		END";

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

		$clauses['orderby'] = $stock_bucket . " ASC, " . $primary . ", {$wpdb->posts}.ID {$order}";
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}

	private function build_tax_query( array $params ): array {
		$queries = array();

		if ( $params['category'] ) {
			$queries[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $params['category'],
				'operator'         => 'IN',
				'include_children' => true,
			);
		}

		if ( $params['tag'] ) {
			$queries[] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $params['tag'],
				'operator' => 'IN',
			);
		}

		if ( $params['brand'] ) {
			$brand_taxonomy = Taxonomy::brand_taxonomy();
			if ( $brand_taxonomy ) {
				$queries[] = array(
					'taxonomy' => $brand_taxonomy,
					'field'    => 'term_id',
					'terms'    => $params['brand'],
					'operator' => 'IN',
				);
			}
		}

		if ( $params['type'] ) {
			$queries[] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => array( $params['type'] ),
				'operator' => 'IN',
			);
		}

		if ( count( $queries ) > 1 ) {
			$queries['relation'] = 'AND';
		}

		return $queries;
	}

	/**
	 * Fetches lookup data for the current page in one query, avoiding an N+1
	 * lookup query and avoiding variation loading for non-sale variable cards.
	 */
	private function lookup_for_ids( array $product_ids ): array {
		$product_ids = array_values( array_filter( array_unique( array_map( 'absint', $product_ids ) ) ) );
		if ( ! $product_ids ) {
			return array();
		}

		global $wpdb;

		$table        = $wpdb->prefix . 'wc_product_meta_lookup';
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$sql          = "SELECT product_id, sku, min_price, max_price, stock_quantity, stock_status, onsale
			FROM {$table}
			WHERE product_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $product_ids ), ARRAY_A );
		$map  = array();

		foreach ( $rows as $row ) {
			$product_id         = (int) $row['product_id'];
			$map[ $product_id ] = $row;
		}

		return $map;
	}
}
