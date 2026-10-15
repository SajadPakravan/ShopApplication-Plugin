<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Request;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class CategoryPageBuilder {
	private $formatter;

	public function __construct( ?ProductFormatter $formatter = null ) {
		$this->formatter = $formatter ?: new ProductFormatter();
	}

	public function build( int $current_category_id ): array {
		$config   = Config::category_configuration();
		$sections = array();
		$position = 0;

		foreach ( $config['order'] as $key ) {
			if ( empty( $config['sections'][ $key ] ) || empty( $config['sections'][ $key ]['enabled'] ) ) {
				continue;
			}
			$section = $config['sections'][ $key ];
			$resolved = $this->resolve_section( $section, $position, $current_category_id );
			if ( null !== $resolved ) {
				$sections[] = $resolved;
				++$position;
			}
		}

		return $sections;
	}

	private function resolve_section( array $section, int $position, int $current_category_id ): ?array {
		$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
		if ( ! array_key_exists( $type, Config::category_section_types() ) ) {
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
			$base['data'] = $this->product_item( absint( $section['product_id'] ?? 0 ) );
			return $base;
		}
		if ( 'category' === $type ) {
			$base['data'] = $this->category_items( $section );
			return $base;
		}
		return null;
	}

	private function image_items( array $section ): array {
		$result = array();
		foreach ( (array) ( $section['data'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$image = '';
			if ( ! empty( $item['attachment_id'] ) ) {
				$image = Taxonomy::attachment_image_url( absint( $item['attachment_id'] ) );
			} elseif ( ! empty( $item['image'] ) ) {
				$image = esc_url_raw( (string) $item['image'] );
			}
			$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$result[] = array(
				'title'    => $title,
				'subtitle' => sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) ),
				'image'    => $image,
				'action'   => Config::normalize_image_action_output( $item['action'] ?? array(), $title ),
			);
		}
		return $result;
	}

	private function product_item( int $product_id ): array {
		if ( ! $product_id ) {
			return array();
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || 'publish' !== get_post_status( $product_id ) || $product->is_type( 'variation' ) ) {
			return array();
		}
		return array( $this->formatter->format_card( $product ) );
	}

	private function category_items( array $section ): array {
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
			if ( ! $include ) {
				return array();
			}
			$args['include'] = $include;
			$args['orderby'] = 'include';
			$args['number']  = count( $include );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$ids    = wp_list_pluck( $terms, 'term_id' );
		$counts = Taxonomy::published_product_counts( 'product_cat', array_map( 'absint', $ids ) );
		$result = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
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
