<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Contracts;

use SiteMonitor\Monitoring\Value\HttpResponse;

interface HttpClientInterface {
	public function get( string $url, int $timeout, int $maximum_bytes ): HttpResponse;
}
