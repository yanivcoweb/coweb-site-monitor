<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use RuntimeException;
use SiteMonitor\Core\ScanLock;
use SiteMonitor\Core\Scheduler;
use SiteMonitor\Database\PageRepository;
use SiteMonitor\Database\NotificationRepository;
use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Database\ScanRepository;
use Throwable;

final class ScanManager {
	public function __construct(
		private readonly ScanLock $lock,
		private readonly PageRepository $pages,
		private readonly ScanRepository $scans,
		private readonly ResultRepository $results,
		private readonly Monitor $monitor,
		private readonly NotificationRepository $notifications
	) {}

	public static function createDefault(): self {
		$pages   = new PageRepository();
		$scans   = new ScanRepository();
		$results = new ResultRepository();

		return new self( new ScanLock(), $pages, $scans, $results, Monitor::createDefault(), new NotificationRepository() );
	}

	public function start( string $trigger_type = 'manual' ): int {
		$token = $this->lock->acquire();
		if ( null === $token ) {
			throw new RuntimeException( 'A Site Monitor scan is already running.' );
		}

		$scan_id = 0;
		try {
			$this->scans->failRunningScans( 'lock_expired' );
			$total   = $this->pages->countEnabled( 'incident' === $trigger_type );
			$scan_id = $this->scans->create( $total, $trigger_type );
			if ( 0 === $total ) {
				$this->scans->finish( $scan_id );
				$this->lock->release( $token );
				( new \SiteMonitor\Notifications\ScanSummary() )->send( $scan_id );

				return $scan_id;
			}
			if ( false === wp_schedule_single_event( time() + 1, Scheduler::BATCH_HOOK, array( $scan_id, $token ) ) ) {
				throw new RuntimeException( 'Could not schedule the first scan batch.' );
			}

			return $scan_id;
		} catch ( Throwable $exception ) {
			if ( $scan_id > 0 ) {
				$this->scans->finish( $scan_id, 'failed', 'queue_failed', $exception->getMessage() );
			}
			$this->lock->release( $token );
			throw $exception;
		}
	}

	public function processBatch( int $scan_id, string $token ): void {
		$scan = $this->scans->find( $scan_id );
		if ( null === $scan || 'running' !== $scan['status'] ) {
			$this->lock->release( $token );

			return;
		}
		if ( ! $this->lock->refresh( $token ) ) {
			$this->scans->finish( $scan_id, 'failed', 'lock_lost', 'The scan lock expired or was replaced.' );

			return;
		}

		$settings   = get_option( 'site_monitor_settings', array() );
		$batch_size = is_array( $settings ) ? max( 1, min( 50, (int) ( $settings['batch_size'] ?? 10 ) ) ) : 10;
		$batch      = $this->pages->findEnabledAfter( (int) $scan['cursor_page_id'], $batch_size, 'incident' === $scan['trigger_type'] );
		$failed     = 0;
		$cursor     = (int) $scan['cursor_page_id'];

		try {
			foreach ( $batch as $page ) {
				if ( ! $this->lock->refresh( $token ) ) {
					throw new RuntimeException( 'The scan lock expired or was replaced during batch processing.' );
				}
				$cursor = (int) $page['id'];
				try {
					$result = $this->monitor->checkPageWithinScan( $cursor, $scan_id, $page );
					if ( in_array( $result->status, array( 'critical', 'unknown' ), true ) ) {
						++$failed;
					}
				} catch ( Throwable $exception ) {
					++$failed;
				}
				$this->scans->updateProgress( $scan_id, $cursor, 1, $failed );
				$failed = 0;
			}

			if ( count( $batch ) < $batch_size ) {
				$this->scans->finish( $scan_id );
				$this->lock->release( $token );
				update_option( 'site_monitor_last_successful_scan', gmdate( 'Y-m-d H:i:s' ), false );
				( new \SiteMonitor\Notifications\ScanSummary() )->send( $scan_id );
				if ( 'scheduled' === $scan['trigger_type'] ) {
					update_option( 'site_monitor_last_successful_scheduled_execution', gmdate( 'Y-m-d H:i:s' ), false );
				}

				return;
			}

			if ( false === wp_schedule_single_event( time() + 5, Scheduler::BATCH_HOOK, array( $scan_id, $token ) ) ) {
				throw new RuntimeException( 'Could not schedule the next scan batch.' );
			}
		} catch ( Throwable $exception ) {
			$this->scans->finish( $scan_id, 'failed', 'batch_failed', $exception->getMessage() );
			$this->lock->release( $token );
		}
	}

	public function cleanup( int $retention_days ): void {
		$days   = max( 1, min( 365, $retention_days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$this->notifications->deleteOlderThan( $cutoff );
		$this->results->deleteOlderThan( $cutoff );
		$this->scans->deleteOlderThan( $cutoff );
		$this->pages->cleanupOrphanedPostLinks();
	}
}
