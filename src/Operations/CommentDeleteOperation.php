<?php
namespace BatchPilotPro\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\BatchResult;
use BatchPilot\Contracts\OperationInterface;
use BatchPilot\Contracts\PreviewResult;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;
use BatchPilot\Contracts\UndoResult;
use BatchPilot\Contracts\ValidationResult;
use BatchPilot\Errors\BatchPilotError;
use BatchPilot\History\OperationRepository;
use BatchPilot\History\Snapshot;
use BatchPilot\History\SnapshotRepository;
use BatchPilot\PreviewToken\TokenGenerator;
use BatchPilot\PreviewToken\TokenStore;

final class CommentDeleteOperation implements OperationInterface {

	private const SAMPLE_SIZE    = 20;
	private const SNAPSHOT_TYPE  = 'comment';
	private const SNAPSHOT_FIELD = 'comment_data';

	private TokenGenerator $token_generator;
	private TokenStore $token_store;
	private OperationRepository $operations;
	private SnapshotRepository $snapshots;

	public function __construct(
		TokenGenerator $token_generator,
		TokenStore $token_store,
		OperationRepository $operations,
		SnapshotRepository $snapshots
	) {
		$this->token_generator = $token_generator;
		$this->token_store     = $token_store;
		$this->operations      = $operations;
		$this->snapshots       = $snapshots;
	}

	public function slug(): string {
		return 'comment-delete';
	}

	public function label(): string {
		return __( 'Delete comments', 'batchpilot-pro' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_params_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'permanent' => [
					'type'        => 'boolean',
					'default'     => false,
					'label'       => __( 'Permanently delete (skip trash)', 'batchpilot-pro' ),
					'description' => __( 'When enabled, comments are hard-deleted and cannot be restored via Undo.', 'batchpilot-pro' ),
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function validate( QueryArgs $args, array $params ): ValidationResult {
		return ValidationResult::ok();
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function preview( QueryArgs $args, array $params, TargetInterface $target ): PreviewResult {
		$count      = $target->count( $args );
		$sample_ids = $target->query( $args, self::SAMPLE_SIZE, 0 );

		$payload = [
			'target'     => $target->slug(),
			'operation'  => $this->slug(),
			'filters'    => $args->to_array(),
			'params'     => $params,
			'sample_ids' => $sample_ids,
			'count'      => $count,
		];

		$token = $this->token_generator->generate( $payload );
		$this->token_store->store( $token, $payload );

		return PreviewResult::of( $count, $sample_ids, $token );
	}

	/**
	 * @param int[]                $ids
	 * @param array<string, mixed> $params
	 */
	public function execute_batch( array $ids, array $params, TargetInterface $target ): BatchResult {
		$permanent    = ! empty( $params['permanent'] );
		$operation_id = isset( $params['__operation_id'] ) ? (int) $params['__operation_id'] : 0;

		$succeeded   = 0;
		$failed      = 0;
		$item_errors = [];
		$snapshots   = [];

		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$comment = \get_comment( $id );

			if ( null === $comment ) {
				++$failed;
				$item_errors[ $id ] = 'Comment not found.';
				continue;
			}

			if ( $permanent && 0 !== $operation_id ) {
				$snapshots[] = new Snapshot(
					$operation_id,
					self::SNAPSHOT_TYPE,
					$id,
					self::SNAPSHOT_FIELD,
					(string) wp_json_encode( $this->snapshot_payload( $comment ) )
				);
			}

			$result = $permanent
				? \wp_delete_comment( $id, true )
				: \wp_trash_comment( $id );

			if ( false === $result ) {
				++$failed;
				$item_errors[ $id ] = $permanent ? 'wp_delete_comment returned false.' : 'wp_trash_comment returned false.';
				if ( $permanent && ! empty( $snapshots ) ) {
					array_pop( $snapshots );
				}
				continue;
			}

			++$succeeded;
		}

		if ( ! empty( $snapshots ) ) {
			$this->snapshots->bulk_insert( $snapshots );
		}

		return BatchResult::of( count( $ids ), $succeeded, $failed, $item_errors );
	}

	public function supports_undo(): bool {
		return true;
	}

	public function undo( int $operation_id ): UndoResult {
		$op = $this->operations->find( $operation_id );
		if ( null === $op ) {
			return UndoResult::error(
				new BatchPilotError( 'bp.undo.not_found', 'Operation not found.', [ 'operation_id' => $operation_id ] )
			);
		}

		$permanent = ! empty( $op->params()['permanent'] );

		if ( ! $permanent ) {
			// Trashed comments: untrash via wp_untrash_comment using the recorded ids.
			$restored = 0;
			foreach ( $op->affected_ids() as $id ) {
				if ( \wp_untrash_comment( (int) $id ) ) {
					++$restored;
				}
			}
			return UndoResult::of( $restored );
		}

		// Hard-deleted: re-insert from snapshots.
		$rows     = $this->snapshots->for_operation( $operation_id );
		$restored = 0;
		foreach ( $rows as $snapshot ) {
			if ( self::SNAPSHOT_TYPE !== $snapshot->object_type() || self::SNAPSHOT_FIELD !== $snapshot->field() ) {
				continue;
			}
			$payload = json_decode( (string) $snapshot->old_value(), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$new_id = \wp_insert_comment( $this->insert_args( $payload ) );
			if ( false !== $new_id && $new_id > 0 ) {
				foreach ( (array) ( $payload['meta'] ?? [] ) as $meta_key => $values ) {
					foreach ( (array) $values as $v ) {
						\add_comment_meta( (int) $new_id, (string) $meta_key, \maybe_unserialize( (string) $v ) );
					}
				}
				++$restored;
			}
		}

		return UndoResult::of( $restored );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot_payload( \WP_Comment $comment ): array {
		$meta = [];
		foreach ( (array) \get_comment_meta( (int) $comment->comment_ID ) as $key => $values ) {
			$meta[ (string) $key ] = array_map( 'strval', (array) $values );
		}
		return [
			'comment_post_ID'      => (int) $comment->comment_post_ID,
			'comment_author'       => (string) $comment->comment_author,
			'comment_author_email' => (string) $comment->comment_author_email,
			'comment_author_url'   => (string) $comment->comment_author_url,
			'comment_author_IP'    => (string) $comment->comment_author_IP,
			'comment_content'      => (string) $comment->comment_content,
			'comment_date'         => (string) $comment->comment_date,
			'comment_date_gmt'     => (string) $comment->comment_date_gmt,
			'comment_approved'     => (string) $comment->comment_approved,
			'comment_agent'        => (string) $comment->comment_agent,
			'comment_type'         => (string) $comment->comment_type,
			'comment_parent'       => (int) $comment->comment_parent,
			'user_id'              => (int) $comment->user_id,
			'meta'                 => $meta,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function insert_args( array $payload ): array {
		return [
			'comment_post_ID'      => (int) ( $payload['comment_post_ID'] ?? 0 ),
			'comment_author'       => (string) ( $payload['comment_author'] ?? '' ),
			'comment_author_email' => (string) ( $payload['comment_author_email'] ?? '' ),
			'comment_author_url'   => (string) ( $payload['comment_author_url'] ?? '' ),
			'comment_author_IP'    => (string) ( $payload['comment_author_IP'] ?? '' ),
			'comment_content'      => (string) ( $payload['comment_content'] ?? '' ),
			'comment_date'         => (string) ( $payload['comment_date'] ?? \current_time( 'mysql' ) ),
			'comment_date_gmt'     => (string) ( $payload['comment_date_gmt'] ?? \current_time( 'mysql', 1 ) ),
			'comment_approved'     => (string) ( $payload['comment_approved'] ?? '1' ),
			'comment_agent'        => (string) ( $payload['comment_agent'] ?? '' ),
			'comment_type'         => (string) ( $payload['comment_type'] ?? 'comment' ),
			'comment_parent'       => (int) ( $payload['comment_parent'] ?? 0 ),
			'user_id'              => (int) ( $payload['user_id'] ?? 0 ),
		];
	}
}
