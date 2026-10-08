<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductFilterBuilder {
	public function build(): array {
		return array(
			'category'  => $this->categories(),
			'brand'     => $this->brands(),
			'attribute' => $this->attributes(),
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
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$by_parent[ (int) $term->parent ][] = $term;
		}

		return $this->category_children( 0, $by_parent, array() );
	}

	private function category_children( int $parent_id, array $by_parent, array $visited ): array {
		if ( isset( $visited[ $parent_id ] ) ) {
			return array();
		}
		$visited[ $parent_id ] = true;
		$result = array();

		foreach ( $by_parent[ $parent_id ] ?? array() as $term ) {
			$result[] = array(
				'id'       => (int) $term->term_id,
				'name'     => (string) $term->name,
				'image'    => Taxonomy::term_image_url( $term ),
				'children' => $this->category_children( (int) $term->term_id, $by_parent, $visited ),
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
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$attributes = wc_get_attribute_taxonomies();
		$result     = array();

		foreach ( (array) $attributes as $attribute ) {
			if ( ! is_object( $attribute ) || empty( $attribute->attribute_public ) ) {
				continue;
			}

			$taxonomy = function_exists( 'wc_attribute_taxonomy_name' )
				? wc_attribute_taxonomy_name( (string) $attribute->attribute_name )
				: 'pa_' . sanitize_title( (string) $attribute->attribute_name );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = $this->attribute_terms( $taxonomy, (string) ( $attribute->attribute_orderby ?? 'menu_order' ) );
			$options = array();
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$options[] = array(
					'id'    => (int) $term->term_id,
					'name'  => (string) $term->name,
					'value' => (string) $term->slug,
					'color' => $this->term_color( $term, $taxonomy ),
				);
			}

			$result[] = array(
				'id'      => (int) $attribute->attribute_id,
				'name'    => (string) $attribute->attribute_label,
				'slug'    => (string) $attribute->attribute_name,
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

	private function term_color( \WP_Term $term, string $taxonomy ): ?string {
		$visual_class = '\\Automattic\\WooCommerce\\Internal\\ProductAttributes\\VisualAttributeTermMeta';
		if ( class_exists( $visual_class ) && is_callable( array( $visual_class, 'is_visual_attribute_taxonomy' ) ) && is_callable( array( $visual_class, 'get_term_visual' ) ) ) {
			try {
				if ( $visual_class::is_visual_attribute_taxonomy( $taxonomy ) ) {
					$color = $this->find_hex_color( $visual_class::get_term_visual( (int) $term->term_id ) );
					if ( $color ) {
						return $color;
					}
				}
			} catch ( \Throwable $exception ) {
				// Fall through to generic term-meta discovery for older/newer stores.
			}
		}

		$preferred_keys = array(
			'color', 'colour', 'term_color', 'swatch_color', 'attribute_color',
			'product_attribute_color', 'woodmart_attribute_color', 'wd_color',
			'woodmart_color', 'wvs_color', 'woo_variation_swatches_color',
		);
		foreach ( $preferred_keys as $key ) {
			$color = $this->find_hex_color( get_term_meta( $term->term_id, $key, true ) );
			if ( $color ) {
				return $color;
			}
		}

		foreach ( (array) get_term_meta( $term->term_id ) as $key => $values ) {
			if ( ! preg_match( '/(?:color|colour|swatch)/i', (string) $key ) ) {
				continue;
			}
			$color = $this->find_hex_color( array_map( 'maybe_unserialize', (array) $values ) );
			if ( $color ) {
				return $color;
			}
		}

		return null;
	}

	private function find_hex_color( $value ): ?string {
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $item ) {
				$found = $this->find_hex_color( $item );
				if ( $found ) {
					return $found;
				}
			}
			return null;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );
		if ( preg_match( '/#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/', $text, $match ) ) {
			$hex = strtoupper( $match[0] );
			if ( 4 === strlen( $hex ) ) {
				$hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
			}
			return $hex;
		}

		return null;
	}
}
