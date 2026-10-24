<?php
namespace BatchPilotPro\Schedules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wpdb;

final class ScheduleRepository {

	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	private function table(): string {
		return $this->db->prefix . 'batchpilot_schedules';
	}

	public function create( Schedule $schedule ): Schedule {
		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			$this->row_for_insert( $schedule ),
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		return $schedule->with_id( (int) $this->db->insert_id );
	}

	public function update( Schedule $schedule ): void {
		if ( null === $schedule->id() ) {
			return;
		}

		$this->db->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			$this->row_for_update( $schedule ),
			[ 'id' => $schedule->id() ],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public function delete( int $id ): void {
		$this->db->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	public function find( int $id ): ?Schedule {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? Schedule::from_row( $row ) : null;
	}

	/** @return Schedule[] */
	public function all(): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM {$this->table()} ORDER BY id DESC",
			ARRAY_A
		);

		return array_map( [ Schedule::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_for_insert( Schedule $schedule ): array {
		return [
			'name'                => $schedule->name(),
			'operation_type'      => $schedule->operation(),
			'target_type'         => $schedule->target(),
			'filters_json'        => (string) \wp_json_encode( $schedule->filters() ),
			'params_json'         => (string) \wp_json_encode( $schedule->params() ),
			'recurrence_json'     => (string) \wp_json_encode( $schedule->recurrence_payload() ),
			'action_scheduler_id' => $schedule->action_scheduler_id(),
			'enabled'             => $schedule->enabled() ? 1 : 0,
			'last_run_at'         => $schedule->last_run_at(),
			'next_run_at'         => $schedule->next_run_at(),
			'created_at'          => $schedule->created_at(),
			'updated_at'          => $schedule->updated_at(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_for_update( Schedule $schedule ): array {
		return [
			'name'                => $schedule->name(),
			'operation_type'      => $schedule->operation(),
			'target_type'         => $schedule->target(),
			'filters_json'        => (string) \wp_json_encode( $schedule->filters() ),
			'params_json'         => (string) \wp_json_encode( $schedule->params() ),
			'recurrence_json'     => (string) \wp_json_encode( $schedule->recurrence_payload() ),
			'action_scheduler_id' => $schedule->action_scheduler_id(),
			'enabled'             => $schedule->enabled() ? 1 : 0,
			'last_run_at'         => $schedule->last_run_at(),
			'next_run_at'         => $schedule->next_run_at(),
			'updated_at'          => $schedule->updated_at(),
		];
	}
}
