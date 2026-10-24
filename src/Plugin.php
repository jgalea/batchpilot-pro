<?php
namespace BatchPilotPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BatchPilot\Async\ActionSchedulerBridge;
use BatchPilot\Execution\ExecutionService;
use BatchPilot\History\OperationRepository;
use BatchPilot\History\SnapshotRepository;
use BatchPilot\PreviewToken\TokenGenerator;
use BatchPilot\PreviewToken\TokenStore;
use BatchPilot\Registry\OperationRegistry;
use BatchPilot\Registry\TargetRegistry;
use BatchPilotPro\Admin\AdminMenu;
use BatchPilotPro\Admin\AssetLoader;
use BatchPilotPro\CLI\ScheduleCommand;
use BatchPilotPro\Operations\BulkFindReplaceOperation;
use BatchPilotPro\Operations\CommentDeleteOperation;
use BatchPilotPro\Operations\TermDeleteOperation;
use BatchPilotPro\Operations\UserDeleteOperation;
use BatchPilotPro\Operations\WooOrderDeleteOperation;
use BatchPilotPro\Presets\ProPresets;
use BatchPilotPro\REST\SchedulesController;
use BatchPilotPro\Schedules\NotificationDispatcher;
use BatchPilotPro\Schedules\ScheduleManager;
use BatchPilotPro\Schedules\ScheduleRepository;
use BatchPilotPro\Schedules\ScheduleRunner;
use BatchPilotPro\Targets\CommentTarget;
use BatchPilotPro\Targets\MediaTarget;
use BatchPilotPro\Targets\TermTarget;
use BatchPilotPro\Targets\UserTarget;
use BatchPilotPro\Targets\WooOrderTarget;
use BatchPilotPro\Targets\WooProductTarget;

final class Plugin {

	private static ?self $instance = null;

	private string $plugin_file;

	private function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public static function boot( string $plugin_file ): self {
		if ( self::$instance instanceof self ) {
			return self::$instance;
		}

		self::$instance = new self( $plugin_file );
		self::$instance->register_hooks();

		return self::$instance;
	}

	private function register_hooks(): void {
		// Register listeners at file-load time so we catch the free plugin's
		// batchpilot_register_targets / batchpilot_register_operations actions,
		// which fire from inside its on_plugins_loaded callback at priority -1.
		// Registering inside our own plugins_loaded callback would be too late.
		\add_action( 'batchpilot_register_targets', [ $this, 'register_targets' ], 10, 1 );
		\add_action( 'batchpilot_register_operations', [ $this, 'register_operations' ], 10, 5 );
		\add_filter( 'batchpilot_presets', [ ProPresets::class, 'extend' ] );
		\add_action( 'admin_notices', [ $this, 'maybe_render_free_required_notice' ] );

		// Opt PostTarget into the Pro find-replace operation. Free's PostTarget
		// reports a default-supported set (delete/duplicate/edit); this filter
		// augments it without subclassing.
		\add_filter(
			'batchpilot_post_target_supported_operations',
			static function ( array $supported ): array {
				$supported[] = 'find-replace';
				return $supported;
			}
		);

		// Schedule machinery wires up AFTER the free plugin has finished bootstrapping,
		// because it depends on the free plugin's ExecutionService + ActionSchedulerBridge
		// (which live in its service container, populated during on_plugins_loaded).
		\add_action( 'batchpilot_booted', [ $this, 'on_batchpilot_booted' ], 10, 1 );

		// Admin menu + asset loader are independent of free's service container, so they
		// register on the standard hooks directly from here.
		( new AdminMenu() )->register();
		( new AssetLoader( $this->plugin_file ) )->register();
	}

	public function register_targets( TargetRegistry $registry ): void {
		$registry->register( new UserTarget() );
		$registry->register( new CommentTarget() );
		$registry->register( new TermTarget() );
		$registry->register( new MediaTarget() );

		// WooCommerce target only when WooCommerce is active; the Target itself
		// doesn't depend on Woo classes, but the underlying queries (post_type
		// 'product', _stock_status meta, product_type taxonomy) are pointless
		// without it.
		if ( \class_exists( 'WooCommerce' ) ) {
			$registry->register( new WooProductTarget() );
			$registry->register( new WooOrderTarget() );
		}
	}

	public function register_operations(
		OperationRegistry $registry,
		TokenGenerator $token_generator,
		TokenStore $token_store,
		OperationRepository $operations_repo,
		SnapshotRepository $snapshots_repo
	): void {
		$registry->register( new UserDeleteOperation( $token_generator, $token_store, $operations_repo, $snapshots_repo ) );
		$registry->register( new CommentDeleteOperation( $token_generator, $token_store, $operations_repo, $snapshots_repo ) );
		$registry->register( new TermDeleteOperation( $token_generator, $token_store, $operations_repo, $snapshots_repo ) );
		$registry->register( new BulkFindReplaceOperation( $token_generator, $token_store, $operations_repo, $snapshots_repo ) );

		if ( \class_exists( 'WooCommerce' ) ) {
			$registry->register( new WooOrderDeleteOperation( $token_generator, $token_store, $operations_repo, $snapshots_repo ) );
		}
	}

	public function on_batchpilot_booted( \BatchPilot\Plugin $free ): void {
		$execution = $free->get( 'execution.service' );
		$scheduler = $free->get( 'async.action_scheduler' );

		if ( ! $execution instanceof ExecutionService || ! $scheduler instanceof ActionSchedulerBridge ) {
			return;
		}

		global $wpdb;
		$repository = new ScheduleRepository( $wpdb );
		$manager    = new ScheduleManager( $repository, $scheduler );
		$runner     = new ScheduleRunner( $repository, $execution );
		$runner->register();

		$controller = new SchedulesController( $repository, $manager, $runner );
		$controller->register();

		( new NotificationDispatcher( $repository ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new ScheduleCommand( $repository, $manager, $runner ) )->register();
		}
	}

	public function maybe_render_free_required_notice(): void {
		if ( \class_exists( '\\BatchPilot\\Plugin' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		\esc_html_e( 'BatchPilot Pro requires the BatchPilot plugin to be installed and active.', 'batchpilot-pro' );
		echo '</p></div>';
	}
}
