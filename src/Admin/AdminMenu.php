<?php
namespace BatchPilotPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {

	public const PAGE_SLUG = 'batchpilot-pro-schedules';

	public function register(): void {
		\add_action( 'admin_menu', [ $this, 'register_menus' ], 20 );
	}

	public function register_menus(): void {
		\add_submenu_page(
			'batchpilot',
			\__( 'Schedules', 'batchpilot-pro' ),
			\__( 'Schedules', 'batchpilot-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		echo '<div class="wrap"><div id="batchpilot-pro-schedules-root" class="batchpilot-pro-app"></div></div>';
	}

	/**
	 * @return string[]
	 */
	public static function page_hook_suffixes(): array {
		return [ 'batchpilot_page_' . self::PAGE_SLUG ];
	}
}
