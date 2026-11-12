<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use SiteMonitor\Monitoring\Contracts\HttpClientInterface;
use SiteMonitor\Monitoring\Contracts\UrlGuardInterface;
use SiteMonitor\Monitoring\Exception\HttpRequestException;
use SiteMonitor\Monitoring\Exception\UnsafeUrlException;
use SiteMonitor\Monitoring\Value\CheckResult;
use SiteMonitor\Monitoring\Value\ValidationIssue;
use Throwable;

final class PageChecker {
	public function __construct(
		private readonly HttpClientInterface $httpClient,
		private readonly UrlGuardInterface $urlGuard,
		private readonly ContentValidator $validator
	) {}

	/** @param array<string, mixed> $configuration */
	public function check( string $url, array $configuration = array() ): CheckResult {
		$timeout       = max( 1, min( 60, (int) ( $configuration['timeout'] ?? 15 ) ) );
		$maximum_bytes = max( 1024, min( 5 * 1024 * 1024, (int) ( $configuration['maximum_bytes'] ?? 1048576 ) ) );
		$redirects     = 0;
		$current_url   = $url;
		$total_time    = 0;

		try {
			while ( true ) {
				$this->urlGuard->assertAllowed( $current_url );
				try {
					$response = $this->httpClient->get( $current_url, $timeout, $maximum_bytes );
				} catch ( HttpRequestException $exception ) {
					if ( 'timeout' !== $exception->errorType() ) {
						throw $exception;
					}
					// A loopback request can hit the PHP worker running this scan.
					// Confirm a timeout with one fresh request before reporting failure.
					$this->urlGuard->assertAllowed( $current_url );
					$response = $this->httpClient->get( $current_url, $timeout, $maximum_bytes );
				}
				$total_time += $response->responseTimeMs;

				if ( $response->statusCode < 300 || $response->statusCode >= 400 ) {
					break;
				}

				$location = $response->header( 'location' );
				if ( null === $location || '' === trim( $location ) ) {
					return $this->httpFailure( $url, $current_url, $response->statusCode, $total_time, 'redirect_missing_location', 'The redirect response has no Location header.', $redirects > 0 );
				}
				if ( false === (bool) ( $configuration['allow_redirects'] ?? true ) ) {
					return $this->httpFailure( $url, $current_url, $response->statusCode, $total_time, 'unexpected_redirect', 'The page redirected unexpectedly.', true );
				}
				if ( ++$redirects > 5 ) {
					return $this->httpFailure( $url, $current_url, $response->statusCode, $total_time, 'too_many_redirects', 'The redirect limit was exceeded.', true );
				}

				$current_url = $this->absoluteUrl( $location, $current_url );
			}

			if ( $response->statusCode < 200 || $response->statusCode >= 400 ) {
				return $this->httpFailure( $url, $current_url, $response->statusCode, $total_time, 'http_error', 'The page returned an unsuccessful HTTP status.', $redirects > 0 );
			}

			$issues = $this->validator->validate( $response->body, $configuration );
			$status = $this->statusFromIssues( $issues );

			return new CheckResult(
				$status,
				$url,
				$current_url,
				$response->statusCode,
				$total_time,
				array() === $issues ? 'none' : 'validation',
				$issues[0]->code ?? null,
				$issues[0]->message ?? null,
				$issues,
				$redirects > 0,
				gmdate( 'Y-m-d H:i:s' )
			);
		} catch ( UnsafeUrlException $exception ) {
			return CheckResult::failure( $url, 'security', 'unsafe_url', $exception->getMessage() );
		} catch ( HttpRequestException $exception ) {
			return CheckResult::failure( $url, $exception->errorType(), $exception->errorCode(), $exception->getMessage() );
		} catch ( Throwable $exception ) {
			return new CheckResult( 'unknown', $url, null, null, null, 'scanner', 'internal_scanner_error', $exception->getMessage(), array(), false, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/** @param list<ValidationIssue> $issues */
	private function statusFromIssues( array $issues ): string {
		foreach ( $issues as $issue ) {
			if ( 'critical' === $issue->severity ) {
				return 'critical';
			}
		}

		return array() === $issues ? 'healthy' : 'warning';
	}

	private function httpFailure( string $requested, string $final, int $status, int $time, string $code, string $message, bool $redirected ): CheckResult {
		return new CheckResult( 'critical', $requested, $final, $status, $time, 'http', $code, $message, array(), $redirected, gmdate( 'Y-m-d H:i:s' ) );
	}

	private function absoluteUrl( string $location, string $base ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		if ( class_exists( '\\WP_Http' ) ) {
			return \WP_Http::make_absolute_url( $location, $base );
		}

		$parts = parse_url( $base );
		$root  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );

		return $root . '/' . ltrim( $location, '/' );
	}
}
