<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Request;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the configurable /categories landing page.
 */
final class CategoryPageBuilder {
	private $formatter;
	private $repository;

	public function __construct( ?ProductFormatter $formatter = null, ?ProductRepository $repository = null ) {
		$this->formatter  = $formatter ?: new ProductFormatter();
		$this->repository = $repository ?: new ProductRepository();
	}

	public function build(): array {
		$config   = Config::category_configuration();
		$sections = array();
		$position = 0;

		foreach ( $config['order'] as $key ) {
			if ( empty( $config['sections'][ $key ] ) || empty( $config['sections'][ $key ]['enabled'] ) ) {
				continue;
			}

			$resolved = $this->resolve_section( $config['sections'][ $key ], $position );
			if ( null !== $resolved ) {
				$sections[] = $resolved;
				++$position;
			}
		}

		return $sections;
	}

	private function resolve_section( array $section, int $position ): ?array {
		$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
		if ( ! array_key_exists( $type, Config::category_section_types() ) ) {
			return null;
		}

		$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
		$base   = array(
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

		if ( 'products' === $type ) {
			$product_result = $this->products( $section );
			$base['data']   = $product_result['data'];
			if ( isset( $product_result['view_all'] ) ) {
				$base['view_all'] = $product_result['view_all'];
			}
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
				$image = Taxonomy::original_image_from_url( (string) $item['image'] );
			}

			$title    = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$result[] = array(
				'title'    => $title,
				'subtitle' => sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) ),
				'image'    => $image,
				'action'   => Config::normalize_image_action_output( $item['action'] ?? array(), $title ),
			);
		}
		return $result;
	}

	private function products( array $section ): array {
		$product_source = sanitize_key( (string) ( $section['product_source'] ?? ( ! empty( $section['product_id'] ) ? 'single' : 'list' ) ) );
		$data = array();

		if ( 'single' === $product_source ) {
			$product_id = absint( $section['product_id'] ?? 0 );
			if ( $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product instanceof \WC_Product && 'publish' === get_post_status( $product_id ) && ! $product->is_type( 'variation' ) ) {
					$data[] = $this->formatter->format_card( $product );
				}
			}
		} else {
			$category_ids = Request::ids( $section['category'] ?? array() );
			$per_page     = min( 50, max( 1, absint( $section['per_page'] ?? 10 ) ?: 10 ) );
			$params = array(
				'page'       => 1,
				'per_page'   => $per_page,
				'search'     => '',
				'type'       => null,
				'on_sale'    => ! empty( $section['on_sale'] ) ? true : null,
				'category'   => $category_ids,
				'brand'      => array(),
				'attributes' => array(),
				'tag'        => array(),
				'min_price'  => null,
				'max_price'  => null,
				'orderby'    => 'date',
				'order'      => 'desc',
			);
			$query = $this->repository->query( $params );
			foreach ( $query['products'] as $product ) {
				$product_id = $product->get_id();
				$data[] = $this->formatter->format_card( $product, $query['lookup'][ $product_id ] ?? array() );
			}
		}

		$result = array( 'data' => $data );
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$title = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
			$result['view_all'] = array(
				'title'  => $title,
				'action' => $this->products_view_all_action( $section, $title ),
			);
		}
		return $result;
	}

	private function products_view_all_action( array $section, string $title ): array {
		$action = array(
			'title'       => $title,
			'type'        => 'all',
			'product_id'  => null,
			'category_id' => array(),
			'brand_id'    => array(),
			'on_sale'     => null,
			'url'         => null,
			'orderby'     => 'date',
			'order'       => 'desc',
		);
		$product_source = sanitize_key( (string) ( $section['product_source'] ?? ( ! empty( $section['product_id'] ) ? 'single' : 'list' ) ) );
		if ( 'single' === $product_source ) {
			$product_id = absint( $section['product_id'] ?? 0 );
			if ( $product_id ) {
				$action['type']       = 'product';
				$action['product_id'] = $product_id;
			}
			return $action;
		}
		$categories = Request::ids( $section['category'] ?? array() );
		$action['category_id'] = array_values( $categories );
		$action['on_sale']     = ! empty( $section['on_sale'] );
		if ( $categories ) {
			$action['type'] = 'category';
		}
		return $action;
	}

	/**
	 * Supports four category display modes:
	 * - parents: all top-level categories.
	 * - children: direct children of one selected parent category.
	 * - custom: explicit category IDs in administrator-defined order.
	 * - tree: all top-level categories with every descendant nested below them.
	 */
	private function category_items( array $section ): array {
		$source = sanitize_key( (string) ( $section['category_source'] ?? 'custom' ) );
		if ( ! in_array( $source, array( 'custom', 'parents', 'children', 'tree' ), true ) ) {
			$source = 'custom';
		}

		if ( 'tree' === $source ) {
			return $this->full_category_tree();
		}

		if ( 'parents' === $source ) {
			return $this->terms_for_parent( 0 );
		}

		if ( 'children' === $source ) {
			$parent_id = absint( $section['parent_category'] ?? 0 );
			return $parent_id ? $this->terms_for_parent( $parent_id ) : array();
		}

		$include = Request::ids( $section['include'] ?? array() );
		if ( ! $include ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $include,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_id = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_id[ (int) $term->term_id ] = $term;
			}
		}
		$ordered = array();
		foreach ( $include as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return $this->format_terms( $ordered );
	}

	private function terms_for_parent( int $parent_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => $parent_id,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$this->sort_terms( $terms );
		return $this->format_terms( $terms );
	}

	private function full_category_tree(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) || ! $terms ) {
			return array();
		}

		$groups = array();
		$ids    = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$groups[ (int) $term->parent ][] = $term;
			$ids[] = (int) $term->term_id;
		}
		foreach ( $groups as &$group ) {
			$this->sort_terms( $group );
		}
		unset( $group );

		$counts = Taxonomy::published_product_counts( 'product_cat', $ids );
		return $this->build_tree_branch( 0, $groups, $counts );
	}

	private function build_tree_branch( int $parent_id, array $groups, array $counts ): array {
		$result = array();
		foreach ( $groups[ $parent_id ] ?? array() as $term ) {
			$item             = $this->format_term( $term, $counts );
			$item['children'] = $this->build_tree_branch( (int) $term->term_id, $groups, $counts );
			$result[]         = $item;
		}
		return $result;
	}

	private function format_terms( array $terms ): array {
		$ids    = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		$counts = Taxonomy::published_product_counts( 'product_cat', $ids );
		$result = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$result[] = $this->format_term( $term, $counts );
			}
		}
		return $result;
	}

	private function format_term( \WP_Term $term, array $counts ): array {
		$id = (int) $term->term_id;
		return array(
			'id'    => $id,
			'name'  => (string) $term->name,
			'count' => (int) ( $counts[ $id ] ?? $term->count ),
			'image' => Taxonomy::term_image_url( $term ),
		);
	}

	private function sort_terms( array &$terms ): void {
		usort(
			$terms,
			static function ( $a, $b ) {
				$a_order = $a instanceof \WP_Term ? (int) get_term_meta( $a->term_id, 'order', true ) : 0;
				$b_order = $b instanceof \WP_Term ? (int) get_term_meta( $b->term_id, 'order', true ) : 0;
				if ( $a_order === $b_order ) {
					$a_name = $a instanceof \WP_Term ? (string) $a->name : '';
					$b_name = $b instanceof \WP_Term ? (string) $b->name : '';
					return strnatcasecmp( $a_name, $b_name );
				}
				return $a_order <=> $b_order;
			}
		);
	}
}
