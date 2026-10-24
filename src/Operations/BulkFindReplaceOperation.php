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

final class BulkFindReplaceOperation implements OperationInterface {

	private const SAMPLE_SIZE    = 20;
	private const SNAPSHOT_TYPE  = 'post';
	private const ALLOWED_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

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
		return 'find-replace';
	}

	public function label(): string {
		return __( 'Find and replace', 'batchpilot-pro' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_params_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'search'         => [
					'type'        => 'string',
					'label'       => __( 'Find', 'batchpilot-pro' ),
					'description' => __( 'String to look for, or a regular expression when "Regex" is enabled.', 'batchpilot-pro' ),
				],
				'replace'        => [
					'type'        => 'string',
					'label'       => __( 'Replace with', 'batchpilot-pro' ),
					'description' => __( 'Replacement text. Empty string deletes matches.', 'batchpilot-pro' ),
				],
				'fields'         => [
					'type'        => 'array',
					'widget'      => 'fields-multi',
					'label'       => __( 'Fields to search', 'batchpilot-pro' ),
					'description' => __( 'Comma-separated subset of: post_title, post_content, post_excerpt. Defaults to post_content.', 'batchpilot-pro' ),
					'default'     => [ 'post_content' ],
				],
				'is_regex'       => [
					'type'        => 'boolean',
					'default'     => false,
					'label'       => __( 'Treat search as regex', 'batchpilot-pro' ),
					'description' => __( 'When on, Find is a PHP/PCRE pattern (without delimiters). Use carefully — bad patterns can skip or over-match.', 'batchpilot-pro' ),
				],
				'case_sensitive' => [
					'type'        => 'boolean',
					'default'     => false,
					'label'       => __( 'Case-sensitive', 'batchpilot-pro' ),
					'description' => __( 'When off, the search is case-insensitive (str_ireplace / "i" regex flag).', 'batchpilot-pro' ),
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function validate( QueryArgs $args, array $params ): ValidationResult {
		if ( ! isset( $params['search'] ) || '' === (string) $params['search'] ) {
			return ValidationResult::error(
				new BatchPilotError( 'bp.params.invalid_search', '"search" is required and cannot be empty.' )
			);
		}

		$fields = $this->normalize_fields( $params );
		if ( empty( $fields ) ) {
			return ValidationResult::error(
				new BatchPilotError(
					'bp.params.invalid_fields',
					'"fields" must contain at least one of post_title / post_content / post_excerpt.'
				)
			);
		}

		if ( ! empty( $params['is_regex'] ) ) {
			$delim   = '/';
			$pattern = $delim . (string) $params['search'] . $delim . ( empty( $params['case_sensitive'] ) ? 'i' : '' );
			// @ silences PCRE warnings; we treat invalid patterns as user error.
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
				return ValidationResult::error(
					new BatchPilotError( 'bp.params.invalid_regex', 'The regex pattern in "search" is invalid.' )
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
		$operation_id   = isset( $params['__operation_id'] ) ? (int) $params['__operation_id'] : 0;
		$search         = (string) ( $params['search'] ?? '' );
		$replace        = (string) ( $params['replace'] ?? '' );
		$fields         = $this->normalize_fields( $params );
		$is_regex       = ! empty( $params['is_regex'] );
		$case_sensitive = ! empty( $params['case_sensitive'] );

		$pattern = '/' . $search . '/' . ( $case_sensitive ? '' : 'i' );

		// Escape $ and \ in the replacement so preg_replace treats it as a literal
		// string (the user typed it that way) instead of interpreting $0..$9 as
		// backreferences. addcslashes covers both cases.
		$regex_replace = $is_regex ? addcslashes( $replace, '\\$' ) : $replace;

		$succeeded   = 0;
		$failed      = 0;
		$item_errors = [];
		$snapshots   = [];

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = \get_post( $id );
			if ( null === $post ) {
				++$failed;
				$item_errors[ $id ] = 'Post not found.';
				continue;
			}

			$updates = [];
			$before  = [];
			foreach ( $fields as $field ) {
				$current = (string) $post->{ $field };
				$next    = $is_regex
					? (string) preg_replace( $pattern, $regex_replace, $current )
					: ( $case_sensitive ? str_replace( $search, $replace, $current ) : str_ireplace( $search, $replace, $current ) );

				if ( $next !== $current ) {
					$updates[ $field ] = $next;
					$before[ $field ]  = $current;
				}
			}

			if ( empty( $updates ) ) {
				++$succeeded;
				continue;
			}

			if ( 0 !== $operation_id ) {
				foreach ( $before as $field => $value ) {
					$snapshots[] = new Snapshot(
						$operation_id,
						self::SNAPSHOT_TYPE,
						$id,
						$field,
						$value
					);
				}
			}

			$updates['ID'] = $id;
			$result        = \wp_update_post( $updates, true );
			if ( \is_wp_error( $result ) ) {
				++$failed;
				$item_errors[ $id ] = (string) $result->get_error_message();
				// Drop any snapshots we just appended for this post.
				$snapshots = array_slice( $snapshots, 0, count( $snapshots ) - count( $before ) );
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

		$rows    = $this->snapshots->for_operation( $operation_id );
		$by_post = [];
		foreach ( $rows as $snapshot ) {
			if ( self::SNAPSHOT_TYPE !== $snapshot->object_type() ) {
				continue;
			}
			$by_post[ (int) $snapshot->object_id() ][ (string) $snapshot->field() ] = (string) $snapshot->old_value();
		}

		$restored = 0;
		foreach ( $by_post as $post_id => $fields ) {
			$updates       = $fields;
			$updates['ID'] = $post_id;
			$result        = \wp_update_post( $updates, true );
			if ( ! \is_wp_error( $result ) ) {
				++$restored;
			}
		}

		return UndoResult::of( $restored );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return string[]
	 */
	private function normalize_fields( array $params ): array {
		$raw = $params['fields'] ?? [ 'post_content' ];
		if ( is_string( $raw ) ) {
			$raw = array_map( 'trim', explode( ',', $raw ) );
		}
		$raw = array_values( array_filter( array_map( 'strval', (array) $raw ) ) );
		return array_values( array_intersect( $raw, self::ALLOWED_FIELDS ) );
	}
}
