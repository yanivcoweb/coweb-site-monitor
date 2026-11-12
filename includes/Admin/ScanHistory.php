<?php
declare(strict_types=1);

namespace SiteMonitor\Admin;

use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Database\ScanRepository;

final class ScanHistory {
	public function registerMenu(): void {
		add_submenu_page( 'site-monitor', __( 'Scan History', 'site-monitor' ), __( 'Scan History', 'site-monitor' ), 'manage_options', 'site-monitor-history', array( $this, 'render' ) );
	}

	public function render(): void {
		Access::requireManageOptions();
		$scan_id = absint( $_GET['scan_id'] ?? 0 );
		if ( $scan_id > 0 ) {
			$this->renderDetail( $scan_id );
			return;
		}

		$current = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$data    = ( new ScanRepository() )->paginate( $current, 20 );
		?>
		<div class="wrap site-monitor-admin">
			<h1><?php esc_html_e( 'Scan History', 'site-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Review scheduled, manual, and single-page scan execution.', 'site-monitor' ); ?></p>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Started', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Trigger', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Progress', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Completed', 'site-monitor' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $data['items'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'No scans recorded.', 'site-monitor' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $data['items'] as $scan ) : ?>
					<tr><td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'site-monitor-history', 'scan_id' => (int) $scan['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $scan['started_at'] ); ?></a></td><td><?php echo esc_html( ucfirst( (string) $scan['trigger_type'] ) ); ?></td><td><span class="site-monitor-status site-monitor-status--<?php echo esc_attr( (string) $scan['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $scan['status'] ) ); ?></span></td><td><?php echo esc_html( sprintf( '%1$d / %2$d (%3$d failed)', (int) $scan['checked_pages'], (int) $scan['total_pages'], (int) $scan['failed_pages'] ) ); ?></td><td><?php echo esc_html( (string) ( $scan['completed_at'] ?: '—' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => 'site-monitor-history', 'paged' => '%#%' ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $current, 'total' => max( 1, (int) ceil( $data['total'] / 20 ) ) ) ) ); ?>
		</div>
		<?php
	}

	private function renderDetail( int $scan_id ): void {
		$scan = ( new ScanRepository() )->find( $scan_id );
		if ( null === $scan ) {
			wp_die( esc_html__( 'The scan does not exist.', 'site-monitor' ), esc_html__( 'Scan not found', 'site-monitor' ), array( 'response' => 404 ) );
		}
		$current = max( 1, absint( $_GET['result_page'] ?? 1 ) );
		$data    = ( new ResultRepository() )->paginateByScan( $scan_id, $current, 50 );
		$results = $data['items'];
		?>
		<div class="wrap site-monitor-admin">
			<h1><?php echo esc_html( sprintf( __( 'Scan #%d', 'site-monitor' ), $scan_id ) ); ?></h1>
			<p><a href="<?php echo esc_url( add_query_arg( 'page', 'site-monitor-history', admin_url( 'admin.php' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to scan history', 'site-monitor' ); ?></a></p>
			<div class="card site-monitor-card"><p><strong><?php esc_html_e( 'Status:', 'site-monitor' ); ?></strong> <?php echo esc_html( ucfirst( (string) $scan['status'] ) ); ?></p><p><strong><?php esc_html_e( 'Trigger:', 'site-monitor' ); ?></strong> <?php echo esc_html( ucfirst( (string) $scan['trigger_type'] ) ); ?></p><p><strong><?php esc_html_e( 'Progress:', 'site-monitor' ); ?></strong> <?php echo esc_html( sprintf( '%1$d / %2$d', (int) $scan['checked_pages'], (int) $scan['total_pages'] ) ); ?></p><?php if ( $scan['error_message'] ) : ?><p><strong><?php esc_html_e( 'Scan error:', 'site-monitor' ); ?></strong> <?php echo esc_html( (string) $scan['error_message'] ); ?></p><?php endif; ?></div>
			<h2><?php esc_html_e( 'Page results', 'site-monitor' ); ?></h2>
			<table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Page', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'site-monitor' ); ?></th><th><?php esc_html_e( 'HTTP', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Response', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Error', 'site-monitor' ); ?></th></tr></thead><tbody>
			<?php if ( array() === $results ) : ?><tr><td colspan="5"><?php esc_html_e( 'No page results were persisted for this scan.', 'site-monitor' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $results as $result ) : ?><tr><td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'site-monitor-pages', 'page_id' => (int) $result['page_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $result['url'] ); ?></a></td><td><?php echo esc_html( ucfirst( (string) $result['status'] ) ); ?></td><td><?php echo null === $result['http_status'] ? '—' : esc_html( (string) $result['http_status'] ); ?></td><td><?php echo null === $result['response_time_ms'] ? '—' : esc_html( (string) $result['response_time_ms'] . ' ms' ); ?></td><td><?php echo esc_html( (string) ( $result['error_message'] ?: '—' ) ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
			<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => 'site-monitor-history', 'scan_id' => $scan_id, 'result_page' => '%#%' ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $current, 'total' => max( 1, (int) ceil( $data['total'] / 50 ) ) ) ) ); ?>
		</div>
		<?php
	}
}
