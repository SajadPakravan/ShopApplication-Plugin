<?php

namespace AppAPI;

defined( 'ABSPATH' ) || exit;

final class Config {
	public const REST_NAMESPACE             = 'app-api/v1';
	public const DEFAULT_PER_PAGE           = 20;
	public const MAX_PER_PAGE               = 100;
	public const DEFAULT_HOME_CACHE         = 60;
	public const HOME_MENU_LOCATION         = 'app-api-home-menu';
	public const OPTION_HOME_SECTIONS       = 'app_api_home_sections_json';
	public const OPTION_HOME_CONFIG         = 'app_api_home_configuration';
	public const OPTION_HOME_BANNERS        = 'app_api_home_banners_json';
	public const OPTION_HOME_CACHE          = 'app_api_home_cache_ttl';
	public const OPTION_CACHE_VERSION       = 'app_api_home_cache_version';
	public const OPTION_HOME_PRESET_VERSION = 'app_api_home_preset_version';
	public const OPTION_HOME_ENDPOINT       = 'app_api_home_endpoint';
	public const OPTION_PRODUCTS_ENDPOINT   = 'app_api_products_endpoint';
	public const OPTION_PRODUCT_ENDPOINT    = 'app_api_product_endpoint';

	/**
	 * Types the administrator can add from the visual home-page API builder.
	 * The menu type is preserved for the existing quick-actions section, but it
	 * is intentionally not exposed as a new type in this release.
	 */
	public static function addable_section_types(): array {
		return array(
			'banner'     => 'بنر',
			'products'   => 'محصولات',
			'category' => 'دسته‌بندی',
			'brand'    => 'برند',
		);
	}

	public static function allowed_section_types(): array {
		return array_merge( array_keys( self::addable_section_types() ), array( 'menu' ) );
	}

	public static function section_type_label( string $type ): string {
		$labels = self::addable_section_types();
		$labels['menu'] = 'منوی دسترسی سریع';
		return $labels[ sanitize_key( $type ) ] ?? $type;
	}

	public static function default_layout_for_type( string $type ): array {
		switch ( sanitize_key( $type ) ) {
			case 'banner':
				return array(
					'component' => 'banner',
					'direction' => 'horizontal',
					'rows'      => 1,
					'columns'   => 1,
				);
			case 'products':
				return array(
					'component' => 'product_carousel',
					'direction' => 'horizontal',
					'rows'      => 1,
					'columns'   => 1,
				);
			case 'category':
				return array(
					'component' => 'category_grid',
					'direction' => 'horizontal',
					'rows'      => 1,
					'columns'   => 1,
				);
			case 'brand':
				return array(
					'component' => 'brand_grid',
					'direction' => 'horizontal',
					'rows'      => 1,
					'columns'   => 1,
				);
			case 'menu':
				return array(
					'component' => 'action_menu',
					'direction' => 'horizontal',
					'rows'      => 2,
					'columns'   => 5,
				);
			default:
				return array(
					'component' => $type ?: 'custom',
					'direction' => 'vertical',
					'rows'      => 1,
					'columns'   => 1,
				);
		}
	}

	public static function home_configuration(): array {
		$saved = get_option( self::OPTION_HOME_CONFIG, null );

		if ( ! is_array( $saved ) || empty( $saved['sections'] ) ) {
			$legacy_raw = get_option( self::OPTION_HOME_SECTIONS, '' );
			$legacy     = is_string( $legacy_raw ) && $legacy_raw ? json_decode( $legacy_raw, true ) : null;
			$saved      = is_array( $legacy ) ? self::configuration_from_sections( $legacy ) : self::default_home_configuration();
		}

		return self::normalize_home_configuration( $saved );
	}

	public static function default_home_configuration(): array {
		return self::configuration_from_sections( self::default_home_sections() );
	}

	public static function new_section_defaults( string $type, string $key = '' ): array {
		$type = in_array( sanitize_key( $type ), self::allowed_section_types(), true ) ? sanitize_key( $type ) : 'products';
		$key  = sanitize_key( $key );
		$id   = $key ?: $type . '_section';

		$section = array(
			'id'       => $id,
			'type'     => $type,
			'enabled'  => true,
			'title'    => '',
			'subtitle' => '',
			'layout'   => self::default_layout_for_type( $type ),
		);

		if ( 'banner' === $type || 'menu' === $type ) {
			$section['data'] = array();
		} elseif ( 'products' === $type ) {
			$section['category']      = array();
			$section['brand']         = array();
			$section['on_sale']       = false;
			$section['per_page']      = 10;
			$section['view_all_title'] = 'مشاهده همه';
		} elseif ( 'category' === $type || 'brand' === $type ) {
			$section['include'] = array();
		}

		return $section;
	}

	public static function default_home_sections(): array {
		return self::uses_yademan_preset() ? self::yademan_home_sections() : self::generic_home_sections();
	}

	private static function configuration_from_sections( array $sections ): array {
		$result = array( 'order' => array(), 'sections' => array() );

		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$key = sanitize_key( $section['_key'] ?? $section['id'] ?? ( 'section_' . $index ) );
			if ( ! $key ) {
				continue;
			}

			$result['order'][]         = $key;
			$result['sections'][ $key ] = $section;
		}

		return self::normalize_home_configuration( $result );
	}

	private static function normalize_home_configuration( array $configuration ): array {
		$raw_sections = isset( $configuration['sections'] ) && is_array( $configuration['sections'] ) ? $configuration['sections'] : array();
		$raw_order    = isset( $configuration['order'] ) ? $configuration['order'] : array();
		$raw_order    = is_array( $raw_order ) ? $raw_order : explode( ',', (string) $raw_order );
		$sections     = array();

		foreach ( $raw_sections as $raw_key => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$key = sanitize_key( (string) $raw_key );
			if ( ! $key ) {
				$key = 'section_' . ( count( $sections ) + 1 );
			}
			while ( isset( $sections[ $key ] ) ) {
				$key .= '_2';
			}

			$type = self::normalize_section_type( (string) ( $section['type'] ?? '' ) );
			if ( ! in_array( $type, self::allowed_section_types(), true ) ) {
				continue;
			}

			$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
			$layout = array_replace( self::default_layout_for_type( $type ), $layout );

			$rows    = self::positive_int( $layout['rows'] ?? 1, 12 );
			$columns = self::positive_int( $layout['columns'] ?? 1, 12 );

			$clean = array(
				'id'       => self::sanitize_api_id( (string) ( $section['id'] ?? $key ), $key ),
				'type'     => $type,
				'enabled'  => ! empty( $section['enabled'] ),
				'title'    => isset( $section['title'] ) ? (string) $section['title'] : '',
				'subtitle' => isset( $section['subtitle'] ) ? (string) $section['subtitle'] : '',
				'layout'   => array(
					'component' => self::sanitize_component( (string) ( $layout['component'] ?? $type ), $type ),
					'direction' => in_array( $layout['direction'] ?? '', array( 'horizontal', 'vertical' ), true ) ? $layout['direction'] : 'horizontal',
					'rows'      => $rows,
					'columns'   => $columns,
				),
			);

			if ( 'banner' === $type || 'menu' === $type ) {
				$clean['data'] = isset( $section['data'] ) && is_array( $section['data'] ) ? array_values( $section['data'] ) : array();
			}

			if ( 'products' === $type ) {
				$legacy_query = isset( $section['query'] ) && is_array( $section['query'] ) ? $section['query'] : array();
				$source       = sanitize_key( (string) ( $section['source'] ?? '' ) );
				$category     = $section['category'] ?? ( $legacy_query['category'] ?? array() );
				$brand        = $section['brand'] ?? ( $legacy_query['brand'] ?? array() );
				$on_sale      = isset( $section['on_sale'] ) ? (bool) $section['on_sale'] : ( 'on_sale' === $source || ! empty( $legacy_query['on_sale'] ) );
				$per_page     = absint( $section['per_page'] ?? ( $legacy_query['per_page'] ?? 10 ) );

				$clean['category']       = self::ids( $category );
				$clean['brand']          = self::ids( $brand );
				$clean['on_sale']        = $on_sale;
				$clean['per_page']       = min( 50, max( 1, $per_page ?: 10 ) );
				$clean['view_all_title'] = sanitize_text_field(
					(string) ( $section['view_all_title'] ?? ( $section['view_all']['title'] ?? 'مشاهده همه' ) )
				) ?: 'مشاهده همه';

				// Keep old name/slug fallbacks until the administrator saves exact IDs.
				$clean['category_names'] = isset( $section['category_names'] ) && is_array( $section['category_names'] ) ? array_values( $section['category_names'] ) : array();
				$clean['category_slugs'] = isset( $section['category_slugs'] ) && is_array( $section['category_slugs'] ) ? array_values( $section['category_slugs'] ) : array();
				$clean['brand_names']    = isset( $section['brand_names'] ) && is_array( $section['brand_names'] ) ? array_values( $section['brand_names'] ) : array();
				$clean['brand_slugs']    = isset( $section['brand_slugs'] ) && is_array( $section['brand_slugs'] ) ? array_values( $section['brand_slugs'] ) : array();
			}

			if ( 'category' === $type || 'brand' === $type ) {
				$config = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
				$clean['include'] = self::ids( $section['include'] ?? ( $config['include'] ?? array() ) );
				$clean['include_names'] = isset( $config['include_names'] ) && is_array( $config['include_names'] ) ? array_values( $config['include_names'] ) : array();
				$clean['include_slugs'] = isset( $config['include_slugs'] ) && is_array( $config['include_slugs'] ) ? array_values( $config['include_slugs'] ) : array();
			}

			$sections[ $key ] = $clean;
		}

		$order = array();
		foreach ( $raw_order as $key ) {
			$key = sanitize_key( (string) $key );
			if ( $key && isset( $sections[ $key ] ) && ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		foreach ( array_keys( $sections ) as $key ) {
			if ( ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}

		return array( 'order' => $order, 'sections' => $sections );
	}

	private static function normalize_section_type( string $type ): string {
		$type = sanitize_key( $type );
		if ( in_array( $type, array( 'banner_slider', 'promo_banners' ), true ) ) {
			return 'banner';
		}
		if ( in_array( $type, array( 'action_menu', 'menu' ), true ) ) {
			return 'menu';
		}
		if ( in_array( $type, array( 'categories', 'category' ), true ) ) {
			return 'category';
		}
		if ( in_array( $type, array( 'brands', 'brand' ), true ) ) {
			return 'brand';
		}
		return $type;
	}


	private static function sanitize_component( string $component, string $fallback ): string {
		$component = trim( $component );
		$component = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $component );
		$component = trim( (string) $component, '_-' );
		return $component ?: $fallback;
	}

	private static function sanitize_api_id( string $id, string $fallback ): string {
		$id = strtolower( trim( $id ) );
		$id = preg_replace( '/[^a-z0-9_-]+/', '_', $id );
		$id = trim( (string) $id, '_-' );
		return $id ?: sanitize_key( $fallback );
	}

	private static function positive_int( $value, int $maximum ): int {
		$value = absint( $value );
		return min( $maximum, max( 1, $value ?: 1 ) );
	}

	private static function ids( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	public static function home_endpoint(): string {
		return self::endpoint_slug( get_option( self::OPTION_HOME_ENDPOINT, 'home' ), 'home' );
	}

	public static function products_endpoint(): string {
		return self::endpoint_slug( get_option( self::OPTION_PRODUCTS_ENDPOINT, 'products' ), 'products' );
	}

	public static function product_endpoint(): string {
		return self::endpoint_slug( get_option( self::OPTION_PRODUCT_ENDPOINT, 'products' ), 'products' );
	}

	public static function endpoint_slug( $value, string $fallback ): string {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );
		$value = trim( (string) $value, '-_' );
		return $value ?: $fallback;
	}

	public static function uses_yademan_preset(): bool {
		$host = function_exists( 'home_url' ) ? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) : '';
		$host = strtolower( (string) $host );
		return 'yademansystem.ir' === $host || 'www.yademansystem.ir' === $host;
	}

	private static function generic_home_sections(): array {
		return array(
			array(
				'id'       => 'hero_banners',
				'type'     => 'banner',
				'enabled'  => true,
				'title'    => '',
				'subtitle' => '',
				'layout'   => array( 'component' => 'banner_slider', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'data'     => array(),
			),
			array(
				'id'       => 'latest_products',
				'type'     => 'products',
				'enabled'  => true,
				'title'    => 'جدیدترین محصولات',
				'subtitle' => '',
				'layout'   => array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'category' => array(),
				'brand'    => array(),
				'on_sale'  => false,
				'per_page' => 10,
				'view_all_title' => 'مشاهده همه',
			),
		);
	}

	private static function yademan_home_sections(): array {
		$uploads = 'https://yademansystem.ir/wp-content/uploads/';

		return array(
			array(
				'id'       => 'hero_banners',
				'type'     => 'banner',
				'enabled'  => true,
				'title'    => '',
				'subtitle' => '',
				'layout'   => array( 'component' => 'banner_slider', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'data'     => array(
					array( 'title' => '', 'subtitle' => '', 'image' => $uploads . '2026/06/YademanSystem_banner_Laptop.webp' ),
					array( 'title' => '', 'subtitle' => '', 'image' => $uploads . '2026/06/YademanSystem_banner_Speaker.webp' ),
				),
			),
			array(
				'id'       => 'quick_actions',
				'type'     => 'menu',
				'enabled'  => true,
				'title'    => '',
				'subtitle' => '',
				'layout'   => array( 'component' => 'action_menu', 'direction' => 'horizontal', 'rows' => 2, 'columns' => 5 ),
				'data'     => array(
					array( 'id' => 'application', 'title' => 'اپلیکیشن فروشگاه', 'subtitle' => '', 'image' => $uploads . '2023/02/33f94db0e93a29b08b5c45d7932dbb114791155d_1658329987.png', 'action' => array( 'type' => 'app_download' ) ),
					array( 'id' => 'sale', 'title' => 'حراجی', 'subtitle' => '', 'image' => $uploads . '2023/02/258db5bf0ff7b28dbae1bfb3dfaa71bfff32faf9_1654679397.png', 'action' => array( 'type' => 'products', 'on_sale' => true ) ),
					array( 'id' => 'purchase_consulting', 'title' => 'مشاوره خرید', 'subtitle' => '', 'image' => $uploads . '2023/02/6c69096a524add2d4646cd162dfa5f66d4ddceac_1668952039.png', 'action' => array( 'type' => 'purchase_consulting' ) ),
					array( 'id' => 'goods_order', 'title' => 'سفارش اجناس', 'subtitle' => '', 'image' => $uploads . '2023/02/17bb6daa07ae2ec11867fb7320ed6f79b26f1f4b_1648897081.png', 'action' => array( 'type' => 'goods_order' ) ),
					array( 'id' => 'assembly_order', 'title' => 'سفارش مونتاژ', 'subtitle' => '', 'image' => $uploads . '2023/02/d0dc31c892be8cf1408e4e14580b3f479da66bd1_1648897133.png', 'action' => array( 'type' => 'assembly_order' ) ),
					array( 'id' => 'repair_order', 'title' => 'سفارش تعمیرات', 'subtitle' => '', 'image' => $uploads . '2023/02/d919c238a583cacd2048a254e5623f81dd11ab24_16736372.png', 'action' => array( 'type' => 'repair_order' ) ),
					array( 'id' => 'return_request', 'title' => 'درخواست مرجوعی', 'subtitle' => '', 'image' => $uploads . '2023/02/f18a182f7c300af9ce3eb8f47201ef340fc87eb3_1670930133.png', 'action' => array( 'type' => 'return_request' ) ),
					array( 'id' => 'store_payment', 'title' => 'پرداخت فروشگاه', 'subtitle' => '', 'image' => $uploads . '2023/02/ac127167132653d14c758748b07824a6a7643a31_1648897095.png', 'action' => array( 'type' => 'store_payment' ) ),
					array( 'id' => 'survey', 'title' => 'نظرسنجی فروشگاه', 'subtitle' => '', 'image' => $uploads . '2023/02/6b21cc5a4ebe6332b778a2f4725ed3fdaa78e014_1673693837.png', 'action' => array( 'type' => 'survey' ) ),
					array( 'id' => 'more', 'title' => 'بیشتر', 'subtitle' => '', 'image' => '', 'action' => array( 'type' => 'products' ) ),
				),
			),
			array(
				'id'       => 'amazing_offers',
				'type'     => 'products',
				'enabled'  => true,
				'title'    => 'پیشنهاد شگفت‌انگیز',
				'subtitle' => '',
				'layout'   => array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'category' => array(),
				'brand'    => array(),
				'on_sale'  => true,
				'per_page' => 10,
				'view_all_title' => 'مشاهده همه',
			),
			array(
				'id'       => 'special_categories',
				'type'     => 'category',
				'enabled'  => true,
				'title'    => 'دسته‌بندی‌های ویژه',
				'subtitle' => '',
				'layout'   => array( 'component' => 'category_grid', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 5 ),
				'include'  => array(),
				'config'   => array( 'include_names' => array( 'لپ‌تاپ', 'کامپیوتر و تجهیزات جانبی', 'اسپیکر', 'هدفون و هندزفری', 'تجهیزات ذخیره‌سازی' ) ),
			),
			array(
				'id'       => 'latest_products',
				'type'     => 'products',
				'enabled'  => true,
				'title'    => 'جدیدترین محصولات',
				'subtitle' => '',
				'layout'   => array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'category' => array(),
				'brand'    => array(),
				'on_sale'  => false,
				'per_page' => 10,
				'view_all_title' => 'مشاهده همه',
			),
			array(
				'id'             => 'computer_products',
				'type'           => 'products',
				'enabled'        => true,
				'title'          => 'کامپیوتر و تجهیزات جانبی',
				'subtitle'       => '',
				'layout'         => array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'category'       => array(),
				'category_names' => array( 'کامپیوتر و تجهیزات جانبی' ),
				'brand'          => array(),
				'on_sale'        => false,
				'per_page'       => 10,
				'view_all_title' => 'مشاهده همه',
			),
			array(
				'id'       => 'computer_promotions',
				'type'     => 'banner',
				'enabled'  => true,
				'title'    => '',
				'subtitle' => '',
				'layout'   => array( 'component' => 'banner_grid', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 2 ),
				'data'     => array(
					array( 'title' => '', 'subtitle' => '', 'image' => $uploads . '2026/07/YademanSystem_banner_computer.webp' ),
					array( 'title' => '', 'subtitle' => '', 'image' => $uploads . '2026/07/YademanSystem_banner_hardware.webp' ),
				),
			),
			array(
				'id'             => 'laptop_products',
				'type'           => 'products',
				'enabled'        => true,
				'title'          => 'لپ‌تاپ و لوازم جانبی',
				'subtitle'       => '',
				'layout'         => array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'category'       => array(),
				'category_names' => array( 'لپ‌تاپ' ),
				'brand'          => array(),
				'on_sale'        => false,
				'per_page'       => 10,
				'view_all_title' => 'مشاهده همه',
			),
			array(
				'id'             => 'speaker_products',
				'type'           => 'products',
				'enabled'        => true,
				'title'          => 'انواع اسپیکر',
				'subtitle'       => '',
				'layout'         => array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'category'       => array(),
				'category_names' => array( 'اسپیکر' ),
				'brand'          => array(),
				'on_sale'        => false,
				'per_page'       => 10,
				'view_all_title' => 'مشاهده همه',
			),
			array(
				'id'       => 'shop_by_category',
				'type'     => 'category',
				'enabled'  => true,
				'title'    => 'خرید بر اساس دسته‌بندی',
				'subtitle' => '',
				'layout'   => array( 'component' => 'category_grid', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 4 ),
				'include'  => array(),
				'config'   => array(
					'include_names' => array( 'لوازم جانبی لپ‌تاپ', 'پایه خنک‌کننده لپ‌تاپ', 'کیبور و ماوس', 'سخت‌افزار', 'اسپیکر بی‌سیم', 'گوشی موبایل', 'لوازم جانبی موبایل', 'هندزفری', 'هارد', 'تجهیزات شبکه', 'ماشین‌های اداری', 'تجهیزات بازی', 'کیف، کوله و کاور', 'تلویزیون', 'ساعت هوشمند', 'تمیز کننده' ),
				),
			),
			array(
				'id'       => 'popular_brands',
				'type'     => 'brand',
				'enabled'  => true,
				'title'    => 'محبوب‌ترین برندها',
				'subtitle' => '',
				'layout'   => array( 'component' => 'brand_grid', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 5 ),
				'include'  => array(),
				'config'   => array( 'include_names' => array( 'آئولا', 'اچ‌پی', 'ارلدام', 'انزو', 'ایسوس', 'تسکو', 'سامسونگ', 'سیلیکون پاور', 'فندا', 'لنوو' ) ),
			),
			array(
				'id'       => 'smartwatch_banner',
				'type'     => 'banner',
				'enabled'  => true,
				'title'    => '',
				'subtitle' => '',
				'layout'   => array( 'component' => 'banner', 'direction' => 'horizontal', 'rows' => 1, 'columns' => 1 ),
				'data'     => array(
					array( 'title' => '', 'subtitle' => '', 'image' => $uploads . '2026/07/YademanSystem_banner_smartwatch-scaled.webp' ),
				),
			),
		);
	}
}
