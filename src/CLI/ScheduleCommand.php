<?php
namespace BatchPilotPro\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilotPro\Schedules\Schedule;
use BatchPilotPro\Schedules\ScheduleManager;
use BatchPilotPro\Schedules\ScheduleRepository;
use BatchPilotPro\Schedules\ScheduleRunner;
use WP_CLI;

/**
 * Manage BatchPilot Pro schedules.
 */
final class ScheduleCommand {

	private ScheduleRepository $repository;
	private ScheduleManager $manager;
	private ScheduleRunner $runner;

	public function __construct( ScheduleRepository $repository, ScheduleManager $manager, ScheduleRunner $runner ) {
		$this->repository = $repository;
		$this->manager    = $manager;
		$this->runner     = $runner;
	}

	/**
	 * Register the schedule subcommands under `wp batchpilot schedule ...`.
	 */
	public function register(): void {
		if ( ! \class_exists( WP_CLI::class ) ) {
			return;
		}

		\WP_CLI::add_command(
			'batchpilot schedule create',
			[ $this, 'create' ],
			[ 'shortdesc' => 'Create a new scheduled operation.' ]
		);
		\WP_CLI::add_command(
			'batchpilot schedule list',
			[ $this, 'list' ],
			[ 'shortdesc' => 'List schedules.' ]
		);
		\WP_CLI::add_command(
			'batchpilot schedule get',
			[ $this, 'get' ],
			[ 'shortdesc' => 'Show a single schedule.' ]
		);
		\WP_CLI::add_command(
			'batchpilot schedule run',
			[ $this, 'run' ],
			[ 'shortdesc' => 'Run a schedule immediately.' ]
		);
		\WP_CLI::add_command(
			'batchpilot schedule delete',
			[ $this, 'delete' ],
			[ 'shortdesc' => 'Delete a schedule.' ]
		);
		\WP_CLI::add_command(
			'batchpilot schedule enable',
			[ $this, 'enable' ],
			[ 'shortdesc' => 'Enable a schedule.' ]
		);
		\WP_CLI::add_command(
			'batchpilot schedule disable',
			[ $this, 'disable' ],
			[ 'shortdesc' => 'Disable a schedule.' ]
		);
	}

	/**
	 * Create a new schedule.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Human-friendly schedule name.
	 *
	 * --target=<target>
	 * : Target slug (e.g. post, page, user).
	 *
	 * --operation=<operation>
	 * : Operation slug (e.g. delete, duplicate, edit, user-delete).
	 *
	 * --interval=<seconds>
	 * : Recurrence interval in seconds. Common values: 3600 (hourly), 86400 (daily), 604800 (weekly).
	 *
	 * [--filters=<json>]
	 * : JSON object of filters to apply. Defaults to {}.
	 *
	 * [--params=<json>]
	 * : JSON object of operation params. Defaults to {}.
	 *
	 * [--disabled]
	 * : Create the schedule disabled (no Action Scheduler subscription).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function create( array $args, array $assoc_args ): void {
		$name      = (string) ( $assoc_args['name'] ?? '' );
		$target    = (string) ( $assoc_args['target'] ?? '' );
		$operation = (string) ( $assoc_args['operation'] ?? '' );
		$interval  = (int) ( $assoc_args['interval'] ?? 0 );

		if ( '' === $name || '' === $target || '' === $operation ) {
			\WP_CLI::error( 'name, target, and operation are required.' );
		}
		if ( $interval <= 0 ) {
			\WP_CLI::error( 'interval must be > 0 (seconds).' );
		}

		$filters = $this->json_arg( $assoc_args, 'filters' );
		$params  = $this->json_arg( $assoc_args, 'params' );

		$schedule = Schedule::newly_created( $name, $target, $operation, $filters, $params, Schedule::RECURRENCE_INTERVAL, $interval );
		if ( isset( $assoc_args['disabled'] ) ) {
			$schedule = $schedule->with_enabled( false );
		}

		$created = $this->manager->create( $schedule );

		\WP_CLI::success( sprintf( 'Schedule #%d created (%s).', (int) $created->id(), $created->enabled() ? 'enabled' : 'disabled' ) );
	}

	/**
	 * List all schedules.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts table, json, ids. Default: table.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function list( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = array_map(
			static fn ( Schedule $s ) => [
				'id'          => $s->id(),
				'name'        => $s->name(),
				'target'      => $s->target(),
				'operation'   => $s->operation(),
				'interval_s'  => $s->interval_seconds(),
				'enabled'     => $s->enabled() ? 'yes' : 'no',
				'last_run_at' => $s->last_run_at() ?? '-',
				'next_run_at' => $s->next_run_at() ?? '-',
			],
			$this->repository->all()
		);

		if ( 'ids' === $format ) {
			\WP_CLI::log( implode( ' ', array_map( static fn ( array $r ) => (string) $r['id'], $rows ) ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[ 'id', 'name', 'target', 'operation', 'interval_s', 'enabled', 'last_run_at', 'next_run_at' ]
		);
	}

	/**
	 * Show a single schedule as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function get( array $args, array $assoc_args ): void {
		$schedule = $this->repository->find( (int) ( $args[0] ?? 0 ) );
		if ( null === $schedule ) {
			\WP_CLI::error( 'Schedule not found.' );
		}
		\WP_CLI::log( (string) wp_json_encode( $this->serialize( $schedule ), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Run a schedule immediately (outside the Action Scheduler queue).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( null === $this->repository->find( $id ) ) {
			\WP_CLI::error( 'Schedule not found.' );
		}
		$this->runner->handle( $id );
		\WP_CLI::success( sprintf( 'Schedule #%d ran.', $id ) );
	}

	/**
	 * Delete a schedule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		$this->manager->delete( $id );
		\WP_CLI::success( sprintf( 'Schedule #%d deleted.', $id ) );
	}

	/**
	 * Enable a previously disabled schedule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function enable( array $args, array $assoc_args ): void {
		$this->set_enabled( (int) ( $args[0] ?? 0 ), true );
	}

	/**
	 * Disable a schedule (cancels its Action Scheduler subscription, retains the row).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function disable( array $args, array $assoc_args ): void {
		$this->set_enabled( (int) ( $args[0] ?? 0 ), false );
	}

	private function set_enabled( int $id, bool $enabled ): void {
		$schedule = $this->repository->find( $id );
		if ( null === $schedule ) {
			\WP_CLI::error( 'Schedule not found.' );
		}
		$this->manager->update( $schedule->with_enabled( $enabled ) );
		\WP_CLI::success( sprintf( 'Schedule #%d %s.', $id, $enabled ? 'enabled' : 'disabled' ) );
	}

	/**
	 * @param array<string, string> $assoc_args
	 * @return array<string, mixed>
	 */
	private function json_arg( array $assoc_args, string $key ): array {
		if ( empty( $assoc_args[ $key ] ) ) {
			return [];
		}
		$decoded = json_decode( (string) $assoc_args[ $key ], true );
		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( sprintf( '--%s must be valid JSON.', $key ) );
		}
		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize( Schedule $schedule ): array {
		return [
			'id'                  => $schedule->id(),
			'name'                => $schedule->name(),
			'target'              => $schedule->target(),
			'operation'           => $schedule->operation(),
			'filters'             => $schedule->filters(),
			'params'              => $schedule->params(),
			'recurrence_type'     => $schedule->recurrence_type(),
			'interval_seconds'    => $schedule->interval_seconds(),
			'enabled'             => $schedule->enabled(),
			'action_scheduler_id' => $schedule->action_scheduler_id(),
			'last_run_at'         => $schedule->last_run_at(),
			'next_run_at'         => $schedule->next_run_at(),
			'created_at'          => $schedule->created_at(),
			'updated_at'          => $schedule->updated_at(),
		];
	}
}
