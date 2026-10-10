<?php

namespace AppAPI\Services;

use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductFormatter {
	public function format( \WC_Product $product, array $lookup = array() ): array {
		return $this->format_card( $product, $lookup );
	}

	/**
	 * One stable product-card contract used everywhere a product card is shown:
	 * product list, home sections and related products.
	 */
	public function format_card( \WC_Product $product, array $lookup = array() ): array {
		$presentation   = $this->card_presentation( $product, $lookup );
		$brand_taxonomy = Taxonomy::brand_taxonomy();

		return array(
			'id'               => (int) $product->get_id(),
			'name'             => (string) $product->get_name(),
			'price'            => $this->money_int( $presentation['price'] ?? 0 ),
			'regular_price'    => $this->money_int( $presentation['regular_price'] ?? 0 ),
			'discount_percent' => (int) ( $presentation['discount_percent'] ?? 0 ),
			'stock_quantity'   => $this->stock_quantity( $product, $presentation['selected_product'], $lookup ),
			'image'            => $this->image_url( (int) ( $presentation['image_id'] ?: $product->get_image_id() ) ),
			'variation_name'   => (string) ( $presentation['variation_name'] ?? '' ),
			'total_sales'      => (int) $product->get_total_sales(),
			'average_rating'   => $this->rating_string( $product->get_average_rating() ),
			'categories'       => Taxonomy::terms_with_images( $product->get_id(), 'product_cat' ),
			'brand'            => $brand_taxonomy ? Taxonomy::first_term_with_image( $product->get_id(), $brand_taxonomy ) : array( 'id' => 0, 'name' => '', 'image' => '' ),
			'colors'           => $this->variation_colors( $product ),
		);
	}

	public function format_list_item( \WC_Product $product, array $lookup = array() ): array {
		return $this->format_card( $product, $lookup );
	}

	/**
	 * Full product details. Variable products expose grouped selectable variation
	 * attributes and only the numeric ID of the manager's default variation.
	 */
	public function format_detail( \WC_Product $product, array $lookup = array() ): array {
		$default_variation = null;
		$variations        = array();

		if ( $product instanceof \WC_Product_Variable ) {
			$default_variation = $this->default_variation( $product );
			$presentation      = $default_variation
				? $this->variation_presentation( $default_variation, $product )
				: $this->variable_parent_fallback_presentation( $product, $lookup );
			$variations = $this->grouped_variations( $product );
		} else {
			$presentation = $this->simple_presentation( $product );
		}

		$brand_taxonomy = Taxonomy::brand_taxonomy();

		// Order is intentional: PHP preserves insertion order when JSON is encoded.
		return array(
			'id'                => (int) $product->get_id(),
			'sku'               => (string) $product->get_sku(),
			'name'              => (string) $product->get_name(),
			'description'       => $this->description( $product ),
			'price'             => $this->money_int( $presentation['price'] ?? 0 ),
			'regular_price'     => $this->money_int( $presentation['regular_price'] ?? 0 ),
			'discount_percent'  => (int) ( $presentation['discount_percent'] ?? 0 ),
			'stock_quantity'    => $this->stock_quantity( $product, $presentation['selected_product'], $lookup ),
			'image'             => $this->image_url( (int) ( $presentation['image_id'] ?: $product->get_image_id() ) ),
			'variation_name'    => (string) ( $presentation['variation_name'] ?? '' ),
			'average_rating'    => $this->rating_string( $product->get_average_rating() ),
			'rating_count'      => (int) $product->get_rating_count(),
			'review_count'      => (int) $product->get_review_count(),
			'total_sales'       => (int) $product->get_total_sales(),
			'categories'        => Taxonomy::terms_with_images( $product->get_id(), 'product_cat' ),
			'brand'             => $brand_taxonomy ? Taxonomy::first_term_with_image( $product->get_id(), $brand_taxonomy ) : array( 'id' => 0, 'name' => '', 'image' => '' ),
			'tags'              => Taxonomy::terms( $product->get_id(), 'product_tag' ),
			'gallery'           => $this->gallery( $product, $presentation ),
			'dimensions'        => $this->dimensions( $product ),
			'shipping_class'    => (string) $product->get_shipping_class(),
			'attributes'        => $this->attributes( $product ),
			'default_variation' => $default_variation ? (int) $default_variation->get_id() : null,
			'variations'        => $variations,
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
		$on_sale = $product->is_on_sale() && null !== $regular && null !== $price && (float) $price < (float) $regular;

		return array(
			'price'            => $price,
			'regular_price'    => $regular,
			'discount_percent' => $on_sale ? $this->discount( $regular, $price ) : 0,
			'selected_product' => $product,
			'variation_name'   => '',
			'image_id'         => $product->get_image_id(),
		);
	}

	private function variable_parent_fallback_presentation( \WC_Product_Variable $product, array $lookup ): array {
		$price = $this->lookup_decimal( $lookup, 'min_price' );
		if ( null === $price ) {
			$price = $this->decimal_or_null( $product->get_price() );
		}
		return array(
			'price'            => $price,
			'regular_price'    => $price,
			'discount_percent' => 0,
			'selected_product' => $product,
			'variation_name'   => '',
			'image_id'         => $product->get_image_id(),
		);
	}

	/** Select the published sale variation with the highest exact discount. */
	private function variable_sale_presentation( \WC_Product_Variable $product ): ?array {
		$selected = null;
		$best_ratio = -1.0;
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$price   = $this->decimal_or_null( $variation->get_price() );
			$regular = $this->decimal_or_null( $variation->get_regular_price() );
			if ( ! $variation->is_on_sale() || null === $price || null === $regular || (float) $regular <= 0 || (float) $price >= (float) $regular ) {
				continue;
			}
			$ratio = ( (float) $regular - (float) $price ) / (float) $regular;
			if ( null === $selected || $ratio > ( $best_ratio + 0.000000000001 ) ) {
				$selected   = $variation;
				$best_ratio = $ratio;
			}
		}
		return $selected instanceof \WC_Product_Variation ? $this->variation_presentation( $selected, $product ) : null;
	}

	private function variation_presentation( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		$price   = $this->decimal_or_null( $variation->get_price() );
		$regular = $this->decimal_or_null( $variation->get_regular_price() );
		if ( null === $regular && null !== $price ) {
			$regular = $price;
		}
		$on_sale = $variation->is_on_sale() && null !== $regular && null !== $price && (float) $price < (float) $regular;
		$values  = array();
		foreach ( $this->variation_attribute_values( $variation, $parent ) as $value ) {
			$values[] = $value['name'];
		}

		return array(
			'price'            => $price,
			'regular_price'    => $regular,
			'discount_percent' => $on_sale ? $this->discount( $regular, $price ) : 0,
			'selected_product' => $variation,
			'variation_name'   => implode( ' | ', $values ),
			'image_id'         => $variation->get_image_id() ?: $parent->get_image_id(),
		);
	}

	private function default_variation( \WC_Product_Variable $product ): ?\WC_Product_Variation {
		$default_attributes = $product->get_default_attributes();
		$variation_id       = 0;
		if ( $default_attributes ) {
			$match_attributes = array();
			foreach ( $default_attributes as $name => $value ) {
				$key = 0 === strpos( (string) $name, 'attribute_' ) ? (string) $name : 'attribute_' . sanitize_title( (string) $name );
				$match_attributes[ $key ] = (string) $value;
			}
			try {
				$data_store   = \WC_Data_Store::load( 'product' );
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

	/**
	 * Groups actual WooCommerce variation records by each variable attribute.
	 * Option IDs are variation IDs, which lets the client immediately load the
	 * matching price/stock/product image after a selection.
	 */
	private function grouped_variations( \WC_Product_Variable $product ): array {
		$groups = array();
		$group_order = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key = $attribute->get_name();
			$groups[ $key ] = array(
				'id'      => (int) $attribute->get_id(),
				'name'    => function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $key, $product ) : $key,
				'options' => array(),
			);
			$group_order[] = $key;
		}

		if ( ! $groups ) {
			return array();
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$presentation = $this->variation_presentation( $variation, $product );
			foreach ( $this->variation_attribute_values( $variation, $product ) as $value ) {
				$key = $value['taxonomy'];
				if ( ! isset( $groups[ $key ] ) ) {
					continue;
				}
				$groups[ $key ]['options'][] = array(
					'id'               => (int) $variation->get_id(),
					'name'             => (string) $value['name'],
					'sku'              => (string) $variation->get_sku(),
					'color'            => (string) $value['color'],
					'image'            => (string) $value['image'],
					'price'            => $this->money_int( $presentation['price'] ?? 0 ),
					'regular_price'    => $this->money_int( $presentation['regular_price'] ?? 0 ),
					'discount_percent' => (int) ( $presentation['discount_percent'] ?? 0 ),
					'stock_quantity'   => $this->stock_quantity( $product, $variation, array() ),
					'product_image'    => $this->image_url( (int) $presentation['image_id'] ),
				);
			}
		}

		$result = array();
		foreach ( $group_order as $key ) {
			if ( ! empty( $groups[ $key ]['options'] ) ) {
				$result[] = $groups[ $key ];
			}
		}
		return $result;
	}

	private function variation_attribute_values( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		$result = array();
		foreach ( $variation->get_attributes() as $name => $raw_value ) {
			if ( '' === (string) $raw_value ) {
				continue;
			}
			$taxonomy = str_replace( 'attribute_', '', (string) $name );
			$display   = (string) $raw_value;
			$color     = '';
			$image     = '';
			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', (string) $raw_value, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$display = (string) $term->name;
					$color   = Taxonomy::term_color( $term );
					$image   = Taxonomy::term_image_url( $term );
				}
			}
			$result[] = array(
				'taxonomy' => $taxonomy,
				'name'     => $display,
				'color'    => $color,
				'image'    => $image,
			);
		}
		return $result;
	}

	private function variation_colors( \WC_Product $product ): array {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return array();
		}
		$colors = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() || ! $attribute->is_taxonomy() ) {
				continue;
			}
			$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$color = Taxonomy::term_color( $term );
				if ( '' !== $color && ! in_array( $color, $colors, true ) ) {
					$colors[] = $color;
				}
			}
		}
		return $colors;
	}

	private function lookup_on_sale( \WC_Product $product, array $lookup ): bool {
		if ( array_key_exists( 'onsale', $lookup ) && 1 === (int) $lookup['onsale'] ) {
			return true;
		}
		return $product->is_on_sale();
	}

	private function stock_quantity( \WC_Product $parent, \WC_Product $selected, array $lookup ) {
		if ( ! $selected->is_in_stock() ) {
			return 0;
		}
		if ( $selected->managing_stock() ) {
			$quantity = $selected->get_stock_quantity();
			return null !== $quantity && (float) $quantity > 0 ? $this->stock_number( $quantity ) : 1;
		}
		if ( $selected->get_id() !== $parent->get_id() && $parent->managing_stock() ) {
			if ( ! $parent->is_in_stock() ) {
				return 0;
			}
			$quantity = $parent->get_stock_quantity();
			return null !== $quantity && (float) $quantity > 0 ? $this->stock_number( $quantity ) : 1;
		}
		if ( $selected->get_id() === $parent->get_id() ) {
			if ( isset( $lookup['stock_status'] ) && 'outofstock' === $lookup['stock_status'] ) {
				return 0;
			}
			if ( array_key_exists( 'stock_quantity', $lookup ) && null !== $lookup['stock_quantity'] && (float) $lookup['stock_quantity'] > 0 ) {
				return $this->stock_number( $lookup['stock_quantity'] );
			}
		}
		return 1;
	}

	private function stock_number( $quantity ) {
		$quantity = (float) $quantity;
		return floor( $quantity ) === $quantity ? (int) $quantity : $quantity;
	}

	private function image_url( int $image_id ): string {
		$image = $image_id ? Taxonomy::attachment_image_url( $image_id ) : '';
		if ( ! $image ) {
			$image = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'full' ) : '';
		}
		return (string) $image;
	}

	private function description( \WC_Product $product ): string {
		$description = (string) $product->get_description();
		return '' === trim( $description ) ? '' : (string) wp_kses_post( apply_filters( 'the_content', $description ) );
	}

	private function gallery( \WC_Product $product, array $presentation ): array {
		$image_ids = array();
		if ( ! empty( $presentation['image_id'] ) ) {
			$image_ids[] = absint( $presentation['image_id'] );
		}
		if ( $product->get_image_id() ) {
			$image_ids[] = absint( $product->get_image_id() );
		}
		foreach ( $product->get_gallery_image_ids() as $gallery_image_id ) {
			$image_ids[] = absint( $gallery_image_id );
		}
		$urls = array();
		foreach ( array_values( array_filter( array_unique( $image_ids ) ) ) as $image_id ) {
			$url = $this->image_url( $image_id );
			if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	private function dimensions( \WC_Product $product ): array {
		return array(
			'length' => $this->decimal_or_null( $product->get_length() ),
			'width'  => $this->decimal_or_null( $product->get_width() ),
			'height' => $this->decimal_or_null( $product->get_height() ),
		);
	}

	private function attributes( \WC_Product $product ): array {
		$result = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$name    = $attribute->get_name();
			$options = array();
			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) {
					$options = array_values( array_map( 'strval', $terms ) );
				}
			} else {
				$options = array_values( array_map( 'strval', $attribute->get_options() ) );
			}
			$result[] = array(
				'name'    => function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name, $product ) : $name,
				'visible' => (bool) $attribute->get_visible(),
				'options' => $options,
			);
		}
		return $result;
	}

	private function lookup_decimal( array $lookup, string $key ): ?string {
		if ( ! array_key_exists( $key, $lookup ) || '' === $lookup[ $key ] || null === $lookup[ $key ] ) {
			return null;
		}
		return $this->decimal_or_null( $lookup[ $key ] );
	}

	private function money_int( $value ): int {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return 0;
		}
		return max( 0, (int) round( (float) $value ) );
	}

	private function decimal_or_null( $value ): ?string {
		if ( '' === $value || null === $value ) {
			return null;
		}
		return wc_format_decimal( $value, wc_get_price_decimals() );
	}

	private function rating_string( $value ): string {
		return number_format( max( 0, (float) $value ), 1, '.', '' );
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
