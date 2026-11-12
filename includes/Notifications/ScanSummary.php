<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications;

use SiteMonitor\Core\Settings;
use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Database\ScanRepository;
use Throwable;

final class ScanSummary {
	public function send( int $scan_id ): void {
		try {
			$scan = ( new ScanRepository() )->find( $scan_id );
			if ( null === $scan || 'completed' !== $scan['status'] || 'manual' !== $scan['trigger_type'] ) {
				return;
			}
			$recipient = (string) Settings::get()['notification_email'];
			if ( ! is_email( $recipient ) ) {
				throw new \RuntimeException( 'The configured notification email is invalid.' );
			}
			$lines = array(
				'אתר: ' . get_bloginfo( 'name' ),
				'סיכום סריקה ידנית #' . $scan_id,
				'נבדקו: ' . $scan['checked_pages'] . ' עמודים. כשלים: ' . $scan['failed_pages'],
				'סיום (UTC): ' . $scan['completed_at'],
				'',
			);
			$problems = 0;
			$repository = new ResultRepository();
			$page = 1;
			do {
				$results = $repository->paginateByScan( $scan_id, $page, 100 );
				foreach ( $results['items'] as $result ) {
					if ( 'healthy' === $result['status'] && 'failed' !== $result['validation_status'] ) {
						continue;
					}
					++$problems;
					$lines[] = $result['url'];
					$lines[] = 'סטטוס: ' . $result['status'] . ' | HTTP: ' . ( $result['http_status'] ?? '—' );
					$lines[] = 'סוג הכשל: ' . ( $result['error_type'] ?? 'validation' ) . ' / ' . ( $result['error_code'] ?? '—' );
					if ( ! empty( $result['error_message'] ) ) {
						$lines[] = $result['error_message'];
					}
					$details = json_decode( (string) $result['details'], true );
					foreach ( (array) ( $details['validation_issues'] ?? array() ) as $issue ) {
						$lines[] = (string) ( $issue['message'] ?? '' );
					}
					$lines[] = '';
				}
				++$page;
			} while ( ! empty( $results['items'] ) && ( $page - 1 ) * 100 < $results['total'] );
			if ( 0 === $problems ) {
				$lines[] = 'לא נמצאו בעיות בתוצאות שנשמרו.';
			}
			if ( (int) $scan['failed_pages'] > $problems ) {
				$lines[] = 'חלק מהבדיקות נכשלו לפני שמירת תוצאה. יש לבדוק את היסטוריית הסריקה והלוגים.';
			}
			$lines[] = admin_url( 'admin.php?page=site-monitor-history&scan_id=' . $scan_id );
			$accepted = wp_mail( $recipient, '[' . wp_strip_all_tags( get_bloginfo( 'name' ) ) . '] סיכום סריקה #' . $scan_id, implode( "\n", $lines ) );
			update_option( 'site_monitor_last_scan_summary', array( 'scan_id' => $scan_id, 'status' => $accepted ? 'accepted' : 'failed', 'attempted_at' => gmdate( 'Y-m-d H:i:s' ) ), false );
			if ( ! $accepted ) {
				throw new \RuntimeException( 'wp_mail() rejected the scan summary.' );
			}
		} catch ( Throwable $exception ) {
			update_option( 'site_monitor_last_notification_error', gmdate( 'Y-m-d H:i:s' ), false );
			error_log( 'Site Monitor scan summary error: ' . $exception->getMessage() );
		}
	}
}
