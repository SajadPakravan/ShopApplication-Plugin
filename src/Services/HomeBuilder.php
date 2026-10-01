<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Request;

defined( 'ABSPATH' ) || exit;

final class HomeBuilder {
	private $repository;
	private $formatter;

	public function __construct( ?ProductRepository $repository = null, ?ProductFormatter $formatter = null ) {
		$this->repository = $repository ?: new ProductRepository();
		$this->formatter  = $formatter ?: new ProductFormatter();
	}

	public function build(): array {
		$sections = array();
		$position = 0;

		foreach ( $this->configured_sections() as $section ) {
			if ( ! is_array( $section ) || isset( $section['enabled'] ) && ! $section['enabled'] ) {
				continue;
			}

			$resolved = $this->resolve_section( $section, $position );
			if ( null !== $resolved ) {
				$sections[] = $resolved;
				++$position;
			}
		}

		return $sections;
	}

	private function configured_sections(): array {
		$raw      = get_option( Config::OPTION_HOME_SECTIONS, '' );
		$sections = is_string( $raw ) && $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $sections ) ) {
			$sections = Config::default_home_sections();
		}

		return apply_filters( 'app_api_home_sections', $sections );
	}

	private function resolve_section( array $section, int $position ): ?array {
		$type = isset( $section['type'] ) ? sanitize_key( $section['type'] ) : '';
		$id   = isset( $section['id'] ) ? sanitize_key( $section['id'] ) : $type . '_' . $position;

		$base = array(
			'id'       => $id,
			'type'     => $type,
			'position' => $position,
			'title'    => isset( $section['title'] ) ? sanitize_text_field( $section['title'] ) : '',
			'layout'   => isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array(),
		);

		switch ( $type ) {
			case 'banner_slider':
				$base['data'] = $this->banners( $section );
				return $base;

			case 'menu':
				$base['data'] = $this->menu( $section );
				return $base;

			case 'products':
				$product_data = $this->products( $section );
				$base['data'] = $product_data['data'];
				$base['meta'] = $product_data['meta'];
				return $base;

			case 'categories':
				$base['data'] = $this->categories( $section );
				return $base;

			case 'custom':
				$base['data'] = isset( $section['data'] ) ? $section['data'] : array();
				return apply_filters( 'app_api_home_custom_section', $base, $section );
		}

		return apply_filters( 'app_api_home_unknown_section', null, $section, $position );
	}

	private function banners( array $section ): array {
		$banners = isset( $section['data'] ) && is_array( $section['data'] ) ? $section['data'] : null;

		if ( null === $banners ) {
			$raw     = get_option( Config::OPTION_HOME_BANNERS, '[]' );
			$banners = is_string( $raw ) ? json_decode( $raw, true ) : array();
		}

		if ( ! is_array( $banners ) ) {
			$banners = array();
		}

		$result = array();
		foreach ( $banners as $index => $banner ) {
			if ( ! is_array( $banner ) ) {
				continue;
			}

			$image = isset( $banner['image'] ) ? esc_url_raw( $banner['image'] ) : '';
			if ( ! $image && ! empty( $banner['attachment_id'] ) ) {
				$image = wp_get_attachment_image_url( absint( $banner['attachment_id'] ), 'full' );
			}

			$result[] = array(
				'id'       => isset( $banner['id'] ) ? sanitize_key( $banner['id'] ) : 'banner_' . ( $index + 1 ),
				'title'    => isset( $banner['title'] ) ? sanitize_text_field( $banner['title'] ) : '',
				'subtitle' => isset( $banner['subtitle'] ) ? sanitize_text_field( $banner['subtitle'] ) : '',
				'image'    => $image ?: '',
				'url'      => isset( $banner['url'] ) ? esc_url_raw( $banner['url'] ) : '',
				'action'   => isset( $banner['action'] ) && is_array( $banner['action'] ) ? $banner['action'] : array(),
			);
		}

		return apply_filters( 'app_api_home_banners', $result, $section );
	}

	private function menu( array $section ): array {
		$config   = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
		$location = isset( $config['location'] ) ? sanitize_key( $config['location'] ) : Config::HOME_MENU_LOCATION;
		$locations= get_nav_menu_locations();
		$menu_id  = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;

		if ( ! $menu_id && ! empty( $config['menu_id'] ) ) {
			$menu_id = absint( $config['menu_id'] );
		}

		$items = $menu_id ? wp_get_nav_menu_items( $menu_id ) : array();
		if ( ! is_array( $items ) ) {
			return array();
		}

		$result = array();
		foreach ( $items as $item ) {
			$icon   = get_post_meta( $item->ID, '_app_api_icon', true );
			$action = array( 'type' => 'url', 'url' => esc_url_raw( $item->url ) );

			if ( 'product_cat' === $item->object ) {
				$action = array( 'type' => 'category', 'id' => (int) $item->object_id );
			} elseif ( 'product' === $item->object ) {
				$action = array( 'type' => 'product', 'id' => (int) $item->object_id );
			} elseif ( 'product_tag' === $item->object ) {
				$action = array( 'type' => 'tag', 'id' => (int) $item->object_id );
			}

			$result[] = array(
				'id'        => (int) $item->ID,
				'parent_id' => (int) $item->menu_item_parent,
				'order'     => (int) $item->menu_order,
				'title'     => $item->title,
				'url'       => esc_url_raw( $item->url ),
				'target'    => $item->target ?: '_self',
				'icon'      => $icon ? esc_url_raw( $icon ) : '',
				'action'    => $action,
			);
		}

		return $result;
	}

	private function products( array $section ): array {
		$query = isset( $section['query'] ) && is_array( $section['query'] ) ? $section['query'] : array();

		$params = array(
			'page'       => 1,
			'per_page'   => min( 30, max( 1, absint( $query['per_page'] ?? 10 ) ) ),
			'search'     => isset( $query['search'] ) ? sanitize_text_field( $query['search'] ) : '',
			'types'      => Request::slugs( $query['type'] ?? array() ),
			'on_sale'    => Request::nullable_boolean( $query['on_sale'] ?? null ),
			'categories' => Request::ids( $query['categories'] ?? array() ),
			'brands'     => Request::ids( $query['brands'] ?? array() ),
			'tags'       => Request::ids( $query['tags'] ?? array() ),
			'min_price'  => isset( $query['min_price'] ) && '' !== $query['min_price'] ? max( 0, (float) $query['min_price'] ) : null,
			'max_price'  => isset( $query['max_price'] ) && '' !== $query['max_price'] ? max( 0, (float) $query['max_price'] ) : null,
			'orderby'    => in_array( $query['orderby'] ?? 'date', array( 'price', 'date', 'rating', 'id', 'title', 'popularity' ), true ) ? $query['orderby'] : 'date',
			'order'      => 'asc' === strtolower( $query['order'] ?? 'desc' ) ? 'asc' : 'desc',
		);

		$result = $this->repository->query( $params );

		return array(
			'data' => array_map( array( $this->formatter, 'format' ), $result['products'] ),
			'meta' => array(
				'total_items' => $result['total'],
				'query'       => $params,
			),
		);
	}

	private function categories( array $section ): array {
		$config = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
		$args   = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => isset( $config['hide_empty'] ) ? (bool) $config['hide_empty'] : true,
			'number'     => min( 100, max( 1, absint( $config['limit'] ?? 8 ) ) ),
			'orderby'    => in_array( $config['orderby'] ?? 'count', array( 'name', 'slug', 'term_id', 'count', 'include' ), true ) ? $config['orderby'] : 'count',
			'order'      => 'asc' === strtolower( $config['order'] ?? 'desc' ) ? 'ASC' : 'DESC',
		);

		if ( array_key_exists( 'parent', $config ) ) {
			$args['parent'] = absint( $config['parent'] );
		}

		$include = Request::ids( $config['include'] ?? array() );
		if ( $include ) {
			$args['include'] = $include;
			$args['orderby'] = 'include';
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( $term ) {
				$thumbnail_id = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
				return array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'parent' => (int) $term->parent,
					'count'  => (int) $term->count,
					'image'  => $thumbnail_id ? ( wp_get_attachment_image_url( $thumbnail_id, 'woocommerce_thumbnail' ) ?: '' ) : '',
				);
			},
			$terms
		);
	}
}
