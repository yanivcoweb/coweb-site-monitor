<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Contracts;

use SiteMonitor\Monitoring\Value\ValidationIssue;

interface ValidationRuleInterface {
	/** @param array<string, mixed> $configuration */
	public function validate( string $body, array $configuration ): ?ValidationIssue;
}
