<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_site_transient( 'batchpilot_pro_release' );

$batchpilot_settings = get_option( 'batchpilot_settings', [] );
if ( ! is_array( $batchpilot_settings ) || empty( $batchpilot_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

wp_clear_scheduled_hook( 'batchpilot_pro_run_schedule' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'batchpilot_pro_run_schedule' );
}
$batchpilot_pro_as_table = $wpdb->prefix . 'actionscheduler_actions';
if ( $batchpilot_pro_as_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $batchpilot_pro_as_table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$batchpilot_pro_as_table} WHERE hook = %s", 'batchpilot_pro_run_schedule' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$batchpilot_pro_schedules = $wpdb->prefix . 'batchpilot_schedules';
if ( $batchpilot_pro_schedules === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $batchpilot_pro_schedules ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "TRUNCATE TABLE {$batchpilot_pro_schedules}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
