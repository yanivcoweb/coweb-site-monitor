<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Value;

final class ValidationIssue {
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly string $severity = 'critical'
	) {}
}
