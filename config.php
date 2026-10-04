<?php

namespace AppAPI;

defined( 'ABSPATH' ) || exit;

final class Config {
	public const REST_NAMESPACE            = 'app-api/v1';
	public const DEFAULT_PER_PAGE          = 20;
	public const MAX_PER_PAGE              = 100;
	public const DEFAULT_HOME_CACHE        = 60;
	public const HOME_MENU_LOCATION        = 'app-api-home-menu';
	public const OPTION_HOME_SECTIONS      = 'app_api_home_sections_json';
	public const OPTION_HOME_CONFIG        = 'app_api_home_configuration';
	public const OPTION_HOME_BANNERS       = 'app_api_home_banners_json';
	public const OPTION_HOME_CACHE         = 'app_api_home_cache_ttl';
	public const OPTION_CACHE_VERSION      = 'app_api_home_cache_version';
	public const OPTION_HOME_PRESET_VERSION = 'app_api_home_preset_version';

	/**
	 * The array order is the exact render order in the Flutter application.
	 * Internal section IDs are only configuration keys and are never exposed by
	 * the home endpoint. Flutter selects the renderer from each section's type.
	 */
	public static function default_home_sections(): array {
		return self::uses_yademan_preset()
			? self::yademan_home_sections()
			: self::generic_home_sections();
	}


	/**
	 * Returns the structured, form-managed home configuration.
	 *
	 * Version 2.1 no longer requires administrators to edit JSON. Existing JSON
	 * settings are imported automatically the first time this method is used.
	 */
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

	public static function section_labels(): array {
		$labels = array();
		foreach ( self::default_home_sections() as $section ) {
			if ( ! empty( $section['id'] ) ) {
				$labels[ sanitize_key( $section['id'] ) ] = isset( $section['title'] ) && '' !== trim( (string) $section['title'] )
					? (string) $section['title']
					: self::fallback_section_label( sanitize_key( $section['id'] ), sanitize_key( $section['type'] ?? '' ) );
			}
		}
		return $labels;
	}

	public static function default_layout_for_type( string $type ): array {
		switch ( sanitize_key( $type ) ) {
			case 'banner_slider':
				return array( 'component' => 'banner_slider', 'direction' => 'horizontal', 'columns' => 1 );
			case 'action_menu':
			case 'menu':
				return array( 'component' => 'action_menu', 'direction' => 'horizontal', 'columns' => 5 );
			case 'products':
				return array( 'component' => 'product_carousel', 'direction' => 'horizontal', 'columns' => 1 );
			case 'categories':
				return array( 'component' => 'category_grid', 'direction' => 'horizontal', 'columns' => 5 );
			case 'brands':
				return array( 'component' => 'brand_carousel', 'direction' => 'horizontal', 'columns' => 5 );
			case 'promo_banners':
				return array( 'component' => 'banner_grid', 'direction' => 'horizontal', 'columns' => 2 );
			default:
				return array( 'component' => $type ?: 'custom', 'direction' => 'vertical', 'columns' => 1 );
		}
	}

	private static function configuration_from_sections( array $sections ): array {
		$result = array( 'order' => array(), 'sections' => array() );
		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$id = sanitize_key( $section['id'] ?? ( 'section_' . $index ) );
			if ( ! $id ) {
				continue;
			}
			$section['id']       = $id;
			$section['subtitle'] = isset( $section['subtitle'] ) ? (string) $section['subtitle'] : '';
			$section['layout']   = isset( $section['layout'] ) && is_array( $section['layout'] )
				? array_merge( self::default_layout_for_type( (string) ( $section['type'] ?? '' ) ), $section['layout'] )
				: self::default_layout_for_type( (string) ( $section['type'] ?? '' ) );
			if ( isset( $section['data'] ) && is_array( $section['data'] ) ) {
				foreach ( $section['data'] as &$item ) {
					if ( is_array( $item ) ) {
						$item['title']    = isset( $item['title'] ) ? (string) $item['title'] : '';
						$item['subtitle'] = isset( $item['subtitle'] ) ? (string) $item['subtitle'] : '';
					}
				}
				unset( $item );
			}
			$result['order'][]       = $id;
			$result['sections'][ $id ] = $section;
		}
		return $result;
	}

	private static function normalize_home_configuration( array $configuration ): array {
		$defaults = self::default_home_configuration();
		$sections = isset( $configuration['sections'] ) && is_array( $configuration['sections'] ) ? $configuration['sections'] : array();
		$order    = isset( $configuration['order'] ) && is_array( $configuration['order'] ) ? $configuration['order'] : array();

		$normalized = array();
		foreach ( $defaults['sections'] as $id => $default ) {
			$current = isset( $sections[ $id ] ) && is_array( $sections[ $id ] ) ? $sections[ $id ] : array();
			$merged  = array_replace_recursive( $default, $current );
			// Numeric/repeater arrays must be replaceable in full so removing an
			// item in the visual editor does not resurrect its default counterpart.
			if ( array_key_exists( 'data', $current ) && is_array( $current['data'] ) ) {
				$merged['data'] = array_values( $current['data'] );
			}
			if ( array_key_exists( 'category', $current ) && is_array( $current['category'] ) ) {
				$merged['category'] = array_values( $current['category'] );
			}
			if ( isset( $current['config'] ) && is_array( $current['config'] ) && array_key_exists( 'include', $current['config'] ) ) {
				$merged['config']['include'] = array_values( (array) $current['config']['include'] );
			}
			$merged['id']       = $id;
			$merged['type']     = sanitize_key( $merged['type'] ?? $default['type'] ?? 'custom' );
			$merged['enabled']  = ! empty( $merged['enabled'] );
			$merged['title']    = isset( $merged['title'] ) ? (string) $merged['title'] : '';
			$merged['subtitle'] = isset( $merged['subtitle'] ) ? (string) $merged['subtitle'] : '';
			$merged['layout']   = array_replace(
				self::default_layout_for_type( $merged['type'] ),
				isset( $merged['layout'] ) && is_array( $merged['layout'] ) ? $merged['layout'] : array()
			);
			$normalized[ $id ] = $merged;
		}

		// Preserve custom sections created through filters or older versions.
		foreach ( $sections as $id => $section ) {
			$id = sanitize_key( (string) $id );
			if ( ! $id || isset( $normalized[ $id ] ) || ! is_array( $section ) ) {
				continue;
			}
			$section['id']       = $id;
			$section['type']     = sanitize_key( $section['type'] ?? 'custom' );
			$section['enabled']  = ! empty( $section['enabled'] );
			$section['title']    = isset( $section['title'] ) ? (string) $section['title'] : '';
			$section['subtitle'] = isset( $section['subtitle'] ) ? (string) $section['subtitle'] : '';
			$section['layout']   = array_replace( self::default_layout_for_type( $section['type'] ), $section['layout'] ?? array() );
			$normalized[ $id ]   = $section;
		}

		$clean_order = array();
		foreach ( $order as $id ) {
			$id = sanitize_key( (string) $id );
			if ( $id && isset( $normalized[ $id ] ) && ! in_array( $id, $clean_order, true ) ) {
				$clean_order[] = $id;
			}
		}
		foreach ( array_keys( $normalized ) as $id ) {
			if ( ! in_array( $id, $clean_order, true ) ) {
				$clean_order[] = $id;
			}
		}

		return array( 'order' => $clean_order, 'sections' => $normalized );
	}

	private static function fallback_section_label( string $id, string $type ): string {
		$known = array(
			'hero_banners'        => 'اسلاید بنرها',
			'quick_actions'       => 'منوی دسترسی سریع',
			'amazing_offers'      => 'پیشنهاد شگفت‌انگیز',
			'special_categories'  => 'دسته‌بندی‌های ویژه',
			'latest_products'     => 'جدیدترین محصولات',
			'computer_products'   => 'کامپیوتر و تجهیزات جانبی',
			'computer_promotions' => 'بنرهای کامپیوتر و سخت‌افزار',
			'laptop_products'     => 'لپ‌تاپ و لوازم جانبی',
			'speaker_products'    => 'انواع اسپیکر',
			'shop_by_category'    => 'خرید بر اساس دسته‌بندی',
			'popular_brands'      => 'محبوب‌ترین برندها',
			'smartwatch_banner'   => 'بنر ساعت هوشمند',
		);
		return $known[ $id ] ?? ( $type ?: $id );
	}

	public static function uses_yademan_preset(): bool {
		$host = function_exists( 'home_url' ) ? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) : '';
		$host = strtolower( (string) $host );

		return 'yademansystem.ir' === $host || 'www.yademansystem.ir' === $host;
	}

	private static function generic_home_sections(): array {
		return array(
			array(
				'id'      => 'banner_slider',
				'type'    => 'banner_slider',
				'enabled' => true,
				'title'   => '',
			),
			array(
				'id'      => 'main_menu',
				'type'    => 'menu',
				'enabled' => true,
				'title'   => '',
				'config'  => array(
					'location' => self::HOME_MENU_LOCATION,
				),
			),
			array(
				'id'        => 'amazing_offers',
				'type'      => 'products',
				'enabled'   => true,
				'title'     => 'پیشنهادهای شگفت‌انگیز',
				'source'    => 'on_sale',
				'per_page'  => 10,
				'view_all'  => array(
					'title'  => 'مشاهده همه',
					'action' => array( 'type' => 'products', 'on_sale' => true ),
				),
			),
			array(
				'id'      => 'special_categories',
				'type'    => 'categories',
				'enabled' => true,
				'title'   => 'دسته‌بندی‌های ویژه',
				'config'  => array(
					'include'    => array(),
					'limit'      => 8,
					'parent'     => 0,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'desc',
				),
			),
			array(
				'id'       => 'latest_products',
				'type'     => 'products',
				'enabled'  => true,
				'title'    => 'جدیدترین محصولات',
				'source'   => 'latest',
				'per_page' => 10,
			),
		);
	}

	private static function yademan_home_sections(): array {
		$uploads = 'https://yademansystem.ir/wp-content/uploads/';

		return array(
			array(
				'id'      => 'hero_banners',
				'type'    => 'banner_slider',
				'enabled' => true,
				'title'   => '',
				'data'    => array(
					array(
						'id'     => 'laptop_banner',
						'image'  => $uploads . '2026/06/YademanSystem_banner_Laptop.webp',
						'action' => array( 'type' => 'category', 'name' => 'لپ‌تاپ' ),
					),
					array(
						'id'     => 'speaker_banner',
						'image'  => $uploads . '2026/06/YademanSystem_banner_Speaker.webp',
						'action' => array( 'type' => 'category', 'name' => 'اسپیکر' ),
					),
				),
			),
			array(
				'id'      => 'quick_actions',
				'type'    => 'action_menu',
				'enabled' => true,
				'title'   => '',
				'data'    => array(
					array( 'id' => 'application', 'title' => 'اپلیکیشن فروشگاه', 'image' => $uploads . '2023/02/33f94db0e93a29b08b5c45d7932dbb114791155d_1658329987.png', 'action' => array( 'type' => 'app_download' ) ),
					array( 'id' => 'sale', 'title' => 'حراجی', 'image' => $uploads . '2023/02/258db5bf0ff7b28dbae1bfb3dfaa71bfff32faf9_1654679397.png', 'action' => array( 'type' => 'products', 'on_sale' => true ) ),
					array( 'id' => 'purchase_consulting', 'title' => 'مشاوره خرید', 'image' => $uploads . '2023/02/6c69096a524add2d4646cd162dfa5f66d4ddceac_1668952039.png', 'action' => array( 'type' => 'purchase_consulting' ) ),
					array( 'id' => 'goods_order', 'title' => 'سفارش اجناس', 'image' => $uploads . '2023/02/17bb6daa07ae2ec11867fb7320ed6f79b26f1f4b_1648897081.png', 'action' => array( 'type' => 'goods_order' ) ),
					array( 'id' => 'assembly_order', 'title' => 'سفارش مونتاژ', 'image' => $uploads . '2023/02/d0dc31c892be8cf1408e4e14580b3f479da66bd1_1648897133.png', 'action' => array( 'type' => 'assembly_order' ) ),
					array( 'id' => 'repair_order', 'title' => 'سفارش تعمیرات', 'image' => $uploads . '2023/02/d919c238a583cacd2048a254e5623f81dd11ab24_16736372.png', 'action' => array( 'type' => 'repair_order' ) ),
					array( 'id' => 'return_request', 'title' => 'درخواست مرجوعی', 'image' => $uploads . '2023/02/f18a182f7c300af9ce3eb8f47201ef340fc87eb3_1670930133.png', 'action' => array( 'type' => 'return_request' ) ),
					array( 'id' => 'store_payment', 'title' => 'پرداخت فروشگاه', 'image' => $uploads . '2023/02/ac127167132653d14c758748b07824a6a7643a31_1648897095.png', 'action' => array( 'type' => 'store_payment' ) ),
					array( 'id' => 'survey', 'title' => 'نظرسنجی فروشگاه', 'image' => $uploads . '2023/02/6b21cc5a4ebe6332b778a2f4725ed3fdaa78e014_1673693837.png', 'action' => array( 'type' => 'survey' ) ),
					array( 'id' => 'more', 'title' => 'بیشتر', 'image' => '', 'action' => array( 'type' => 'products' ) ),
				),
			),
			array(
				'id'       => 'amazing_offers',
				'type'     => 'products',
				'enabled'  => true,
				'title'    => 'پیشنهاد شگفت‌انگیز',
				'source'   => 'on_sale',
				'per_page' => 10,
				'view_all' => array(
					'title'  => 'مشاهده همه',
					'action' => array( 'type' => 'products', 'on_sale' => true ),
				),
			),
			array(
				'id'      => 'special_categories',
				'type'    => 'categories',
				'enabled' => true,
				'title'   => 'دسته‌بندی‌های ویژه',
				'config'  => array(
					// Replace/include exact WooCommerce product-category IDs here.
					'include'       => array(),
					'include_names' => array( 'لپ‌تاپ', 'کامپیوتر و تجهیزات جانبی', 'اسپیکر', 'هدفون و هندزفری', 'تجهیزات ذخیره‌سازی' ),
					'hide_empty'    => false,
				),
			),
			array(
				'id'       => 'latest_products',
				'type'     => 'products',
				'enabled'  => true,
				'title'    => 'جدیدترین محصولات',
				'source'   => 'latest',
				'per_page' => 10,
			),
			array(
				'id'           => 'computer_products',
				'type'         => 'products',
				'enabled'      => true,
				'title'        => 'کامپیوتر و تجهیزات جانبی',
				'source'       => 'category',
				'category'     => array(),
				'category_names' => array( 'کامپیوتر و تجهیزات جانبی' ),
				'per_page'     => 10,
				'action'       => array( 'type' => 'category', 'name' => 'کامپیوتر و تجهیزات جانبی' ),
			),
			array(
				'id'      => 'computer_promotions',
				'type'    => 'promo_banners',
				'enabled' => true,
				'title'   => '',
				'data'    => array(
					array(
						'id'     => 'computer_accessories_banner',
						'image'  => $uploads . '2026/07/YademanSystem_banner_computer.webp',
						'action' => array( 'type' => 'category', 'name' => 'کامپیوتر و تجهیزات جانبی' ),
					),
					array(
						'id'     => 'hardware_banner',
						'image'  => $uploads . '2026/07/YademanSystem_banner_hardware.webp',
						'action' => array( 'type' => 'category', 'name' => 'سخت‌افزار' ),
					),
				),
			),
			array(
				'id'             => 'laptop_products',
				'type'           => 'products',
				'enabled'        => true,
				'title'          => 'لپ‌تاپ و لوازم جانبی',
				'source'         => 'category',
				'category'       => array(),
				'category_names' => array( 'لپ‌تاپ' ),
				'per_page'       => 10,
				'action'         => array( 'type' => 'category', 'name' => 'لپ‌تاپ' ),
			),
			array(
				'id'             => 'speaker_products',
				'type'           => 'products',
				'enabled'        => true,
				'title'          => 'انواع اسپیکر',
				'source'         => 'category',
				'category'       => array(),
				'category_names' => array( 'اسپیکر' ),
				'per_page'       => 10,
				'action'         => array( 'type' => 'category', 'name' => 'اسپیکر' ),
			),
			array(
				'id'      => 'shop_by_category',
				'type'    => 'categories',
				'enabled' => true,
				'title'   => 'خرید بر اساس دسته‌بندی',
				'config'  => array(
					// Replace/include exact WooCommerce product-category IDs here.
					'include'       => array(),
					'include_names' => array(
						'لوازم جانبی لپ‌تاپ',
						'پایه خنک‌کننده لپ‌تاپ',
						'کیبور و ماوس',
						'سخت‌افزار',
						'اسپیکر بی‌سیم',
						'گوشی موبایل',
						'لوازم جانبی موبایل',
						'هندزفری',
						'هارد',
						'تجهیزات شبکه',
						'ماشین‌های اداری',
						'تجهیزات بازی',
						'کیف، کوله و کاور',
						'تلویزیون',
						'ساعت هوشمند',
						'تمیز کننده',
					),
					'hide_empty' => false,
				),
			),
			array(
				'id'      => 'popular_brands',
				'type'    => 'brands',
				'enabled' => true,
				'title'   => 'محبوب‌ترین برندها',
				'config'  => array(
					// Replace/include exact brand term IDs here.
					'include'       => array(),
					'include_names' => array( 'آئولا', 'اچ‌پی', 'ارلدام', 'انزو', 'ایسوس', 'تسکو', 'سامسونگ', 'سیلیکون پاور', 'فندا', 'لنوو' ),
					'hide_empty'    => false,
				),
			),
			array(
				'id'      => 'smartwatch_banner',
				'type'    => 'promo_banners',
				'enabled' => true,
				'title'   => '',
				'data'    => array(
					array(
						'id'     => 'smartwatch',
						'image'  => $uploads . '2026/07/YademanSystem_banner_smartwatch-scaled.webp',
						'action' => array( 'type' => 'category', 'name' => 'ساعت هوشمند' ),
					),
				),
			),
		);
	}
}
