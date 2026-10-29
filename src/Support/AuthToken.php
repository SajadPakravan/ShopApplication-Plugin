<?php

namespace AppAPI\Support;

use AppAPI\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Small opaque bearer-token layer for application authentication.
 *
 * Only a hash of each token is stored. The token embeds the WordPress user ID
 * so validation can load one user instead of scanning the users table.
 */
final class AuthToken {
	private const META_KEY = '_app_api_auth_tokens';
	private const PREFIX   = 'app1';
	private const MAX_TOKENS_PER_USER = 10;

	public static function issue( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return array();
		}

		$secret = bin2hex( random_bytes( 32 ) );
		$token  = self::PREFIX . '.' . $user_id . '.' . $secret;
		$hash   = self::hash( $token );
		$now    = time();
		$expiry = $now + Config::AUTH_TOKEN_TTL;
		$tokens = self::clean_tokens( get_user_meta( $user_id, self::META_KEY, true ), $now );

		$tokens[ $hash ] = array(
			'created_at' => $now,
			'expires_at' => $expiry,
		);

		if ( count( $tokens ) > self::MAX_TOKENS_PER_USER ) {
			uasort(
				$tokens,
				static function ( $a, $b ) {
					return (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 );
				}
			);
			while ( count( $tokens ) > self::MAX_TOKENS_PER_USER ) {
				array_shift( $tokens );
			}
		}

		update_user_meta( $user_id, self::META_KEY, $tokens );

		return array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => Config::AUTH_TOKEN_TTL,
			'expires_at'   => gmdate( 'c', $expiry ),
		);
	}

	public static function determine_current_user( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$token = self::bearer_token();
		if ( ! $token ) {
			return $user_id;
		}

		$parts = explode( '.', $token, 3 );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] || ! ctype_digit( $parts[1] ) || '' === $parts[2] ) {
			return $user_id;
		}

		$candidate_user_id = absint( $parts[1] );
		if ( ! $candidate_user_id ) {
			return $user_id;
		}

		$now    = time();
		$tokens = self::clean_tokens( get_user_meta( $candidate_user_id, self::META_KEY, true ), $now );
		$hash   = self::hash( $token );

		// Persist cleanup only when needed.
		$stored = get_user_meta( $candidate_user_id, self::META_KEY, true );
		if ( is_array( $stored ) && count( $stored ) !== count( $tokens ) ) {
			update_user_meta( $candidate_user_id, self::META_KEY, $tokens );
		}

		if ( empty( $tokens[ $hash ] ) || (int) $tokens[ $hash ]['expires_at'] <= $now ) {
			return $user_id;
		}

		return $candidate_user_id;
	}

	private static function bearer_token(): string {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	private static function clean_tokens( $tokens, int $now ): array {
		$tokens = is_array( $tokens ) ? $tokens : array();
		foreach ( $tokens as $hash => $data ) {
			if ( ! is_string( $hash ) || ! is_array( $data ) || (int) ( $data['expires_at'] ?? 0 ) <= $now ) {
				unset( $tokens[ $hash ] );
			}
		}
		return $tokens;
	}
}
