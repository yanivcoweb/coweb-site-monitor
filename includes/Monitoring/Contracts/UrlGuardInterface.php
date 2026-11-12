<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Contracts;

interface UrlGuardInterface {
	public function assertAllowed( string $url ): void;
}
