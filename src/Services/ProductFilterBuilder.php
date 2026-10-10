<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductFilterBuilder {
	public function build(): array {
		return array(
			'categories' => $this->categories(),
			'brands'     => $this->brands(),
			'attributes' => $this->attributes(),
		);
	}

	private function categories(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => 'order',
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();
		$term_ids  = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$by_parent[ (int) $term->parent ][] = $term;
			$term_ids[] = (int) $term->term_id;
		}

		$counts = Taxonomy::published_product_counts( 'product_cat', $term_ids );

		return $this->category_children( 0, $by_parent, array(), $counts );
	}

	private function category_children( int $parent_id, array $by_parent, array $visited, array $counts ): array {
		if ( isset( $visited[ $parent_id ] ) ) {
			return array();
		}
		$visited[ $parent_id ] = true;
		$result = array();

		foreach ( $by_parent[ $parent_id ] ?? array() as $term ) {
			$result[] = array(
				'id'       => (int) $term->term_id,
				'name'     => (string) $term->name,
				'count'    => (int) ( $counts[ (int) $term->term_id ] ?? $term->count ),
				'image'    => Taxonomy::term_image_url( $term ),
				'children' => $this->category_children( (int) $term->term_id, $by_parent, $visited, $counts ),
			);
		}

		return $result;
	}

	private function brands(): array {
		$taxonomy = Taxonomy::brand_taxonomy();
		if ( ! $taxonomy ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$ids    = wp_list_pluck( $terms, 'term_id' );
		$counts = Taxonomy::published_product_counts( $taxonomy, $ids );
		$result = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$result[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'count' => (int) ( $counts[ (int) $term->term_id ] ?? $term->count ),
				'image' => Taxonomy::term_image_url( $term ),
			);
		}

		return $result;
	}

	/**
	 * Only global attributes whose WooCommerce "Enable archives" flag is active
	 * are exposed as storefront filters. This mirrors the manager's intent and
	 * automatically includes attributes added later.
	 */
	private function attributes(): array {
		$result = array();

		foreach ( Taxonomy::filterable_attributes() as $attribute ) {
			$taxonomy = (string) $attribute['taxonomy'];
			$terms    = $this->attribute_terms( $taxonomy, (string) $attribute['orderby'] );
			$options  = array();

			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$options[] = array(
					'id'    => (int) $term->term_id,
					'name'  => (string) $term->name,
					'color' => Taxonomy::term_color( $term ),
					'image' => Taxonomy::term_image_url( $term ),
				);
			}

			$result[] = array(
				'id'      => (int) $attribute['id'],
				'name'    => (string) $attribute['name'],
				'options' => $options,
			);
		}

		return $result;
	}

	private function attribute_terms( string $taxonomy, string $orderby ): array {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		switch ( $orderby ) {
			case 'name':
				$args['orderby'] = 'name';
				$args['order']   = 'ASC';
				break;
			case 'id':
				$args['orderby'] = 'term_id';
				$args['order']   = 'ASC';
				break;
			case 'name_num':
				$args['orderby'] = 'name';
				$args['order']   = 'ASC';
				break;
			case 'menu_order':
			default:
				$args['meta_key'] = 'order';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				break;
		}

		$terms = get_terms( $args );
		return is_wp_error( $terms ) || ! is_array( $terms ) ? array() : $terms;
	}

}