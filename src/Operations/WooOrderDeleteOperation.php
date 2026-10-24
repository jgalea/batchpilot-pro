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

/**
 * Delete WooCommerce orders. Works against both the legacy posts-based
 * store and HPOS because we go through the order CRUD: $order->delete()
 * dispatches to the right data store internally.
 *
 * Trash (default): order->delete( false ) sets status to wc-trash. Undo
 * untrashes by setting status back to the previous value, which we record
 * in the snapshot.
 *
 * Permanent: order->delete( true ) hard-deletes. Not undoable in HPOS
 * mode (rows are gone from wc_orders) so we report a permanent-delete
 * undo error.
 */
final class WooOrderDeleteOperation implements OperationInterface {

	private const SAMPLE_SIZE    = 20;
	private const SNAPSHOT_TYPE  = 'woo-order';
	private const SNAPSHOT_FIELD = 'previous_status';

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
		return 'woo-order-delete';
	}

	public function label(): string {
		return __( 'Delete orders', 'batchpilot-pro' );
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
					'description' => __( 'When enabled, orders are hard-deleted via the WooCommerce CRUD. Hard deletes cannot be undone, especially on HPOS stores.', 'batchpilot-pro' ),
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
		if ( ! function_exists( 'wc_get_order' ) ) {
			return BatchResult::error( new BatchPilotError( 'bp.woo.unavailable', 'WooCommerce is not active.' ) );
		}

		$permanent    = ! empty( $params['permanent'] );
		$operation_id = isset( $params['__operation_id'] ) ? (int) $params['__operation_id'] : 0;

		$succeeded   = 0;
		$failed      = 0;
		$item_errors = [];
		$snapshots   = [];

		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$order = \wc_get_order( $id );

			if ( false === $order ) {
				++$failed;
				$item_errors[ $id ] = 'Order not found.';
				continue;
			}

			if ( ! $permanent && 0 !== $operation_id ) {
				$snapshots[] = new Snapshot(
					$operation_id,
					self::SNAPSHOT_TYPE,
					$id,
					self::SNAPSHOT_FIELD,
					(string) $order->get_status()
				);
			}

			$ok = $order->delete( $permanent );
			if ( ! $ok ) {
				++$failed;
				$item_errors[ $id ] = 'order->delete() returned false.';
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

		if ( ! empty( $op->params()['permanent'] ) ) {
			return UndoResult::error(
				new BatchPilotError(
					'bp.undo.permanent_delete',
					'Permanent order deletions cannot be undone.',
					[ 'operation_id' => $operation_id ]
				)
			);
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return UndoResult::error( new BatchPilotError( 'bp.woo.unavailable', 'WooCommerce is not active.' ) );
		}

		$by_id = [];
		foreach ( $this->snapshots->for_operation( $operation_id ) as $snapshot ) {
			if ( self::SNAPSHOT_TYPE !== $snapshot->object_type() || self::SNAPSHOT_FIELD !== $snapshot->field() ) {
				continue;
			}
			$by_id[ (int) $snapshot->object_id() ] = (string) $snapshot->old_value();
		}

		$restored = 0;
		foreach ( $op->affected_ids() as $id ) {
			$id    = (int) $id;
			$order = \wc_get_order( $id );
			if ( false === $order ) {
				continue;
			}
			$previous = isset( $by_id[ $id ] ) && '' !== $by_id[ $id ] ? $by_id[ $id ] : 'pending';
			$order->set_status( $previous );
			$order->save();
			++$restored;
		}

		return UndoResult::of( $restored );
	}
}
