<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications\Contracts;

use SiteMonitor\Notifications\Value\DeliveryResult;
use SiteMonitor\Notifications\Value\Notification;

interface NotificationRepositoryInterface {
	public function openIncident( Notification $notification ): void;

	public function resolveIncident( int $page_id, string $resolved_at ): void;

	public function recordAttempt( int $result_id, Notification $notification, string $provider, DeliveryResult $delivery ): void;

	public function markAttempt( int $page_id, string $attempted_at, bool $accepted ): void;
}
