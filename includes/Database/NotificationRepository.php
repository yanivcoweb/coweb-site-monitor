<?php
declare(strict_types=1);

namespace SiteMonitor\Database;

use RuntimeException;
use SiteMonitor\Notifications\Contracts\NotificationRepositoryInterface;
use SiteMonitor\Notifications\Value\DeliveryResult;
use SiteMonitor\Notifications\Value\Notification;

final class NotificationRepository implements NotificationRepositoryInterface {
	public function openIncident( Notification $notification ): void {
		global $wpdb;
		$updated = $wpdb->update(
			Schema::tables()['pages'],
			array(
				'notification_state'       => 'open',
				'incident_started_at'      => $notification->detectedAt,
				'incident_failure_type'    => $notification->failureType,
				'incident_http_status'     => $notification->httpStatus,
				'incident_error_message'   => $notification->errorDescription,
			),
			array( 'id' => $notification->pageId ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Could not persist the notification incident.' );
		}
	}

	public function resolveIncident( int $page_id, string $resolved_at ): void {
		global $wpdb;
		$updated = $wpdb->update(
			Schema::tables()['pages'],
			array(
				'notification_state'       => 'none',
				'incident_started_at'      => null,
				'incident_failure_type'    => null,
				'incident_http_status'     => null,
				'incident_error_message'   => null,
			),
			array( 'id' => $page_id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Could not resolve the notification incident.' );
		}
	}

	public function recordAttempt( int $result_id, Notification $notification, string $provider, DeliveryResult $delivery ): void {
		global $wpdb;
		$inserted = $wpdb->insert(
			Schema::tables()['notification_attempts'],
			array(
				'page_id'           => $notification->pageId,
				'result_id'         => $result_id,
				'notification_type' => $notification->type,
				'provider'          => sanitize_key( $provider ),
				'recipient'         => $notification->recipient,
				'subject'           => $notification->subject(),
				'attempt_status'    => $delivery->accepted ? 'accepted' : 'failed',
				'provider_message'  => $delivery->message,
				'attempted_at'      => $notification->attemptedAt,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not record the notification attempt.' );
		}
	}

	public function markAttempt( int $page_id, string $attempted_at, bool $accepted ): void {
		global $wpdb;
		$data    = array( 'last_notification_attempt_at' => $attempted_at );
		$formats = array( '%s' );
		if ( $accepted ) {
			$data['last_alerted_at'] = $attempted_at;
			$formats[]               = '%s';
		}
		$updated = $wpdb->update( Schema::tables()['pages'], $data, array( 'id' => $page_id ), $formats, array( '%d' ) );
		if ( false === $updated ) {
			throw new RuntimeException( 'Could not persist notification attempt state.' );
		}
	}

	public function deleteOlderThan( string $cutoff ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::tables()['notification_attempts'] . ' WHERE attempted_at < %s',
				$cutoff
			)
		);
	}

	/** @return list<array<string,mixed>> */
	public function findByPage( int $page_id, int $limit = 50 ): array {
		global $wpdb;
		$table = Schema::tables()['notification_attempts'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE page_id = %d ORDER BY attempted_at DESC, id DESC LIMIT %d", $page_id, max( 1, min( 100, $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
