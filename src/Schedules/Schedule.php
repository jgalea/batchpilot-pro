<?php
namespace BatchPilotPro\Schedules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schedule {

	public const RECURRENCE_INTERVAL = 'interval';
	public const RECURRENCE_ONCE     = 'once';

	private ?int $id;
	private string $name;
	private string $target;
	private string $operation;
	/** @var array<string, mixed> */
	private array $filters;
	/** @var array<string, mixed> */
	private array $params;
	private string $recurrence_type;
	private int $interval_seconds;
	private ?int $action_scheduler_id;
	private bool $enabled;
	private ?string $last_run_at;
	private ?string $next_run_at;
	private string $created_at;
	private string $updated_at;
	private string $notify_email;
	private string $notify_webhook;

	/**
	 * @param array<string, mixed> $filters
	 * @param array<string, mixed> $params
	 */
	public function __construct(
		?int $id,
		string $name,
		string $target,
		string $operation,
		array $filters,
		array $params,
		string $recurrence_type,
		int $interval_seconds,
		?int $action_scheduler_id,
		bool $enabled,
		?string $last_run_at,
		?string $next_run_at,
		string $created_at,
		string $updated_at,
		string $notify_email = '',
		string $notify_webhook = ''
	) {
		$this->id                  = $id;
		$this->name                = $name;
		$this->target              = $target;
		$this->operation           = $operation;
		$this->filters             = $filters;
		$this->params              = $params;
		$this->recurrence_type     = $recurrence_type;
		$this->interval_seconds    = $interval_seconds;
		$this->action_scheduler_id = $action_scheduler_id;
		$this->enabled             = $enabled;
		$this->last_run_at         = $last_run_at;
		$this->next_run_at         = $next_run_at;
		$this->created_at          = $created_at;
		$this->updated_at          = $updated_at;
		$this->notify_email        = $notify_email;
		$this->notify_webhook      = $notify_webhook;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @param array<string, mixed> $params
	 */
	public static function newly_created(
		string $name,
		string $target,
		string $operation,
		array $filters,
		array $params,
		string $recurrence_type,
		int $interval_seconds,
		string $notify_email = '',
		string $notify_webhook = ''
	): self {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new self(
			null,
			$name,
			$target,
			$operation,
			$filters,
			$params,
			$recurrence_type,
			$interval_seconds,
			null,
			true,
			null,
			null,
			$now,
			$now,
			$notify_email,
			$notify_webhook
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$recurrence = isset( $row['recurrence_json'] ) ? json_decode( (string) $row['recurrence_json'], true ) : [];
		if ( ! is_array( $recurrence ) ) {
			$recurrence = [];
		}

		return new self(
			(int) $row['id'],
			(string) $row['name'],
			(string) $row['target_type'],
			(string) $row['operation_type'],
			(array) ( json_decode( (string) ( $row['filters_json'] ?? '[]' ), true ) ?? [] ),
			(array) ( json_decode( (string) ( $row['params_json'] ?? '[]' ), true ) ?? [] ),
			(string) ( $recurrence['type'] ?? self::RECURRENCE_INTERVAL ),
			(int) ( $recurrence['interval_seconds'] ?? 0 ),
			isset( $row['action_scheduler_id'] ) && null !== $row['action_scheduler_id'] ? (int) $row['action_scheduler_id'] : null,
			(bool) ( (int) ( $row['enabled'] ?? 0 ) ),
			self::normalize_datetime( $row['last_run_at'] ?? null ),
			self::normalize_datetime( $row['next_run_at'] ?? null ),
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(string) ( $recurrence['notify_email'] ?? '' ),
			(string) ( $recurrence['notify_webhook'] ?? '' )
		);
	}

	public function id(): ?int {
		return $this->id;
	}

	public function with_id( int $id ): self {
		$clone     = clone $this;
		$clone->id = $id;
		return $clone;
	}

	public function name(): string {
		return $this->name;
	}

	public function target(): string {
		return $this->target;
	}

	public function operation(): string {
		return $this->operation;
	}

	/** @return array<string, mixed> */
	public function filters(): array {
		return $this->filters;
	}

	/** @return array<string, mixed> */
	public function params(): array {
		return $this->params;
	}

	public function recurrence_type(): string {
		return $this->recurrence_type;
	}

	public function interval_seconds(): int {
		return $this->interval_seconds;
	}

	public function action_scheduler_id(): ?int {
		return $this->action_scheduler_id;
	}

	public function with_action_scheduler_id( ?int $id ): self {
		$clone                      = clone $this;
		$clone->action_scheduler_id = $id;
		$clone->updated_at          = gmdate( 'Y-m-d H:i:s' );
		return $clone;
	}

	public function enabled(): bool {
		return $this->enabled;
	}

	public function with_enabled( bool $enabled ): self {
		$clone             = clone $this;
		$clone->enabled    = $enabled;
		$clone->updated_at = gmdate( 'Y-m-d H:i:s' );
		return $clone;
	}

	public function last_run_at(): ?string {
		return $this->last_run_at;
	}

	public function next_run_at(): ?string {
		return $this->next_run_at;
	}

	public function with_last_run( string $last_run_at, ?string $next_run_at ): self {
		$clone              = clone $this;
		$clone->last_run_at = $last_run_at;
		$clone->next_run_at = $next_run_at;
		$clone->updated_at  = gmdate( 'Y-m-d H:i:s' );
		return $clone;
	}

	public function created_at(): string {
		return $this->created_at;
	}

	public function updated_at(): string {
		return $this->updated_at;
	}

	public function notify_email(): string {
		return $this->notify_email;
	}

	public function notify_webhook(): string {
		return $this->notify_webhook;
	}

	/** @return array<string, mixed> */
	public function recurrence_payload(): array {
		return [
			'type'             => $this->recurrence_type,
			'interval_seconds' => $this->interval_seconds,
			'notify_email'     => $this->notify_email,
			'notify_webhook'   => $this->notify_webhook,
		];
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return (string) $value;
	}
}
