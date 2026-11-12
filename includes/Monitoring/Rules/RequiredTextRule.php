<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Rules;

use SiteMonitor\Monitoring\Contracts\ValidationRuleInterface;
use SiteMonitor\Monitoring\Value\ValidationIssue;

final class RequiredTextRule implements ValidationRuleInterface {
	public function validate( string $body, array $configuration ): ?ValidationIssue {
		$required = array_values( array_filter( (array) ( $configuration['required_text'] ?? array() ), 'is_string' ) );

		foreach ( $required as $text ) {
			if ( '' !== $text && false === stripos( $body, $text ) ) {
				return new ValidationIssue( 'required_text_missing', 'Configured required text was not found.', 'warning' );
			}
		}

		return null;
	}
}
