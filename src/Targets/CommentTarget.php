<?php
namespace BatchPilotPro\Targets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\FilterDefinition;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;

final class CommentTarget implements TargetInterface {

	private const SUPPORTED = [ 'comment-delete' ];

	public function slug(): string {
		return 'comment';
	}

	public function label(): string {
		return __( 'Comments', 'batchpilot-pro' );
	}

	public function get_filters(): array {
		return [
			new FilterDefinition(
				'ids',
				__( 'Specific comment IDs', 'batchpilot-pro' ),
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
							'label' => __( 'Approved', 'batchpilot-pro' ),
							'value' => 'approve',
						],
						[
							'label' => __( 'Pending', 'batchpilot-pro' ),
							'value' => 'hold',
						],
						[
							'label' => __( 'Spam', 'batchpilot-pro' ),
							'value' => 'spam',
						],
						[
							'label' => __( 'Trash', 'batchpilot-pro' ),
							'value' => 'trash',
						],
					],
				]
			),
			new FilterDefinition( 'post_id', __( 'Post (ID)', 'batchpilot-pro' ), 'post' ),
			new FilterDefinition( 'date_before', __( 'Posted before', 'batchpilot-pro' ), 'date' ),
			new FilterDefinition( 'date_after', __( 'Posted after', 'batchpilot-pro' ), 'date' ),
			new FilterDefinition(
				'type',
				__( 'Type', 'batchpilot-pro' ),
				'enum',
				[
					'options' => [
						[
							'label' => __( 'Comment', 'batchpilot-pro' ),
							'value' => 'comment',
						],
						[
							'label' => __( 'Pingback', 'batchpilot-pro' ),
							'value' => 'pingback',
						],
						[
							'label' => __( 'Trackback', 'batchpilot-pro' ),
							'value' => 'trackback',
						],
					],
				]
			),
		];
	}

	public function query( QueryArgs $args, int $limit = 0, int $offset = 0 ): array {
		$query = $this->build_query_args( $args );

		$query['number'] = $limit > 0 ? $limit : 0;
		$query['offset'] = $offset;
		$query['fields'] = 'ids';

		$comment_query = new \WP_Comment_Query();
		$ids           = (array) $comment_query->query( $query );

		return array_map( 'intval', $ids );
	}

	public function count( QueryArgs $args ): int {
		$query           = $this->build_query_args( $args );
		$query['count']  = true;
		$query['number'] = 0;
		$query['offset'] = 0;

		$comment_query = new \WP_Comment_Query();
		return (int) $comment_query->query( $query );
	}

	public function get_display( int $id ): array {
		$comment = \get_comment( $id );
		if ( null === $comment ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		$author  = '' !== $comment->comment_author ? $comment->comment_author : __( '(no author)', 'batchpilot-pro' );
		$excerpt = wp_trim_words( (string) $comment->comment_content, 12, '…' );

		return [
			'id'            => (int) $comment->comment_ID,
			'title'         => sprintf( '%s: %s', $author, $excerpt ),
			'status'        => (string) wp_get_comment_status( $comment ),
			'date'          => (string) $comment->comment_date,
			'edit_url'      => (string) get_edit_comment_link( (int) $comment->comment_ID ),
			'thumbnail_url' => (string) get_avatar_url( (string) $comment->comment_author_email, [ 'size' => 64 ] ),
		];
	}

	public function supports_operation( string $operation_slug ): bool {
		return in_array( $operation_slug, self::SUPPORTED, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_query_args( QueryArgs $args ): array {
		$query = [];

		if ( $args->has( 'ids' ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', (array) $args->get( 'ids' ) ),
					static fn ( int $id ): bool => $id > 0
				)
			);
			if ( ! empty( $ids ) ) {
				$query['comment__in'] = $ids;
			}
		}

		if ( $args->has( 'status' ) ) {
			$statuses = (array) $args->get( 'status' );
			$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
			if ( ! empty( $statuses ) ) {
				$query['status'] = count( $statuses ) === 1 ? $statuses[0] : $statuses;
			}
		}

		if ( $args->has( 'post_id' ) ) {
			$query['post_id'] = (int) $args->get( 'post_id' );
		}

		if ( $args->has( 'type' ) ) {
			$query['type'] = (string) $args->get( 'type' );
		}

		$date_query = [];
		if ( $args->has( 'date_before' ) ) {
			$date_query[] = [
				'before'    => (string) $args->get( 'date_before' ),
				'inclusive' => true,
			];
		}
		if ( $args->has( 'date_after' ) ) {
			$date_query[] = [
				'after'     => (string) $args->get( 'date_after' ),
				'inclusive' => true,
			];
		}
		if ( ! empty( $date_query ) ) {
			$query['date_query'] = $date_query;
		}

		return $query;
	}
}
