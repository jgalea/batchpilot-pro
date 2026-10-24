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

final class UserDeleteOperation implements OperationInterface {

	private const SAMPLE_SIZE    = 20;
	private const SNAPSHOT_TYPE  = 'user';
	private const SNAPSHOT_FIELD = 'user_data';
	private const MAX_META_BYTES = 65000;

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
		return 'user-delete';
	}

	public function label(): string {
		return __( 'Delete users', 'batchpilot-pro' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_params_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'reassign_to' => [
					'type'        => 'integer',
					'widget'      => 'user',
					'label'       => __( 'Reassign content to', 'batchpilot-pro' ),
					'description' => __( 'Optional. If set, posts owned by deleted users are reassigned to this user. Leave empty to delete their posts along with the user.', 'batchpilot-pro' ),
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function validate( QueryArgs $args, array $params ): ValidationResult {
		if ( isset( $params['reassign_to'] ) && '' !== $params['reassign_to'] && null !== $params['reassign_to'] ) {
			$reassign_to = (int) $params['reassign_to'];
			if ( $reassign_to <= 0 || false === \get_userdata( $reassign_to ) ) {
				return ValidationResult::error(
					new BatchPilotError(
						'bp.params.invalid_reassign_to',
						'reassign_to must be the ID of an existing user.',
						[ 'reassign_to' => $reassign_to ]
					)
				);
			}
		}

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
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$operation_id = isset( $params['__operation_id'] ) ? (int) $params['__operation_id'] : 0;
		$reassign_to  = isset( $params['reassign_to'] ) && '' !== $params['reassign_to']
			? (int) $params['reassign_to']
			: null;

		$succeeded   = 0;
		$failed      = 0;
		$item_errors = [];
		$snapshots   = [];

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$user = \get_userdata( $id );

			if ( false === $user ) {
				++$failed;
				$item_errors[ $id ] = 'User not found.';
				continue;
			}

			$snapshot_payload = $this->snapshot_payload( $id );
			$encoded          = (string) \wp_json_encode( $snapshot_payload );

			if ( strlen( $encoded ) > self::MAX_META_BYTES ) {
				// Truncate meta to keep snapshot row under MEDIUMTEXT limit.
				$snapshot_payload['user_meta'] = [];
				$snapshot_payload['truncated'] = true;
				$encoded                       = (string) \wp_json_encode( $snapshot_payload );
			}

			if ( 0 !== $operation_id ) {
				$snapshots[] = new Snapshot(
					$operation_id,
					self::SNAPSHOT_TYPE,
					$id,
					self::SNAPSHOT_FIELD,
					$encoded
				);
			}

			$result = \wp_delete_user( $id, $reassign_to );
			if ( true !== $result ) {
				++$failed;
				$item_errors[ $id ] = 'wp_delete_user returned false.';
				// Drop the snapshot for this user since the delete failed.
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
				new BatchPilotError(
					'bp.undo.not_found',
					'Operation not found.',
					[ 'operation_id' => $operation_id ]
				)
			);
		}

		$rows     = $this->snapshots->for_operation( $operation_id );
		$restored = 0;

		foreach ( $rows as $snapshot ) {
			if ( self::SNAPSHOT_TYPE !== $snapshot->object_type() || self::SNAPSHOT_FIELD !== $snapshot->field() ) {
				continue;
			}

			$payload = json_decode( (string) $snapshot->old_value(), true );
			if ( ! is_array( $payload ) || empty( $payload['user_data'] ) ) {
				continue;
			}

			$new_id = $this->recreate_user( $payload );
			if ( $new_id > 0 ) {
				++$restored;
			}
		}

		return UndoResult::of( $restored );
	}

	/**
	 * @return array{user_data: array<string, mixed>, user_meta: array<string, array<int, string>>, roles: array<int, string>}
	 */
	private function snapshot_payload( int $user_id ): array {
		$user = \get_userdata( $user_id );
		if ( false === $user ) {
			return [
				'user_data' => [],
				'user_meta' => [],
				'roles'     => [],
			];
		}

		$user_data = [
			'user_login'      => (string) $user->user_login,
			'user_pass'       => (string) $user->user_pass,
			'user_email'      => (string) $user->user_email,
			'user_url'        => (string) $user->user_url,
			'user_nicename'   => (string) $user->user_nicename,
			'display_name'    => (string) $user->display_name,
			'user_registered' => (string) $user->user_registered,
		];

		$user_meta = [];
		foreach ( (array) \get_user_meta( $user_id ) as $key => $values ) {
			$user_meta[ (string) $key ] = array_map( 'strval', (array) $values );
		}

		return [
			'user_data' => $user_data,
			'user_meta' => $user_meta,
			'roles'     => array_map( 'strval', (array) $user->roles ),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function recreate_user( array $payload ): int {
		$user_data = (array) ( $payload['user_data'] ?? [] );
		if ( empty( $user_data['user_login'] ) ) {
			return 0;
		}

		// If the original login is now taken (e.g., undo after someone reused
		// the username), fall back to a suffix so re-creation still succeeds.
		$login = (string) $user_data['user_login'];
		if ( \username_exists( $login ) ) {
			$login = $login . '_restored_' . wp_generate_password( 4, false, false );
		}

		// wp_insert_user wants user_pass plain-text or pre-hashed via wp_set_password later.
		// Since we kept the hash, insert with a placeholder then overwrite the hash directly.
		$new_user_id = \wp_insert_user(
			[
				'user_login'      => $login,
				'user_pass'       => \wp_generate_password( 16 ),
				'user_email'      => (string) ( $user_data['user_email'] ?? '' ),
				'user_url'        => (string) ( $user_data['user_url'] ?? '' ),
				'user_nicename'   => (string) ( $user_data['user_nicename'] ?? '' ),
				'display_name'    => (string) ( $user_data['display_name'] ?? '' ),
				'user_registered' => (string) ( $user_data['user_registered'] ?? \gmdate( 'Y-m-d H:i:s' ) ),
				'role'            => '',
			]
		);

		if ( \is_wp_error( $new_user_id ) || 0 === (int) $new_user_id ) {
			return 0;
		}

		$new_user_id = (int) $new_user_id;

		// Restore the password hash directly (skipping wp_set_password which would re-hash).
		if ( ! empty( $user_data['user_pass'] ) ) {
			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->users,
				[ 'user_pass' => (string) $user_data['user_pass'] ],
				[ 'ID' => $new_user_id ],
				[ '%s' ],
				[ '%d' ]
			);
			\clean_user_cache( $new_user_id );
		}

		foreach ( (array) ( $payload['user_meta'] ?? [] ) as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			// Skip session tokens (these are short-lived auth state) and capabilities
			// (those get restored from `roles` below).
			if ( in_array( $meta_key, [ 'session_tokens', 'wp_capabilities', 'wp_user_level' ], true ) ) {
				continue;
			}
			foreach ( (array) $values as $v ) {
				\add_user_meta( $new_user_id, $meta_key, \maybe_unserialize( (string) $v ) );
			}
		}

		$user_obj = \get_userdata( $new_user_id );
		if ( false !== $user_obj ) {
			foreach ( (array) ( $payload['roles'] ?? [] ) as $role ) {
				$user_obj->add_role( (string) $role );
			}
		}

		return $new_user_id;
	}
}
