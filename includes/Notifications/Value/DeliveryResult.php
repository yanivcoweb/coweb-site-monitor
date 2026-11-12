<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications\Value;

final class DeliveryResult {
	public function __construct(
		public readonly bool $accepted,
		public readonly string $message
	) {}

	public static function accepted( string $message ): self {
		return new self( true, $message );
	}

	public static function failed( string $message ): self {
		return new self( false, $message );
	}
}
