<?php

namespace AppAPI\Services;

use AppAPI\Support\AuthToken;

defined( 'ABSPATH' ) || exit;

final class AuthService {
	public function login( string $identifier, string $password ) {
		$identity = $this->classify_identifier( $identifier );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$user = $this->find_user( $identity['type'], $identity['value'] );
		if ( ! $user instanceof \WP_User ) {
			return $this->invalid_credentials();
		}

		$authenticated = wp_authenticate( $user->user_login, $password );
		if ( is_wp_error( $authenticated ) || ! $authenticated instanceof \WP_User ) {
			return $this->invalid_credentials();
		}

		return $this->auth_payload( $authenticated );
	}

	public function register( string $identifier, string $password ) {
		$identity = $this->classify_identifier( $identifier );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( strlen( $password ) < 8 ) {
			return new \WP_Error(
				'app_api_auth_weak_password',
				'رمز عبور باید حداقل ۸ کاراکتر داشته باشد.',
				array( 'status' => 400 )
			);
		}

		if ( 'username' === $identity['type'] && $this->text_length( $identity['value'] ) < 4 ) {
			return new \WP_Error(
				'app_api_auth_username_too_short',
				'نام کاربری باید حداقل ۴ کاراکتر داشته باشد.',
				array( 'status' => 400 )
			);
		}

		$duplicate = $this->duplicate_error( $identity['type'], $identity['value'] );
		if ( $duplicate ) {
			return $duplicate;
		}

		$email    = '';
		$mobile   = '';
		$username = '';

		if ( 'email' === $identity['type'] ) {
			$email    = $identity['value'];
			$username = $this->generate_username();
		} elseif ( 'mobile' === $identity['type'] ) {
			$mobile   = $identity['value'];
			$username = $this->generate_username();
		} else {
			$username = sanitize_user( $identity['value'], false );
			if ( '' === $username || ! validate_username( $username ) ) {
				return new \WP_Error(
					'app_api_auth_invalid_username',
					'نام کاربری واردشده معتبر نیست.',
					array( 'status' => 400 )
				);
			}
		}

		if ( '' === $username ) {
			return new \WP_Error(
				'app_api_auth_username_generation_failed',
				'ایجاد نام کاربری انجام نشد. لطفاً دوباره تلاش کنید.',
				array( 'status' => 500 )
			);
		}

		$role = get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' );
		$userdata = array(
			'user_login'   => $username,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $username,
			'role'         => $role,
		);

		$user_id = wp_insert_user( $userdata );
		if ( is_wp_error( $user_id ) ) {
			return new \WP_Error(
				'app_api_auth_registration_failed',
				'ثبت‌نام انجام نشد. لطفاً اطلاعات را بررسی کرده و دوباره تلاش کنید.',
				array( 'status' => 400 )
			);
		}

		$user_id = absint( $user_id );
		if ( $email ) {
			update_user_meta( $user_id, 'billing_email', $email );
		}
		if ( $mobile ) {
			update_user_meta( $user_id, 'app_api_mobile', $mobile );
			update_user_meta( $user_id, 'billing_phone', $mobile );
		}

		if ( function_exists( 'WC' ) ) {
			do_action(
				'woocommerce_created_customer',
				$user_id,
				array(
					'user_login' => $username,
					'user_pass'  => $password,
					'user_email' => $email,
				),
				false
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'app_api_auth_registration_failed',
				'ثبت‌نام انجام شد اما دریافت حساب کاربری ممکن نبود.',
				array( 'status' => 500 )
			);
		}

		return $this->auth_payload( $user );
	}

	public function classify_identifier( string $identifier ) {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return new \WP_Error(
				'app_api_auth_identifier_required',
				'شناسه کاربر الزامی است.',
				array( 'status' => 400 )
			);
		}

		$identifier = $this->english_digits( $identifier );
		$phone_candidate = preg_replace( '/[\s\-()]+/', '', $identifier );
		if ( preg_match( '/^09\d{9}$/', $phone_candidate ) ) {
			return array( 'type' => 'mobile', 'value' => $phone_candidate );
		}

		if ( false !== strpos( $identifier, '@' ) ) {
			$email = sanitize_email( $identifier );
			if ( ! $email || ! is_email( $email ) ) {
				return new \WP_Error(
					'app_api_auth_invalid_email',
					'ایمیل واردشده معتبر نیست.',
					array( 'status' => 400 )
				);
			}
			return array( 'type' => 'email', 'value' => strtolower( $email ) );
		}

		$username = sanitize_user( $identifier, false );
		if ( '' === $username || ! validate_username( $username ) ) {
			return new \WP_Error(
				'app_api_auth_invalid_username',
				'نام کاربری واردشده معتبر نیست.',
				array( 'status' => 400 )
			);
		}
		return array( 'type' => 'username', 'value' => $username );
	}

	private function duplicate_error( string $type, string $value ) {
		if ( 'email' === $type && email_exists( $value ) ) {
			return new \WP_Error(
				'app_api_auth_email_exists',
				'این ایمیل قبلاً ثبت شده است. لطفاً وارد حساب کاربری شوید یا از ایمیل دیگری استفاده کنید.',
				array( 'status' => 409 )
			);
		}
		if ( 'mobile' === $type && $this->mobile_user_ids( $value ) ) {
			return new \WP_Error(
				'app_api_auth_mobile_exists',
				'این شماره همراه قبلاً ثبت شده است. لطفاً وارد حساب کاربری شوید یا از شماره دیگری استفاده کنید.',
				array( 'status' => 409 )
			);
		}
		if ( 'username' === $type && username_exists( $value ) ) {
			return new \WP_Error(
				'app_api_auth_username_exists',
				'این نام کاربری قبلاً ثبت شده است. لطفاً وارد حساب کاربری شوید یا نام کاربری دیگری انتخاب کنید.',
				array( 'status' => 409 )
			);
		}
		return null;
	}

	private function find_user( string $type, string $value ) {
		if ( 'email' === $type ) {
			return get_user_by( 'email', $value );
		}
		if ( 'mobile' === $type ) {
			return $this->find_user_by_mobile( $value );
		}
		return get_user_by( 'login', $value );
	}

	private function find_user_by_mobile( string $mobile ) {
		$user_ids = $this->mobile_user_ids( $mobile );
		if ( 1 !== count( $user_ids ) ) {
			return false;
		}
		return get_user_by( 'id', absint( $user_ids[0] ) );
	}

	private function mobile_user_ids( string $mobile ): array {
		global $wpdb;

		$mobile = $this->normalize_mobile( $mobile );
		if ( ! $mobile ) {
			return array();
		}

		$keys = apply_filters(
			'app_api_mobile_meta_keys',
			array( 'app_api_mobile', 'billing_phone', 'shipping_phone', 'mobile', 'phone', 'billing_mobile' )
		);
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $keys ) ) ) );
		if ( ! $keys ) {
			return array();
		}

		$variants = $this->mobile_variants( $mobile );
		$key_placeholders   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$value_placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
		$sql = "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$key_placeholders}) AND meta_value IN ({$value_placeholders}) LIMIT 10";
		$params = array_merge( $keys, $variants );
		$user_ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_unique( array_map( 'absint', (array) $user_ids ) ) );
	}

	private function auth_payload( \WP_User $user ): array {
		$token_data = AuthToken::issue( (int) $user->ID );
		$mobile     = $this->user_mobile( (int) $user->ID );

		return array(
			'id'           => (int) $user->ID,
			'username'     => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'mobile'       => $mobile,
			'access_token' => (string) ( $token_data['access_token'] ?? '' ),
		);
	}

	private function user_mobile( int $user_id ): string {
		foreach ( array( 'app_api_mobile', 'billing_phone', 'mobile', 'phone' ) as $key ) {
			$value = (string) get_user_meta( $user_id, $key, true );
			$normalized = $this->normalize_mobile( $value );
			if ( $normalized ) {
				return $normalized;
			}
		}
		return '';
	}

	private function generate_username(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$max      = strlen( $alphabet ) - 1;
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$suffix = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$suffix .= $alphabet[ random_int( 0, $max ) ];
			}
			$username = 'user-' . $suffix;
			if ( ! username_exists( $username ) ) {
				return $username;
			}
		}
		return '';
	}

	private function mobile_variants( string $mobile ): array {
		$local = substr( $mobile, 1 );
		return array_values(
			array_unique(
				array(
					$mobile,
					'+98' . $local,
					'98' . $local,
					'0098' . $local,
				)
			)
		);
	}

	private function normalize_mobile( string $value ): string {
		$value = $this->english_digits( trim( $value ) );
		$value = preg_replace( '/[\s\-()]+/', '', $value );
		if ( preg_match( '/^09\d{9}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^(?:\+98|0098|98)(9\d{9})$/', $value, $matches ) ) {
			return '0' . $matches[1];
		}
		return '';
	}

	private function english_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	private function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private function invalid_credentials(): \WP_Error {
		return new \WP_Error(
			'app_api_auth_invalid_credentials',
			'شناسه کاربر یا رمز عبور نادرست است.',
			array( 'status' => 401 )
		);
	}
}
