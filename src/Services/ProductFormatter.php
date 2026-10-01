<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductFormatter {
	public function format( \WC_Product $product ): array {
		$presentation  = $this->price_presentation( $product );
		$stock_product = $presentation['variation'] instanceof \WC_Product_Variation
			? $presentation['variation']
			: $product;

		$image_id = $presentation['image_id'] ?: $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

		if ( ! $image ) {
			$image = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
		}

		$brand_taxonomy = Taxonomy::brand_taxonomy();

		return array(
			'id'                  => $product->get_id(),
			'type'                => $product->get_type(),
			'name'                => $product->get_name(),
			'slug'                => $product->get_slug(),
			'sku'                 => $product->get_sku(),
			'permalink'           => $product->get_permalink(),
			'price'               => $presentation['price'],
			'regular_price'       => $presentation['regular_price'],
			'sale_price'          => $presentation['sale_price'],
			'price_range'         => $presentation['price_range'],
			'discount_percent'    => $presentation['discount_percent'],
			'on_sale'             => $presentation['on_sale'],
			'variation_id'        => $presentation['variation_id'],
			'variation_name'      => $presentation['variation_name'],
			'variation_sku'       => $presentation['variation_sku'],
			'variation_attributes'=> $presentation['variation_attributes'],
			'stock_status'        => $stock_product->get_stock_status(),
			'stock_quantity'      => $this->stock_quantity( $product, $stock_product ),
			'image'               => $image,
			'image_id'            => $image_id ? (int) $image_id : null,
			'average_rating'      => (string) $product->get_average_rating(),
			'rating_count'        => (int) $product->get_rating_count(),
			'review_count'        => (int) $product->get_review_count(),
			'total_sales'         => (int) $product->get_total_sales(),
			'categories'          => Taxonomy::terms( $product->get_id(), 'product_cat' ),
			'brands'              => $brand_taxonomy ? Taxonomy::terms( $product->get_id(), $brand_taxonomy ) : array(),
			'tags'                => Taxonomy::terms( $product->get_id(), 'product_tag' ),
		);
	}

	private function price_presentation( \WC_Product $product ): array {
		if ( $product->is_type( 'variable' ) && $product instanceof \WC_Product_Variable ) {
			return $this->variable_price_presentation( $product );
		}

		$price   = $this->decimal_or_null( $product->get_price() );
		$regular = $this->decimal_or_null( $product->get_regular_price() );
		$sale    = $this->decimal_or_null( $product->get_sale_price() );
		$on_sale = null !== $sale && null !== $regular && (float) $sale < (float) $regular;

		return array(
			'price'                => $price,
			'regular_price'        => $regular,
			'sale_price'           => $on_sale ? $sale : null,
			'price_range'          => array(
				'min'         => $price,
				'max'         => $price,
				'regular_min' => $regular,
				'regular_max' => $regular,
			),
			'discount_percent'     => $this->discount( $regular, $price ),
			'on_sale'              => $on_sale,
			'variation'            => null,
			'variation_id'         => null,
			'variation_name'       => null,
			'variation_sku'        => null,
			'variation_attributes' => array(),
			'image_id'             => $product->get_image_id(),
		);
	}

	private function variable_price_presentation( \WC_Product_Variable $product ): array {
		$prices      = $product->get_variation_prices( true );
		$visible_ids = array_map( 'absint', $product->get_visible_children() );
		$candidates  = array();
		$has_sale    = false;

		foreach ( $prices['price'] as $variation_id => $raw_price ) {
			$variation_id = (int) $variation_id;
			if ( $visible_ids && ! in_array( $variation_id, $visible_ids, true ) ) {
				continue;
			}

			$price   = (float) $raw_price;
			$regular = isset( $prices['regular_price'][ $variation_id ] ) && '' !== $prices['regular_price'][ $variation_id ]
				? (float) $prices['regular_price'][ $variation_id ]
				: $price;
			$is_sale = $regular > 0 && $price < $regular;
			$has_sale = $has_sale || $is_sale;

			$candidates[] = array(
				'id'       => $variation_id,
				'price'    => $price,
				'regular'  => $regular,
				'is_sale'  => $is_sale,
				'discount' => $is_sale ? $this->discount( $regular, $price ) : 0,
			);
		}

		if ( $has_sale ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( array $candidate ): bool {
						return $candidate['is_sale'];
					}
				)
			);
		}

		usort(
			$candidates,
			static function ( array $left, array $right ) use ( $has_sale ): int {
				if ( $has_sale && $left['discount'] !== $right['discount'] ) {
					return $right['discount'] <=> $left['discount'];
				}

				if ( $left['price'] !== $right['price'] ) {
					return $left['price'] <=> $right['price'];
				}

				return $left['id'] <=> $right['id'];
			}
		);

		$variation = null;
		$fallback  = null;

		foreach ( $candidates as $candidate ) {
			$current = wc_get_product( $candidate['id'] );
			if ( ! $current instanceof \WC_Product_Variation ) {
				continue;
			}

			if ( null === $fallback ) {
				$fallback = $current;
			}

			if ( $current->is_purchasable() && $current->is_in_stock() ) {
				$variation = $current;
				break;
			}
		}

		if ( ! $variation ) {
			$variation = $fallback;
		}

		$price = $variation
			? $this->decimal_or_null( $variation->get_price() )
			: $this->decimal_or_null( $product->get_variation_price( 'min', true ) );
		$regular = $variation
			? $this->decimal_or_null( $variation->get_regular_price() )
			: $this->decimal_or_null( $product->get_variation_regular_price( 'min', true ) );

		if ( null === $regular ) {
			$regular = $price;
		}

		$sale    = $variation ? $this->decimal_or_null( $variation->get_sale_price() ) : null;
		$on_sale = null !== $regular && null !== $price && (float) $price < (float) $regular;
		$attributes = $variation ? $this->variation_attributes( $variation, $product ) : array();

		return array(
			'price'                => $price,
			'regular_price'        => $regular,
			'sale_price'           => $on_sale ? ( $sale ?: $price ) : null,
			'price_range'          => array(
				'min'         => $this->decimal_or_null( $product->get_variation_price( 'min', true ) ),
				'max'         => $this->decimal_or_null( $product->get_variation_price( 'max', true ) ),
				'regular_min' => $this->decimal_or_null( $product->get_variation_regular_price( 'min', true ) ),
				'regular_max' => $this->decimal_or_null( $product->get_variation_regular_price( 'max', true ) ),
			),
			'discount_percent'     => $this->discount( $regular, $price ),
			'on_sale'              => $on_sale,
			'variation'            => $variation,
			'variation_id'         => $variation ? $variation->get_id() : null,
			'variation_name'       => $attributes ? implode( ' | ', wp_list_pluck( $attributes, 'value' ) ) : null,
			'variation_sku'        => $variation ? $variation->get_sku() : null,
			'variation_attributes' => $attributes,
			'image_id'             => $variation && $variation->get_image_id() ? $variation->get_image_id() : $product->get_image_id(),
		);
	}

	private function variation_attributes( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		$result = array();

		foreach ( $variation->get_attributes() as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}

			$taxonomy = str_replace( 'attribute_', '', $name );
			$label    = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy, $parent ) : $taxonomy;
			$display  = $value;

			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$display = $term->name;
				}
			}

			$result[] = array(
				'name'  => $taxonomy,
				'label' => $label,
				'value' => $display,
			);
		}

		return $result;
	}

	private function stock_quantity( \WC_Product $parent, \WC_Product $selected ): ?float {
		if ( $selected->managing_stock() ) {
			$quantity = $selected->get_stock_quantity();
			return null === $quantity ? null : (float) $quantity;
		}

		if ( $parent->managing_stock() ) {
			$quantity = $parent->get_stock_quantity();
			return null === $quantity ? null : (float) $quantity;
		}

		return null;
	}

	private function decimal_or_null( $value ): ?string {
		if ( '' === $value || null === $value ) {
			return null;
		}

		return wc_format_decimal( $value, wc_get_price_decimals() );
	}

	private function discount( $regular, $price ): int {
		$regular = (float) $regular;
		$price   = (float) $price;

		if ( $regular <= 0 || $price >= $regular ) {
			return 0;
		}

		return (int) round( ( ( $regular - $price ) / $regular ) * 100 );
	}
}
