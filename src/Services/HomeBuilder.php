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
			if ( ! is_array( $section ) || empty( $section['enabled'] ) ) {
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
		$configuration = Config::home_configuration();
		$sections      = array();

		foreach ( $configuration['order'] as $key ) {
			if ( isset( $configuration['sections'][ $key ] ) && is_array( $configuration['sections'][ $key ] ) ) {
				$sections[] = $configuration['sections'][ $key ];
			}
		}

		return apply_filters( 'app_api_home_sections', $sections );
	}

	private function resolve_section( array $section, int $position ): ?array {
		$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
		if ( ! in_array( $type, Config::allowed_section_types(), true ) ) {
			return apply_filters( 'app_api_home_unknown_section', null, $section, $position );
		}

		$layout = isset( $section['layout'] ) && is_array( $section['layout'] )
			? array_replace( Config::default_layout_for_type( $type ), $section['layout'] )
			: Config::default_layout_for_type( $type );

		$resolved_layout = array(
			'component' => $this->sanitize_component( (string) ( $layout['component'] ?? $type ), $type ),
			'direction' => in_array( $layout['direction'] ?? '', array( 'horizontal', 'vertical' ), true ) ? $layout['direction'] : 'horizontal',
			'rows'      => $this->positive_int( $layout['rows'] ?? 1, 12 ),
			'columns'   => $this->positive_int( $layout['columns'] ?? 1, 12 ),
		);

		$base = array(
			'id'       => $this->api_id( (string) ( $section['id'] ?? ( $type . '_' . $position ) ), $type . '_' . $position ),
			'type'     => $type,
			'position' => $position,
			'title'    => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
			'subtitle' => sanitize_text_field( (string) ( $section['subtitle'] ?? '' ) ),
			'layout'   => $resolved_layout,
		);

		switch ( $type ) {
			case 'image':
				$base['data'] = $this->image_items( $section );
				return $base;

			case 'products':
				$product_result   = $this->products( $section );
				$base['data']      = $product_result['data'];
				$base['view_all']  = $product_result['view_all'];
				return $base;

			case 'category':
				$base['data']     = $this->categories( $section );
				$base['view_all'] = $this->view_all( $section );
				return $base;

			case 'brand':
				$base['data']     = $this->brands( $section );
				$base['view_all'] = $this->view_all( $section );
				return $base;
		}

		return null;
	}

	private function image_items( array $section ): array {
		$items  = isset( $section['data'] ) && is_array( $section['data'] ) ? $section['data'] : array();
		$result = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$image = ! empty( $item['attachment_id'] )
				? $this->attachment_image_url( absint( $item['attachment_id'] ) )
				: $this->original_image_from_url( (string) ( $item['image'] ?? '' ) );

			$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$result[] = array(
				'title'    => $title,
				'subtitle' => sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) ),
				'image'    => $image ?: '',
				'action'   => $this->resolve_action(
					isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : array(),
					$title
				),
			);
		}

		return apply_filters( 'app_api_home_images', $result, $section );
	}

	private function products( array $section ): array {
		$category_ids = Request::ids( $section['category'] ?? array() );
		if ( ! $category_ids ) {
			$category_ids = $this->resolve_term_ids(
				'product_cat',
				$section['category_names'] ?? array(),
				$section['category_slugs'] ?? array()
			);
		}

		$brand_taxonomy = Taxonomy::brand_taxonomy();
		$brand_ids      = Request::ids( $section['brand'] ?? array() );
		if ( ! $brand_ids && $brand_taxonomy ) {
			$brand_ids = $this->resolve_term_ids(
				$brand_taxonomy,
				$section['brand_names'] ?? array(),
				$section['brand_slugs'] ?? array()
			);
		}

		$per_page = absint( $section['per_page'] ?? 10 );
		$per_page = min( 50, max( 1, $per_page ?: 10 ) );
		$on_sale  = ! empty( $section['on_sale'] );

		$params = array(
			'page'      => 1,
			'per_page'  => $per_page,
			'search'    => '',
			'type'      => null,
			'on_sale'   => $on_sale ? true : null,
			'category'  => $category_ids,
			'brand'     => $brand_ids,
			'tag'       => array(),
			'min_price' => null,
			'max_price' => null,
			'orderby'   => 'date',
			'order'     => 'desc',
		);

		$query = $this->repository->query( $params );
		$data  = array();

		foreach ( $query['products'] as $product ) {
			$product_id = $product->get_id();
			$data[]     = $this->formatter->format_card( $product, $query['lookup'][ $product_id ] ?? array() );
		}

		return array(
			'data'     => $data,
			'view_all' => $this->view_all( $section ),
		);
	}

	private function categories( array $section ): array {
		$include = Request::ids( $section['include'] ?? array() );
		if ( ! $include ) {
			$include = $this->resolve_term_ids( 'product_cat', $section['include_names'] ?? array(), $section['include_slugs'] ?? array() );
		}
		if ( ! $include ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $include,
				'orderby'    => 'include',
				'number'     => count( $include ),
			)
		);

		return $this->format_terms( $terms );
	}

	private function brands( array $section ): array {
		$taxonomy = Taxonomy::brand_taxonomy();
		if ( ! $taxonomy ) {
			return array();
		}

		$include = Request::ids( $section['include'] ?? array() );
		if ( ! $include ) {
			$include = $this->resolve_term_ids( $taxonomy, $section['include_names'] ?? array(), $section['include_slugs'] ?? array() );
		}
		if ( ! $include ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'include'    => $include,
				'orderby'    => 'include',
				'number'     => count( $include ),
			)
		);

		return $this->format_terms( $terms );
	}

	private function format_terms( $terms ): array {
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$result = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$result[] = $this->format_term( $term );
			}
		}
		return $result;
	}

	private function format_term( \WP_Term $term ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
			'image'  => $this->term_image_url( $term ),
		);
	}

	private function term_image_url( \WP_Term $term ): string {
		$keys = array(
			'thumbnail_id', 'image_id', 'brand_image_id', 'product_brand_image_id',
			'pwb_brand_image', 'yith_wcbr_image', 'berocket_term_thumbnail_id',
			'logo_id', 'logo', 'image',
		);

		foreach ( $keys as $key ) {
			$url = $this->image_value_url( get_term_meta( $term->term_id, $key, true ) );
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

	private function view_all( array $section ): array {
		$title  = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
		$action = isset( $section['view_all_action'] ) && is_array( $section['view_all_action'] )
			? $section['view_all_action']
			: array();

		return array(
			'title'  => $title,
			'action' => $this->resolve_action( $action, $title ),
		);
	}

	private function resolve_action( array $action, string $title ): array {
		return Config::normalize_action( $action, $title );
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
		return $term instanceof \WP_Term ? $term : null;
	}


	private function sanitize_component( string $component, string $fallback ): string {
		$component = trim( $component );
		$component = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $component );
		$component = trim( (string) $component, '_-' );
		return $component ?: $fallback;
	}

	private function api_id( string $id, string $fallback ): string {
		$id = strtolower( trim( $id ) );
		$id = preg_replace( '/[^a-z0-9_-]+/', '_', $id );
		$id = trim( (string) $id, '_-' );
		return $id ?: sanitize_key( $fallback );
	}

	private function positive_int( $value, int $maximum ): int {
		$value = absint( $value );
		return min( $maximum, max( 1, $value ?: 1 ) );
	}

	private function sanitize_value( $value ) {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$result[ is_int( $key ) ? $key : sanitize_key( (string) $key ) ] = $this->sanitize_value( $item );
			}
			return $result;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}
}
