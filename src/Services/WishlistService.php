<?php

namespace AppAPI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges Shop Application to the active store wishlist implementation.
 *
 * WoodMart is preferred when present because its logged-in wishlists are used
 * directly by the storefront. YITH is supported as a second adapter. Stores
 * without a supported provider fall back to a user-meta list so the REST
 * contract stays functional and can later be adapted by filters.
 */
final class WishlistService {
	private const FALLBACK_META = '_shop_application_favorites';

	public function product_ids( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$ids = $this->woodmart_product_ids( $user_id );
		if ( null !== $ids ) {
			return $ids;
		}

		$ids = $this->yith_product_ids( $user_id );
		if ( null !== $ids ) {
			return $ids;
		}

		$filtered = apply_filters( 'shop_application_customer_wishlist_ids', null, $user_id );
		if ( is_array( $filtered ) ) {
			return $this->normalize_ids( $filtered );
		}

		return $this->normalize_ids( get_user_meta( $user_id, self::FALLBACK_META, true ) );
	}

	public function add( int $user_id, int $product_id ) {
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );
		if ( ! $user_id || ! $this->valid_product( $product_id ) ) {
			return new \WP_Error( 'shop_application_invalid_favorite', 'محصول موردنظر برای افزودن به علاقه‌مندی‌ها معتبر نیست.', array( 'status' => 400 ) );
		}

		$result = $this->woodmart_add( $user_id, $product_id );
		if ( null !== $result ) {
			return $result;
		}

		$result = $this->yith_add( $user_id, $product_id );
		if ( null !== $result ) {
			return $result;
		}

		$ids = $this->normalize_ids( get_user_meta( $user_id, self::FALLBACK_META, true ) );
		if ( ! in_array( $product_id, $ids, true ) ) {
			$ids[] = $product_id;
			update_user_meta( $user_id, self::FALLBACK_META, $ids );
		}
		return true;
	}

	public function remove( int $user_id, int $product_id ) {
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );
		if ( ! $user_id || ! $product_id ) {
			return new \WP_Error( 'shop_application_invalid_favorite', 'شناسه محصول معتبر نیست.', array( 'status' => 400 ) );
		}

		$result = $this->woodmart_remove( $user_id, $product_id );
		if ( null !== $result ) {
			return $result;
		}

		$result = $this->yith_remove( $user_id, $product_id );
		if ( null !== $result ) {
			return $result;
		}

		$ids = array_values( array_diff( $this->normalize_ids( get_user_meta( $user_id, self::FALLBACK_META, true ) ), array( $product_id ) ) );
		update_user_meta( $user_id, self::FALLBACK_META, $ids );
		return true;
	}

	public function replace( int $user_id, array $product_ids ) {
		$product_ids = array_values( array_filter( $this->normalize_ids( $product_ids ), array( $this, 'valid_product' ) ) );
		$current     = $this->product_ids( $user_id );

		foreach ( array_diff( $current, $product_ids ) as $remove_id ) {
			$result = $this->remove( $user_id, (int) $remove_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		foreach ( array_diff( $product_ids, $current ) as $add_id ) {
			$result = $this->add( $user_id, (int) $add_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	private function woodmart_wishlist_object() {
		if ( class_exists( '\\XTS\\WC_Wishlist\\Wishlist' ) ) {
			try {
				return new \XTS\WC_Wishlist\Wishlist( false, get_current_user_id() );
			} catch ( \Throwable $e ) {
				// Fall through to the UI adapter used by some WoodMart versions.
			}
		}
		if ( class_exists( '\\XTS\\WC_Wishlist\\Ui' ) && method_exists( '\\XTS\\WC_Wishlist\\Ui', 'get_instance' ) ) {
			try {
				$ui = \XTS\WC_Wishlist\Ui::get_instance();
				if ( is_object( $ui ) && method_exists( $ui, 'get_wishlist' ) ) {
					$wishlist = $ui->get_wishlist();
					if ( is_object( $wishlist ) ) {
						return $wishlist;
					}
				}
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	private function woodmart_product_ids( int $user_id ): ?array {
		if ( get_current_user_id() === $user_id ) {
			$wishlist = $this->woodmart_wishlist_object();
			if ( $wishlist && method_exists( $wishlist, 'get_all' ) ) {
				try {
					$ids = array();
					foreach ( (array) $wishlist->get_all() as $item ) {
						if ( is_array( $item ) && ! empty( $item['product_id'] ) ) {
							$ids[] = absint( $item['product_id'] );
						}
					}
					return $this->normalize_ids( $ids );
				} catch ( \Throwable $e ) {
					// Fall through to direct database read.
				}
			}
		}

		return $this->woodmart_database_product_ids( $user_id );
	}

	private function woodmart_add( int $user_id, int $product_id ) {
		if ( get_current_user_id() !== $user_id ) {
			return null;
		}
		$wishlist = $this->woodmart_wishlist_object();
		if ( ! $wishlist || ! method_exists( $wishlist, 'add' ) ) {
			return null;
		}
		try {
			$wishlist->add( $product_id );
			if ( method_exists( $wishlist, 'update_count_cookie' ) ) {
				$wishlist->update_count_cookie();
			}
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'shop_application_wishlist_add_failed', 'افزودن محصول به علاقه‌مندی‌های سایت انجام نشد.', array( 'status' => 500 ) );
		}
	}

	private function woodmart_remove( int $user_id, int $product_id ) {
		if ( get_current_user_id() !== $user_id ) {
			return null;
		}
		$wishlist = $this->woodmart_wishlist_object();
		if ( ! $wishlist || ! method_exists( $wishlist, 'remove' ) ) {
			return null;
		}
		try {
			$method = new \ReflectionMethod( $wishlist, 'remove' );
			if ( $method->getNumberOfParameters() > 1 && method_exists( $wishlist, 'get_id' ) ) {
				$wishlist->remove( $product_id, $wishlist->get_id() );
			} else {
				$wishlist->remove( $product_id );
			}
			if ( method_exists( $wishlist, 'update_count_cookie' ) ) {
				$wishlist->update_count_cookie();
			}
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'shop_application_wishlist_remove_failed', 'حذف محصول از علاقه‌مندی‌های سایت انجام نشد.', array( 'status' => 500 ) );
		}
	}

	private function woodmart_database_product_ids( int $user_id ): ?array {
		global $wpdb;
		$wishlist_table = $this->existing_table( array( $wpdb->prefix . 'woodmart_wishlists', $wpdb->prefix . 'woodmart_wishlist' ) );
		$product_table  = $this->existing_table( array( $wpdb->prefix . 'woodmart_wishlist_products', $wpdb->prefix . 'woodmart_wishlists_products' ) );
		if ( ! $wishlist_table || ! $product_table ) {
			return null;
		}
		$wishlist_cols = $this->table_columns( $wishlist_table );
		$product_cols  = $this->table_columns( $product_table );
		$user_col      = $this->first_column( $wishlist_cols, array( 'user_id', 'customer_id' ) );
		$list_id_col   = $this->first_column( $wishlist_cols, array( 'id', 'ID', 'wishlist_id' ) );
		$product_list  = $this->first_column( $product_cols, array( 'wishlist_id', 'list_id' ) );
		$product_id    = $this->first_column( $product_cols, array( 'product_id', 'prod_id' ) );
		if ( ! $user_col || ! $list_id_col || ! $product_list || ! $product_id ) {
			return null;
		}
		$list_ids = $wpdb->get_col( $wpdb->prepare( "SELECT `{$list_id_col}` FROM `{$wishlist_table}` WHERE `{$user_col}` = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $list_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
		$sql          = "SELECT `{$product_id}` FROM `{$product_table}` WHERE `{$product_list}` IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows         = $wpdb->get_col( $wpdb->prepare( $sql, array_map( 'absint', $list_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->normalize_ids( $rows );
	}

	private function yith_wishlist_object() {
		if ( class_exists( 'YITH_WCWL_Wishlist_Factory' ) && method_exists( 'YITH_WCWL_Wishlist_Factory', 'get_current_wishlist' ) ) {
			try {
				return \YITH_WCWL_Wishlist_Factory::get_current_wishlist();
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	private function yith_product_ids( int $user_id ): ?array {
		if ( get_current_user_id() !== $user_id || ! class_exists( 'YITH_WCWL_Wishlist_Factory' ) ) {
			return null;
		}
		$wishlist = $this->yith_wishlist_object();
		if ( ! is_object( $wishlist ) || ! method_exists( $wishlist, 'get_items' ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) $wishlist->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
				$ids[] = absint( $item->get_product_id() );
			}
		}
		return $this->normalize_ids( $ids );
	}

	private function yith_add( int $user_id, int $product_id ) {
		if ( get_current_user_id() !== $user_id || ! class_exists( 'YITH_WCWL_Wishlist_Factory' ) ) {
			return null;
		}
		$wishlist = $this->yith_wishlist_object();
		if ( ! $wishlist || ! method_exists( $wishlist, 'add_product' ) ) {
			return null;
		}
		try {
			$wishlist->add_product( $product_id );
			if ( method_exists( $wishlist, 'save' ) ) {
				$wishlist->save();
			}
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'shop_application_wishlist_add_failed', 'افزودن محصول به علاقه‌مندی‌های سایت انجام نشد.', array( 'status' => 500 ) );
		}
	}

	private function yith_remove( int $user_id, int $product_id ) {
		if ( get_current_user_id() !== $user_id || ! class_exists( 'YITH_WCWL_Wishlist_Factory' ) ) {
			return null;
		}
		$wishlist = $this->yith_wishlist_object();
		if ( ! $wishlist || ! method_exists( $wishlist, 'remove_product' ) ) {
			return null;
		}
		try {
			$wishlist->remove_product( $product_id );
			if ( method_exists( $wishlist, 'save' ) ) {
				$wishlist->save();
			}
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'shop_application_wishlist_remove_failed', 'حذف محصول از علاقه‌مندی‌های سایت انجام نشد.', array( 'status' => 500 ) );
		}
	}

	private function existing_table( array $candidates ): string {
		global $wpdb;
		foreach ( $candidates as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				return $table;
			}
		}
		return '';
	}

	private function table_columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_values( array_filter( array_column( $rows, 'Field' ) ) ) : array();
	}

	private function first_column( array $columns, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private function normalize_ids( $values ): array {
		$values = is_array( $values ) ? $values : array();
		$ids = array();
		foreach ( $values as $value ) {
			$value = absint( $value );
			if ( $value && ! in_array( $value, $ids, true ) ) {
				$ids[] = $value;
			}
		}
		return $ids;
	}

	private function valid_product( $product_id ): bool {
		$product_id = absint( $product_id );
		return $product_id > 0 && 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id );
	}
}
