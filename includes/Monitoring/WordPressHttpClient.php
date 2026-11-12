<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use SiteMonitor\Monitoring\Contracts\HttpClientInterface;
use SiteMonitor\Monitoring\Exception\HttpRequestException;
use SiteMonitor\Monitoring\Value\HttpResponse;

final class WordPressHttpClient implements HttpClientInterface {
	public function get( string $url, int $timeout, int $maximum_bytes ): HttpResponse {
		$started  = hrtime( true );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => max( 1, min( 60, $timeout ) ),
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'sslverify'           => true,
				'limit_response_size' => max( 1024, min( 5 * MB_IN_BYTES, $maximum_bytes ) ),
				'user-agent'          => 'Site Monitor/' . SITE_MONITOR_VERSION . '; ' . home_url( '/' ),
			)
		);
		$elapsed  = (int) round( ( hrtime( true ) - $started ) / 1_000_000 );

		if ( is_wp_error( $response ) ) {
			$code = (string) $response->get_error_code();
			throw new HttpRequestException( $this->classifyError( $code, $response->get_error_message() ), $code, $response->get_error_message() );
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			$headers,
			$elapsed
		);
	}

	private function classifyError( string $code, string $message ): string {
		$haystack = strtolower( $code . ' ' . $message );

		if ( str_contains( $haystack, 'timed out' ) || str_contains( $haystack, 'timeout' ) ) {
			return 'timeout';
		}
		if ( str_contains( $haystack, 'ssl' ) || str_contains( $haystack, 'certificate' ) ) {
			return 'ssl';
		}
		if ( str_contains( $haystack, 'resolve host' ) || str_contains( $haystack, 'dns' ) ) {
			return 'dns';
		}

		return 'network';
	}
}
