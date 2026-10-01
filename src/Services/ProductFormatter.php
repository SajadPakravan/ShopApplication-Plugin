<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductFormatter {
	/**
	 * Backward-compatible alias for product-card formatting.
	 */
	public function format( \WC_Product $product, array $lookup = array() ): array {
		return $this->format_card( $product, $lookup );
	}

	/**
	 * Compact representation used by product lists and home-page product rows.
	 */
	public function format_card( \WC_Product $product, array $lookup = array() ): array {
		$presentation = $this->price_presentation( $product, $lookup );
		return $this->card_data( $product, $presentation, $lookup );
	}

	/**
	 * Current product-detail representation. The detail endpoint may be expanded
	 * later without making product-card responses heavier.
	 */
	public function format_detail( \WC_Product $product, array $lookup = array() ): array {
		$presentation = $this->price_presentation( $product, $lookup );
		$selected     = $presentation['selected_product'];
		$image_id     = $presentation['image_id'] ?: $product->get_image_id();
		$data         = $this->card_data( $product, $presentation, $lookup );

		$data['variation_id']         = $selected instanceof \WC_Product_Variation ? $selected->get_id() : null;
		$data['variation_sku']        = $selected instanceof \WC_Product_Variation ? $selected->get_sku() : null;
		$data['variation_attributes'] = $presentation['variation_attributes'];
		$data['stock_status']         = $selected->get_stock_status();
		$data['image_id']             = $image_id ? (int) $image_id : null;

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

	private function price_presentation( \WC_Product $product, array $lookup ): array {
		if ( $product->is_type( 'variable' ) && $product instanceof \WC_Product_Variable ) {
			if ( $this->lookup_on_sale( $product, $lookup ) ) {
				$sale_presentation = $this->variable_sale_presentation( $product );
				if ( null !== $sale_presentation ) {
					return $sale_presentation;
				}
			}

			return $this->variable_default_presentation( $product, $lookup );
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
	 * Non-sale variable products intentionally do not load their variation list.
	 * Their synchronized minimum price comes from wc_product_meta_lookup and the
	 * parent image/stock data are used for the card.
	 */
	private function variable_default_presentation( \WC_Product_Variable $product, array $lookup ): array {
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
		$selected       = null;
		$selected_price = null;
		$selected_regular = null;
		$best_ratio     = -1.0;

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

		$attributes = $this->variation_attributes( $selected, $product );
		$image_id   = $selected->get_image_id() ?: $product->get_image_id();

		return array(
			'price'                => $selected_price,
			'regular_price'        => $selected_regular,
			'discount_percent'     => $this->discount( $selected_regular, $selected_price ),
			'on_sale'              => true,
			'selected_product'     => $selected,
			'variation_name'       => $attributes ? implode( ' | ', wp_list_pluck( $attributes, 'value' ) ) : null,
			'variation_attributes' => $attributes,
			'image_id'             => $image_id,
		);
	}

	private function lookup_on_sale( \WC_Product $product, array $lookup ): bool {
		if ( array_key_exists( 'onsale', $lookup ) && null !== $lookup['onsale'] ) {
			return 1 === (int) $lookup['onsale'];
		}

		// Fallback for the single-product endpoint if lookup data is unavailable.
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

	private function stock_quantity( \WC_Product $parent, \WC_Product $selected, array $lookup ): ?float {
		if ( $selected->managing_stock() ) {
			$quantity = $selected->get_stock_quantity();
			return null === $quantity ? null : (float) $quantity;
		}

		if ( $parent->managing_stock() ) {
			$quantity = $parent->get_stock_quantity();
			return null === $quantity ? null : (float) $quantity;
		}

		if ( $selected->get_id() === $parent->get_id() && isset( $lookup['stock_quantity'] ) && null !== $lookup['stock_quantity'] ) {
			return (float) $lookup['stock_quantity'];
		}

		return null;
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
