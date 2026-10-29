<?php

namespace AppAPI\Services;

defined( 'ABSPATH' ) || exit;

final class CustomerService {
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

		if ( ! function_exists( 'wc_get_customer_order_count' ) || ! class_exists( 'WC_Customer' ) ) {
			return new \WP_Error(
				'shop_application_woocommerce_unavailable',
				'ووکامرس در دسترس نیست.',
				array( 'status' => 503 )
			);
		}

		$customer = new \WC_Customer( $user_id );
		$orders   = $this->orders( $user_id );
		$cart     = $this->persistent_cart( $user_id );
		$wishlist = $this->wishlist( $user_id );
		$downloads = $this->downloads( $user_id );

		return array(
			'id'            => $user_id,
			'username'      => (string) $user->user_login,
			'first_name'    => (string) $customer->get_first_name(),
			'last_name'     => (string) $customer->get_last_name(),
			'display_name'  => (string) $user->display_name,
			'email'         => (string) $customer->get_email(),
			'phone'         => $this->phone( $user_id, $customer ),
			'avatar'        => (string) get_avatar_url( $user_id, array( 'size' => 512 ) ),
			'date_created'  => $this->date_only( $user->user_registered ),
			'billing'       => $this->billing_address( $customer ),
			'shipping'      => $this->shipping_address( $customer ),
			'orders_count'  => count( $orders ),
			'orders'        => $orders,
			'cart_count'    => $this->cart_quantity( $cart ),
			'cart'          => $cart,
			'wishlist_count'=> count( $wishlist ),
			'wishlist'      => $wishlist,
			'downloads_count' => count( $downloads ),
			'downloads'     => $downloads,
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

	private function orders( int $user_id ): array {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		$result = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$items = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product = $item->get_product();
				$items[] = array(
					'id'           => (int) $item_id,
					'product_id'   => (int) $item->get_product_id(),
					'variation_id' => (int) $item->get_variation_id(),
					'name'         => (string) $item->get_name(),
					'quantity'     => (int) $item->get_quantity(),
					'total'        => $this->money_int( $item->get_total() ),
					'image'        => $product ? $this->product_image( $product ) : '',
				);
			}

			$date = $order->get_date_created();
			$result[] = array(
				'id'             => (int) $order->get_id(),
				'number'         => (string) $order->get_order_number(),
				'status'         => (string) $order->get_status(),
				'date'           => $date ? $date->date_i18n( 'Y-m-d' ) : '',
				'currency'       => (string) $order->get_currency(),
				'total'          => $this->money_int( $order->get_total() ),
				'payment_method' => (string) $order->get_payment_method_title(),
				'item_count'     => (int) $order->get_item_count(),
				'items'          => $items,
			);
		}
		return $result;
	}

	private function persistent_cart( int $user_id ): array {
		$meta_key   = '_woocommerce_persistent_cart_' . get_current_blog_id();
		$persistent = get_user_meta( $user_id, $meta_key, true );
		$raw_items  = is_array( $persistent ) && isset( $persistent['cart'] ) && is_array( $persistent['cart'] ) ? $persistent['cart'] : array();
		$result     = array();

		foreach ( $raw_items as $cart_key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id   = absint( $item['product_id'] ?? 0 );
			$variation_id = absint( $item['variation_id'] ?? 0 );
			$product      = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$result[] = array(
				'key'           => (string) $cart_key,
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'name'          => (string) $product->get_name(),
				'quantity'      => max( 0, (int) ( $item['quantity'] ?? 0 ) ),
				'price'         => $this->money_int( $product->get_price() ),
				'regular_price' => $this->money_int( $product->get_regular_price() ?: $product->get_price() ),
				'image'         => $this->product_image( $product ),
				'variation'     => isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array(),
			);
		}

		return $result;
	}

	private function wishlist( int $user_id ): array {
		/**
		 * WooCommerce core has no native wishlist. Wishlist plugins/themes can
		 * expose their products here without changing this API contract.
		 */
		$items = apply_filters( 'shop_application_customer_wishlist', array(), $user_id );
		return is_array( $items ) ? array_values( $items ) : array();
	}

	private function downloads( int $user_id ): array {
		if ( ! function_exists( 'wc_get_customer_available_downloads' ) ) {
			return array();
		}
		$downloads = wc_get_customer_available_downloads( $user_id );
		$result = array();
		foreach ( (array) $downloads as $download ) {
			if ( ! is_array( $download ) ) {
				continue;
			}
			$result[] = array(
				'product_id'         => absint( $download['product_id'] ?? 0 ),
				'product_name'       => (string) ( $download['product_name'] ?? '' ),
				'download_name'      => (string) ( $download['download_name'] ?? '' ),
				'download_url'       => esc_url_raw( (string) ( $download['download_url'] ?? '' ) ),
				'downloads_remaining'=> isset( $download['downloads_remaining'] ) ? (string) $download['downloads_remaining'] : '',
				'access_expires'     => (string) ( $download['access_expires'] ?? '' ),
			);
		}
		return $result;
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
