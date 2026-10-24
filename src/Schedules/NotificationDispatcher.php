<?php
namespace BatchPilotPro\Schedules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\BatchResult;
use BatchPilot\History\Operation;

/**
 * Hooks the free plugin's batchpilot_operation_completed action and dispatches
 * email + webhook notifications for any operation that originated from a Pro
 * schedule.
 *
 * Operations are linked back to schedules by tag: ScheduleRunner stamps
 * $params['__schedule_id'] before calling ExecutionService::record, so when
 * the run completes we can look up which schedule (if any) triggered it.
 */
final class NotificationDispatcher {

	private ScheduleRepository $repository;

	public function __construct( ScheduleRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		\add_action( 'batchpilot_operation_completed', [ $this, 'on_operation_completed' ], 10, 3 );
	}

	public function on_operation_completed( int $operation_id, BatchResult $result, Operation $row ): void {
		$schedule_id = isset( $row->params()['__schedule_id'] ) ? (int) $row->params()['__schedule_id'] : 0;
		if ( $schedule_id <= 0 ) {
			return;
		}

		$schedule = $this->repository->find( $schedule_id );
		if ( null === $schedule ) {
			return;
		}

		if ( '' !== $schedule->notify_email() ) {
			$this->send_email( $schedule, $operation_id, $result );
		}
		if ( '' !== $schedule->notify_webhook() ) {
			$this->send_webhook( $schedule, $operation_id, $result );
		}
	}

	private function send_email( Schedule $schedule, int $operation_id, BatchResult $result ): void {
		$site    = (string) \get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: 1: site name, 2: schedule name */
			\__( '[%1$s] BatchPilot schedule completed: %2$s', 'batchpilot-pro' ),
			$site,
			$schedule->name()
		);

		$body = sprintf(
			"Schedule:    %s\nOperation:   #%d (%s on %s)\nProcessed:   %d\nSucceeded:   %d\nFailed:      %d\n\nView history: %s\n",
			$schedule->name(),
			$operation_id,
			$schedule->operation(),
			$schedule->target(),
			$result->processed(),
			$result->succeeded(),
			$result->failed(),
			\admin_url( 'admin.php?page=batchpilot-history' )
		);

		\wp_mail( $schedule->notify_email(), $subject, $body );
	}

	private function send_webhook( Schedule $schedule, int $operation_id, BatchResult $result ): void {
		\wp_remote_post(
			$schedule->notify_webhook(),
			[
				'timeout' => 5,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) \wp_json_encode(
					[
						'event'        => 'batchpilot.schedule.completed',
						'schedule_id'  => $schedule->id(),
						'schedule'     => $schedule->name(),
						'operation_id' => $operation_id,
						'target'       => $schedule->target(),
						'operation'    => $schedule->operation(),
						'processed'    => $result->processed(),
						'succeeded'    => $result->succeeded(),
						'failed'       => $result->failed(),
						'site'         => \home_url(),
					]
				),
			]
		);
	}
}
