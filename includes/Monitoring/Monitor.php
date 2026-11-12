<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use InvalidArgumentException;
use SiteMonitor\Database\PageRepository;
use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Database\ScanRepository;
use SiteMonitor\Monitoring\Value\CheckResult;
use SiteMonitor\Notifications\NotificationManager;
use Throwable;

final class Monitor {
	public function __construct(
		private readonly PageChecker $checker,
		private readonly PageRepository $pages,
		private readonly ScanRepository $scans,
		private readonly ResultRepository $results,
		private readonly NotificationManager $notifications
	) {}

	public static function createDefault(): self {
		return new self(
			new PageChecker( new WordPressHttpClient(), new SameSiteUrlGuard(), new ContentValidator() ),
			new PageRepository(),
			new ScanRepository(),
			new ResultRepository(),
			NotificationManager::createDefault()
		);
	}

	public function checkPage( int $page_id, string $trigger_type = 'manual' ): CheckResult {
		$page = $this->pages->find( $page_id );
		if ( null === $page ) {
			throw new InvalidArgumentException( 'The monitored page does not exist.' );
		}

		$scan_id = $this->scans->createSinglePageScan( $trigger_type );
		$result  = $this->checkPageWithinScan( $page_id, $scan_id, $page );
		$this->scans->complete( $scan_id, $result );
		( new \SiteMonitor\Notifications\ScanSummary() )->send( $scan_id );

		return $result;
	}

	/** @param array<string,mixed>|null $page */
	public function checkPageWithinScan( int $page_id, int $scan_id, ?array $page = null ): CheckResult {
		$page ??= $this->pages->find( $page_id );
		if ( null === $page ) {
			throw new InvalidArgumentException( 'The monitored page does not exist.' );
		}

		$configuration = json_decode( (string) ( $page['validation_config'] ?? '' ), true );
		$configuration = is_array( $configuration ) ? $configuration : array();
		$settings      = get_option( 'site_monitor_settings', array() );
		if ( is_array( $settings ) ) {
			$configuration['timeout'] = (int) ( $settings['request_timeout'] ?? 15 );
		}

		$result = $this->checker->check( (string) $page['url'], $configuration );
		$result_id = $this->results->save( $scan_id, $page_id, $result );
		$this->pages->updateAfterCheck( $page_id, $result );

		try {
			$this->notifications->handle( $page, $result_id, $result );
		} catch ( Throwable $exception ) {
			update_option( 'site_monitor_last_notification_error', $result->checkedAt, false );
			error_log( 'Site Monitor notification error: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}
}
