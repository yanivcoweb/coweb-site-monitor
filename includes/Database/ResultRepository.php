<?php
declare(strict_types=1);

namespace SiteMonitor\Database;

use RuntimeException;
use SiteMonitor\Monitoring\Value\CheckResult;

final class ResultRepository {
	public function save( int $scan_id, int $page_id, CheckResult $result ): int {
		global $wpdb;
		$table = Schema::tables()['results'];

		$inserted = $wpdb->insert(
			$table,
			array(
				'scan_id'          => $scan_id,
				'page_id'          => $page_id,
				'status'           => $result->status,
				'http_status'      => $result->httpStatus,
				'response_time_ms' => $result->responseTimeMs,
				'final_url'        => $result->finalUrl,
				'validation_status' => array() === $result->validationIssues ? 'passed' : 'failed',
				'error_type'       => 'none' === $result->errorType ? null : $result->errorType,
				'error_code'       => $result->errorCode,
				'error_message'    => $result->errorMessage,
				'details'          => wp_json_encode( $result->details(), JSON_UNESCAPED_SLASHES ),
				'checked_at'       => $result->checkedAt,
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not save the monitoring result.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function deleteOlderThan( string $cutoff ): int {
		global $wpdb;
		$table = Schema::tables()['results'];

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE checked_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return list<array<string,mixed>> */
	public function recentFailures( int $limit = 10 ): array {
		global $wpdb;
		$tables = Schema::tables();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.url, p.post_id FROM {$tables['results']} r INNER JOIN {$tables['pages']} p ON p.id = r.page_id WHERE r.status = 'critical' ORDER BY r.checked_at DESC, r.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function findByScan( int $scan_id, int $limit = 100 ): array {
		global $wpdb;
		$tables = Schema::tables();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.url FROM {$tables['results']} r INNER JOIN {$tables['pages']} p ON p.id = r.page_id WHERE r.scan_id = %d ORDER BY r.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scan_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array{items:list<array<string,mixed>>,total:int} */
	public function paginateByScan( int $scan_id, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$tables   = Schema::tables();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$items    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.url FROM {$tables['results']} r INNER JOIN {$tables['pages']} p ON p.id = r.page_id WHERE r.scan_id = %d ORDER BY r.id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scan_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['results']} WHERE scan_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scan_id
			)
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => (int) $total,
		);
	}

	/** @return list<array<string,mixed>> */
	public function findByPage( int $page_id, int $limit = 50 ): array {
		global $wpdb;
		$table = Schema::tables()['results'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE page_id = %d ORDER BY checked_at DESC, id DESC LIMIT %d", $page_id, max( 1, min( 100, $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
