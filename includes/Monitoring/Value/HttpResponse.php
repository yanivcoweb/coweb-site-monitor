<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Value;

final class HttpResponse {
	/** @param array<string, string> $headers */
	public function __construct(
		public readonly int $statusCode,
		public readonly string $body,
		public readonly array $headers,
		public readonly int $responseTimeMs
	) {}

	public function header( string $name ): ?string {
		$name = strtolower( $name );

		return $this->headers[ $name ] ?? null;
	}
}
