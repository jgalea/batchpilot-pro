<?php
namespace BatchPilotPro\Schedules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Execution\ExecutionService;

final class ScheduleRunner {

	private ScheduleRepository $repository;
	private ExecutionService $execution;

	public function __construct( ScheduleRepository $repository, ExecutionService $execution ) {
		$this->repository = $repository;
		$this->execution  = $execution;
	}

	public function register(): void {
		\add_action( ScheduleManager::HOOK, [ $this, 'handle' ], 10, 1 );
	}

	/**
	 * Action Scheduler callback. Runs the schedule's underlying operation via the
	 * free plugin's ExecutionService so it records to history just like a manual run.
	 */
	public function handle( int $schedule_id ): void {
		$schedule = $this->repository->find( $schedule_id );
		if ( null === $schedule ) {
			return;
		}

		if ( ! $schedule->enabled() ) {
			return;
		}

		// Stamp the schedule id into params so the operation row knows which schedule
		// triggered it. NotificationDispatcher reads this back to correlate the
		// batchpilot_operation_completed event with the originating Schedule.
		$tagged_params                  = $schedule->params();
		$tagged_params['__schedule_id'] = (int) $schedule->id();

		$operation_id = $this->execution->record(
			$schedule->target(),
			$schedule->operation(),
			0, // user_id 0 — system-initiated
			$schedule->filters(),
			$tagged_params
		);

		$this->execution->run_sync( $operation_id );

		$last_run = gmdate( 'Y-m-d H:i:s' );
		$next_run = $schedule->interval_seconds() > 0
			? gmdate( 'Y-m-d H:i:s', time() + $schedule->interval_seconds() )
			: null;

		$this->repository->update( $schedule->with_last_run( $last_run, $next_run ) );
	}
}
