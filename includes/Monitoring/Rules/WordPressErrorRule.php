<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Rules;

use SiteMonitor\Monitoring\Contracts\ValidationRuleInterface;
use SiteMonitor\Monitoring\Value\ValidationIssue;

final class WordPressErrorRule implements ValidationRuleInterface {
	private const DEFAULT_PATTERNS = array(
		'Error establishing a database connection',
		'There has been a critical error on this website',
		'Fatal error:',
	);

	public function validate( string $body, array $configuration ): ?ValidationIssue {
		if ( false === (bool) ( $configuration['detect_wordpress_errors'] ?? true ) ) {
			return null;
		}

		$custom   = array_values( array_filter( (array) ( $configuration['wordpress_error_patterns'] ?? array() ), 'is_string' ) );
		$patterns = array_unique( array_merge( self::DEFAULT_PATTERNS, $custom ) );

		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $body, $pattern ) ) {
				return new ValidationIssue( 'wordpress_error_detected', 'The response contains a known WordPress error message.' );
			}
		}

		return null;
	}
}
