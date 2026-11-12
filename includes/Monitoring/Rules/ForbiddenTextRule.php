<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Rules;

use SiteMonitor\Monitoring\Contracts\ValidationRuleInterface;
use SiteMonitor\Monitoring\Value\ValidationIssue;

final class ForbiddenTextRule implements ValidationRuleInterface {
	public function validate( string $body, array $configuration ): ?ValidationIssue {
		$forbidden = array_values( array_filter( (array) ( $configuration['forbidden_text'] ?? array() ), 'is_string' ) );

		foreach ( $forbidden as $text ) {
			if ( '' !== $text && false !== stripos( $body, $text ) ) {
				return new ValidationIssue( 'forbidden_text_found', 'Configured forbidden text was found.' );
			}
		}

		return null;
	}
}
