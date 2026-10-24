<?php
/**
 * Plugin Name:       BatchPilot Pro
 * Description:       Pro extensions for BatchPilot: more targets (Users to start), more operations, scheduling, WooCommerce, and notifications.
 * Version:           1.0.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Jean Galea
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       batchpilot-pro
 * Update URI:        https://github.com/jgalea/batchpilot-pro
 * Requires Plugins:  batchpilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BATCHPILOT_PRO_VERSION', '1.0.2' );
define( 'BATCHPILOT_PRO_PLUGIN_FILE', __FILE__ );
define( 'BATCHPILOT_PRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BATCHPILOT_PRO_BASENAME', plugin_basename( __FILE__ ) );

require_once BATCHPILOT_PRO_PLUGIN_DIR . 'includes/updater.php';

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BatchPilotPro\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = BATCHPILOT_PRO_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

\BatchPilotPro\Plugin::boot( __FILE__ );
