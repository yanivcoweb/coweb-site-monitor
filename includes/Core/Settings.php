<?php
declare(strict_types=1);

namespace SiteMonitor\Core;

final class Settings {
	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'frequency'                => 'daily',
			'incident_check_minutes'   => 0,
			'request_timeout'          => 15,
			'batch_size'               => 10,
			'retention_days'           => 30,
			'alert_failure_threshold'  => 2,
			'alert_repeat_hours'       => 24,
			'notification_email'       => sanitize_email( (string) get_option( 'admin_email', '' ) ),
			'post_types'               => array( 'page' ),
			'exclude_password_content' => true,
		);
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		$stored = get_option( 'site_monitor_settings', array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitize administrator-supplied settings while preserving non-form keys.
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $existing
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input, array $existing = array() ): array {
		$frequency = sanitize_key( (string) ( $input['frequency'] ?? 'daily' ) );
		$allowed   = array( 'daily', 'site_monitor_12_hours', 'site_monitor_6_hours', 'hourly' );
		$incident_minutes = (int) ( $input['incident_check_minutes'] ?? $existing['incident_check_minutes'] ?? 0 );

		return array_merge(
			self::defaults(),
			$existing,
			array(
				'frequency'               => in_array( $frequency, $allowed, true ) ? $frequency : 'daily',
				'incident_check_minutes'  => in_array( $incident_minutes, array( 0, 5, 10, 15, 30, 60 ), true ) ? $incident_minutes : 0,
				'request_timeout'         => max( 1, min( 60, (int) ( $input['request_timeout'] ?? 15 ) ) ),
				'batch_size'              => max( 1, min( 50, (int) ( $input['batch_size'] ?? 10 ) ) ),
				'retention_days'          => max( 1, min( 365, (int) ( $input['retention_days'] ?? 30 ) ) ),
				'alert_failure_threshold' => max( 1, min( 100, (int) ( $input['alert_failure_threshold'] ?? 2 ) ) ),
				'alert_repeat_hours'      => max( 0, min( 8760, (int) ( $input['alert_repeat_hours'] ?? 24 ) ) ),
				'notification_email'      => sanitize_email( (string) ( $input['notification_email'] ?? '' ) ),
			)
		);
	}
}
