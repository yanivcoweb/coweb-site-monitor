<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Rules;

use SiteMonitor\Monitoring\Contracts\ValidationRuleInterface;
use SiteMonitor\Monitoring\Value\ValidationIssue;

final class MinimumLengthRule implements ValidationRuleInterface {
	public function validate( string $body, array $configuration ): ?ValidationIssue {
		$minimum = max( 0, (int) ( $configuration['minimum_length'] ?? 0 ) );

		if ( $minimum > 0 && strlen( $body ) < $minimum ) {
			return new ValidationIssue( 'content_too_short', 'The response body is shorter than the configured minimum.', 'warning' );
		}

		return null;
	}
}
