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
			return new \WP_Error(
				'shop_application_auth_required',
				'برای دسترسی به اطلاعات مشتری باید وارد حساب کاربری شوید.',
				array( 'status' => 401 )
			);
		}

		$requested_id = absint( $request->get_param( 'id' ) );
		if ( $requested_id === (int) $current_user_id || current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error(
			'shop_application_customer_forbidden',
			'شما اجازه مشاهده اطلاعات این مشتری را ندارید.',
			array( 'status' => 403 )
		);
	}

	public function show( \WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		$result  = $this->service->get_customer( $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Response::success(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}
}
