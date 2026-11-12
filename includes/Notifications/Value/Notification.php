<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications\Value;

final class Notification {
	public function __construct(
		public readonly string $type,
		public readonly int $pageId,
		public readonly string $recipient,
		public readonly string $websiteName,
		public readonly string $url,
		public readonly string $failureType,
		public readonly ?int $httpStatus,
		public readonly string $errorDescription,
		public readonly string $detectedAt,
		public readonly string $dashboardUrl,
		public readonly string $attemptedAt
	) {}

	public function subject(): string {
		$label = 'recovery' === $this->type ? 'Recovered' : ( 'repeat' === $this->type ? 'Still failing' : 'Failure detected' );

		return sprintf( '[Site Monitor] %s: %s', $label, $this->websiteName );
	}

	public function body(): string {
		$heading = 'recovery' === $this->type
			? 'A monitored page has recovered.'
			: ( 'repeat' === $this->type ? 'A monitored page is still failing.' : 'A monitored page has failed.' );

		return implode(
			"\n",
			array(
				$heading,
				'',
				'Website: ' . $this->websiteName,
				'URL: ' . $this->url,
				'Failure type: ' . $this->failureType,
				'HTTP status: ' . ( null === $this->httpStatus ? 'Not available' : (string) $this->httpStatus ),
				'Error description: ' . $this->errorDescription,
				'Detected at (UTC): ' . $this->detectedAt,
				'Dashboard: ' . $this->dashboardUrl,
			)
		);
	}
}
