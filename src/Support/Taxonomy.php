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

	/**
	 * Archive-enabled global WooCommerce attributes available to storefront filters.
	 * The result is keyed by numeric WooCommerce attribute ID.
	 */
	public static function filterable_attributes(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $cache;
		}

		foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! is_object( $attribute ) || empty( $attribute->attribute_public ) ) {
				continue;
			}

			$id       = absint( $attribute->attribute_id ?? 0 );
			$name     = (string) ( $attribute->attribute_name ?? '' );
			$taxonomy = function_exists( 'wc_attribute_taxonomy_name' )
				? wc_attribute_taxonomy_name( $name )
				: 'pa_' . sanitize_title( $name );

			if ( ! $id || ! $name || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$cache[ $id ] = array(
				'id'       => $id,
				'name'     => (string) ( $attribute->attribute_label ?? $name ),
				'taxonomy' => $taxonomy,
				'orderby'  => (string) ( $attribute->attribute_orderby ?? 'menu_order' ),
			);
		}

		return $cache;
	}

	/**
	 * Finds a swatch color stored by common WooCommerce attribute/swatch plugins.
	 * Empty string is returned when the term has no color metadata.
	 */
	public static function term_color( \WP_Term $term ): string {
		$keys = array(
			'color', '_color', 'colour', '_colour', 'swatch_color', 'swatch_colour',
			'product_attribute_color', 'attribute_color', 'wvs_color', 'woo_variation_swatches_color',
			'woodmart_color', 'wd_color', 'term_color', 'pa_color',
		);

		foreach ( $keys as $key ) {
			$color = self::color_value( maybe_unserialize( get_term_meta( $term->term_id, $key, true ) ) );
			if ( $color ) {
				return $color;
			}
		}

		foreach ( get_term_meta( $term->term_id ) as $key => $values ) {
			if ( ! preg_match( '/colou?r|swatch/i', (string) $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$color = self::color_value( maybe_unserialize( $value ) );
				if ( $color ) {
					return $color;
				}
			}
		}

		return '';
	}

	public static function terms_with_images( int $product_id, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $product_id, $taxonomy );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$result = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$result[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'image' => self::term_image_url( $term ),
			);
		}
		return $result;
	}

	public static function first_term_with_image( int $product_id, string $taxonomy ): array {
		$terms = self::terms_with_images( $product_id, $taxonomy );
		return $terms ? $terms[0] : array( 'id' => 0, 'name' => '', 'image' => '' );
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

	/**
	 * Returns the original/full taxonomy image when the active taxonomy stores one.
	 * Core WooCommerce Brands uses thumbnail_id; several established brand plugins
	 * use other term-meta keys, so the resolver accepts the common formats too.
	 */
	public static function term_image_url( \WP_Term $term ): string {
		// Brand-specific helpers must only be called for the active brand taxonomy;
		// term IDs are not globally unique across taxonomies.
		$brand_taxonomy = self::brand_taxonomy();
		if ( $brand_taxonomy && $term->taxonomy === $brand_taxonomy ) {
			if ( function_exists( 'wc_get_brand_thumbnail_url' ) ) {
				$url = wc_get_brand_thumbnail_url( (int) $term->term_id, 'full' );
				if ( $url ) {
					return self::original_image_from_url( (string) $url );
				}
			}
			if ( function_exists( 'get_brand_thumbnail_url' ) ) {
				$url = get_brand_thumbnail_url( (int) $term->term_id, 'full' );
				if ( $url ) {
					return self::original_image_from_url( (string) $url );
				}
			}
		}

		$keys = array(
			'thumbnail_id', '_thumbnail_id', 'image_id', 'brand_image_id', 'brand_logo_id',
			'product_brand_image_id', 'pwb_brand_image', 'pwb_brand_logo', 'yith_wcbr_image',
			'berocket_term_thumbnail_id', 'logo_id', 'logo', 'image', 'term_image',
			'brand_logo', 'brand_image', 'woodmart_image', 'woodmart_brand_image',
		);

		foreach ( $keys as $key ) {
			$url = self::image_value_url( maybe_unserialize( get_term_meta( $term->term_id, $key, true ) ) );
			if ( $url ) {
				return $url;
			}
		}

		$all_meta = get_term_meta( $term->term_id );
		foreach ( $all_meta as $key => $values ) {
			if ( ! preg_match( '/(?:image|thumbnail|logo|brand)/i', (string) $key ) ) {
				continue;
			}
			$url = self::image_value_url( array_map( 'maybe_unserialize', (array) $values ) );
			if ( $url ) {
				return $url;
			}
		}

		// Some older taxonomy/brand plugins store term fields in an option array.
		$option_keys = array(
			$term->taxonomy . '_' . $term->term_id,
			'taxonomy_' . $term->term_id,
			'brand_' . $term->term_id,
			'product_brand_' . $term->term_id,
		);
		foreach ( $option_keys as $option_key ) {
			$url = self::image_value_url( get_option( $option_key, null ) );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}

	public static function attachment_image_url( int $attachment_id ): string {
		if ( ! $attachment_id ) {
			return '';
		}

		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$url = wp_get_original_image_url( $attachment_id );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( $url ) {
			return esc_url_raw( $url );
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		return $url ? esc_url_raw( $url ) : '';
	}

	public static function original_image_from_url( string $url ): string {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return '';
		}

		$attachment_id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0;
		if ( ! $attachment_id ) {
			$without_size = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+(?:$|\?))/i', '', $url );
			if ( is_string( $without_size ) && $without_size !== $url && function_exists( 'attachment_url_to_postid' ) ) {
				$attachment_id = attachment_url_to_postid( $without_size );
			}
		}

		return $attachment_id ? self::attachment_image_url( (int) $attachment_id ) : $url;
	}

	private static function image_value_url( $value ): string {
		$value = maybe_unserialize( $value );

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return self::attachment_image_url( (int) $value );
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return self::original_image_from_url( $value );
			}
			if ( preg_match( '/https?:\/\/[^\s"\']+/i', $value, $match ) ) {
				return self::original_image_from_url( $match[0] );
			}
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			$priority = array( 'id', 'ID', 'attachment_id', 'image_id', 'thumbnail_id', 'logo_id', 'url', 'image', 'src', 'logo' );
			foreach ( $priority as $key ) {
				if ( array_key_exists( $key, $value ) ) {
					$url = self::image_value_url( $value[ $key ] );
					if ( $url ) {
						return $url;
					}
				}
			}
			foreach ( $value as $nested ) {
				$url = self::image_value_url( $nested );
				if ( $url ) {
					return $url;
				}
			}
		}

		return '';
	}


	private static function color_value( $value ): string {
		$value = maybe_unserialize( $value );

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			foreach ( array( 'color', 'colour', 'value', 'hex', 'swatch_color' ) as $key ) {
				if ( array_key_exists( $key, $value ) ) {
					$color = self::color_value( $value[ $key ] );
					if ( $color ) {
						return $color;
					}
				}
			}
			return '';
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		if ( preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return strtolower( $value );
		}
		if ( preg_match( '/^(?:rgb|rgba|hsl|hsla)\([^\r\n]+\)$/i', $value ) ) {
			return sanitize_text_field( $value );
		}

		return '';
	}

	/**
	 * Recounts published parent products for taxonomy terms in one SQL query.
	 * This avoids relying on stale term->count values from third-party brand taxonomies.
	 */
	public static function published_product_counts( string $taxonomy, array $term_ids ): array {
		$term_ids = array_values( array_filter( array_unique( array_map( 'absint', $term_ids ) ) ) );
		if ( ! taxonomy_exists( $taxonomy ) || ! $term_ids ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$sql = "SELECT tt.term_id, COUNT(DISTINCT p.ID) AS product_count
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s
				AND tt.term_id IN ({$placeholders})
				AND p.post_type = 'product'
				AND p.post_status = 'publish'
			GROUP BY tt.term_id";

		$params = array_merge( array( $taxonomy ), $term_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$map  = array_fill_keys( $term_ids, 0 );
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['term_id'] ] = (int) $row['product_count'];
		}
		return $map;
	}
}
