<?php
declare(strict_types=1);

namespace SiteMonitor\Database;

use RuntimeException;
use SiteMonitor\Monitoring\Value\CheckResult;

final class PageRepository {
	/** @param array{url:string,post_id:?int,source:string,enabled?:int} $page */
	public function upsert( array $page ): int {
		global $wpdb;
		$table = Schema::tables()['pages'];
		$now   = gmdate( 'Y-m-d H:i:s' );
		$url   = esc_url_raw( $page['url'] );
		$hash  = hash( 'sha256', $url );
		$id    = null === $page['post_id']
			? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s LIMIT 1", $hash ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d OR url_hash = %s LIMIT 1", $page['post_id'], $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $id ) {
			$update = array( 'url' => $url, 'url_hash' => $hash, 'post_id' => $page['post_id'], 'source' => $page['source'], 'updated_at' => $now );
			$formats = array( '%s', '%s', '%d', '%s', '%s' );
			if ( array_key_exists( 'enabled', $page ) ) {
				$update['enabled'] = (int) $page['enabled'];
				$formats[]         = '%d';
			}
			$result = $wpdb->update(
				$table,
				$update,
				array( 'id' => (int) $id ),
				$formats,
				array( '%d' )
			);
		} else {
			$result = $wpdb->insert(
				$table,
				array( 'url' => $url, 'url_hash' => $hash, 'post_id' => $page['post_id'], 'source' => $page['source'], 'enabled' => (int) ( $page['enabled'] ?? 0 ), 'created_at' => $now, 'updated_at' => $now ),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
			);
			$id = $wpdb->insert_id;
		}

		if ( false === $result ) {
			throw new RuntimeException( 'Could not save the monitored page.' );
		}

		return (int) $id;
	}

	/** @return array{items:list<array<string,mixed>>,total:int} */
	public function paginate( int $page = 1, int $per_page = 20, string $search = '' ): array {
		global $wpdb;
		$table    = Schema::tables()['pages'];
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = '';
		$params   = array();

		if ( '' !== $search ) {
			$where    = ' WHERE url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table}{$where}";
		$list_sql  = "SELECT * FROM {$table}{$where} ORDER BY enabled DESC, updated_at DESC LIMIT %d OFFSET %d";
		$count     = $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$list_args = array_merge( $params, array( $per_page, $offset ) );
		$items     = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => (int) $count );
	}

	/** @param list<int> $ids */
	public function setEnabled( array $ids, bool $enabled ): int {
		return $this->bulkUpdate( $ids, 'enabled', $enabled ? 1 : 0 );
	}

	/** @param list<int> $ids */
	public function remove( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return 0;
		}
		$table        = Schema::tables()['pages'];
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param list<int> $ids */
	private function bulkUpdate( array $ids, string $column, int $value ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids || 'enabled' !== $column ) {
			return 0;
		}
		$table        = Schema::tables()['pages'];
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $value, gmdate( 'Y-m-d H:i:s' ) ), $ids );

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = %d, updated_at = %s WHERE id IN ({$placeholders})", ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<string, mixed>|null */
	public function find( int $page_id ): ?array {
		global $wpdb;
		$table = Schema::tables()['pages'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $page_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	public function countEnabled( bool $incident_only = false ): int {
		global $wpdb;
		$table = Schema::tables()['pages'];

		$where = $incident_only ? " AND (last_status = 'critical' OR notification_state = 'open')" : '';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1{$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array{total:int,enabled:int,healthy:int,warning:int,critical:int,unknown:int,open_incidents:int} */
	public function summary(): array {
		global $wpdb;
		$table = Schema::tables()['pages'];
		$row   = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(enabled = 1) AS enabled,
				SUM(enabled = 1 AND last_status = 'healthy') AS healthy,
				SUM(enabled = 1 AND last_status = 'warning') AS warning,
				SUM(enabled = 1 AND last_status = 'critical') AS critical,
				SUM(enabled = 1 AND last_status = 'unknown') AS unknown,
				SUM(notification_state = 'open') AS open_incidents
			FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$row   = is_array( $row ) ? $row : array();

		return array(
			'total'          => (int) ( $row['total'] ?? 0 ),
			'enabled'        => (int) ( $row['enabled'] ?? 0 ),
			'healthy'        => (int) ( $row['healthy'] ?? 0 ),
			'warning'        => (int) ( $row['warning'] ?? 0 ),
			'critical'       => (int) ( $row['critical'] ?? 0 ),
			'unknown'        => (int) ( $row['unknown'] ?? 0 ),
			'open_incidents' => (int) ( $row['open_incidents'] ?? 0 ),
		);
	}

	/** @return list<array<string,mixed>> */
	public function findEnabledAfter( int $cursor_page_id, int $limit, bool $incident_only = false ): array {
		global $wpdb;
		$table = Schema::tables()['pages'];
		$where = $incident_only ? " AND (last_status = 'critical' OR notification_state = 'open')" : '';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} FORCE INDEX (enabled_id) WHERE enabled = 1{$where} AND id > %d ORDER BY id ASC LIMIT %d", max( 0, $cursor_page_id ), max( 1, min( 50, $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function updateAfterCheck( int $page_id, CheckResult $result ): void {
		global $wpdb;
		$table    = Schema::tables()['pages'];
		$failures = 'critical' === $result->status ? 1 : 0;
		$sql      = "UPDATE {$table}
			SET last_status = %s,
				last_http_status = %d,
				last_response_time_ms = %d,
				consecutive_failures = IF(%s = 'critical', consecutive_failures + 1, 0),
				last_checked_at = %s,
				updated_at = %s
			WHERE id = %d";
		$updated  = $wpdb->query(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result->status,
				$result->httpStatus ?? 0,
				$result->responseTimeMs ?? 0,
				1 === $failures ? 'critical' : $result->status,
				$result->checkedAt,
				$result->checkedAt,
				$page_id
			)
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Could not update the monitored page.' );
		}
	}

	public function cleanupOrphanedPostLinks(): int {
		global $wpdb;
		$table = Schema::tables()['pages'];

		return (int) $wpdb->query( "UPDATE {$table} p LEFT JOIN {$wpdb->posts} posts ON posts.ID = p.post_id SET p.enabled = 0 WHERE p.post_id IS NOT NULL AND posts.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
