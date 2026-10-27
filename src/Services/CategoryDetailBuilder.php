<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the fixed category-detail payload appended after the category's direct children.
 * The semantic order is fixed: products first, brands second.
 */
final class CategoryDetailBuilder {
	private $repository;
	private $formatter;

	public function __construct( ?ProductRepository $repository = null, ?ProductFormatter $formatter = null ) {
		$this->repository = $repository ?: new ProductRepository();
		$this->formatter  = $formatter ?: new ProductFormatter();
	}

	public function build( \WP_Term $category ): array {
		$config = Config::category_detail_configuration();
		$result = array();

		$products = $config['sections']['products'] ?? array();
		if ( ! empty( $products['enabled'] ) ) {
			$result['products'] = $this->products( $category, $products );
		}

		$brands = $config['sections']['brands'] ?? array();
		if ( ! empty( $brands['enabled'] ) ) {
			$result['brands'] = $this->brands( $category, $brands );
		}

		return $result;
	}

	private function products( \WP_Term $category, array $section ): array {
		$per_page = min( 50, max( 1, absint( $section['per_page'] ?? 10 ) ?: 10 ) );
		$params = array(
			'page'       => 1,
			'per_page'   => $per_page,
			'search'     => '',
			'type'       => null,
			'on_sale'    => null,
			'category'   => array( (int) $category->term_id ),
			'brand'      => array(),
			'attributes' => array(),
			'tag'        => array(),
			'min_price'  => null,
			'max_price'  => null,
			'orderby'    => 'date',
			'order'      => 'desc',
		);

		$query = $this->repository->query( $params );
		$data  = array();
		foreach ( $query['products'] as $product ) {
			$product_id = $product->get_id();
			$data[] = $this->formatter->format_card( $product, $query['lookup'][ $product_id ] ?? array() );
		}

		$block = array( 'data' => $data );
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$title = $this->view_all_title( $section );
			$block['view_all'] = array(
				'title'  => $title,
				'action' => array(
					'title'       => $title,
					'type'        => 'category',
					'product_id'  => null,
					'category_id' => array( (int) $category->term_id ),
					'brand_id'    => array(),
					'on_sale'     => false,
					'url'         => null,
					'orderby'     => 'date',
					'order'       => 'desc',
				),
			);
		}

		return array( $block );
	}

	private function brands( \WP_Term $category, array $section ): array {
		$taxonomy = Taxonomy::brand_taxonomy();
		$all      = $taxonomy ? $this->brands_for_category( (int) $category->term_id, $taxonomy ) : array();
		$per_page = min( 50, max( 1, absint( $section['per_page'] ?? 10 ) ?: 10 ) );
		$data     = array_slice( $all, 0, $per_page );

		$block = array( 'data' => $data );
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$title     = $this->view_all_title( $section );
			$brand_ids = array_values( array_map( static fn( $item ) => (int) $item['id'], $all ) );
			$block['view_all'] = array(
				'title'  => $title,
				'action' => array(
					'title'       => $title,
					'type'        => 'brand',
					'product_id'  => null,
					'category_id' => array( (int) $category->term_id ),
					'brand_id'    => $brand_ids,
					'on_sale'     => null,
					'url'         => null,
					'orderby'     => 'date',
					'order'       => 'desc',
				),
			);
		}

		return array( $block );
	}

	/**
	 * Returns brands that actually occur on published products inside this category.
	 * The count is category-scoped rather than the global taxonomy count.
	 */
	private function brands_for_category( int $category_id, string $taxonomy ): array {
		$product_ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => array( $category_id ),
						'include_children' => true,
					),
				),
			)
		);

		$product_ids = array_values( array_filter( array_map( 'absint', (array) $product_ids ) ) );
		if ( ! $product_ids ) {
			return array();
		}

		$relations = wp_get_object_terms(
			$product_ids,
			$taxonomy,
			array( 'fields' => 'all_with_object_id' )
		);
		if ( is_wp_error( $relations ) || ! is_array( $relations ) ) {
			return array();
		}

		$terms  = array();
		$counts = array();
		$seen   = array();
		foreach ( $relations as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id   = (int) $term->term_id;
			$object_id = isset( $term->object_id ) ? (int) $term->object_id : 0;
			$terms[ $term_id ] = $term;
			if ( $object_id ) {
				$key = $term_id . ':' . $object_id;
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$counts[ $term_id ] = ( $counts[ $term_id ] ?? 0 ) + 1;
				}
			}
		}

		// Fallback for WordPress versions/storage engines that do not expose object_id.
		if ( $terms && ! $counts ) {
			foreach ( $product_ids as $product_id ) {
				$product_terms = wp_get_post_terms( $product_id, $taxonomy );
				if ( is_wp_error( $product_terms ) ) {
					continue;
				}
				foreach ( $product_terms as $term ) {
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$term_id = (int) $term->term_id;
					$terms[ $term_id ]  = $term;
					$counts[ $term_id ] = ( $counts[ $term_id ] ?? 0 ) + 1;
				}
			}
		}

		usort(
			$terms,
			static function ( $a, $b ) {
				return strnatcasecmp( (string) $a->name, (string) $b->name );
			}
		);

		$result = array();
		foreach ( $terms as $term ) {
			$id = (int) $term->term_id;
			$result[] = array(
				'id'    => $id,
				'name'  => (string) $term->name,
				'count' => (int) ( $counts[ $id ] ?? 0 ),
				'image' => Taxonomy::term_image_url( $term ),
			);
		}
		return $result;
	}

	private function view_all_title( array $section ): string {
		$title = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) );
		return '' !== trim( $title ) ? $title : 'مشاهده همه';
	}
}
