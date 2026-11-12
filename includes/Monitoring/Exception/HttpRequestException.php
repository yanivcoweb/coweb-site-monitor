<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Exception;

use RuntimeException;

final class HttpRequestException extends RuntimeException {
	public function __construct( private readonly string $errorType, string $errorCode, string $message ) {
		parent::__construct( $message );
		$this->code = 0;
		$this->errorCode = $errorCode;
	}

	private readonly string $errorCode;

	public function errorType(): string {
		return $this->errorType;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
