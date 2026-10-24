<?php
namespace BatchPilotPro\Targets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\FilterDefinition;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;

/**
 * WooCommerce products. Only registered when WooCommerce is active. Products
 * are a CPT (`product`) so the free DeleteOperation / DuplicateOperation /
 * BulkEditOperation work against them once this Target declares support.
 */
final class WooProductTarget implements TargetInterface {

	private const SUPPORTED = [ 'delete', 'duplicate', 'edit' ];

	public function slug(): string {
		return 'woo-product';
	}

	public function label(): string {
		return __( 'WooCommerce products', 'batchpilot-pro' );
	}

	public function get_filters(): array {
		return [
			new FilterDefinition(
				'ids',
				__( 'Specific product IDs', 'batchpilot-pro' ),
				'ids',
				[ 'multiple' => true ]
			),
			new FilterDefinition(
				'status',
				__( 'Status', 'batchpilot-pro' ),
				'enum',
				[
					'multiple' => true,
					'options'  => [
						[
							'label' => __( 'Published', 'batchpilot-pro' ),
							'value' => 'publish',
						],
						[
							'label' => __( 'Draft', 'batchpilot-pro' ),
							'value' => 'draft',
						],
						[
							'label' => __( 'Private', 'batchpilot-pro' ),
							'value' => 'private',
						],
						[
							'label' => __( 'Trash', 'batchpilot-pro' ),
							'value' => 'trash',
						],
					],
				]
			),
			new FilterDefinition(
				'product_type',
				__( 'Product type', 'batchpilot-pro' ),
				'enum',
				[
					'multiple' => true,
					'options'  => [
						[
							'label' => __( 'Simple', 'batchpilot-pro' ),
							'value' => 'simple',
						],
						[
							'label' => __( 'Variable', 'batchpilot-pro' ),
							'value' => 'variable',
						],
						[
							'label' => __( 'Grouped', 'batchpilot-pro' ),
							'value' => 'grouped',
						],
						[
							'label' => __( 'External / Affiliate', 'batchpilot-pro' ),
							'value' => 'external',
						],
					],
				]
			),
			new FilterDefinition(
				'stock_status',
				__( 'Stock status', 'batchpilot-pro' ),
				'enum',
				[
					'options' => [
						[
							'label' => __( 'In stock', 'batchpilot-pro' ),
							'value' => 'instock',
						],
						[
							'label' => __( 'Out of stock', 'batchpilot-pro' ),
							'value' => 'outofstock',
						],
						[
							'label' => __( 'On backorder', 'batchpilot-pro' ),
							'value' => 'onbackorder',
						],
					],
				]
			),
			new FilterDefinition(
				'on_sale',
				__( 'On sale right now', 'batchpilot-pro' ),
				'bool'
			),
			new FilterDefinition( 'modified_before', __( 'Modified before', 'batchpilot-pro' ), 'date' ),
			new FilterDefinition( 'modified_after', __( 'Modified after', 'batchpilot-pro' ), 'date' ),
		];
	}

	public function query( QueryArgs $args, int $limit = 0, int $offset = 0 ): array {
		$query_args                     = $this->build_wp_query_args( $args );
		$query_args['fields']           = 'ids';
		$query_args['posts_per_page']   = $limit > 0 ? $limit : -1;
		$query_args['offset']           = $offset;
		$query_args['no_found_rows']    = true;
		$query_args['suppress_filters'] = false;

		$query = new \WP_Query( $query_args );
		return array_map( 'intval', (array) $query->posts );
	}

	public function count( QueryArgs $args ): int {
		$query_args                   = $this->build_wp_query_args( $args );
		$query_args['fields']         = 'ids';
		$query_args['posts_per_page'] = 1;
		$query_args['no_found_rows']  = false;

		$query = new \WP_Query( $query_args );
		return (int) $query->found_posts;
	}

	public function get_display( int $id ): array {
		$post = \get_post( $id );
		if ( null === $post || 'product' !== $post->post_type ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		$thumb_id = (int) \get_post_thumbnail_id( $post );
		$thumb    = 0 === $thumb_id ? null : (string) \wp_get_attachment_image_url( $thumb_id, 'thumbnail' );

		return [
			'id'            => (int) $post->ID,
			'title'         => (string) $post->post_title,
			'status'        => (string) $post->post_status,
			'date'          => (string) $post->post_date,
			'edit_url'      => (string) \get_edit_post_link( $post->ID, 'raw' ),
			'thumbnail_url' => $thumb,
		];
	}

	public function supports_operation( string $operation_slug ): bool {
		return in_array( $operation_slug, self::SUPPORTED, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_wp_query_args( QueryArgs $args ): array {
		$query = [
			'post_type'   => 'product',
			'post_status' => 'any',
		];

		if ( $args->has( 'ids' ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', (array) $args->get( 'ids' ) ),
					static fn ( int $id ): bool => $id > 0
				)
			);
			if ( ! empty( $ids ) ) {
				$query['post__in'] = $ids;
			}
		}

		if ( $args->has( 'status' ) ) {
			$query['post_status'] = $args->get( 'status' );
		}

		$tax_query = [];
		if ( $args->has( 'product_type' ) ) {
			$types = (array) $args->get( 'product_type' );
			$types = array_values( array_filter( array_map( 'strval', $types ) ) );
			if ( ! empty( $types ) ) {
				$tax_query[] = [
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $types,
				];
			}
		}
		if ( ! empty( $tax_query ) ) {
			$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$meta_query = [];
		if ( $args->has( 'stock_status' ) ) {
			$meta_query[] = [
				'key'   => '_stock_status',
				'value' => (string) $args->get( 'stock_status' ),
			];
		}
		if ( $args->has( 'on_sale' ) && true === $args->get( 'on_sale' ) ) {
			// Products are "on sale" if _sale_price meta is non-empty.
			$meta_query[] = [
				'key'     => '_sale_price',
				'value'   => '',
				'compare' => '!=',
			];
		}
		if ( ! empty( $meta_query ) ) {
			$query['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$date_query = [];
		if ( $args->has( 'modified_before' ) ) {
			$date_query[] = [
				'before'    => (string) $args->get( 'modified_before' ),
				'column'    => 'post_modified',
				'inclusive' => true,
			];
		}
		if ( $args->has( 'modified_after' ) ) {
			$date_query[] = [
				'after'     => (string) $args->get( 'modified_after' ),
				'column'    => 'post_modified',
				'inclusive' => true,
			];
		}
		if ( ! empty( $date_query ) ) {
			$query['date_query'] = $date_query;
		}

		return $query;
	}
}
