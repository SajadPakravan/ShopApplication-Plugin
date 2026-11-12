<?php

namespace AppAPI\Services;

defined( 'ABSPATH' ) || exit;

final class CustomerService {
	private const VIEWED_META = '_shop_application_viewed_products';
	private const MAX_VIEWED_PRODUCTS = 100;

	private $formatter;
	private $wishlist;

	public function __construct( ?ProductFormatter $formatter = null, ?WishlistService $wishlist = null ) {
		$this->formatter = $formatter ?: new ProductFormatter();
		$this->wishlist  = $wishlist ?: new WishlistService();
	}

	/**
	 * Customer profile only. Account collections are exposed by dedicated REST endpoints.
	 */
	public function get_customer( int $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'shop_application_customer_not_found',
				'مشتری موردنظر پیدا نشد.',
				array( 'status' => 404 )
			);
		}

		if ( ! class_exists( 'WC_Customer' ) ) {
			return new \WP_Error(
				'shop_application_woocommerce_unavailable',
				'ووکامرس در دسترس نیست.',
				array( 'status' => 503 )
			);
		}

		$customer = new \WC_Customer( $user_id );

		return array(
			'id'                    => $user_id,
			'username'              => (string) $user->user_login,
			'first_name'            => (string) $customer->get_first_name(),
			'last_name'             => (string) $customer->get_last_name(),
			'display_name'          => (string) $user->display_name,
			'email'                 => (string) $customer->get_email(),
			'phone'                 => $this->phone( $user_id, $customer ),
			'avatar'                => (string) get_avatar_url( $user_id, array( 'size' => 512 ) ),
			'date_created'          => $this->date_only( $user->user_registered ),
			'billing'               => $this->billing_address( $customer ),
			'shipping'              => $this->shipping_address( $customer ),
			'orders_count'          => function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user_id ) : count( $this->orders( $user_id ) ),
			'cart_count'            => $this->cart_quantity( $this->persistent_cart( $user_id ) ),
			'favorites_count'       => count( $this->wishlist->product_ids( $user_id ) ),
			'viewed_products_count' => count( $this->viewed_product_ids( $user_id ) ),
			'comments_count'        => $this->comments_count( $user_id ),
		);
	}

	public function update_customer( int $user_id, array $data ) {
		$user_id  = absint( $user_id );
		$user     = get_user_by( 'id', $user_id );
		$customer = class_exists( 'WC_Customer' ) ? new \WC_Customer( $user_id ) : null;
		if ( ! $user instanceof \WP_User || ! $customer instanceof \WC_Customer ) {
			return new \WP_Error( 'shop_application_customer_not_found', 'مشتری موردنظر پیدا نشد.', array( 'status' => 404 ) );
		}

		$wp_update = array( 'ID' => $user_id );
		if ( array_key_exists( 'email', $data ) ) {
			$email = sanitize_email( (string) $data['email'] );
			if ( '' !== $email && ! is_email( $email ) ) {
				return new \WP_Error( 'shop_application_invalid_email', 'ایمیل واردشده معتبر نیست.', array( 'status' => 400 ) );
			}
			$existing = $email ? email_exists( $email ) : false;
			if ( $existing && (int) $existing !== $user_id ) {
				return new \WP_Error( 'shop_application_email_exists', 'این ایمیل قبلاً برای حساب دیگری ثبت شده است.', array( 'status' => 409 ) );
			}
			$wp_update['user_email'] = $email;
		}
		if ( array_key_exists( 'display_name', $data ) ) {
			$wp_update['display_name'] = sanitize_text_field( (string) $data['display_name'] );
		}
		if ( count( $wp_update ) > 1 ) {
			$result = wp_update_user( $wp_update );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'first_name', $data ) ) {
			$customer->set_first_name( sanitize_text_field( (string) $data['first_name'] ) );
		}
		if ( array_key_exists( 'last_name', $data ) ) {
			$customer->set_last_name( sanitize_text_field( (string) $data['last_name'] ) );
		}
		if ( array_key_exists( 'email', $data ) ) {
			$customer->set_email( sanitize_email( (string) $data['email'] ) );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			$phone = sanitize_text_field( (string) $data['phone'] );
			$customer->set_billing_phone( $phone );
			update_user_meta( $user_id, 'app_api_mobile', $phone );
		}

		if ( isset( $data['billing'] ) && is_array( $data['billing'] ) ) {
			$this->apply_customer_address( $customer, $data['billing'], 'billing' );
		}
		if ( isset( $data['shipping'] ) && is_array( $data['shipping'] ) ) {
			$this->apply_customer_address( $customer, $data['shipping'], 'shipping' );
		}
		$customer->save();

		return $this->get_customer( $user_id );
	}

	public function orders( int $user_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => absint( $user_id ),
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		$result = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$result[] = $this->format_order( $order );
			}
		}
		return $result;
	}

	public function create_order( int $user_id, array $data ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new \WP_Error( 'shop_application_woocommerce_unavailable', 'ووکامرس در دسترس نیست.', array( 'status' => 503 ) );
		}
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		if ( ! $items ) {
			return new \WP_Error( 'shop_application_order_items_required', 'برای ایجاد سفارش حداقل یک محصول لازم است.', array( 'status' => 400 ) );
		}
		try {
			$order = wc_create_order( array( 'customer_id' => absint( $user_id ) ) );
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$product_id   = absint( $item['id'] ?? $item['product_id'] ?? 0 );
				$variation_id = absint( $item['variation_id'] ?? 0 );
				$quantity     = max( 1, absint( $item['quantity'] ?? 1 ) );
				$product      = wc_get_product( $variation_id ?: $product_id );
				if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
					continue;
				}
				$order->add_product( $product, $quantity );
			}
			if ( 0 === count( $order->get_items() ) ) {
				$order->delete( true );
				return new \WP_Error( 'shop_application_order_items_invalid', 'هیچ محصول معتبری برای ایجاد سفارش دریافت نشد.', array( 'status' => 400 ) );
			}
			$customer = new \WC_Customer( $user_id );
			$order->set_address( $this->billing_address( $customer ), 'billing' );
			$order->set_address( $this->shipping_address( $customer ), 'shipping' );
			if ( ! empty( $data['customer_note'] ) ) {
				$order->set_customer_note( sanitize_textarea_field( (string) $data['customer_note'] ) );
			}
			if ( ! empty( $data['payment_method'] ) ) {
				$order->set_payment_method( sanitize_text_field( (string) $data['payment_method'] ) );
			}
			$order->calculate_totals();
			$order->save();
			return $this->format_order( $order );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'shop_application_order_create_failed', 'ایجاد سفارش انجام نشد.', array( 'status' => 500 ) );
		}
	}

	public function update_order( int $user_id, int $order_id, array $data ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== absint( $user_id ) ) {
			return new \WP_Error( 'shop_application_order_not_found', 'سفارش موردنظر پیدا نشد.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'failed' ), true ) ) {
			return new \WP_Error( 'shop_application_order_not_editable', 'این سفارش در وضعیت فعلی قابل ویرایش نیست.', array( 'status' => 409 ) );
		}
		if ( ! empty( $data['cancel'] ) ) {
			$order->update_status( 'cancelled', 'لغو سفارش توسط مشتری از طریق اپلیکیشن.' );
			return $this->format_order( $order );
		}
		if ( array_key_exists( 'customer_note', $data ) ) {
			$order->set_customer_note( sanitize_textarea_field( (string) $data['customer_note'] ) );
		}
		if ( isset( $data['billing'] ) && is_array( $data['billing'] ) ) {
			$order->set_address( $this->sanitize_address_array( $data['billing'], true ), 'billing' );
		}
		if ( isset( $data['shipping'] ) && is_array( $data['shipping'] ) ) {
			$order->set_address( $this->sanitize_address_array( $data['shipping'], false ), 'shipping' );
		}
		$order->save();
		return $this->format_order( $order );
	}

	public function persistent_cart( int $user_id ): array {
		$raw_items = $this->raw_persistent_cart( $user_id );
		$result    = array();
		foreach ( $raw_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id   = absint( $item['product_id'] ?? 0 );
			$variation_id = absint( $item['variation_id'] ?? 0 );
			$product      = wc_get_product( $variation_id ?: $product_id );
			$parent       = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product || ! $parent instanceof \WC_Product ) {
				continue;
			}
			$result[] = array(
				'id'        => $product_id,
				'name'      => (string) $parent->get_name(),
				'quantity'  => max( 0, (int) ( $item['quantity'] ?? 0 ) ),
				'price'     => $this->money_int( $product->get_price() ),
				'image'     => $this->product_image( $product ),
				'variation' => $this->variation_data( $product ),
			);
		}
		return $result;
	}

	public function add_cart_item( int $user_id, array $data ) {
		$normalized = $this->normalize_cart_product( $data );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$cart     = $this->raw_persistent_cart( $user_id );
		$cart_key = $this->cart_key( $normalized['product_id'], $normalized['variation_id'], $normalized['variation'] );
		$quantity = max( 1, absint( $data['quantity'] ?? 1 ) );
		if ( isset( $cart[ $cart_key ] ) && is_array( $cart[ $cart_key ] ) ) {
			$cart[ $cart_key ]['quantity'] = max( 1, (int) ( $cart[ $cart_key ]['quantity'] ?? 0 ) + $quantity );
		} else {
			$product = wc_get_product( $normalized['variation_id'] ?: $normalized['product_id'] );
			$cart[ $cart_key ] = array(
				'product_id'   => $normalized['product_id'],
				'variation_id' => $normalized['variation_id'],
				'variation'    => $normalized['variation'],
				'quantity'     => $quantity,
				'data_hash'    => function_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product ) : '',
			);
		}
		$this->save_persistent_cart( $user_id, $cart );
		return $this->persistent_cart( $user_id );
	}

	public function update_cart_item( int $user_id, array $data ) {
		$product_id = absint( $data['id'] ?? $data['product_id'] ?? 0 );
		if ( ! $product_id ) {
			return new \WP_Error( 'shop_application_cart_product_required', 'شناسه محصول برای ویرایش سبد خرید لازم است.', array( 'status' => 400 ) );
		}
		$quantity  = isset( $data['quantity'] ) ? max( 0, (int) $data['quantity'] ) : null;
		$variation = isset( $data['variation'] ) && is_array( $data['variation'] ) ? $data['variation'] : array();
		$cart      = $this->raw_persistent_cart( $user_id );
		$found     = false;
		foreach ( $cart as $key => $item ) {
			if ( ! $this->cart_item_matches( $item, $product_id, $variation ) ) {
				continue;
			}
			$found = true;
			if ( null !== $quantity ) {
				if ( $quantity <= 0 ) {
					unset( $cart[ $key ] );
				} else {
					$cart[ $key ]['quantity'] = $quantity;
				}
			}
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'shop_application_cart_item_not_found', 'این محصول در سبد خرید پیدا نشد.', array( 'status' => 404 ) );
		}
		$this->save_persistent_cart( $user_id, $cart );
		return $this->persistent_cart( $user_id );
	}

	public function remove_cart_item( int $user_id, array $data ) {
		$data['quantity'] = 0;
		return $this->update_cart_item( $user_id, $data );
	}

	public function favorites( int $user_id ): array {
		$result = array();
		foreach ( $this->wishlist->product_ids( $user_id ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product && 'publish' === get_post_status( $product_id ) ) {
				$result[] = $this->formatter->format_card( $product );
			}
		}
		return $result;
	}

	public function add_favorite( int $user_id, int $product_id ) {
		$result = $this->wishlist->add( $user_id, $product_id );
		return is_wp_error( $result ) ? $result : $this->favorites( $user_id );
	}

	public function replace_favorites( int $user_id, array $product_ids ) {
		$result = $this->wishlist->replace( $user_id, $product_ids );
		return is_wp_error( $result ) ? $result : $this->favorites( $user_id );
	}

	public function remove_favorite( int $user_id, int $product_id ) {
		$result = $this->wishlist->remove( $user_id, $product_id );
		return is_wp_error( $result ) ? $result : $this->favorites( $user_id );
	}

	public function viewed_products( int $user_id ): array {
		$result = array();
		foreach ( $this->viewed_product_ids( $user_id ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product && 'publish' === get_post_status( $product_id ) ) {
				$result[] = $this->formatter->format_card( $product );
			}
		}
		return $result;
	}

	public function record_viewed_product( int $user_id, int $product_id ): void {
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );
		if ( ! $user_id || ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$items = get_user_meta( $user_id, self::VIEWED_META, true );
		$items = is_array( $items ) ? $items : array();
		$normalized = array();
		$is_list = empty( $items ) || array_keys( $items ) === range( 0, count( $items ) - 1 );
		foreach ( $items as $key => $value ) {
			if ( $is_list && is_numeric( $value ) ) {
				$normalized[ absint( $value ) ] = 0;
			} elseif ( is_numeric( $key ) && is_numeric( $value ) ) {
				$normalized[ absint( $key ) ] = (int) $value;
			}
		}
		$normalized[ $product_id ] = time();
		arsort( $normalized, SORT_NUMERIC );
		$normalized = array_slice( $normalized, 0, self::MAX_VIEWED_PRODUCTS, true );
		update_user_meta( $user_id, self::VIEWED_META, $normalized );
	}

	public function comments( int $user_id ): array {
		$comments = get_comments(
			array(
				'user_id'   => absint( $user_id ),
				'post_type' => 'product',
				'status'    => 'all',
				'orderby'   => 'comment_date_gmt',
				'order'     => 'DESC',
				'number'    => 0,
			)
		);
		$result = array();
		foreach ( $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$result[] = $this->format_comment( $comment );
			}
		}
		return $result;
	}

	public function add_comment( int $user_id, array $data ) {
		$user       = get_user_by( 'id', $user_id );
		$product_id = absint( $data['product_id'] ?? $data['id'] ?? 0 );
		$content    = sanitize_textarea_field( (string) ( $data['content'] ?? '' ) );
		$rating     = isset( $data['rating'] ) ? max( 0, min( 5, absint( $data['rating'] ) ) ) : 0;
		if ( ! $user instanceof \WP_User || 'product' !== get_post_type( $product_id ) || '' === trim( $content ) ) {
			return new \WP_Error( 'shop_application_invalid_comment', 'محصول و متن دیدگاه باید معتبر باشند.', array( 'status' => 400 ) );
		}
		$comment_id = wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_content'      => $content,
				'comment_type'         => 'review',
				'user_id'              => $user_id,
				'comment_author'       => $user->display_name ?: $user->user_login,
				'comment_author_email' => $user->user_email,
				'comment_agent'        => 'Shop Application API',
			),
			true
		);
		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}
		if ( $rating > 0 ) {
			update_comment_meta( $comment_id, 'rating', $rating );
		}
		$comment = get_comment( $comment_id );
		return $comment instanceof \WP_Comment ? $this->format_comment( $comment ) : array();
	}

	public function update_comment( int $user_id, int $comment_id, array $data ) {
		$comment = get_comment( absint( $comment_id ) );
		if ( ! $comment instanceof \WP_Comment || (int) $comment->user_id !== absint( $user_id ) ) {
			return new \WP_Error( 'shop_application_comment_not_found', 'دیدگاه موردنظر پیدا نشد.', array( 'status' => 404 ) );
		}
		$update = array( 'comment_ID' => $comment->comment_ID );
		if ( array_key_exists( 'content', $data ) ) {
			$content = sanitize_textarea_field( (string) $data['content'] );
			if ( '' === trim( $content ) ) {
				return new \WP_Error( 'shop_application_invalid_comment', 'متن دیدگاه نمی‌تواند خالی باشد.', array( 'status' => 400 ) );
			}
			$update['comment_content'] = $content;
		}
		$result = wp_update_comment( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array_key_exists( 'rating', $data ) ) {
			$rating = max( 0, min( 5, absint( $data['rating'] ) ) );
			update_comment_meta( $comment->comment_ID, 'rating', $rating );
		}
		return $this->format_comment( get_comment( $comment->comment_ID ) );
	}

	public function delete_comment( int $user_id, int $comment_id ) {
		$comment = get_comment( absint( $comment_id ) );
		if ( ! $comment instanceof \WP_Comment || (int) $comment->user_id !== absint( $user_id ) ) {
			return new \WP_Error( 'shop_application_comment_not_found', 'دیدگاه موردنظر پیدا نشد.', array( 'status' => 404 ) );
		}
		wp_delete_comment( $comment->comment_ID, true );
		return $this->comments( $user_id );
	}

	private function format_order( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			$parent     = wc_get_product( $product_id );
			$product    = $item->get_product();
			$quantity   = max( 1, (int) $item->get_quantity() );
			$items[] = array(
				'id'        => $product_id,
				'name'      => $parent instanceof \WC_Product ? (string) $parent->get_name() : (string) $item->get_name(),
				'quantity'  => $quantity,
				'price'     => $this->money_int( (float) $item->get_total() / $quantity ),
				'image'     => $product instanceof \WC_Product ? $this->product_image( $product ) : '',
				'variation' => $this->variation_data( $product ),
			);
		}
		$date = $order->get_date_created();
		return array(
			'id'             => (int) $order->get_id(),
			'status'         => (string) $order->get_status(),
			'date'           => $date ? $date->date_i18n( 'Y-m-d' ) : '',
			'total'          => $this->money_int( $order->get_total() ),
			'payment_method' => (string) $order->get_payment_method_title(),
			'item_count'     => (int) $order->get_item_count(),
			'items'          => $items,
		);
	}

	private function billing_address( \WC_Customer $customer ): array {
		return array(
			'first_name' => (string) $customer->get_billing_first_name(),
			'last_name'  => (string) $customer->get_billing_last_name(),
			'company'    => (string) $customer->get_billing_company(),
			'country'    => (string) $customer->get_billing_country(),
			'state'      => (string) $customer->get_billing_state(),
			'city'       => (string) $customer->get_billing_city(),
			'address_1'  => (string) $customer->get_billing_address_1(),
			'address_2'  => (string) $customer->get_billing_address_2(),
			'postcode'   => (string) $customer->get_billing_postcode(),
			'email'      => (string) $customer->get_billing_email(),
			'phone'      => (string) $customer->get_billing_phone(),
		);
	}

	private function shipping_address( \WC_Customer $customer ): array {
		$phone = method_exists( $customer, 'get_shipping_phone' ) ? (string) $customer->get_shipping_phone() : '';
		return array(
			'first_name' => (string) $customer->get_shipping_first_name(),
			'last_name'  => (string) $customer->get_shipping_last_name(),
			'company'    => (string) $customer->get_shipping_company(),
			'country'    => (string) $customer->get_shipping_country(),
			'state'      => (string) $customer->get_shipping_state(),
			'city'       => (string) $customer->get_shipping_city(),
			'address_1'  => (string) $customer->get_shipping_address_1(),
			'address_2'  => (string) $customer->get_shipping_address_2(),
			'postcode'   => (string) $customer->get_shipping_postcode(),
			'phone'      => $phone,
		);
	}

	private function apply_customer_address( \WC_Customer $customer, array $data, string $type ): void {
		$data = $this->sanitize_address_array( $data, 'billing' === $type );
		foreach ( $data as $key => $value ) {
			$method = 'set_' . $type . '_' . $key;
			if ( method_exists( $customer, $method ) ) {
				$customer->{$method}( $value );
			}
		}
	}

	private function sanitize_address_array( array $data, bool $billing ): array {
		$keys = array( 'first_name', 'last_name', 'company', 'country', 'state', 'city', 'address_1', 'address_2', 'postcode', 'phone' );
		if ( $billing ) {
			$keys[] = 'email';
		}
		$result = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$result[ $key ] = 'email' === $key ? sanitize_email( (string) $data[ $key ] ) : sanitize_text_field( (string) $data[ $key ] );
			}
		}
		return $result;
	}

	private function normalize_cart_product( array $data ) {
		$product_id   = absint( $data['id'] ?? $data['product_id'] ?? 0 );
		$variation_id = absint( $data['variation_id'] ?? 0 );
		$product      = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'shop_application_cart_product_invalid', 'محصول موردنظر معتبر نیست.', array( 'status' => 400 ) );
		}
		if ( $product instanceof \WC_Product_Variation ) {
			$variation_id = $product->get_id();
			$product_id   = $product->get_parent_id();
			$variation    = $product->get_variation_attributes();
		} else {
			$variation_id = 0;
			$variation    = array();
		}
		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new \WP_Error( 'shop_application_cart_product_unavailable', 'این محصول در حال حاضر قابل خرید نیست.', array( 'status' => 409 ) );
		}
		return array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'variation' => $variation );
	}

	private function raw_persistent_cart( int $user_id ): array {
		$meta_key   = '_woocommerce_persistent_cart_' . get_current_blog_id();
		$persistent = get_user_meta( absint( $user_id ), $meta_key, true );
		return is_array( $persistent ) && isset( $persistent['cart'] ) && is_array( $persistent['cart'] ) ? $persistent['cart'] : array();
	}

	private function save_persistent_cart( int $user_id, array $cart ): void {
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		update_user_meta( absint( $user_id ), $meta_key, array( 'cart' => $cart ) );
		if ( absint( $user_id ) === get_current_user_id() && function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			try {
				WC()->cart->set_cart_contents( $cart );
				WC()->cart->set_session();
			} catch ( \Throwable $e ) {
				// Persistent cart remains the source of truth when no frontend WC session exists.
			}
		}
	}

	private function cart_key( int $product_id, int $variation_id, array $variation ): string {
		try {
			$cart = new \WC_Cart();
			return $cart->generate_cart_id( $product_id, $variation_id, $variation, array() );
		} catch ( \Throwable $e ) {
			return md5( $product_id . '|' . $variation_id . '|' . wp_json_encode( $variation ) );
		}
	}

	private function cart_item_matches( $item, int $product_id, array $friendly_variation ): bool {
		if ( ! is_array( $item ) || absint( $item['product_id'] ?? 0 ) !== $product_id ) {
			return false;
		}
		if ( ! $friendly_variation ) {
			return true;
		}
		$variation_id = absint( $item['variation_id'] ?? 0 );
		$product      = wc_get_product( $variation_id ?: $product_id );
		return $this->normalize_variation_compare( $this->variation_data( $product ) ) === $this->normalize_variation_compare( $friendly_variation );
	}

	private function normalize_variation_compare( array $variation ): array {
		$normalized = array();
		foreach ( $variation as $key => $value ) {
			$normalized[ sanitize_key( (string) $key ) ] = trim( (string) $value );
		}
		ksort( $normalized );
		return $normalized;
	}

	private function variation_data( $product ): array {
		if ( ! $product instanceof \WC_Product_Variation ) {
			return array();
		}
		$result = array();
		foreach ( $product->get_variation_attributes() as $key => $value ) {
			$taxonomy = preg_replace( '/^(attribute_)?pa_/', '', (string) $key );
			$term_taxonomy = preg_replace( '/^attribute_/', '', (string) $key );
			$display = (string) $value;
			if ( taxonomy_exists( $term_taxonomy ) ) {
				$term = get_term_by( 'slug', (string) $value, $term_taxonomy );
				if ( $term instanceof \WP_Term ) {
					$display = (string) $term->name;
				}
			}
			$result[ sanitize_key( $taxonomy ) ] = $display;
		}
		return $result;
	}

	private function viewed_product_ids( int $user_id ): array {
		$items = get_user_meta( absint( $user_id ), self::VIEWED_META, true );
		$items = is_array( $items ) ? $items : array();
		if ( ! $items ) {
			return array();
		}
		if ( array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) {
			arsort( $items, SORT_NUMERIC );
			return array_values( array_filter( array_map( 'absint', array_keys( $items ) ) ) );
		}
		return array_values( array_filter( array_map( 'absint', $items ) ) );
	}

	private function comments_count( int $user_id ): int {
		return (int) get_comments(
			array(
				'user_id'   => absint( $user_id ),
				'post_type' => 'product',
				'status'    => 'all',
				'count'     => true,
			)
		);
	}

	private function format_comment( $comment ): array {
		if ( ! $comment instanceof \WP_Comment ) {
			return array();
		}
		$product = wc_get_product( (int) $comment->comment_post_ID );
		return array(
			'id'           => (int) $comment->comment_ID,
			'product_id'   => (int) $comment->comment_post_ID,
			'product_name' => $product instanceof \WC_Product ? (string) $product->get_name() : '',
			'rating'       => (int) get_comment_meta( $comment->comment_ID, 'rating', true ),
			'content'      => (string) $comment->comment_content,
			'date'         => $this->date_only( (string) $comment->comment_date ),
			'status'       => '1' === (string) $comment->comment_approved ? 'approved' : ( 'spam' === $comment->comment_approved ? 'spam' : 'pending' ),
		);
	}

	private function phone( int $user_id, \WC_Customer $customer ): string {
		foreach ( array( 'app_api_mobile', 'billing_phone', 'mobile', 'phone' ) as $key ) {
			$value = trim( (string) get_user_meta( $user_id, $key, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return (string) $customer->get_billing_phone();
	}

	private function cart_quantity( array $cart ): int {
		$total = 0;
		foreach ( $cart as $item ) {
			$total += max( 0, (int) ( $item['quantity'] ?? 0 ) );
		}
		return $total;
	}

	private function product_image( \WC_Product $product ): string {
		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$image_id = $parent instanceof \WC_Product ? (int) $parent->get_image_id() : 0;
		}
		if ( ! $image_id ) {
			return '';
		}
		$original = wp_get_original_image_url( $image_id );
		if ( $original ) {
			return (string) $original;
		}
		$full = wp_get_attachment_image_url( $image_id, 'full' );
		return $full ? (string) $full : '';
	}

	private function money_int( $value ): int {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return 0;
		}
		return (int) round( (float) $value );
	}

	private function date_only( string $value ): string {
		$timestamp = strtotime( $value );
		return $timestamp ? wp_date( 'Y-m-d', $timestamp ) : '';
	}
}
