<?php
namespace BatchPilotPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AssetLoader {

	public const HANDLE = 'batchpilot-pro-admin';

	private string $plugin_file;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public function register(): void {
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, AdminMenu::page_hook_suffixes(), true ) ) {
			return;
		}

		$plugin_dir = \plugin_dir_path( $this->plugin_file );
		$plugin_url = \plugin_dir_url( $this->plugin_file );
		$asset_path = $plugin_dir . 'assets/build/admin.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: [
				'dependencies' => [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
				'version'      => BATCHPILOT_PRO_VERSION,
			];

		// Pro's FilterEditor reads the shared FilterRow off
		// window.batchPilot.widgets, which is published by the free plugin's
		// batchpilot-widgets script. Listing it as a dependency makes WP enqueue
		// it before Pro's bundle, and only when Pro is actually on screen.
		$dependencies   = (array) $asset['dependencies'];
		$dependencies[] = 'batchpilot-widgets';

		\wp_enqueue_script(
			self::HANDLE,
			$plugin_url . 'assets/build/admin.js',
			$dependencies,
			(string) $asset['version'],
			true
		);

		\wp_enqueue_style( 'wp-components' );

		\wp_enqueue_style(
			self::HANDLE,
			$plugin_url . 'assets/build/admin.css',
			[ 'wp-components' ],
			(string) $asset['version']
		);

		\wp_set_script_translations( self::HANDLE, 'batchpilot-pro' );

		\wp_add_inline_script(
			self::HANDLE,
			'window.batchPilotProAdmin = ' . \wp_json_encode( $this->bootstrap_payload() ) . ';',
			'before'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function bootstrap_payload(): array {
		return [
			'namespace' => 'batchpilot/v1',
			'restUrl'   => \esc_url_raw( \rest_url( 'batchpilot/v1/' ) ),
			'nonce'     => \wp_create_nonce( 'wp_rest' ),
			'adminUrl'  => \admin_url(),
			'pluginUrl' => \plugin_dir_url( $this->plugin_file ),
			'version'   => BATCHPILOT_PRO_VERSION,
		];
	}
}
