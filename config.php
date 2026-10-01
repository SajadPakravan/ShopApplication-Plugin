<?php

namespace AppAPI;

defined( 'ABSPATH' ) || exit;

final class Config {
	public const REST_NAMESPACE       = 'app-api/v1';
	public const DEFAULT_PER_PAGE     = 20;
	public const MAX_PER_PAGE         = 100;
	public const DEFAULT_HOME_CACHE   = 60;
	public const HOME_MENU_LOCATION   = 'app-api-home-menu';
	public const OPTION_HOME_SECTIONS = 'app_api_home_sections_json';
	public const OPTION_HOME_BANNERS  = 'app_api_home_banners_json';
	public const OPTION_HOME_CACHE    = 'app_api_home_cache_ttl';
	public const OPTION_CACHE_VERSION = 'app_api_home_cache_version';

	/**
	 * The order of this array is the render order in the Flutter application.
	 * Each item can be overridden from WooCommerce > Application API.
	 */
	public static function default_home_sections(): array {
		return array(
			array(
				'id'      => 'banner_slider',
				'type'    => 'banner_slider',
				'enabled' => true,
				'title'   => '',
				'layout'  => array(
					'component'    => 'banner_slider',
					'aspect_ratio' => 2.2,
				),
			),
			array(
				'id'      => 'main_menu',
				'type'    => 'menu',
				'enabled' => true,
				'title'   => '',
				'config'  => array(
					'location' => self::HOME_MENU_LOCATION,
				),
				'layout'  => array(
					'component' => 'icon_menu',
					'columns'   => 4,
				),
			),
			array(
				'id'      => 'amazing_offers',
				'type'    => 'products',
				'enabled' => true,
				'title'   => 'پیشنهادهای شگفت‌انگیز',
				'query'   => array(
					'on_sale' => true,
					'orderby' => 'date',
					'order'   => 'desc',
					'per_page'=> 10,
				),
				'layout'  => array(
					'component' => 'product_carousel',
					'direction' => 'horizontal',
				),
			),
			array(
				'id'      => 'special_categories',
				'type'    => 'categories',
				'enabled' => true,
				'title'   => 'دسته‌بندی‌های ویژه',
				'config'  => array(
					'limit'      => 8,
					'parent'     => 0,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'desc',
				),
				'layout'  => array(
					'component' => 'category_grid',
					'columns'   => 4,
				),
			),
			array(
				'id'      => 'latest_products',
				'type'    => 'products',
				'enabled' => true,
				'title'   => 'جدیدترین محصولات',
				'query'   => array(
					'orderby' => 'date',
					'order'   => 'desc',
					'per_page'=> 10,
				),
				'layout'  => array(
					'component' => 'product_carousel',
					'direction' => 'horizontal',
				),
			),
		);
	}
}
