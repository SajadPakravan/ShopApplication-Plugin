<?php

namespace AppAPI\Support;

defined( 'ABSPATH' ) || exit;

final class Request {
	public static function ids( $value ): array {
		$values = self::flatten( $value );
		$ids    = array_map( 'absint', $values );
		$ids    = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}

	public static function slugs( $value ): array {
		$values = self::flatten( $value );
		$values = array_map( 'sanitize_key', $values );
		$values = array_filter( $values );

		return array_values( array_unique( $values ) );
	}

	public static function nullable_boolean( $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		$normalized = strtolower( trim( (string) $value ) );

		if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}

		return null;
	}

	private static function flatten( $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $value );
		}

		$result = array();

		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$result ) {
				foreach ( explode( ',', (string) $item ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part ) {
						$result[] = $part;
					}
				}
			}
		);

		return $result;
	}
}
