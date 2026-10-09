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

	/**
	 * Normalizes product attribute filters to the public API shape:
	 * [ [ 'id' => 1, 'options' => [118,119] ], ... ]
	 *
	 * Supported request forms include:
	 * - attributes[1]=118,119&attributes[18]=209,238
	 * - attributes=[{"id":1,"options":[118,119]}]
	 * - attributes=1:118,119|18:209,238
	 */
	public static function attributes( $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			$decoded = json_decode( $trimmed, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			} elseif ( false !== strpos( $trimmed, ':' ) ) {
				$parsed = array();
				foreach ( preg_split( '/[|;]/', $trimmed ) as $chunk ) {
					if ( false === strpos( $chunk, ':' ) ) {
						continue;
					}
					list( $attribute_id, $options ) = array_pad( explode( ':', $chunk, 2 ), 2, '' );
					$attribute_id = absint( trim( $attribute_id ) );
					if ( $attribute_id ) {
						$parsed[ $attribute_id ] = $options;
					}
				}
				$value = $parsed;
			} else {
				return array();
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$attribute_id = 0;
			$options      = array();

			if ( is_array( $item ) && array_key_exists( 'id', $item ) ) {
				$attribute_id = absint( $item['id'] );
				$options      = self::ids( $item['options'] ?? array() );
			} elseif ( is_numeric( $key ) && (int) $key > 0 ) {
				$attribute_id = absint( $key );
				$options      = self::ids( $item );
			}

			if ( ! $attribute_id || ! $options ) {
				continue;
			}

			if ( ! isset( $normalized[ $attribute_id ] ) ) {
				$normalized[ $attribute_id ] = array();
			}
			$normalized[ $attribute_id ] = array_values( array_unique( array_merge( $normalized[ $attribute_id ], $options ) ) );
		}

		$result = array();
		foreach ( $normalized as $attribute_id => $options ) {
			$result[] = array(
				'id'      => (int) $attribute_id,
				'options' => array_values( array_map( 'absint', $options ) ),
			);
		}

		return $result;
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
