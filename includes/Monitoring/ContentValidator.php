<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

use SiteMonitor\Monitoring\Contracts\ValidationRuleInterface;
use SiteMonitor\Monitoring\Rules\EmptyContentRule;
use SiteMonitor\Monitoring\Rules\ForbiddenTextRule;
use SiteMonitor\Monitoring\Rules\MinimumLengthRule;
use SiteMonitor\Monitoring\Rules\RequiredTextRule;
use SiteMonitor\Monitoring\Rules\WordPressErrorRule;
use SiteMonitor\Monitoring\Value\ValidationIssue;

final class ContentValidator {
	/** @var list<ValidationRuleInterface> */
	private array $rules;

	/** @param list<ValidationRuleInterface>|null $rules */
	public function __construct( ?array $rules = null ) {
		$rules ??= array( new EmptyContentRule(), new MinimumLengthRule(), new RequiredTextRule(), new ForbiddenTextRule(), new WordPressErrorRule() );

		if ( function_exists( 'apply_filters' ) ) {
			$rules = apply_filters( 'site_monitor_validation_rules', $rules );
		}

		$this->rules = array_values( array_filter( $rules, static fn ( mixed $rule ): bool => $rule instanceof ValidationRuleInterface ) );
	}

	/**
	 * @param array<string, mixed> $configuration Validation settings.
	 * @return list<ValidationIssue>
	 */
	public function validate( string $body, array $configuration = array() ): array {
		$issues = array();

		foreach ( $this->rules as $rule ) {
			$issue = $rule->validate( $body, $configuration );
			if ( null !== $issue ) {
				$issues[] = $issue;
			}
		}

		return $issues;
	}
}
