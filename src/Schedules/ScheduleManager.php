<?php
namespace BatchPilotPro\Schedules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Async\ActionSchedulerBridge;

final class ScheduleManager {

	public const HOOK             = 'batchpilot_pro_run_schedule';
	public const ACTION_GROUP     = 'batchpilot-pro';
	public const INTERVAL_HOURLY  = 3600;
	public const INTERVAL_DAILY   = 86400;
	public const INTERVAL_WEEKLY  = 604800;
	public const INTERVAL_MONTHLY = 2592000;

	private ScheduleRepository $repository;
	private ActionSchedulerBridge $scheduler;

	public function __construct( ScheduleRepository $repository, ActionSchedulerBridge $scheduler ) {
		$this->repository = $repository;
		$this->scheduler  = $scheduler;
	}

	/**
	 * Register a brand-new schedule and (if enabled) its Action Scheduler subscription.
	 */
	public function create( Schedule $schedule ): Schedule {
		$created = $this->repository->create( $schedule );

		if ( $created->enabled() ) {
			$created = $this->subscribe( $created );
			$this->repository->update( $created );
		}

		return $created;
	}

	/**
	 * Update a schedule's storage row. If its enabled/recurrence changed, refresh the AS subscription.
	 */
	public function update( Schedule $schedule ): Schedule {
		if ( null === $schedule->id() ) {
			return $schedule;
		}

		$existing = $this->repository->find( $schedule->id() );
		if ( null === $existing ) {
			return $schedule;
		}

		$needs_refresh = $existing->enabled() !== $schedule->enabled()
			|| $existing->interval_seconds() !== $schedule->interval_seconds()
			|| $existing->recurrence_type() !== $schedule->recurrence_type();

		if ( $needs_refresh && null !== $existing->action_scheduler_id() ) {
			$this->scheduler->cancel_action( (int) $existing->action_scheduler_id() );
			$schedule = $schedule->with_action_scheduler_id( null );
		}

		if ( $schedule->enabled() && null === $schedule->action_scheduler_id() ) {
			$schedule = $this->subscribe( $schedule );
		}

		$this->repository->update( $schedule );
		return $schedule;
	}

	/**
	 * Cancel the AS subscription and drop the schedule row.
	 */
	public function delete( int $schedule_id ): void {
		$existing = $this->repository->find( $schedule_id );
		if ( null === $existing ) {
			return;
		}

		if ( null !== $existing->action_scheduler_id() ) {
			$this->scheduler->cancel_action( (int) $existing->action_scheduler_id() );
		}

		$this->repository->delete( $schedule_id );
	}

	/**
	 * Re-subscribe an existing schedule (e.g., after restoring from inactive).
	 */
	private function subscribe( Schedule $schedule ): Schedule {
		if ( ! $this->scheduler->is_available() ) {
			return $schedule;
		}

		$interval = $schedule->interval_seconds();
		if ( $interval <= 0 ) {
			return $schedule;
		}

		// Stagger by 30 seconds so creating a daily schedule doesn't fire immediately
		// during the same request (Action Scheduler's queue runner picks up due actions
		// on the next runner tick).
		$first_run = time() + 30;

		$as_id = $this->scheduler->schedule_recurring_action(
			$first_run,
			$interval,
			self::HOOK,
			[ 'schedule_id' => $schedule->id() ],
			self::ACTION_GROUP
		);

		return $schedule
			->with_action_scheduler_id( $as_id > 0 ? $as_id : null )
			->with_last_run( $schedule->last_run_at() ?? '', gmdate( 'Y-m-d H:i:s', $first_run ) );
	}
}
