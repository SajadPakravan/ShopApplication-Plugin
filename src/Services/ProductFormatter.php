<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

\defined( 'ABSPATH' ) || exit;

final class ProductFormatter {
	/**
	 * Backward-compatible alias for product-card formatting.
	 */
	public function format( \WC_Product $product, array $lookup = array() ): array {
		return $this->format_card( $product, $lookup );
	}

	/**
	 * Compact representation used by product lists and home-page product rows.
	 *
	 * Variable-card rule:
	 * - on-sale product: use the published sale variation with the greatest
	 *   exact discount ratio; an exact tie keeps WooCommerce child order.
	 * - non-sale product: use the store manager's default variation.
	 */
	public function format_card( \WC_Product $product, array $lookup = array() ): array {
		$presentation = $this->card_presentation( $product, $lookup );

		return $this->card_data( $product, $presentation, $lookup );
	}

	/**
	 * Full product representation used by GET /products/{id}.
	 *
	 * A variable product always presents its configured default variation at the
	 * top level, even when another variation has a greater discount. The complete
	 * published variation list is returned separately.
	 */
	public function format_detail( \WC_Product $product, array $lookup = array() ): array {
		$default_variation = null;
		$variations        = array();

		if ( $product instanceof \WC_Product_Variable ) {
			$default_variation = $this->default_variation( $product );
			$presentation      = $default_variation
				? $this->variation_presentation( $default_variation, $product )
				: $this->variable_parent_fallback_presentation( $product, $lookup );
			$variations        = $this->format_variations( $product );
		} else {
			$presentation = $this->simple_presentation( $product );
		}

		$data                      = $this->card_data( $product, $presentation, $lookup );
		$data['default_variation'] = $default_variation
			? $this->format_variation( $default_variation, $product )
			: null;
		$data['variations']        = $variations;

		return $data;
	}

	private function card_data( \WC_Product $product, array $presentation, array $lookup ): array {
		$image_id       = $presentation['image_id'] ?: $product->get_image_id();
		$image          = $this->image_url( $image_id );
		$brand_taxonomy = Taxonomy::brand_taxonomy();

		return array(
			'id'               => $product->get_id(),
			'type'             => $product->get_type(),
			'name'             => $product->get_name(),
			'sku'              => $product->get_sku(),
			'price'            => $presentation['price'],
			'regular_price'    => $presentation['regular_price'],
			'discount_percent' => $presentation['discount_percent'],
			'on_sale'          => $presentation['on_sale'],
			'stock_quantity'   => $this->stock_quantity(
				$product,
				$presentation['selected_product'],
				$lookup
			),
			'image'            => $image,
			'variation_name'   => $presentation['variation_name'],
			'average_rating'   => (string) $product->get_average_rating(),
			'rating_count'     => (int) $product->get_rating_count(),
			'review_count'     => (int) $product->get_review_count(),
			'total_sales'      => (int) $product->get_total_sales(),
			'categories'       => Taxonomy::terms( $product->get_id(), 'product_cat' ),
			'brands'           => $brand_taxonomy ? Taxonomy::terms( $product->get_id(), $brand_taxonomy ) : array(),
			'tags'             => Taxonomy::terms( $product->get_id(), 'product_tag' ),
		);
	}

	private function card_presentation( \WC_Product $product, array $lookup ): array {
		if ( $product instanceof \WC_Product_Variable ) {
			if ( $this->lookup_on_sale( $product, $lookup ) ) {
				$sale_presentation = $this->variable_sale_presentation( $product );

				if ( null !== $sale_presentation ) {
					return $sale_presentation;
				}
			}

			$default_variation = $this->default_variation( $product );

			if ( $default_variation ) {
				return $this->variation_presentation( $default_variation, $product );
			}

			return $this->variable_parent_fallback_presentation( $product, $lookup );
		}

		return $this->simple_presentation( $product );
	}

	private function simple_presentation( \WC_Product $product ): array {
		$price   = $this->decimal_or_null( $product->get_price() );
		$regular = $this->decimal_or_null( $product->get_regular_price() );

		if ( null === $regular && null !== $price ) {
			$regular = $price;
		}

		$on_sale = $product->is_on_sale()
			&& null !== $regular
			&& null !== $price
			&& (float) $price < (float) $regular;

		return array(
			'price'                => $price,
			'regular_price'        => $regular,
			'discount_percent'     => $on_sale ? $this->discount( $regular, $price ) : 0,
			'on_sale'              => $on_sale,
			'selected_product'     => $product,
			'variation_name'       => null,
			'variation_attributes' => array(),
			'image_id'             => $product->get_image_id(),
		);
	}

	/**
	 * Safe fallback for a variable product whose default variation is missing,
	 * incomplete, deleted, or unpublished. A valid installation should normally
	 * never reach this branch.
	 */
	private function variable_parent_fallback_presentation( \WC_Product_Variable $product, array $lookup ): array {
		$price = $this->lookup_decimal( $lookup, 'min_price' );

		if ( null === $price ) {
			$price = $this->decimal_or_null( $product->get_price() );
		}

		return array(
			'price'                => $price,
			'regular_price'        => $price,
			'discount_percent'     => 0,
			'on_sale'              => false,
			'selected_product'     => $product,
			'variation_name'       => null,
			'variation_attributes' => array(),
			'image_id'             => $product->get_image_id(),
		);
	}

	/**
	 * Loads variations only for a variable product already marked on sale.
	 * The first variation with the greatest exact discount ratio wins; equal
	 * discounts preserve the original WooCommerce child order.
	 */
	private function variable_sale_presentation( \WC_Product_Variable $product ): ?array {
		$selected         = null;
		$selected_price   = null;
		$selected_regular = null;
		$best_ratio       = -1.0;

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$price   = $this->decimal_or_null( $variation->get_price() );
			$regular = $this->decimal_or_null( $variation->get_regular_price() );

			if (
				! $variation->is_on_sale()
				|| null === $price
				|| null === $regular
				|| (float) $regular <= 0
				|| (float) $price >= (float) $regular
			) {
				continue;
			}

			$ratio = ( (float) $regular - (float) $price ) / (float) $regular;

			// Strictly greater only: an exact tie keeps the first sale variation.
			if ( null === $selected || $ratio > ( $best_ratio + 0.000000000001 ) ) {
				$selected         = $variation;
				$selected_price   = $price;
				$selected_regular = $regular;
				$best_ratio       = $ratio;
			}
		}

		if ( ! $selected instanceof \WC_Product_Variation ) {
			return null;
		}

		$presentation                      = $this->variation_presentation( $selected, $product );
		$presentation['price']             = $selected_price;
		$presentation['regular_price']     = $selected_regular;
		$presentation['discount_percent']  = $this->discount( $selected_regular, $selected_price );
		$presentation['on_sale']           = true;

		return $presentation;
	}

	private function variation_presentation( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		$price      = $this->decimal_or_null( $variation->get_price() );
		$regular    = $this->decimal_or_null( $variation->get_regular_price() );
		$attributes = $this->variation_attributes( $variation, $parent );
		$image_id   = $variation->get_image_id() ?: $parent->get_image_id();

		if ( null === $regular && null !== $price ) {
			$regular = $price;
		}

		$on_sale = $variation->is_on_sale()
			&& null !== $regular
			&& null !== $price
			&& (float) $price < (float) $regular;

		return array(
			'price'                => $price,
			'regular_price'        => $regular,
			'discount_percent'     => $on_sale ? $this->discount( $regular, $price ) : 0,
			'on_sale'              => $on_sale,
			'selected_product'     => $variation,
			'variation_name'       => $attributes ? implode( ' | ', wp_list_pluck( $attributes, 'value' ) ) : $variation->get_name(),
			'variation_attributes' => $attributes,
			'image_id'             => $image_id,
		);
	}

	/**
	 * Resolves the exact default variation configured by the store manager.
	 * If defaults are incomplete or stale, the first published variation is used
	 * as a defensive fallback so the mobile API never loses its initial state.
	 */
	private function default_variation( \WC_Product_Variable $product ): ?\WC_Product_Variation {
		$default_attributes = $product->get_default_attributes();
		$variation_id       = 0;

		if ( $default_attributes ) {
			$match_attributes = array();

			foreach ( $default_attributes as $name => $value ) {
				$key                      = 0 === strpos( (string) $name, 'attribute_' )
					? (string) $name
					: 'attribute_' . sanitize_title( (string) $name );
				$match_attributes[ $key ] = (string) $value;
			}

			try {
				$data_store = \WC_Data_Store::load( 'product' );
				$variation_id = (int) $data_store->find_matching_product_variation( $product, $match_attributes );
			} catch ( \Throwable $exception ) {
				$variation_id = 0;
			}
		}

		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( $variation instanceof \WC_Product_Variation && 'publish' === $variation->get_status() ) {
				return $variation;
			}
		}

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( $variation instanceof \WC_Product_Variation && 'publish' === $variation->get_status() ) {
				return $variation;
			}
		}

		return null;
	}

	private function format_variations( \WC_Product_Variable $product ): array {
		$result = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$result[] = $this->format_variation( $variation, $product );
		}

		return $result;
	}

	private function format_variation( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		$presentation = $this->variation_presentation( $variation, $parent );

		return array(
			'id'               => $variation->get_id(),
			'name'             => $variation->get_name(),
			'sku'              => $variation->get_sku(),
			'price'            => $presentation['price'],
			'regular_price'    => $presentation['regular_price'],
			'discount_percent' => $presentation['discount_percent'],
			'on_sale'          => $presentation['on_sale'],
			'stock_quantity'   => $this->stock_quantity( $parent, $variation, array() ),
			'image'            => $this->image_url( $presentation['image_id'] ),
		);
	}

	private function lookup_on_sale( \WC_Product $product, array $lookup ): bool {
		if ( array_key_exists( 'onsale', $lookup ) && null !== $lookup['onsale'] ) {
			return 1 === (int) $lookup['onsale'];
		}

		return $product->is_on_sale();
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

	/**
	 * Normalized stock contract for Flutter:
	 * - 0 means unavailable/out of stock.
	 * - actual positive managed quantity is returned when WooCommerce tracks it.
	 * - 1 means available when WooCommerce does not expose a numeric quantity
	 *   (stock management disabled, inherited availability, or backorders).
	 */
	private function stock_quantity( \WC_Product $parent, \WC_Product $selected, array $lookup ) {
		if ( ! $selected->is_in_stock() ) {
			return 0;
		}

		if ( $selected->managing_stock() ) {
			$quantity = $selected->get_stock_quantity();

			if ( null !== $quantity && (float) $quantity > 0 ) {
				return $this->stock_number( $quantity );
			}

			return 1;
		}

		if ( $selected->get_id() !== $parent->get_id() && $parent->managing_stock() ) {
			if ( ! $parent->is_in_stock() ) {
				return 0;
			}

			$quantity = $parent->get_stock_quantity();

			if ( null !== $quantity && (float) $quantity > 0 ) {
				return $this->stock_number( $quantity );
			}

			return 1;
		}

		if ( $selected->get_id() === $parent->get_id() ) {
			if ( isset( $lookup['stock_status'] ) && 'outofstock' === $lookup['stock_status'] ) {
				return 0;
			}

			if ( array_key_exists( 'stock_quantity', $lookup ) && null !== $lookup['stock_quantity'] ) {
				$quantity = (float) $lookup['stock_quantity'];

				if ( $quantity > 0 ) {
					return $this->stock_number( $quantity );
				}
			}
		}

		return 1;
	}

	private function stock_number( $quantity ) {
		$quantity = (float) $quantity;

		return floor( $quantity ) === $quantity ? (int) $quantity : $quantity;
	}

	private function image_url( int $image_id ): string {
		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

		if ( ! $image ) {
			$image = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
		}

		return (string) $image;
	}

	private function lookup_decimal( array $lookup, string $key ): ?string {
		if ( ! array_key_exists( $key, $lookup ) || '' === $lookup[ $key ] || null === $lookup[ $key ] ) {
			return null;
		}

		return $this->decimal_or_null( $lookup[ $key ] );
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
