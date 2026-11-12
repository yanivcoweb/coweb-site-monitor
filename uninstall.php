<?php
/**
 * Site Monitor uninstall policy.
 *
 * Data is preserved unless the site owner explicitly opts in by defining
 * SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL as true in wp-config.php.
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL' ) || true !== SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL ) {
	return;
}

wp_clear_scheduled_hook( 'site_monitor_run_scheduled_scan' );
wp_clear_scheduled_hook( 'site_monitor_process_scan_batch' );
wp_clear_scheduled_hook( 'site_monitor_cleanup_history' );
wp_clear_scheduled_hook( 'site_monitor_check_incidents' );

global $wpdb;

$table_names = array(
	$wpdb->prefix . 'site_monitor_notification_attempts',
	$wpdb->prefix . 'site_monitor_results',
	$wpdb->prefix . 'site_monitor_scans',
	$wpdb->prefix . 'site_monitor_pages',
);

foreach ( $table_names as $table_name ) {
	// Table names are constructed solely from WordPress's trusted table prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'site_monitor_db_version' );
delete_option( 'site_monitor_settings' );
delete_option( 'site_monitor_activated_at' );
delete_option( 'site_monitor_scan_lock' );
delete_option( 'site_monitor_last_notification_error' );
delete_option( 'site_monitor_last_scheduler_run' );
delete_option( 'site_monitor_last_scheduler_error' );
delete_option( 'site_monitor_last_incident_scheduler_error' );
delete_option( 'site_monitor_last_cleanup_error' );
delete_option( 'site_monitor_last_successful_scan' );
delete_option( 'site_monitor_last_successful_scheduled_execution' );
