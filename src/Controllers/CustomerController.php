<?php

namespace AppAPI\Controllers;

use AppAPI\Services\CustomerService;
use AppAPI\Support\Response;

defined( 'ABSPATH' ) || exit;

final class CustomerController {
	private $service;

	public function __construct( ?CustomerService $service = null ) {
		$this->service = $service ?: new CustomerService();
	}

	public function permissions_check( \WP_REST_Request $request ) {
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return $this->auth_error();
		}

		$requested_id = absint( $request->get_param( 'id' ) );
		if ( $requested_id === (int) $current_user_id || current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error(
			'shop_application_customer_forbidden',
			'شما اجازه دسترسی به اطلاعات این مشتری را ندارید.',
			array( 'status' => 403 )
		);
	}

	public function account_permissions_check() {
		return get_current_user_id() ? true : $this->auth_error();
	}

	public function show( \WP_REST_Request $request ) {
		return $this->respond( $this->service->get_customer( absint( $request->get_param( 'id' ) ) ) );
	}

	public function update( \WP_REST_Request $request ) {
		return $this->respond( $this->service->update_customer( absint( $request->get_param( 'id' ) ), $this->body( $request ) ) );
	}

	public function orders() {
		return $this->collection_response( 'orders', $this->service->orders( get_current_user_id() ) );
	}

	public function create_order( \WP_REST_Request $request ) {
		return $this->respond( $this->service->create_order( get_current_user_id(), $this->body( $request ) ), 201 );
	}

	public function update_order( \WP_REST_Request $request ) {
		return $this->respond( $this->service->update_order( get_current_user_id(), absint( $request->get_param( 'id' ) ), $this->body( $request ) ) );
	}

	public function cart() {
		return $this->collection_response( 'cart', $this->service->persistent_cart( get_current_user_id() ), true );
	}

	public function add_cart( \WP_REST_Request $request ) {
		return $this->collection_response_result( 'cart', $this->service->add_cart_item( get_current_user_id(), $this->body( $request ) ), true );
	}

	public function update_cart( \WP_REST_Request $request ) {
		return $this->collection_response_result( 'cart', $this->service->update_cart_item( get_current_user_id(), $this->body( $request ) ), true );
	}

	public function remove_cart( \WP_REST_Request $request ) {
		return $this->collection_response_result( 'cart', $this->service->remove_cart_item( get_current_user_id(), $this->body( $request ) ), true );
	}

	public function favorites() {
		return $this->collection_response( 'favorites', $this->service->favorites( get_current_user_id() ) );
	}

	public function add_favorite( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) ?: $request->get_param( 'product_id' ) );
		return $this->collection_response_result( 'favorites', $this->service->add_favorite( get_current_user_id(), $id ) );
	}

	public function replace_favorites( \WP_REST_Request $request ) {
		$body = $this->body( $request );
		$ids  = isset( $body['ids'] ) && is_array( $body['ids'] ) ? $body['ids'] : array();
		return $this->collection_response_result( 'favorites', $this->service->replace_favorites( get_current_user_id(), $ids ) );
	}

	public function remove_favorite( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) ?: $request->get_param( 'product_id' ) );
		return $this->collection_response_result( 'favorites', $this->service->remove_favorite( get_current_user_id(), $id ) );
	}

	public function viewed_products() {
		return $this->collection_response( 'viewed_products', $this->service->viewed_products( get_current_user_id() ) );
	}

	public function comments() {
		return $this->collection_response( 'comments', $this->service->comments( get_current_user_id() ) );
	}

	public function add_comment( \WP_REST_Request $request ) {
		return $this->respond( $this->service->add_comment( get_current_user_id(), $this->body( $request ) ), 201 );
	}

	public function update_comment( \WP_REST_Request $request ) {
		return $this->respond( $this->service->update_comment( get_current_user_id(), absint( $request->get_param( 'id' ) ), $this->body( $request ) ) );
	}

	public function delete_comment( \WP_REST_Request $request ) {
		return $this->collection_response_result( 'comments', $this->service->delete_comment( get_current_user_id(), absint( $request->get_param( 'id' ) ) ) );
	}

	private function body( \WP_REST_Request $request ): array {
		$params = $request->get_params();
		$params = is_array( $params ) ? $params : array();
		$body   = $request->get_body_params();
		if ( is_array( $body ) ) {
			$params = array_merge( $params, $body );
		}
		$json = $request->get_json_params();
		if ( is_array( $json ) ) {
			$params = array_merge( $params, $json );
		}
		return $params;
	}

	private function respond( $result, int $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = Response::success( array( 'success' => true, 'data' => $result ) );
		if ( $response instanceof \WP_REST_Response ) {
			$response->set_status( $status );
		}
		return $response;
	}

	private function collection_response( string $key, array $items, bool $cart_quantity = false ) {
		$count = $cart_quantity ? $this->quantity_count( $items ) : count( $items );
		return Response::success(
			array(
				'success' => true,
				'count'   => $count,
				'data'    => $items,
			)
		);
	}

	private function collection_response_result( string $key, $result, bool $cart_quantity = false ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->collection_response( $key, is_array( $result ) ? $result : array(), $cart_quantity );
	}

	private function quantity_count( array $items ): int {
		$count = 0;
		foreach ( $items as $item ) {
			$count += max( 0, (int) ( $item['quantity'] ?? 0 ) );
		}
		return $count;
	}

	private function auth_error(): \WP_Error {
		return new \WP_Error(
			'shop_application_auth_required',
			'برای دسترسی به این وب‌سرویس باید وارد حساب کاربری شوید و توکن معتبر ارسال کنید.',
			array( 'status' => 401 )
		);
	}
}
