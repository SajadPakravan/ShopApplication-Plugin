<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Request;
use AppAPI\Support\Taxonomy;

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
			if ( ! is_array( $section ) || ( isset( $section['enabled'] ) && ! $section['enabled'] ) ) {
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
			'layout'   => isset( $section['layout'] ) && is_array( $section['layout'] ) ? $this->sanitize_array( $section['layout'] ) : array(),
		);

		if ( ! empty( $section['action'] ) && is_array( $section['action'] ) ) {
			$base['action'] = $this->resolve_action( $section['action'] );
		}

		switch ( $type ) {
			case 'banner_slider':
			case 'promo_banners':
				$base['data'] = $this->banners( $section );
				return $base;

			case 'action_menu':
				$base['data'] = $this->action_items( $section );
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

			case 'brands':
				$base['data'] = $this->brands( $section );
				return $base;

			case 'faq':
				$base['data'] = $this->faq( $section );
				return $base;

			case 'custom':
				$base['data'] = isset( $section['data'] ) ? $this->sanitize_array( $section['data'] ) : array();
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
				$image = $this->attachment_image_url( absint( $banner['attachment_id'] ) );
			}

			$item = array(
				'id'       => isset( $banner['id'] ) ? sanitize_key( $banner['id'] ) : 'banner_' . ( $index + 1 ),
				'title'    => isset( $banner['title'] ) ? sanitize_text_field( $banner['title'] ) : '',
				'subtitle' => isset( $banner['subtitle'] ) ? sanitize_text_field( $banner['subtitle'] ) : '',
				'image'    => $image ?: '',
				'action'   => isset( $banner['action'] ) && is_array( $banner['action'] ) ? $this->resolve_action( $banner['action'] ) : array(),
			);

			if ( ! empty( $banner['url'] ) ) {
				$item['url'] = esc_url_raw( $banner['url'] );
			}

			$result[] = $item;
		}

		return apply_filters( 'app_api_home_banners', $result, $section );
	}

	private function action_items( array $section ): array {
		$items  = isset( $section['data'] ) && is_array( $section['data'] ) ? $section['data'] : array();
		$result = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$image = isset( $item['image'] ) ? esc_url_raw( $item['image'] ) : '';
			if ( ! $image && ! empty( $item['attachment_id'] ) ) {
				$image = $this->attachment_image_url( absint( $item['attachment_id'] ) );
			}

			$result[] = array(
				'id'     => isset( $item['id'] ) ? sanitize_key( $item['id'] ) : 'action_' . ( $index + 1 ),
				'title'  => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'image'  => $image ?: '',
				'action' => isset( $item['action'] ) && is_array( $item['action'] ) ? $this->resolve_action( $item['action'] ) : array(),
			);
		}

		return $result;
	}

	private function menu( array $section ): array {
		$config    = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
		$location  = isset( $config['location'] ) ? sanitize_key( $config['location'] ) : Config::HOME_MENU_LOCATION;
		$locations = get_nav_menu_locations();
		$menu_id   = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;

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
				'title'     => sanitize_text_field( $item->title ),
				'icon'      => $icon ? esc_url_raw( $icon ) : '',
				'action'    => $action,
			);
		}

		return $result;
	}

	private function products( array $section ): array {
		$query = isset( $section['query'] ) && is_array( $section['query'] ) ? $section['query'] : array();
		$type  = isset( $query['type'] ) ? trim( (string) $query['type'] ) : '';

		$category_ids = Request::ids( $query['category'] ?? ( $query['categories'] ?? array() ) );
		$category_ids = array_values( array_unique( array_merge(
			$category_ids,
			$this->resolve_term_ids( 'product_cat', $query['category_names'] ?? array(), $query['category_slugs'] ?? array() )
		) ) );

		$brand_taxonomy = Taxonomy::brand_taxonomy();
		$brand_ids      = Request::ids( $query['brand'] ?? ( $query['brands'] ?? array() ) );
		if ( $brand_taxonomy ) {
			$brand_ids = array_values( array_unique( array_merge(
				$brand_ids,
				$this->resolve_term_ids( $brand_taxonomy, $query['brand_names'] ?? array(), $query['brand_slugs'] ?? array() )
			) ) );
		}

		$tag_ids = Request::ids( $query['tag'] ?? ( $query['tags'] ?? array() ) );
		$tag_ids = array_values( array_unique( array_merge(
			$tag_ids,
			$this->resolve_term_ids( 'product_tag', $query['tag_names'] ?? array(), $query['tag_slugs'] ?? array() )
		) ) );

		$params = array(
			'page'      => 1,
			'per_page'  => min( 30, max( 1, absint( $query['per_page'] ?? 10 ) ) ),
			'search'    => isset( $query['search'] ) ? sanitize_text_field( $query['search'] ) : '',
			'type'      => '' === $type ? null : sanitize_key( $type ),
			'on_sale'   => Request::nullable_boolean( $query['on_sale'] ?? null ),
			'category'  => $category_ids,
			'brand'     => $brand_ids,
			'tag'       => $tag_ids,
			'min_price' => isset( $query['min_price'] ) && '' !== $query['min_price'] ? max( 0, (float) $query['min_price'] ) : null,
			'max_price' => isset( $query['max_price'] ) && '' !== $query['max_price'] ? max( 0, (float) $query['max_price'] ) : null,
			'orderby'   => in_array( $query['orderby'] ?? 'date', array( 'price', 'date', 'rating', 'id', 'title', 'popularity' ), true ) ? $query['orderby'] : 'date',
			'order'     => 'asc' === strtolower( $query['order'] ?? 'desc' ) ? 'asc' : 'desc',
		);

		$result = $this->repository->query( $params );
		$data   = array();

		foreach ( $result['products'] as $product ) {
			$product_id = $product->get_id();
			$data[]     = $this->formatter->format_card( $product, $result['lookup'][ $product_id ] ?? array() );
		}

		return array(
			'data' => $data,
			'meta' => array(
				'total_items' => $result['total'],
				'query'       => $params,
			),
		);
	}

	private function categories( array $section ): array {
		$config  = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
		$include = Request::ids( $config['include'] ?? array() );
		$include = array_values( array_unique( array_merge(
			$include,
			$this->resolve_term_ids( 'product_cat', $config['include_names'] ?? array(), $config['include_slugs'] ?? array() )
		) ) );

		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => isset( $config['hide_empty'] ) ? (bool) $config['hide_empty'] : true,
			'number'     => min( 100, max( 1, absint( $config['limit'] ?? ( $include ? count( $include ) : 8 ) ) ) ),
			'orderby'    => in_array( $config['orderby'] ?? 'count', array( 'name', 'slug', 'term_id', 'count', 'include' ), true ) ? $config['orderby'] : 'count',
			'order'      => 'asc' === strtolower( $config['order'] ?? 'desc' ) ? 'ASC' : 'DESC',
		);

		if ( array_key_exists( 'parent', $config ) ) {
			$args['parent'] = absint( $config['parent'] );
		}

		if ( $include ) {
			$args['include'] = $include;
			$args['orderby'] = 'include';
			$args['number']  = count( $include );
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$result = array();
		foreach ( $terms as $term ) {
			$result[] = $this->format_term( $term, 'category' );
		}

		return $result;
	}

	private function brands( array $section ): array {
		$taxonomy = Taxonomy::brand_taxonomy();
		if ( ! $taxonomy ) {
			return array();
		}

		$config  = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
		$include = Request::ids( $config['include'] ?? array() );
		$include = array_values( array_unique( array_merge(
			$include,
			$this->resolve_term_ids( $taxonomy, $config['include_names'] ?? array(), $config['include_slugs'] ?? array() )
		) ) );

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => isset( $config['hide_empty'] ) ? (bool) $config['hide_empty'] : true,
			'number'     => min( 100, max( 1, absint( $config['limit'] ?? ( $include ? count( $include ) : 10 ) ) ) ),
			'orderby'    => $include ? 'include' : 'count',
			'order'      => 'DESC',
		);

		if ( $include ) {
			$args['include'] = $include;
			$args['number']  = count( $include );
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$result = array();
		foreach ( $terms as $term ) {
			$result[] = $this->format_term( $term, 'brand' );
		}

		return $result;
	}

	private function faq( array $section ): array {
		$fallback = isset( $section['data'] ) && is_array( $section['data'] ) ? $this->sanitize_faq_items( $section['data'] ) : array();
		$config   = isset( $section['config'] ) && is_array( $section['config'] ) ? $section['config'] : array();
		$source   = isset( $config['source'] ) ? sanitize_key( $config['source'] ) : '';

		if ( 'front_page' !== $source ) {
			return $fallback;
		}

		$extracted = $this->front_page_faq_items();
		if ( ! $extracted ) {
			return $fallback;
		}

		if ( ! $fallback ) {
			return $extracted;
		}

		$answer_map = array();
		foreach ( $extracted as $item ) {
			$answer_map[ $this->normalize_text( $item['question'] ) ] = $item['answer'];
		}

		$matched = 0;
		foreach ( $fallback as &$item ) {
			$key = $this->normalize_text( $item['question'] );
			if ( isset( $answer_map[ $key ] ) && '' !== $answer_map[ $key ] ) {
				$item['answer'] = $answer_map[ $key ];
				++$matched;
			}
		}
		unset( $item );

		return $matched ? $fallback : $extracted;
	}

	private function front_page_faq_items(): array {
		$page_id = (int) get_option( 'page_on_front' );
		if ( ! $page_id ) {
			return array();
		}

		$content = (string) get_post_field( 'post_content', $page_id );
		if ( '' === trim( $content ) ) {
			return array();
		}

		$items = array();
		$pattern = '~\[(?<tag>woodmart_accordion_item|vc_toggle|accordion_item|accordion-item|faq_item|faq-item)\b(?<atts>[^\]]*)\](?<body>.*?)\[/\k<tag>\]~is';
		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts  = shortcode_parse_atts( $match['atts'] );
				$title = '';
				if ( is_array( $atts ) ) {
					$title = isset( $atts['title'] ) ? $atts['title'] : ( $atts['question'] ?? '' );
				}

				$question = sanitize_text_field( wp_strip_all_tags( (string) $title ) );
				$answer   = $this->clean_faq_answer( $match['body'] );
				if ( $question ) {
					$items[] = array( 'question' => $question, 'answer' => $answer );
				}
			}
		}

		$rendered = apply_filters( 'the_content', $content );
		if ( preg_match_all( '~<details\b[^>]*>\s*<summary\b[^>]*>(.*?)</summary>(.*?)</details>~is', $rendered, $details, PREG_SET_ORDER ) ) {
			foreach ( $details as $detail ) {
				$question = sanitize_text_field( wp_strip_all_tags( $detail[1] ) );
				$answer   = wp_kses_post( trim( $detail[2] ) );
				if ( $question ) {
					$items[] = array( 'question' => $question, 'answer' => $answer );
				}
			}
		}

		return $this->unique_faq_items( $items );
	}

	private function sanitize_faq_items( array $items ): array {
		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
			if ( ! $question ) {
				continue;
			}

			$result[] = array(
				'question' => $question,
				'answer'   => isset( $item['answer'] ) ? wp_kses_post( $item['answer'] ) : '',
			);
		}

		return $this->unique_faq_items( $result );
	}

	private function unique_faq_items( array $items ): array {
		$result = array();
		$seen   = array();

		foreach ( $items as $item ) {
			$key = $this->normalize_text( $item['question'] );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]      = $item;
		}

		return $result;
	}

	private function clean_faq_answer( string $answer ): string {
		$answer = strip_shortcodes( $answer );
		$answer = wpautop( trim( $answer ) );
		return wp_kses_post( $answer );
	}

	private function format_term( \WP_Term $term, string $action_type ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
			'image'  => $this->term_image_url( $term ),
			'action' => array(
				'type' => $action_type,
				'id'   => (int) $term->term_id,
			),
		);
	}

	private function term_image_url( \WP_Term $term ): string {
		$keys = array(
			'thumbnail_id',
			'image_id',
			'brand_image_id',
			'product_brand_image_id',
			'pwb_brand_image',
			'yith_wcbr_image',
			'berocket_term_thumbnail_id',
			'logo_id',
			'logo',
			'image',
		);

		foreach ( $keys as $key ) {
			$value = get_term_meta( $term->term_id, $key, true );
			$url   = $this->image_value_url( $value );
			if ( $url ) {
				return $url;
			}
		}

		$all_meta = get_term_meta( $term->term_id );
		foreach ( $all_meta as $key => $values ) {
			if ( ! preg_match( '/(?:image|thumbnail|logo)/i', (string) $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$url = $this->image_value_url( maybe_unserialize( $value ) );
				if ( $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	private function image_value_url( $value ): string {
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return $this->attachment_image_url( (int) $value );
		}

		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return $this->original_image_from_url( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( array( 'id', 'attachment_id', 'image_id' ) as $id_key ) {
				if ( ! empty( $value[ $id_key ] ) ) {
					$url = $this->attachment_image_url( absint( $value[ $id_key ] ) );
					if ( $url ) {
						return $url;
					}
				}
			}
			foreach ( array( 'url', 'image', 'src' ) as $url_key ) {
				if ( ! empty( $value[ $url_key ] ) && filter_var( $value[ $url_key ], FILTER_VALIDATE_URL ) ) {
					return $this->original_image_from_url( $value[ $url_key ] );
				}
			}
		}

		return '';
	}

	private function original_image_from_url( string $url ): string {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return '';
		}

		$attachment_id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0;
		if ( ! $attachment_id ) {
			$without_size = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+(?:$|\?))/i', '', $url );
			if ( is_string( $without_size ) && $without_size !== $url && function_exists( 'attachment_url_to_postid' ) ) {
				$attachment_id = attachment_url_to_postid( $without_size );
			}
		}

		return $attachment_id ? $this->attachment_image_url( (int) $attachment_id ) : $url;
	}

	private function attachment_image_url( int $attachment_id ): string {
		if ( ! $attachment_id ) {
			return '';
		}

		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$url = wp_get_original_image_url( $attachment_id );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( $url ) {
			return esc_url_raw( $url );
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		return $url ? esc_url_raw( $url ) : '';
	}

	private function resolve_action( array $action ): array {
		$type = isset( $action['type'] ) ? sanitize_key( $action['type'] ) : '';
		if ( ! $type ) {
			return array();
		}

		$result = array( 'type' => $type );

		if ( in_array( $type, array( 'category', 'brand', 'tag' ), true ) ) {
			$taxonomy = 'category' === $type ? 'product_cat' : ( 'tag' === $type ? 'product_tag' : Taxonomy::brand_taxonomy() );
			$id       = ! empty( $action['id'] ) ? absint( $action['id'] ) : 0;

			if ( ! $id && $taxonomy ) {
				$term = $this->find_term(
					$taxonomy,
					isset( $action['name'] ) ? (string) $action['name'] : '',
					isset( $action['slug'] ) ? (string) $action['slug'] : ''
				);
				$id = $term ? (int) $term->term_id : 0;
			}

			if ( $id ) {
				$result['id'] = $id;
			}
			return $result;
		}

		if ( 'product' === $type && ! empty( $action['id'] ) ) {
			$result['id'] = absint( $action['id'] );
			return $result;
		}

		if ( 'url' === $type ) {
			$result['url'] = isset( $action['url'] ) ? esc_url_raw( $action['url'] ) : '';
			return $result;
		}

		foreach ( $action as $key => $value ) {
			$key = sanitize_key( $key );
			if ( 'type' === $key || 'name' === $key || 'slug' === $key ) {
				continue;
			}
			$result[ $key ] = $this->sanitize_value( $value );
		}

		return $result;
	}

	private function resolve_term_ids( string $taxonomy, $names, $slugs ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$names  = is_array( $names ) ? $names : ( '' !== trim( (string) $names ) ? array( $names ) : array() );
		$slugs  = is_array( $slugs ) ? $slugs : ( '' !== trim( (string) $slugs ) ? array( $slugs ) : array() );
		$result = array();

		foreach ( $names as $name ) {
			$term = $this->find_term( $taxonomy, (string) $name, '' );
			if ( $term ) {
				$result[] = (int) $term->term_id;
			}
		}

		foreach ( $slugs as $slug ) {
			$term = $this->find_term( $taxonomy, '', (string) $slug );
			if ( $term ) {
				$result[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( $result ) );
	}

	private function find_term( string $taxonomy, string $name = '', string $slug = '' ): ?\WP_Term {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		if ( '' !== trim( $slug ) ) {
			$term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}

		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}

		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			return $term;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'search'     => $name,
				'number'     => 50,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return null;
		}

		$target = $this->normalize_text( $name );
		foreach ( $terms as $candidate ) {
			if ( $target === $this->normalize_text( $candidate->name ) ) {
				return $candidate;
			}
		}

		return null;
	}

	private function normalize_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = str_replace( array( 'ي', 'ى', 'ك', "\xE2\x80\x8C", "\xC2\xA0" ), array( 'ی', 'ی', 'ک', ' ', ' ' ), $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = (string) $text;
		return trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text ) );
	}

	private function sanitize_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ is_int( $key ) ? $key : sanitize_key( $key ) ] = $this->sanitize_value( $item );
		}
		return $result;
	}

	private function sanitize_value( $value ) {
		if ( is_array( $value ) ) {
			return $this->sanitize_array( $value );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}
}
