<?php
/**
 * Plugin activation service.
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

namespace SiteMonitor\Core;

use SiteMonitor\Database\Schema;

final class Activator {
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( SITE_MONITOR_FILE ) );
			wp_die(
				esc_html__( 'Site Monitor requires PHP 8.1 or newer.', 'site-monitor' ),
				esc_html__( 'Plugin activation failed', 'site-monitor' ),
				array( 'back_link' => true )
			);
		}

		Schema::install();

		if ( false === get_option( 'site_monitor_settings', false ) ) {
			add_option( 'site_monitor_settings', Settings::defaults(), '', false );
		}

		update_option( 'site_monitor_activated_at', gmdate( 'Y-m-d H:i:s' ), false );
		if ( ! wp_next_scheduled( Scheduler::SCAN_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', Scheduler::SCAN_HOOK );
		}
		if ( ! wp_next_scheduled( Scheduler::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Scheduler::CLEANUP_HOOK );
		}
	}

}
