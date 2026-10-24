<?php
namespace BatchPilotPro\Presets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProPresets {

	/**
	 * @param array<int, array<string, mixed>> $presets
	 * @return array<int, array<string, mixed>>
	 */
	public static function extend( array $presets ): array {
		return array_merge( $presets, self::all() );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function all(): array {
		return [
			[
				'slug'        => 'pro-delete-users-no-role',
				'label'       => __( 'Delete users with no role on this site', 'batchpilot-pro' ),
				'description' => __( 'Removes users who exist on this site but have no assigned role. Common after multisite cleanups or imports.', 'batchpilot-pro' ),
				'target'      => 'user',
				'operation'   => 'user-delete',
				'filters'     => [ 'has_no_role' => true ],
				'params'      => [],
			],
		];
	}
}
