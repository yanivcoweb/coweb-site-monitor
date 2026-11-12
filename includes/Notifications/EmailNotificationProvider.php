<?php
declare(strict_types=1);

namespace SiteMonitor\Notifications;

use SiteMonitor\Notifications\Contracts\NotificationProviderInterface;
use SiteMonitor\Notifications\Value\DeliveryResult;
use SiteMonitor\Notifications\Value\Notification;
use Throwable;

final class EmailNotificationProvider implements NotificationProviderInterface {
	public function id(): string {
		return 'email';
	}

	public function send( Notification $notification ): DeliveryResult {
		if ( ! is_email( $notification->recipient ) ) {
			return DeliveryResult::failed( 'The configured notification email is invalid.' );
		}

		try {
			$accepted = wp_mail( $notification->recipient, $notification->subject(), $notification->body() );
		} catch ( Throwable $exception ) {
			return DeliveryResult::failed( $exception->getMessage() );
		}

		return $accepted
			? DeliveryResult::accepted( 'wp_mail() accepted the message for processing; delivery is not confirmed.' )
			: DeliveryResult::failed( 'wp_mail() rejected the message.' );
	}
}
