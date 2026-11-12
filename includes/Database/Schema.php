<?php
/**
 * Database schema installation and versioning.
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

namespace SiteMonitor\Database;

final class Schema {
	public const VERSION = '1.2.2';

	private const VERSION_OPTION = 'site_monitor_db_version';

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$pages_table     = $wpdb->prefix . 'site_monitor_pages';
		$scans_table     = $wpdb->prefix . 'site_monitor_scans';
		$results_table   = $wpdb->prefix . 'site_monitor_results';
		$notifications_table = $wpdb->prefix . 'site_monitor_notification_attempts';

		$pages_sql = "CREATE TABLE {$pages_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			url_hash char(64) NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'manual',
			enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
			monitoring_priority tinyint(3) unsigned NOT NULL DEFAULT 5,
			validation_config longtext DEFAULT NULL,
			last_status varchar(20) NOT NULL DEFAULT 'unknown',
			last_http_status smallint(5) unsigned DEFAULT NULL,
			last_response_time_ms int(10) unsigned DEFAULT NULL,
			consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
			last_checked_at datetime DEFAULT NULL,
			last_alerted_at datetime DEFAULT NULL,
			last_notification_attempt_at datetime DEFAULT NULL,
			notification_state varchar(20) NOT NULL DEFAULT 'none',
			incident_started_at datetime DEFAULT NULL,
			incident_failure_type varchar(64) DEFAULT NULL,
			incident_http_status smallint(5) unsigned DEFAULT NULL,
			incident_error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			UNIQUE KEY post_id_unique (post_id),
			KEY enabled_priority (enabled,monitoring_priority),
			KEY enabled_id (enabled,id),
			KEY last_status (last_status),
			KEY last_checked_at (last_checked_at)
		) {$charset_collate};";

		$scans_sql = "CREATE TABLE {$scans_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			total_pages int(10) unsigned NOT NULL DEFAULT 0,
			checked_pages int(10) unsigned NOT NULL DEFAULT 0,
			failed_pages int(10) unsigned NOT NULL DEFAULT 0,
			cursor_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			trigger_type varchar(20) NOT NULL DEFAULT 'scheduled',
			error_code varchar(64) DEFAULT NULL,
			error_message text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_started (status,started_at),
			KEY trigger_type (trigger_type),
			KEY completed_at (completed_at)
		) {$charset_collate};";

		$results_sql = "CREATE TABLE {$results_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			page_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'unknown',
			http_status smallint(5) unsigned DEFAULT NULL,
			response_time_ms int(10) unsigned DEFAULT NULL,
			final_url text DEFAULT NULL,
			validation_status varchar(20) DEFAULT NULL,
			error_type varchar(32) DEFAULT NULL,
			error_code varchar(64) DEFAULT NULL,
			error_message text DEFAULT NULL,
			details longtext DEFAULT NULL,
			checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY page_checked (page_id,checked_at),
			KEY status_checked (status,checked_at),
			KEY checked_at (checked_at)
		) {$charset_collate};";

		$notifications_sql = "CREATE TABLE {$notifications_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_id bigint(20) unsigned NOT NULL,
			result_id bigint(20) unsigned NOT NULL,
			notification_type varchar(20) NOT NULL,
			provider varchar(32) NOT NULL,
			recipient varchar(320) NOT NULL,
			subject text NOT NULL,
			attempt_status varchar(20) NOT NULL,
			provider_message text DEFAULT NULL,
			attempted_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY page_attempted (page_id,attempted_at),
			KEY result_id (result_id),
			KEY attempt_status (attempt_status)
		) {$charset_collate};";

		if ( self::tableExists( $pages_table ) ) {
			self::addPageNotificationColumns( $pages_table );
			self::addPageIndexes( $pages_table );
		} else {
			dbDelta( $pages_sql );
		}
		if ( ! self::tableExists( $scans_table ) ) {
			dbDelta( $scans_sql );
		}
		if ( ! self::tableExists( $results_table ) ) {
			dbDelta( $results_sql );
		}
		dbDelta( $notifications_sql );

		self::ensureNotificationSettings();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Get table names without hardcoding the installation prefix.
	 *
	 * @return array{pages: string, scans: string, results: string, notification_attempts: string}
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'pages'   => $wpdb->prefix . 'site_monitor_pages',
			'scans'   => $wpdb->prefix . 'site_monitor_scans',
			'results' => $wpdb->prefix . 'site_monitor_results',
			'notification_attempts' => $wpdb->prefix . 'site_monitor_notification_attempts',
		);
	}

	private static function ensureNotificationSettings(): void {
		$settings = get_option( 'site_monitor_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = array(
			'alert_failure_threshold' => 2,
			'alert_repeat_hours'      => 24,
			'notification_email'      => sanitize_email( (string) get_option( 'admin_email', '' ) ),
		);

		update_option( 'site_monitor_settings', array_merge( $defaults, $settings ), false );
	}

	private static function tableExists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function addPageNotificationColumns( string $table ): void {
		global $wpdb;
		$columns = array(
			'last_notification_attempt_at' => 'datetime DEFAULT NULL AFTER last_alerted_at',
			'notification_state'           => "varchar(20) NOT NULL DEFAULT 'none' AFTER last_notification_attempt_at",
			'incident_started_at'          => 'datetime DEFAULT NULL AFTER notification_state',
			'incident_failure_type'        => 'varchar(64) DEFAULT NULL AFTER incident_started_at',
			'incident_http_status'         => 'smallint(5) unsigned DEFAULT NULL AFTER incident_failure_type',
			'incident_error_message'       => 'text DEFAULT NULL AFTER incident_http_status',
		);

		foreach ( $columns as $column => $definition ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null === $exists ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	private static function addPageIndexes( string $table ): void {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				'enabled_id'
			)
		);
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY enabled_id (enabled,id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
