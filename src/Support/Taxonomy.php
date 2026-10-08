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

	/**
	 * Returns the original/full taxonomy image when the active taxonomy stores one.
	 * Core WooCommerce Brands uses thumbnail_id; several established brand plugins
	 * use other term-meta keys, so the resolver accepts the common formats too.
	 */
	public static function term_image_url( \WP_Term $term ): string {
		if ( 'product_brand' === $term->taxonomy && function_exists( 'wc_get_brand_thumbnail_url' ) ) {
			$url = wc_get_brand_thumbnail_url( (int) $term->term_id, 'full' );
			if ( $url ) {
				return self::original_image_from_url( (string) $url );
			}
		}

		$keys = array(
			'thumbnail_id', 'image_id', 'brand_image_id', 'product_brand_image_id',
			'pwb_brand_image', 'yith_wcbr_image', 'berocket_term_thumbnail_id',
			'logo_id', 'logo', 'image', 'brand_logo', 'brand_image',
		);

		foreach ( $keys as $key ) {
			$url = self::image_value_url( get_term_meta( $term->term_id, $key, true ) );
			if ( $url ) {
				return $url;
			}
		}

		$all_meta = get_term_meta( $term->term_id );
		foreach ( $all_meta as $key => $values ) {
			if ( ! preg_match( '/(?:image|thumbnail|logo)/i', (string) $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$url = self::image_value_url( maybe_unserialize( $value ) );
				if ( $url ) {
					return $url;
				}
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
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return self::attachment_image_url( (int) $value );
		}

		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return self::original_image_from_url( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( array( 'id', 'attachment_id', 'image_id' ) as $id_key ) {
				if ( ! empty( $value[ $id_key ] ) ) {
					$url = self::attachment_image_url( absint( $value[ $id_key ] ) );
					if ( $url ) {
						return $url;
					}
				}
			}
			foreach ( array( 'url', 'image', 'src' ) as $url_key ) {
				if ( ! empty( $value[ $url_key ] ) && filter_var( $value[ $url_key ], FILTER_VALIDATE_URL ) ) {
					return self::original_image_from_url( (string) $value[ $url_key ] );
				}
			}
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
