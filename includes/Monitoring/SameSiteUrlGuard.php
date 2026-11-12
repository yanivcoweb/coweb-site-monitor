<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use SiteMonitor\Monitoring\Contracts\UrlGuardInterface;
use SiteMonitor\Monitoring\Exception\UnsafeUrlException;

final class SameSiteUrlGuard implements UrlGuardInterface {
	public function assertAllowed( string $url ): void {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			throw new UnsafeUrlException( 'Only absolute HTTP(S) URLs are allowed.' );
		}

		$site_host = strtolower( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '.' ) );
		$site_port = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
		$host      = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		$scheme    = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			throw new UnsafeUrlException( 'Only absolute HTTP(S) URLs are allowed.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new UnsafeUrlException( 'URLs containing credentials are not allowed.' );
		}
		if ( $host !== $site_host ) {
			throw new UnsafeUrlException( 'The URL must belong to this website.' );
		}
		if ( null !== $port && ! in_array( $port, array( 80, 443, (int) $site_port ), true ) ) {
			throw new UnsafeUrlException( 'The URL uses a blocked port.' );
		}

		$addresses = $this->resolveAddresses( $host );
		if ( array() === $addresses ) {
			throw new UnsafeUrlException( 'The URL host could not be resolved.' );
		}

		$local_development_host = str_ends_with( $site_host, '.local' ) && $host === $site_host;
		foreach ( $addresses as $address ) {
			if ( $local_development_host ) {
				continue;
			}
			if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				throw new UnsafeUrlException( 'The URL resolves to a non-public network address.' );
			}
		}
	}

	/** @return list<string> */
	private function resolveAddresses( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$addresses = gethostbynamel( $host ) ?: array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		return array_values( array_unique( $addresses ) );
	}
}
