<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications;

use SiteMonitor\Database\NotificationRepository;
use SiteMonitor\Monitoring\Value\CheckResult;
use SiteMonitor\Notifications\Contracts\NotificationProviderInterface;
use SiteMonitor\Notifications\Contracts\NotificationRepositoryInterface;
use SiteMonitor\Notifications\Value\DeliveryResult;
use SiteMonitor\Notifications\Value\Notification;
use Throwable;

final class NotificationManager {
	/** @param list<NotificationProviderInterface> $providers */
	public function __construct(
		private readonly NotificationRepositoryInterface $repository,
		private readonly array $providers,
		private readonly string $website_name,
		private readonly string $dashboard_url
	) {}

	public static function createDefault(): self {
		$providers = apply_filters( 'site_monitor_notification_providers', array( new EmailNotificationProvider() ) );
		$providers = is_array( $providers ) ? array_values( array_filter( $providers, static fn ( mixed $provider ): bool => $provider instanceof NotificationProviderInterface ) ) : array();

		return new self(
			new NotificationRepository(),
			$providers,
			(string) get_bloginfo( 'name' ),
			admin_url( 'admin.php?page=site-monitor' )
		);
	}

	/** @param array<string,mixed> $page
	 *  @param array<string,mixed>|null $settings
	 */
	public function handle( array $page, int $result_id, CheckResult $result, ?array $settings = null ): void {
		$settings ??= get_option( 'site_monitor_settings', array() );
		$settings     = is_array( $settings ) ? $settings : array();
		$threshold    = max( 1, min( 100, (int) ( $settings['alert_failure_threshold'] ?? 2 ) ) );
		$repeat_hours = max( 0, min( 8760, (int) ( $settings['alert_repeat_hours'] ?? 24 ) ) );
		$recipient    = sanitize_email( (string) ( $settings['notification_email'] ?? '' ) );
		$is_open      = 'open' === (string) ( $page['notification_state'] ?? 'none' );
		$is_failure   = 'critical' === $result->status;
		$failures     = $is_failure ? (int) ( $page['consecutive_failures'] ?? 0 ) + 1 : 0;
		$type         = null;

		if ( ! $is_open && $is_failure && $failures >= $threshold ) {
			$type = 'failure';
		} elseif ( $is_open && 'healthy' === $result->status ) {
			$type = 'recovery';
		} elseif ( $is_open && $is_failure && $this->repeatIsDue( (string) ( $page['last_notification_attempt_at'] ?? '' ), $result->checkedAt, $repeat_hours ) ) {
			$type = 'repeat';
		}

		if ( null === $type ) {
			return;
		}

		$notification = $this->makeNotification( $type, $page, $result, $recipient );
		if ( 'failure' === $type ) {
			$this->repository->openIncident( $notification );
		}

		$accepted = $this->dispatch( $result_id, $notification );
		$this->repository->markAttempt( $notification->pageId, $notification->attemptedAt, $accepted );

		if ( 'recovery' === $type ) {
			$this->repository->resolveIncident( $notification->pageId, $result->checkedAt );
		}
	}

	private function repeatIsDue( string $last_attempted_at, string $checked_at, int $repeat_hours ): bool {
		if ( 0 === $repeat_hours || '' === $last_attempted_at ) {
			return false;
		}
		$last = strtotime( $last_attempted_at . ' UTC' );
		$now  = strtotime( $checked_at . ' UTC' );

		return false !== $last && false !== $now && $now >= $last + ( $repeat_hours * HOUR_IN_SECONDS );
	}

	/** @param array<string,mixed> $page */
	private function makeNotification( string $type, array $page, CheckResult $result, string $recipient ): Notification {
		$is_recovery = 'recovery' === $type;

		return new Notification(
			$type,
			(int) $page['id'],
			$recipient,
			$this->website_name,
			(string) $page['url'],
			$is_recovery ? (string) ( $page['incident_failure_type'] ?? 'unknown' ) : $this->failureType( $result ),
			$is_recovery ? $this->nullableInt( $page['incident_http_status'] ?? null ) : $result->httpStatus,
			$is_recovery ? (string) ( $page['incident_error_message'] ?? 'No error description was recorded.' ) : ( $result->errorMessage ?? 'No error description was provided.' ),
			$is_recovery ? (string) ( $page['incident_started_at'] ?? $result->checkedAt ) : $result->checkedAt,
			$this->dashboard_url,
			$result->checkedAt
		);
	}

	private function failureType( CheckResult $result ): string {
		return $result->errorType . ( $result->errorCode ? '/' . $result->errorCode : '' );
	}

	private function nullableInt( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private function dispatch( int $result_id, Notification $notification ): bool {
		$accepted = false;
		foreach ( $this->providers as $provider ) {
			try {
				$delivery = $provider->send( $notification );
			} catch ( Throwable $exception ) {
				$delivery = DeliveryResult::failed( $exception->getMessage() );
			}
			$this->repository->recordAttempt( $result_id, $notification, $provider->id(), $delivery );
			$accepted = $accepted || $delivery->accepted;
		}

		return $accepted;
	}
}
