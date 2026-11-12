<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Value;

final class CheckResult {
	/** @param list<ValidationIssue> $validationIssues */
	public function __construct(
		public readonly string $status,
		public readonly string $requestedUrl,
		public readonly ?string $finalUrl,
		public readonly ?int $httpStatus,
		public readonly ?int $responseTimeMs,
		public readonly string $errorType,
		public readonly ?string $errorCode,
		public readonly ?string $errorMessage,
		public readonly array $validationIssues,
		public readonly bool $redirected,
		public readonly string $checkedAt
	) {}

	public static function failure( string $url, string $type, string $code, string $message ): self {
		return new self( 'critical', $url, null, null, null, $type, $code, $message, array(), false, gmdate( 'Y-m-d H:i:s' ) );
	}

	/** @return array<string, mixed> */
	public function details(): array {
		return array(
			'redirected'        => $this->redirected,
			'validation_issues' => array_map(
				static fn ( ValidationIssue $issue ): array => array(
					'code'     => $issue->code,
					'message'  => $issue->message,
					'severity' => $issue->severity,
				),
				$this->validationIssues
			),
		);
	}
}
