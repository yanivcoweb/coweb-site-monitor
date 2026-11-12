<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use InvalidArgumentException;

final class UrlNormalizer {
	public function normalize( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new InvalidArgumentException( 'Enter a complete HTTP or HTTPS URL.' );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? '/' . ltrim( preg_replace( '#/+#', '/', (string) $parts['path'] ), '/' ) : '/';
		$query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return esc_url_raw( $scheme . '://' . $host . $port . $path . $query );
	}
}
