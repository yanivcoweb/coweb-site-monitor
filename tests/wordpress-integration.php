<?php
/**
 * Destructive local WordPress integration QA with automatic data restoration.
 *
 * Run only against a disposable/local WordPress installation:
 * SITE_MONITOR_WP_ROOT=/path/to/wordpress SITE_MONITOR_QA_ALLOW_DESTRUCTIVE=1 php tests/wordpress-integration.php
 *
 * SITE_MONITOR_QA_DB_HOST may be used when the CLI database endpoint differs
 * from the value in wp-config.php (as it does for Local on Windows).
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

$wp_root = rtrim( (string) getenv( 'SITE_MONITOR_WP_ROOT' ), '/\\' );
if ( '' === $wp_root || '1' !== getenv( 'SITE_MONITOR_QA_ALLOW_DESTRUCTIVE' ) ) {
	fwrite( STDERR, "Set SITE_MONITOR_WP_ROOT and SITE_MONITOR_QA_ALLOW_DESTRUCTIVE=1.\n" );
	exit( 2 );
}

$database_host = (string) getenv( 'SITE_MONITOR_QA_DB_HOST' );
if ( '' !== $database_host && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', $database_host );
}

require $wp_root . '/wp-load.php';

use SiteMonitor\Core\Activator;
use SiteMonitor\Core\Deactivator;
use SiteMonitor\Core\ScanLock;
use SiteMonitor\Core\Scheduler;
use SiteMonitor\Database\NotificationRepository;
use SiteMonitor\Database\PageRepository;
use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Database\ScanRepository;
use SiteMonitor\Database\Schema;
use SiteMonitor\Monitoring\ScanManager;
use SiteMonitor\Monitoring\Value\CheckResult;
use SiteMonitor\Notifications\NotificationManager;

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run outside a local WordPress environment.\n" );
	exit( 2 );
}

global $wpdb;

$tables       = Schema::tables();
$backup_token = 'qa_backup';
$backups      = array();
$option_rows  = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'site_monitor_%'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$cron         = get_option( 'cron', array() );
$failures     = 0;

/** Report one assertion. */
function qa_assert( bool $condition, string $name ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS {$name}\n";
		return;
	}

	++$failures;
	fwrite( STDERR, "FAIL {$name}\n" );
}

try {
	foreach ( $tables as $key => $table ) {
		$backup = $table . '_' . $backup_token;
		if ( $backup === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $backup ) ) ) ) {
			throw new RuntimeException( "Backup table already exists: {$backup}" );
		}
		$wpdb->query( "CREATE TABLE `{$backup}` LIKE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO `{$backup}` SELECT * FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$backups[ $key ] = $backup;
	}

	$initial_counts = array_map(
		static fn ( string $table ): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tables
	);

	$interrupted_scan = ( new ScanRepository() )->create( 0, 'qa_deactivation' );
	add_option( 'site_monitor_scan_lock', array( 'token' => 'qa-deactivation', 'expires_at' => time() + 900, 'acquired_at' => time() ), '', false );
	Deactivator::deactivate();
	$deactivated = ( new ScanRepository() )->find( $interrupted_scan );
	qa_assert( is_array( $deactivated ) && 'failed' === $deactivated['status'] && 'deactivated' === $deactivated['error_code'], 'deactivation fails an interrupted running scan' );
	qa_assert( false === get_option( 'site_monitor_scan_lock', false ), 'deactivation removes the scan lock' );
	qa_assert( ! wp_next_scheduled( Scheduler::SCAN_HOOK ) && ! wp_next_scheduled( Scheduler::BATCH_HOOK ) && ! wp_next_scheduled( Scheduler::CLEANUP_HOOK ), 'deactivation clears Site Monitor cron events' );
	qa_assert( $initial_counts['pages'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['pages']}`" ), 'deactivation preserves monitored pages' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	qa_assert( $initial_counts['results'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['results']}`" ), 'deactivation preserves results' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	Activator::activate();
	qa_assert( Schema::VERSION === get_option( 'site_monitor_db_version' ), 'activation installs the current schema version' );
	qa_assert( (bool) wp_next_scheduled( Scheduler::SCAN_HOOK ) && (bool) wp_next_scheduled( Scheduler::CLEANUP_HOOK ), 'activation schedules scan and cleanup events' );

	$before_migration = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['pages']}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "ALTER TABLE `{$tables['pages']}` DROP COLUMN incident_error_message" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "ALTER TABLE `{$tables['pages']}` DROP INDEX enabled_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	update_option( 'site_monitor_db_version', '1.0.0', false );
	Schema::maybe_upgrade();
	$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$tables['pages']}` LIKE %s", 'incident_error_message' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$index  = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
			$tables['pages'],
			'enabled_id'
		)
	);
	qa_assert( 'incident_error_message' === $column, 'schema migration restores a missing notification column' );
	qa_assert( 'enabled_id' === $index, 'schema migration installs the batch-query index' );
	qa_assert( $before_migration === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['pages']}`" ), 'schema migration preserves page rows' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$expired_scan = ( new ScanRepository() )->create( 0, 'qa_interrupted' );
	update_option( 'site_monitor_scan_lock', array( 'token' => 'qa-expired', 'expires_at' => time() - 1, 'acquired_at' => time() - 901 ), false );
	$block_queue = static function ( mixed $pre, object $event ): mixed {
		return Scheduler::BATCH_HOOK === $event->hook ? false : $pre;
	};
	add_filter( 'pre_schedule_event', $block_queue, 10, 2 );
	try {
		ScanManager::createDefault()->start( 'qa_recovery' );
	} catch ( Throwable $exception ) {
		// A forced queue failure is the expected outcome for the replacement scan.
	}
	remove_filter( 'pre_schedule_event', $block_queue, 10 );
	$recovered = ( new ScanRepository() )->find( $expired_scan );
	$latest    = ( new ScanRepository() )->latest();
	qa_assert( is_array( $recovered ) && 'failed' === $recovered['status'] && 'lock_expired' === $recovered['error_code'], 'an expired lock fails the abandoned scan before replacement' );
	qa_assert( is_array( $latest ) && 'failed' === $latest['status'] && 'queue_failed' === $latest['error_code'], 'a first-batch queue failure is persisted' );
	qa_assert( false === get_option( 'site_monitor_scan_lock', false ), 'a queue failure releases the scan lock' );

	$page_id = ( new PageRepository() )->upsert(
		array(
			'url'     => home_url( '/?site-monitor-qa=notification' ),
			'post_id' => null,
			'source'  => 'manual',
			'enabled' => 0,
		)
	);
	$page    = ( new PageRepository() )->find( $page_id );
	$result  = new CheckResult( 'critical', (string) $page['url'], (string) $page['url'], 500, 5, 'http', 'http_error', 'QA failure.', array(), false, gmdate( 'Y-m-d H:i:s' ) );
	$scan_id = ( new ScanRepository() )->createSinglePageScan( 'qa_notification' );
	$result_id = ( new ResultRepository() )->save( $scan_id, $page_id, $result );
	( new PageRepository() )->updateAfterCheck( $page_id, $result );
	$mail_failure = static fn (): bool => false;
	add_filter( 'pre_wp_mail', $mail_failure );
	NotificationManager::createDefault()->handle(
		$page,
		$result_id,
		$result,
		array( 'alert_failure_threshold' => 1, 'alert_repeat_hours' => 24, 'notification_email' => 'qa@example.com' )
	);
	remove_filter( 'pre_wp_mail', $mail_failure );
	$attempts = ( new NotificationRepository() )->findByPage( $page_id );
	$page     = ( new PageRepository() )->find( $page_id );
	qa_assert( 1 === count( $attempts ) && 'failed' === $attempts[0]['attempt_status'], 'a wp_mail failure is retained in the notification audit trail' );
	qa_assert( is_array( $page ) && 'open' === $page['notification_state'], 'a delivery failure does not erase the detected incident' );

	$old_time = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );
	$new_time = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	$old_scan = ( new ScanRepository() )->createSinglePageScan( 'qa_retention_old' );
	$new_scan = ( new ScanRepository() )->createSinglePageScan( 'qa_retention_new' );
	$wpdb->update( $tables['scans'], array( 'status' => 'completed', 'completed_at' => $old_time ), array( 'id' => $old_scan ) );
	$wpdb->update( $tables['scans'], array( 'status' => 'completed', 'completed_at' => $new_time ), array( 'id' => $new_scan ) );
	$old_result = new CheckResult( 'healthy', (string) $page['url'], (string) $page['url'], 200, 5, 'none', null, null, array(), false, $old_time );
	$new_result = new CheckResult( 'healthy', (string) $page['url'], (string) $page['url'], 200, 5, 'none', null, null, array(), false, $new_time );
	$old_result_id = ( new ResultRepository() )->save( $old_scan, $page_id, $old_result );
	$new_result_id = ( new ResultRepository() )->save( $new_scan, $page_id, $new_result );
	$wpdb->insert( $tables['notification_attempts'], array( 'page_id' => $page_id, 'result_id' => $old_result_id, 'notification_type' => 'failure', 'provider' => 'qa', 'recipient' => 'qa@example.com', 'subject' => 'QA', 'attempt_status' => 'failed', 'attempted_at' => $old_time ) );
	$wpdb->insert( $tables['notification_attempts'], array( 'page_id' => $page_id, 'result_id' => $new_result_id, 'notification_type' => 'failure', 'provider' => 'qa', 'recipient' => 'qa@example.com', 'subject' => 'QA', 'attempt_status' => 'failed', 'attempted_at' => $new_time ) );
	ScanManager::createDefault()->cleanup( 30 );
	qa_assert( null === ( new ScanRepository() )->find( $old_scan ) && null !== ( new ScanRepository() )->find( $new_scan ), 'retention removes only expired completed scans' );
	qa_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tables['results']}` WHERE id = %d", $old_result_id ) ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tables['results']}` WHERE id = %d", $new_result_id ) ), 'retention removes only expired results' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	qa_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tables['notification_attempts']}` WHERE result_id = %d", $old_result_id ) ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tables['notification_attempts']}` WHERE result_id = %d", $new_result_id ) ), 'retention removes only expired notification attempts' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	update_option( 'site_monitor_last_scheduler_run', 'qa', false );
	update_option( 'site_monitor_last_scheduler_error', 'qa', false );
	update_option( 'site_monitor_last_cleanup_error', 'qa', false );
	update_option( 'site_monitor_last_successful_scan', 'qa', false );
	update_option( 'site_monitor_last_successful_scheduled_execution', 'qa', false );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', true );
	}
	if ( ! defined( 'SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL' ) ) {
		define( 'SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL', true );
	}
	require dirname( __DIR__ ) . '/uninstall.php';
	$tables_removed = true;
	foreach ( $tables as $table ) {
		$tables_removed = $tables_removed && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
	qa_assert( $tables_removed, 'opt-in uninstall removes all plugin tables' );
	qa_assert( false === get_option( 'site_monitor_settings', false ) && false === get_option( 'site_monitor_last_scheduler_run', false ) && false === get_option( 'site_monitor_last_successful_scheduled_execution', false ), 'opt-in uninstall removes settings and scheduler metadata' );
	qa_assert( ! wp_next_scheduled( Scheduler::SCAN_HOOK ) && ! wp_next_scheduled( Scheduler::BATCH_HOOK ) && ! wp_next_scheduled( Scheduler::CLEANUP_HOOK ), 'opt-in uninstall clears Site Monitor cron events' );
} finally {
	foreach ( $tables as $key => $table ) {
		if ( isset( $backups[ $key ] ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "RENAME TABLE `{$backups[ $key ]}` TO `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	$current_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'site_monitor_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $current_options as $option_name ) {
		delete_option( (string) $option_name );
	}
	foreach ( $option_rows as $row ) {
		$wpdb->insert(
			$wpdb->options,
			array( 'option_name' => $row['option_name'], 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ),
			array( '%s', '%s', '%s' )
		);
	}
	update_option( 'cron', $cron, true );
	wp_cache_flush();
}

echo sprintf( "%d integration assertion(s) failed.\n", $failures );
exit( 0 === $failures ? 0 : 1 );
