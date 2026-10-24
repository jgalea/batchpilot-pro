<?php
namespace BatchPilotPro\Targets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\FilterDefinition;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;

final class TermTarget implements TargetInterface {

	private const SUPPORTED = [ 'term-delete' ];

	public function slug(): string {
		return 'term';
	}

	public function label(): string {
		return __( 'Taxonomy terms', 'batchpilot-pro' );
	}

	public function get_filters(): array {
		return [
			new FilterDefinition(
				'ids',
				__( 'Specific term IDs', 'batchpilot-pro' ),
				'ids',
				[ 'multiple' => true ]
			),
			new FilterDefinition(
				'taxonomy',
				__( 'Taxonomy', 'batchpilot-pro' ),
				'enum',
				[
					'multiple' => true,
					'options'  => $this->taxonomy_options(),
				]
			),
			new FilterDefinition( 'parent', __( 'Parent term (ID)', 'batchpilot-pro' ), 'post' ),
			new FilterDefinition(
				'has_posts',
				__( 'Has at least one post', 'batchpilot-pro' ),
				'bool'
			),
		];
	}

	public function query( QueryArgs $args, int $limit = 0, int $offset = 0 ): array {
		$query = $this->build_query_args( $args );

		$query['number'] = $limit > 0 ? $limit : 0;
		$query['offset'] = $offset;
		$query['fields'] = 'ids';

		$results = \get_terms( $query );
		if ( \is_wp_error( $results ) ) {
			return [];
		}

		return array_map( 'intval', (array) $results );
	}

	public function count( QueryArgs $args ): int {
		$query = $this->build_query_args( $args );

		// WP_Term_Query supports 'count' but only when fields=count.
		$query['fields'] = 'count';
		$query['number'] = 0;
		$query['offset'] = 0;

		$result = \get_terms( $query );
		if ( \is_wp_error( $result ) ) {
			return 0;
		}
		return (int) $result;
	}

	public function get_display( int $id ): array {
		$term = \get_term( $id );
		if ( null === $term || \is_wp_error( $term ) ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		return [
			'id'            => (int) $term->term_id,
			'title'         => sprintf( '%s (%s)', $term->name, $term->slug ),
			'status'        => sprintf( '%d %s', (int) $term->count, __( 'posts', 'batchpilot-pro' ) ),
			'date'          => '',
			'edit_url'      => (string) \get_edit_term_link( (int) $term->term_id, (string) $term->taxonomy ),
			'thumbnail_url' => null,
		];
	}

	public function supports_operation( string $operation_slug ): bool {
		return in_array( $operation_slug, self::SUPPORTED, true );
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	private function taxonomy_options(): array {
		$out = [];
		foreach ( \get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			$out[] = [
				'label' => isset( $tax->labels->name ) ? (string) $tax->labels->name : (string) $tax->name,
				'value' => (string) $tax->name,
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_query_args( QueryArgs $args ): array {
		$query = [
			'hide_empty' => false,
			// Default: query across all public taxonomies. Overridden if 'taxonomy' filter is set.
			'taxonomy'   => array_keys( \get_taxonomies( [ 'public' => true ] ) ),
		];

		if ( $args->has( 'ids' ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', (array) $args->get( 'ids' ) ),
					static fn ( int $id ): bool => $id > 0
				)
			);
			if ( ! empty( $ids ) ) {
				$query['include'] = $ids;
			}
		}

		if ( $args->has( 'taxonomy' ) ) {
			$taxes = (array) $args->get( 'taxonomy' );
			$taxes = array_values( array_filter( array_map( 'strval', $taxes ) ) );
			if ( ! empty( $taxes ) ) {
				$query['taxonomy'] = $taxes;
			}
		}

		if ( $args->has( 'parent' ) ) {
			$query['parent'] = (int) $args->get( 'parent' );
		}

		if ( $args->has( 'has_posts' ) ) {
			$has_posts = (bool) $args->get( 'has_posts' );
			if ( $has_posts ) {
				$query['hide_empty'] = true;
			} else {
				$query['hide_empty']   = false;
				$query['childless']    = false;
				$query['meta_compare'] = '0';
			}
		}

		return $query;
	}
}
