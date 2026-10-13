<?php

namespace AppAPI\Services;

use AppAPI\Config;

 defined( 'ABSPATH' ) || exit;

final class ProductDetailBuilder {
	private $repository;
	private $formatter;

	public function __construct( ?ProductRepository $repository = null, ?ProductFormatter $formatter = null ) {
		$this->repository = $repository ?: new ProductRepository();
		$this->formatter  = $formatter ?: new ProductFormatter();
	}

	/**
	 * Product detail lists have a fixed, semantic order. The response is appended
	 * after variations as: reviews, then related_products.
	 */
	public function build( \WC_Product $product ): array {
		$config = Config::product_detail_configuration();
		$result = array();

		$reviews = $config['sections']['reviews'] ?? array();
		if ( ! empty( $reviews['enabled'] ) ) {
			$result['reviews'] = $this->reviews( $product, $reviews );
		}

		$related = $config['sections']['related_products'] ?? array();
		if ( ! empty( $related['enabled'] ) ) {
			$result['related_products'] = $this->related_products( $product, $related );
		}

		return $result;
	}

	private function related_products( \WC_Product $product, array $section ): array {
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

		$block = array( 'data' => $data );
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$title = $this->view_all_title( $section );
			$block['view_all'] = array(
				'title'  => $title,
				'action' => array(
					'title'       => $title,
					'type'        => 'category',
					'category_id' => array_values( $category_ids ),
					'brand_id'    => array(),
					'on_sale'     => ! empty( $section['on_sale'] ),
					'url'         => null,
					'orderby'     => 'date',
					'order'       => 'desc',
				),
			);
		}

		return array( $block );
	}

	private function reviews( \WC_Product $product, array $section ): array {
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
					'post_id'  => $product->get_id(),
					'status'   => 'approve',
					'number'   => $per_page,
					'orderby'  => 'comment_date_gmt',
					'order'    => 'DESC',
					'meta_key' => 'rating',
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

		$block = array( 'data' => $data );
		if ( ! empty( $section['view_all_enabled'] ) ) {
			$title = $this->view_all_title( $section );
			$block['view_all'] = array(
				'title'  => $title,
				'action' => array(
					'title'       => $title,
					'type'        => 'all',
					'category_id' => array(),
					'brand_id'    => array(),
					'url'         => null,
					'orderby'     => 'date',
					'order'       => 'desc',
				),
			);
		}

		return array( $block );
	}

	private function view_all_title( array $section ): string {
		$title = sanitize_text_field( (string) ( $section['view_all_title'] ?? 'مشاهده همه' ) );
		return '' !== trim( $title ) ? $title : 'مشاهده همه';
	}
}
