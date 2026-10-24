<?php
namespace BatchPilotPro\Targets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\FilterDefinition;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;

/**
 * MediaTarget queries attachments (post_type=attachment) with media-specific
 * filters. Attachments ARE posts, so the free DeleteOperation /
 * DuplicateOperation / BulkEditOperation work against them unchanged once
 * this Target reports support for them.
 */
final class MediaTarget implements TargetInterface {

	private const SUPPORTED = [ 'delete', 'edit' ];

	public function slug(): string {
		return 'media';
	}

	public function label(): string {
		return __( 'Media', 'batchpilot-pro' );
	}

	public function get_filters(): array {
		return [
			new FilterDefinition(
				'ids',
				__( 'Specific attachment IDs', 'batchpilot-pro' ),
				'ids',
				[ 'multiple' => true ]
			),
			new FilterDefinition(
				'mime_type',
				__( 'MIME type', 'batchpilot-pro' ),
				'enum',
				[
					'multiple' => true,
					'options'  => [
						[
							'label' => __( 'Image', 'batchpilot-pro' ),
							'value' => 'image',
						],
						[
							'label' => __( 'Video', 'batchpilot-pro' ),
							'value' => 'video',
						],
						[
							'label' => __( 'Audio', 'batchpilot-pro' ),
							'value' => 'audio',
						],
						[
							'label' => __( 'Application (PDF, etc.)', 'batchpilot-pro' ),
							'value' => 'application',
						],
					],
				]
			),
			new FilterDefinition( 'uploaded_before', __( 'Uploaded before', 'batchpilot-pro' ), 'date' ),
			new FilterDefinition( 'uploaded_after', __( 'Uploaded after', 'batchpilot-pro' ), 'date' ),
			new FilterDefinition(
				'unattached',
				__( 'Unattached only (no parent post)', 'batchpilot-pro' ),
				'bool'
			),
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
		if ( null === $post || 'attachment' !== $post->post_type ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		$thumb = (string) \wp_get_attachment_image_url( (int) $post->ID, 'thumbnail' );

		return [
			'id'            => (int) $post->ID,
			'title'         => (string) $post->post_title,
			'status'        => (string) \get_post_mime_type( $post ),
			'date'          => (string) $post->post_date,
			'edit_url'      => (string) \get_edit_post_link( $post->ID, 'raw' ),
			'thumbnail_url' => '' !== $thumb ? $thumb : null,
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
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
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

		if ( $args->has( 'mime_type' ) ) {
			$mimes = (array) $args->get( 'mime_type' );
			$mimes = array_values( array_filter( array_map( 'strval', $mimes ) ) );
			if ( ! empty( $mimes ) ) {
				$query['post_mime_type'] = $mimes;
			}
		}

		$date_query = [];
		if ( $args->has( 'uploaded_before' ) ) {
			$date_query[] = [
				'before'    => (string) $args->get( 'uploaded_before' ),
				'column'    => 'post_date',
				'inclusive' => true,
			];
		}
		if ( $args->has( 'uploaded_after' ) ) {
			$date_query[] = [
				'after'     => (string) $args->get( 'uploaded_after' ),
				'column'    => 'post_date',
				'inclusive' => true,
			];
		}
		if ( ! empty( $date_query ) ) {
			$query['date_query'] = $date_query;
		}

		if ( $args->has( 'unattached' ) && true === $args->get( 'unattached' ) ) {
			$query['post_parent'] = 0;
		}

		return $query;
	}
}
