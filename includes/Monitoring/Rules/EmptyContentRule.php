<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring\Rules;

use SiteMonitor\Monitoring\Contracts\ValidationRuleInterface;
use SiteMonitor\Monitoring\Value\ValidationIssue;

final class EmptyContentRule implements ValidationRuleInterface {
	public function validate( string $body, array $configuration ): ?ValidationIssue {
		if ( '' === trim( $body ) ) {
			return new ValidationIssue( 'empty_content', 'The response body is empty.' );
		}

		return null;
	}
}
