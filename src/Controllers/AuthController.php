<?php

namespace AppAPI\Controllers;

use AppAPI\Services\AuthService;
use AppAPI\Support\Response;

defined( 'ABSPATH' ) || exit;

final class AuthController {
	private $service;

	public function __construct( ?AuthService $service = null ) {
		$this->service = $service ?: new AuthService();
	}

	public function login( \WP_REST_Request $request ) {
		$identifier = trim( (string) $request->get_param( 'identifier' ) );
		$password   = (string) $request->get_param( 'password' );

		if ( '' === $identifier || '' === $password ) {
			return new \WP_Error(
				'app_api_auth_required_fields',
				'شناسه کاربر و رمز عبور الزامی هستند.',
				array( 'status' => 400 )
			);
		}

		$result = $this->service->login( $identifier, $password );
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

	public function register( \WP_REST_Request $request ) {
		$identifier = trim( (string) $request->get_param( 'identifier' ) );
		$password   = (string) $request->get_param( 'password' );

		if ( '' === $identifier || '' === $password ) {
			return new \WP_Error(
				'app_api_auth_required_fields',
				'شناسه کاربر و رمز عبور الزامی هستند.',
				array( 'status' => 400 )
			);
		}

		$result = $this->service->register( $identifier, $password );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Response::success(
			array(
				'success' => true,
				'data'    => $result,
			),
			201
		);
	}
}
