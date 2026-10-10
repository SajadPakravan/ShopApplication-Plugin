<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Request;
use AppAPI\Support\Taxonomy;


defined( 'ABSPATH' ) || exit;

final class CategoryPageBuilder {
	private $repository;
	private $formatter;

	public function __construct( ?ProductRepository $repository = null, ?ProductFormatter $formatter = null ) {
		$this->repository = $repository ?: new ProductRepository();
		$this->formatter  = $formatter ?: new ProductFormatter();
	}

	public function build( \WP_Term $category ): array {
		$config   = Config::category_page_configuration();
		$sections = array();
		$position = 0;

		foreach ( $config['order'] as $key ) {
			if ( empty( $config['sections'][ $key ] ) || empty( $config['sections'][ $key ]['enabled'] ) ) {
				continue;
			}
			$resolved = $this->resolve_section( $config['sections'][ $key ], $position, $category );
			if ( null !== $resolved ) {
				$sections[] = $resolved;
				++$position;
			}
		}
		return $sections;
	}

	private function resolve_section( array $section, int $position, \WP_Term $page_category ): ?array {
		$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
		if ( ! in_array( $type, Config::allowed_category_section_types(), true ) ) {
			return null;
		}
		$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
		$base = array(
			'id'       => sanitize_key( (string) ( $section['id'] ?? $type . '_' . $position ) ),
			'type'     => $type,
			'position' => $position,
			'title'    => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
			'subtitle' => sanitize_text_field( (string) ( $section['subtitle'] ?? '' ) ),
			'layout'   => array(
				'direction' => in_array( $layout['direction'] ?? '', array( 'horizontal', 'vertical' ), true ) ? $layout['direction'] : 'horizontal',
				'rows'      => min( 12, max( 1, absint( $layout['rows'] ?? 1 ) ?: 1 ) ),
				'columns'   => min( 12, max( 1, absint( $layout['columns'] ?? 1 ) ?: 1 ) ),
			),
		);

		if ( 'image' === $type ) {
			$base['data'] = $this->image_items( $section );
			return $base;
		}
		if ( 'product' === $type ) {
			$product_id = absint( $section['product_id'] ?? 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			$base['data'] = array();
			if ( $product instanceof \WC_Product && ! $product->is_type( 'variation' ) && 'publish' === get_post_status( $product_id ) ) {
				$base['data'][] = $this->formatter->format_card( $product, $this->repository->lookup( $product_id ) );
			}
			return $base;
		}
		if ( 'category' === $type ) {
			$base['data'] = $this->categories( $section );
			if ( ! empty( $section['view_all_enabled'] ) ) {
				$title = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
				$base['view_all'] = array(
					'title'  => $title,
					'action' => Config::normalize_view_all_action( 'category', $section['view_all_action'] ?? array(), $title ),
				);
			}
			return $base;
		}
		return null;
	}

	private function image_items( array $section ): array {
		$items = isset( $section['data'] ) && is_array( $section['data'] ) ? $section['data'] : array();
		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$image = '';
			if ( ! empty( $item['attachment_id'] ) ) {
				$image = Taxonomy::attachment_image_url( absint( $item['attachment_id'] ) );
			} elseif ( ! empty( $item['image'] ) ) {
				$image = esc_url_raw( (string) $item['image'] );
			}
			$result[] = array(
				'title'    => $title,
				'subtitle' => sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) ),
				'image'    => $image ?: '',
				'action'   => Config::normalize_action( $item['action'] ?? array(), $title ),
			);
		}
		return $result;
	}

	private function categories( array $section ): array {
		$source = sanitize_key( (string) ( $section['category_source'] ?? 'custom' ) );
		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		);
		if ( 'parents' === $source ) {
			$args['parent']   = 0;
			$args['meta_key'] = 'order';
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'ASC';
		} else {
			$include = Request::ids( $section['include'] ?? array() );
			if ( ! $include ) { return array(); }
			$args['include'] = $include;
			$args['orderby'] = 'include';
			$args['number']  = count( $include );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return array(); }
		$ids = array_map( static function ( $term ) { return $term instanceof \WP_Term ? (int) $term->term_id : 0; }, $terms );
		$counts = Taxonomy::published_product_counts( 'product_cat', array_values( array_filter( $ids ) ) );
		$result = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) { continue; }
			$result[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'count' => (int) ( $counts[ (int) $term->term_id ] ?? $term->count ),
				'image' => Taxonomy::term_image_url( $term ),
			);
		}
		return $result;
	}
}
