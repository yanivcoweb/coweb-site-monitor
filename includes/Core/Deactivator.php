<?php
/**
 * Plugin deactivation service.
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

namespace SiteMonitor\Core;

use SiteMonitor\Database\ScanRepository;

final class Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'site_monitor_run_scheduled_scan' );
		wp_clear_scheduled_hook( 'site_monitor_process_scan_batch' );
		wp_clear_scheduled_hook( 'site_monitor_cleanup_history' );
		wp_clear_scheduled_hook( Scheduler::INCIDENT_HOOK );
		( new ScanRepository() )->failRunningScans(
			'deactivated',
			'The scan was interrupted because Site Monitor was deactivated.'
		);
		delete_option( 'site_monitor_scan_lock' );
	}
}
