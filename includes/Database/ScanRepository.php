<?php
declare(strict_types=1);

namespace SiteMonitor\Database;

use RuntimeException;
use SiteMonitor\Monitoring\Value\CheckResult;

final class ScanRepository {
	public function create( int $total_pages, string $trigger_type ): int {
		global $wpdb;
		$table = Schema::tables()['scans'];
		$now   = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$table,
			array( 'started_at' => $now, 'status' => 'running', 'total_pages' => max( 0, $total_pages ), 'trigger_type' => sanitize_key( $trigger_type ) ),
			array( '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not create the scan.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function createSinglePageScan( string $trigger_type = 'manual' ): int {
		return $this->create( 1, $trigger_type );
	}

	/** @return array<string,mixed>|null */
	public function find( int $scan_id ): ?array {
		global $wpdb;
		$table = Schema::tables()['scans'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function latest(): ?array {
		global $wpdb;
		$table = Schema::tables()['scans'];
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/** @return array{items:list<array<string,mixed>>,total:int} */
	public function paginate( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$table    = Schema::tables()['scans'];
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$items    = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function updateProgress( int $scan_id, int $cursor, int $checked_delta, int $failed_delta ): void {
		global $wpdb;
		$table = Schema::tables()['scans'];
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET cursor_page_id = %d, checked_pages = checked_pages + %d, failed_pages = failed_pages + %d, status = 'running' WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cursor,
				$checked_delta,
				$failed_delta,
				$scan_id
			)
		);
		if ( false === $result ) {
			throw new RuntimeException( 'Could not update scan progress.' );
		}
	}

	public function finish( int $scan_id, string $status = 'completed', ?string $error_code = null, ?string $error_message = null ): void {
		global $wpdb;
		$table = Schema::tables()['scans'];
		$wpdb->update(
			$table,
			array( 'completed_at' => gmdate( 'Y-m-d H:i:s' ), 'status' => $status, 'error_code' => $error_code, 'error_message' => $error_message ),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function failRunningScans( string $error_code, string $error_message = 'A previous scan stopped before releasing its lock.' ): int {
		global $wpdb;
		$table = Schema::tables()['scans'];

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', completed_at = %s, error_code = %s, error_message = %s WHERE status = 'running'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				$error_code,
				$error_message
			)
		);
	}

	public function complete( int $scan_id, CheckResult $result ): void {
		global $wpdb;
		$table  = Schema::tables()['scans'];
		$failed = in_array( $result->status, array( 'critical', 'unknown' ), true ) ? 1 : 0;
		$updated = $wpdb->update(
			$table,
			array(
				'completed_at'  => $result->checkedAt,
				'status'        => 'completed',
				'checked_pages' => 1,
				'failed_pages'  => $failed,
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Could not complete the scan.' );
		}
	}

	public function deleteOlderThan( string $cutoff ): int {
		global $wpdb;
		$table = Schema::tables()['scans'];

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE completed_at IS NOT NULL AND completed_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
