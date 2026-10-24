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

final class TermDeleteOperation implements OperationInterface {

	private const SAMPLE_SIZE    = 20;
	private const SNAPSHOT_TYPE  = 'term';
	private const SNAPSHOT_FIELD = 'term_data';

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
		return 'term-delete';
	}

	public function label(): string {
		return __( 'Delete terms', 'batchpilot-pro' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_params_schema(): array {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
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
			'user_id'    => \get_current_user_id(),
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
		$operation_id = isset( $params['__operation_id'] ) ? (int) $params['__operation_id'] : 0;

		$succeeded   = 0;
		$failed      = 0;
		$item_errors = [];
		$snapshots   = [];

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$term = \get_term( $id );

			if ( null === $term || \is_wp_error( $term ) ) {
				++$failed;
				$item_errors[ $id ] = 'Term not found.';
				continue;
			}

			if ( 0 !== $operation_id ) {
				$snapshots[] = new Snapshot(
					$operation_id,
					self::SNAPSHOT_TYPE,
					$id,
					self::SNAPSHOT_FIELD,
					(string) wp_json_encode( $this->snapshot_payload( $term ) )
				);
			}

			$result = \wp_delete_term( $id, (string) $term->taxonomy );

			if ( true !== $result ) {
				++$failed;
				$item_errors[ $id ] = \is_wp_error( $result )
					? (string) $result->get_error_message()
					: 'wp_delete_term returned false.';
				if ( ! empty( $snapshots ) ) {
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

			$result = \wp_insert_term(
				(string) ( $payload['name'] ?? '' ),
				(string) ( $payload['taxonomy'] ?? '' ),
				[
					'slug'        => (string) ( $payload['slug'] ?? '' ),
					'description' => (string) ( $payload['description'] ?? '' ),
					'parent'      => (int) ( $payload['parent'] ?? 0 ),
				]
			);

			if ( \is_wp_error( $result ) || empty( $result['term_id'] ) ) {
				continue;
			}

			$new_id = (int) $result['term_id'];
			foreach ( (array) ( $payload['meta'] ?? [] ) as $meta_key => $values ) {
				foreach ( (array) $values as $v ) {
					\add_term_meta( $new_id, (string) $meta_key, \maybe_unserialize( (string) $v ) );
				}
			}
			++$restored;
		}

		return UndoResult::of( $restored );
	}

	/**
	 * @param object $term
	 * @return array<string, mixed>
	 */
	private function snapshot_payload( $term ): array {
		$meta = [];
		foreach ( (array) \get_term_meta( (int) $term->term_id ) as $key => $values ) {
			$meta[ (string) $key ] = array_map( 'strval', (array) $values );
		}
		return [
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'meta'        => $meta,
		];
	}
}
