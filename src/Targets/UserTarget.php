<?php
namespace BatchPilotPro\Targets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Contracts\FilterDefinition;
use BatchPilot\Contracts\QueryArgs;
use BatchPilot\Contracts\TargetInterface;

final class UserTarget implements TargetInterface {

	private const SUPPORTED = [ 'user-delete' ];

	public function slug(): string {
		return 'user';
	}

	public function label(): string {
		return __( 'Users', 'batchpilot-pro' );
	}

	public function get_filters(): array {
		return [
			new FilterDefinition(
				'ids',
				__( 'Specific user IDs', 'batchpilot-pro' ),
				'ids',
				[ 'multiple' => true ]
			),
			new FilterDefinition(
				'role',
				__( 'Role', 'batchpilot-pro' ),
				'enum',
				[
					'multiple' => true,
					'options'  => $this->role_options(),
				]
			),
			new FilterDefinition(
				'registered_before',
				__( 'Registered before', 'batchpilot-pro' ),
				'date'
			),
			new FilterDefinition(
				'registered_after',
				__( 'Registered after', 'batchpilot-pro' ),
				'date'
			),
			new FilterDefinition(
				'has_no_role',
				__( 'Has no role on this site', 'batchpilot-pro' ),
				'bool'
			),
		];
	}

	public function query( QueryArgs $args, int $limit = 0, int $offset = 0 ): array {
		$query_args           = $this->build_user_query_args( $args );
		$query_args['fields'] = 'ID';
		$query_args['number'] = $limit > 0 ? $limit : -1;
		$query_args['offset'] = $offset;

		$query = new \WP_User_Query( $query_args );

		return array_map( 'intval', (array) $query->get_results() );
	}

	public function count( QueryArgs $args ): int {
		$query_args                = $this->build_user_query_args( $args );
		$query_args['fields']      = 'ID';
		$query_args['number']      = 1;
		$query_args['count_total'] = true;

		$query = new \WP_User_Query( $query_args );

		return (int) $query->get_total();
	}

	public function get_display( int $id ): array {
		$user = get_userdata( $id );
		if ( false === $user ) {
			return [
				'id'            => $id,
				'title'         => '',
				'status'        => 'missing',
				'date'          => '',
				'edit_url'      => '',
				'thumbnail_url' => null,
			];
		}

		$roles = (array) $user->roles;

		return [
			'id'            => (int) $user->ID,
			'title'         => '' !== $user->display_name ? (string) $user->display_name : (string) $user->user_login,
			'status'        => empty( $roles ) ? 'no-role' : (string) $roles[0],
			'date'          => (string) $user->user_registered,
			'edit_url'      => (string) get_edit_user_link( $user->ID ),
			'thumbnail_url' => (string) get_avatar_url( $user->ID, [ 'size' => 64 ] ),
		];
	}

	public function supports_operation( string $operation_slug ): bool {
		return in_array( $operation_slug, self::SUPPORTED, true );
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	private function role_options(): array {
		$out = [];
		foreach ( wp_roles()->roles as $slug => $info ) {
			$label = isset( $info['name'] ) ? translate_user_role( (string) $info['name'] ) : (string) $slug;
			$out[] = [
				'label' => (string) $label,
				'value' => (string) $slug,
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_user_query_args( QueryArgs $args ): array {
		$query = [];

		if ( $args->has( 'ids' ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', (array) $args->get( 'ids' ) ),
					static fn ( int $id ): bool => $id > 0
				)
			);
			if ( ! empty( $ids ) ) {
				$query['include'] = $ids;
			}
		}

		if ( $args->has( 'has_no_role' ) && true === $args->get( 'has_no_role' ) ) {
			// WP_User_Query supports 'role__not_in' but to match "no role at all"
			// we filter against having any of the registered roles.
			$query['role__not_in'] = array_keys( wp_roles()->roles );
		} elseif ( $args->has( 'role' ) ) {
			$roles = (array) $args->get( 'role' );
			$roles = array_values( array_filter( array_map( 'strval', $roles ) ) );
			if ( ! empty( $roles ) ) {
				$query['role__in'] = $roles;
			}
		}

		$date_query = [];
		if ( $args->has( 'registered_before' ) ) {
			$date_query[] = [
				'before'    => (string) $args->get( 'registered_before' ),
				'inclusive' => true,
			];
		}
		if ( $args->has( 'registered_after' ) ) {
			$date_query[] = [
				'after'     => (string) $args->get( 'registered_after' ),
				'inclusive' => true,
			];
		}
		if ( ! empty( $date_query ) ) {
			$query['date_query'] = $date_query;
		}

		return $query;
	}
}
