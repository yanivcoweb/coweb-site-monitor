<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications\Contracts;

use SiteMonitor\Notifications\Value\DeliveryResult;
use SiteMonitor\Notifications\Value\Notification;

interface NotificationProviderInterface {
	public function id(): string;

	public function send( Notification $notification ): DeliveryResult;
}
