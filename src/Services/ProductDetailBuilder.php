<?php

namespace AppAPI\Services;

use AppAPI\Config;
use AppAPI\Support\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class ProductDetailBuilder {
	private $repository;
	private $formatter;

	public function __construct( ?ProductRepository $repository = null, ?ProductFormatter $formatter = null ) {
		$this->repository = $repository ?: new ProductRepository();
		$this->formatter  = $formatter ?: new ProductFormatter();
	}

	public function build( \WC_Product $product ): array {
		$config   = Config::product_detail_configuration();
		$sections = array();
		$position = 0;

		foreach ( $config['order'] as $key ) {
			if ( empty( $config['sections'][ $key ] ) || empty( $config['sections'][ $key ]['enabled'] ) ) {
				continue;
			}
			$section = $config['sections'][ $key ];
			$resolved = 'related_products' === $key
				? $this->related_products_section( $product, $section, $position )
				: $this->reviews_section( $product, $section, $position );
			if ( $resolved ) {
				$sections[] = $resolved;
				++$position;
			}
		}

		return $sections;
	}

	private function related_products_section( \WC_Product $product, array $section, int $position ): array {
		$category_ids = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
		$category_ids = is_wp_error( $category_ids ) ? array() : array_values( array_filter( array_map( 'absint', (array) $category_ids ) ) );
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
			'exclude'    => array( $product->get_id() ),
		);

		$data = array();
		if ( $category_ids ) {
			$query = $this->repository->query( $params );
			foreach ( $query['products'] as $related ) {
				$id     = $related->get_id();
				$data[] = $this->formatter->format_card( $related, $query['lookup'][ $id ] ?? array() );
			}
		}

		$result = $this->base_section( $section, $position, 'products' );
		$result['data'] = $data;
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$result['view_all'] = array(
				'title' => sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه',
				'action' => array(
					'title'          => sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه',
					'type'           => 'all',
					'destination_id' => null,
					'on_sale'        => ! empty( $section['on_sale'] ),
					'url'            => null,
					'orderby'        => $this->related_orderby( (string) ( $section['view_all_orderby'] ?? 'date' ) ),
					'order'          => $this->order( (string) ( $section['view_all_order'] ?? 'desc' ) ),
				),
			);
		}
		return $result;
	}

	private function reviews_section( \WC_Product $product, array $section, int $position ): array {
		$per_page = min( 50, max( 1, absint( $section['per_page'] ?? 10 ) ?: 10 ) );
		$comments = get_comments(
			array(
				'post_id' => $product->get_id(),
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => $per_page,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		// Older WooCommerce installations stored reviews as ordinary comments.
		if ( ! $comments ) {
			$comments = get_comments(
				array(
					'post_id' => $product->get_id(),
					'status'  => 'approve',
					'number'  => $per_page,
					'orderby' => 'comment_date_gmt',
					'order'   => 'DESC',
					'meta_key'=> 'rating',
				)
			);
		}

		$data = array();
		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$data[] = array(
				'id'      => (int) $comment->comment_ID,
				'author'  => (string) $comment->comment_author,
				'rating'  => (int) get_comment_meta( $comment->comment_ID, 'rating', true ),
				'content' => (string) wp_kses_post( $comment->comment_content ),
				'date'    => mysql2date( 'Y-m-d', $comment->comment_date, false ),
			);
		}

		$result = $this->base_section( $section, $position, 'reviews' );
		$result['data'] = $data;
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$title = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) ) ?: 'مشاهده همه';
			$result['view_all'] = array(
				'title' => $title,
				'action' => array(
					'title'          => $title,
					'type'           => 'all',
					'destination_id' => null,
					'url'            => null,
					'orderby'        => in_array( $section['view_all_orderby'] ?? '', array( 'date', 'rating' ), true ) ? $section['view_all_orderby'] : 'date',
					'order'          => $this->order( (string) ( $section['view_all_order'] ?? 'desc' ) ),
				),
			);
		}
		return $result;
	}

	private function base_section( array $section, int $position, string $type ): array {
		$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
		return array(
			'id'       => sanitize_key( (string) ( $section['id'] ?? $type ) ),
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
	}

	private function related_orderby( string $orderby ): string {
		$orderby = sanitize_key( $orderby );
		if ( 'cout_sales' === $orderby ) {
			$orderby = 'count_sales';
		}
		return in_array( $orderby, Config::allowed_action_orderby(), true ) ? $orderby : 'date';
	}

	private function order( string $order ): string {
		$order = strtolower( sanitize_key( $order ) );
		return in_array( $order, array( 'asc', 'desc' ), true ) ? $order : 'desc';
	}
}
