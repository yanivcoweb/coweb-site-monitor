<?php
declare(strict_types=1);

namespace SiteMonitor\Core;

use SiteMonitor\Monitoring\ScanManager;

final class Scheduler {
	public const SCAN_HOOK    = 'site_monitor_run_scheduled_scan';
	public const BATCH_HOOK   = 'site_monitor_process_scan_batch';
	public const CLEANUP_HOOK = 'site_monitor_cleanup_history';
	public const INCIDENT_HOOK = 'site_monitor_check_incidents';

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'addSchedules' ) );
		add_action( 'init', array( $this, 'ensureScheduled' ) );
		add_action( self::SCAN_HOOK, array( $this, 'runScheduledScan' ) );
		add_action( self::BATCH_HOOK, array( $this, 'processBatch' ), 10, 2 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanupHistory' ) );
		add_action( self::INCIDENT_HOOK, array( $this, 'runIncidentScan' ) );
	}

	/** @param array<string,array<string,mixed>> $schedules */
	public function addSchedules( array $schedules ): array {
		foreach ( array( 5, 10, 15, 30, 60 ) as $minutes ) {
			$schedules[ 'site_monitor_incident_' . $minutes ] = array( 'interval' => $minutes * MINUTE_IN_SECONDS, 'display' => sprintf( __( 'Every %d minutes (incident checks)', 'site-monitor' ), $minutes ) );
		}
		$schedules['site_monitor_12_hours'] = array( 'interval' => 12 * HOUR_IN_SECONDS, 'display' => __( 'Every 12 hours', 'site-monitor' ) );
		$schedules['site_monitor_6_hours']  = array( 'interval' => 6 * HOUR_IN_SECONDS, 'display' => __( 'Every 6 hours', 'site-monitor' ) );

		return $schedules;
	}

	public function ensureScheduled(): void {
		$minutes = (int) Settings::get()['incident_check_minutes'];
		$incident_schedule = 'site_monitor_incident_' . $minutes;
		$incident_event = wp_get_scheduled_event( self::INCIDENT_HOOK );
		if ( $incident_event && ( 0 === $minutes || $incident_event->schedule !== $incident_schedule ) ) {
			wp_clear_scheduled_hook( self::INCIDENT_HOOK );
			$incident_event = false;
		}
		if ( in_array( $minutes, array( 5, 10, 15, 30, 60 ), true ) && ! $incident_event ) {
			wp_schedule_event( time() + $minutes * MINUTE_IN_SECONDS, $incident_schedule, self::INCIDENT_HOOK );
		}
		$settings  = get_option( 'site_monitor_settings', array() );
		$frequency = is_array( $settings ) ? (string) ( $settings['frequency'] ?? 'daily' ) : 'daily';
		$allowed   = array( 'daily', 'site_monitor_12_hours', 'site_monitor_6_hours', 'hourly' );
		$frequency = in_array( $frequency, $allowed, true ) ? $frequency : 'daily';
		$event     = wp_get_scheduled_event( self::SCAN_HOOK );

		if ( $event && $event->schedule !== $frequency ) {
			wp_clear_scheduled_hook( self::SCAN_HOOK );
			$event = false;
		}
		if ( ! $event ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::SCAN_HOOK );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public function runScheduledScan(): void {
		try {
			ScanManager::createDefault()->start( 'scheduled' );
			update_option( 'site_monitor_last_scheduler_run', gmdate( 'Y-m-d H:i:s' ), false );
		} catch ( \Throwable $exception ) {
			update_option( 'site_monitor_last_scheduler_error', gmdate( 'Y-m-d H:i:s' ), false );
		}
	}

	public function runIncidentScan(): void {
		if ( 0 === (int) Settings::get()['incident_check_minutes'] || 0 === ( new \SiteMonitor\Database\PageRepository() )->countEnabled( true ) ) {
			return;
		}
		try {
			ScanManager::createDefault()->start( 'incident' );
		} catch ( \Throwable $exception ) {
			// A running scan holds the shared lock; retry at the next interval.
			update_option( 'site_monitor_last_incident_scheduler_error', gmdate( 'Y-m-d H:i:s' ), false );
		}
	}

	public function processBatch( int $scan_id, string $token ): void {
		ScanManager::createDefault()->processBatch( $scan_id, $token );
	}

	public function cleanupHistory(): void {
		try {
			$settings = get_option( 'site_monitor_settings', array() );
			$days     = is_array( $settings ) ? max( 1, min( 365, (int) ( $settings['retention_days'] ?? 30 ) ) ) : 30;
			ScanManager::createDefault()->cleanup( $days );
		} catch ( \Throwable $exception ) {
			update_option( 'site_monitor_last_cleanup_error', gmdate( 'Y-m-d H:i:s' ), false );
		}
	}
}
