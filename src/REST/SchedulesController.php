<?php
namespace BatchPilotPro\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilotPro\Schedules\Schedule;
use BatchPilotPro\Schedules\ScheduleManager;
use BatchPilotPro\Schedules\ScheduleRepository;
use BatchPilotPro\Schedules\ScheduleRunner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SchedulesController {

	private const REST_NAMESPACE = 'batchpilot/v1';

	private ScheduleRepository $repository;
	private ScheduleManager $manager;
	private ScheduleRunner $runner;

	public function __construct(
		ScheduleRepository $repository,
		ScheduleManager $manager,
		ScheduleRunner $runner
	) {
		$this->repository = $repository;
		$this->manager    = $manager;
		$this->runner     = $runner;
	}

	public function register(): void {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		\register_rest_route(
			self::REST_NAMESPACE,
			'/schedules',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		\register_rest_route(
			self::REST_NAMESPACE,
			'/schedules/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		\register_rest_route(
			self::REST_NAMESPACE,
			'/schedules/(?P<id>\d+)/run',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run_now' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bp.auth.forbidden', 'Insufficient permissions.', [ 'status' => 403 ] );
		}
		return true;
	}

	public function list( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$schedules = array_map( [ $this, 'serialize' ], $this->repository->all() );
		return new WP_REST_Response( [ 'schedules' => $schedules ] );
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		$schedule = $this->repository->find( (int) $request['id'] );
		if ( null === $schedule ) {
			return new WP_REST_Response( [ 'code' => 'bp.schedule.not_found' ], 404 );
		}
		return new WP_REST_Response( $this->serialize( $schedule ) );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();

		$schedule = Schedule::newly_created(
			(string) ( $body['name'] ?? '' ),
			(string) ( $body['target'] ?? '' ),
			(string) ( $body['operation'] ?? '' ),
			(array) ( $body['filters'] ?? [] ),
			(array) ( $body['params'] ?? [] ),
			(string) ( $body['recurrence_type'] ?? Schedule::RECURRENCE_INTERVAL ),
			(int) ( $body['interval_seconds'] ?? 0 ),
			(string) ( $body['notify_email'] ?? '' ),
			(string) ( $body['notify_webhook'] ?? '' )
		);

		if ( '' === $schedule->name() || '' === $schedule->target() || '' === $schedule->operation() ) {
			return new WP_REST_Response(
				[
					'code'    => 'bp.schedule.invalid',
					'message' => 'name, target, and operation are required.',
				],
				400
			);
		}

		if ( $schedule->interval_seconds() <= 0 ) {
			return new WP_REST_Response(
				[
					'code'    => 'bp.schedule.invalid_interval',
					'message' => 'interval_seconds must be > 0.',
				],
				400
			);
		}

		$created = $this->manager->create( $schedule );
		return new WP_REST_Response( $this->serialize( $created ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$existing = $this->repository->find( (int) $request['id'] );
		if ( null === $existing ) {
			return new WP_REST_Response( [ 'code' => 'bp.schedule.not_found' ], 404 );
		}

		$body = (array) $request->get_json_params();

		$updated = new Schedule(
			$existing->id(),
			isset( $body['name'] ) ? (string) $body['name'] : $existing->name(),
			isset( $body['target'] ) ? (string) $body['target'] : $existing->target(),
			isset( $body['operation'] ) ? (string) $body['operation'] : $existing->operation(),
			isset( $body['filters'] ) ? (array) $body['filters'] : $existing->filters(),
			isset( $body['params'] ) ? (array) $body['params'] : $existing->params(),
			isset( $body['recurrence_type'] ) ? (string) $body['recurrence_type'] : $existing->recurrence_type(),
			isset( $body['interval_seconds'] ) ? (int) $body['interval_seconds'] : $existing->interval_seconds(),
			$existing->action_scheduler_id(),
			isset( $body['enabled'] ) ? (bool) $body['enabled'] : $existing->enabled(),
			$existing->last_run_at(),
			$existing->next_run_at(),
			$existing->created_at(),
			gmdate( 'Y-m-d H:i:s' ),
			isset( $body['notify_email'] ) ? (string) $body['notify_email'] : $existing->notify_email(),
			isset( $body['notify_webhook'] ) ? (string) $body['notify_webhook'] : $existing->notify_webhook()
		);

		$result = $this->manager->update( $updated );
		return new WP_REST_Response( $this->serialize( $result ) );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$this->manager->delete( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function run_now( WP_REST_Request $request ): WP_REST_Response {
		$id       = (int) $request['id'];
		$schedule = $this->repository->find( $id );
		if ( null === $schedule ) {
			return new WP_REST_Response( [ 'code' => 'bp.schedule.not_found' ], 404 );
		}

		$this->runner->handle( $id );

		$refreshed = $this->repository->find( $id );
		return new WP_REST_Response( $this->serialize( $refreshed ?? $schedule ) );
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
			'notify_email'        => $schedule->notify_email(),
			'notify_webhook'      => $schedule->notify_webhook(),
		];
	}
}
