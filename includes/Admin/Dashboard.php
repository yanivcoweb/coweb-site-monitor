<?php
declare(strict_types=1);

namespace SiteMonitor\Admin;

use SiteMonitor\Core\Scheduler;
use SiteMonitor\Core\Settings;
use SiteMonitor\Database\PageRepository;
use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Database\ScanRepository;
use SiteMonitor\Monitoring\ScanManager;
use Throwable;

final class Dashboard {
	public function registerMenu(): void {
		add_menu_page( __( 'Site Monitor', 'site-monitor' ), __( 'Site Monitor', 'site-monitor' ), 'manage_options', 'site-monitor', array( $this, 'render' ), 'dashicons-shield-alt', 80 );
		add_submenu_page( 'site-monitor', __( 'Site Monitor Dashboard', 'site-monitor' ), __( 'Dashboard', 'site-monitor' ), 'manage_options', 'site-monitor', array( $this, 'render' ) );
	}

	public function handleRunScan(): void {
		Access::authorize( 'site_monitor_run_scan' );
		try {
			ScanManager::createDefault()->start( 'manual' );
			$this->redirect( 'scan_started' );
		} catch ( Throwable $exception ) {
			$this->redirect( 'scan_locked' );
		}
	}

	public function render(): void {
		Access::requireManageOptions();
		$summary    = ( new PageRepository() )->summary();
		$latest     = ( new ScanRepository() )->latest();
		$failures   = ( new ResultRepository() )->recentFailures( 10 );
		$scheduler  = $this->schedulerHealth( Settings::get() );
		$is_running = is_array( $latest ) && 'running' === $latest['status'];
		$this->renderNotice();
		?>
		<div class="wrap site-monitor-admin">
			<h1><?php esc_html_e( 'Site Monitor Dashboard', 'site-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Current website health, incidents, and scan execution at a glance.', 'site-monitor' ); ?></p>

			<div class="site-monitor-summary" aria-label="<?php esc_attr_e( 'Monitoring summary', 'site-monitor' ); ?>">
				<?php $this->summaryCard( __( 'Enabled pages', 'site-monitor' ), $summary['enabled'], 'neutral', 'enabled' ); ?>
				<?php $this->summaryCard( __( 'Healthy', 'site-monitor' ), $summary['healthy'], 'healthy', 'healthy' ); ?>
				<?php $this->summaryCard( __( 'Warnings', 'site-monitor' ), $summary['warning'], 'warning', 'warning' ); ?>
				<?php $this->summaryCard( __( 'Critical', 'site-monitor' ), $summary['critical'], 'critical', 'critical' ); ?>
				<?php $this->summaryCard( __( 'Open incidents', 'site-monitor' ), $summary['open_incidents'], 'critical', 'incidents' ); ?>
			</div>

			<div class="site-monitor-grid">
				<section class="card site-monitor-card">
					<h2><?php esc_html_e( 'Scan status', 'site-monitor' ); ?></h2>
					<div id="site-monitor-scan-status" aria-live="polite">
						<?php if ( $latest ) : ?>
							<p><strong><?php esc_html_e( 'Latest scan:', 'site-monitor' ); ?></strong> <span data-scan-status><?php echo esc_html( ucfirst( (string) $latest['status'] ) ); ?></span></p>
							<p data-scan-progress><?php echo esc_html( sprintf( __( '%1$d of %2$d pages checked; %3$d failed.', 'site-monitor' ), (int) $latest['checked_pages'], (int) $latest['total_pages'], (int) $latest['failed_pages'] ) ); ?></p>
							<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'site-monitor-history', 'scan_id' => (int) $latest['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View scan results', 'site-monitor' ); ?></a></p>
						<?php else : ?><p><?php esc_html_e( 'No scans have run yet.', 'site-monitor' ); ?></p><?php endif; ?>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="site_monitor_run_scan">
						<?php wp_nonce_field( 'site_monitor_run_scan' ); ?>
						<?php submit_button( __( 'Run scan now', 'site-monitor' ), 'primary', 'submit', false, $is_running ? array( 'disabled' => 'disabled', 'data-scan-button' => '1' ) : array( 'data-scan-button' => '1' ) ); ?>
					</form>
					<p data-scan-feedback role="status" aria-live="polite"><?php if ( $is_running ) { esc_html_e( 'Scanning in the background. Progress updates after each page; a page may take a while to respond.', 'site-monitor' ); } ?></p>
				</section>

				<section class="card site-monitor-card">
					<h2><?php esc_html_e( 'Scheduler', 'site-monitor' ); ?></h2>
					<p><strong><?php esc_html_e( 'Health:', 'site-monitor' ); ?></strong> <span class="site-monitor-status site-monitor-status--<?php echo esc_attr( $scheduler['healthy'] ? 'healthy' : 'critical' ); ?>"><?php echo esc_html( $scheduler['message'] ); ?></span></p>
					<p><strong><?php esc_html_e( 'Next scan:', 'site-monitor' ); ?></strong> <?php $next = wp_next_scheduled( Scheduler::SCAN_HOOK ); echo $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : esc_html__( 'Not scheduled', 'site-monitor' ); ?></p>
					<p><strong><?php esc_html_e( 'Last batch completion:', 'site-monitor' ); ?></strong> <?php echo esc_html( (string) get_option( 'site_monitor_last_successful_scan', '—' ) ); ?></p>
					<p><strong><?php esc_html_e( 'Last scheduled completion:', 'site-monitor' ); ?></strong> <?php echo esc_html( (string) get_option( 'site_monitor_last_successful_scheduled_execution', '—' ) ); ?></p>
				</section>
			</div>

			<h2><?php esc_html_e( 'Recent critical failures', 'site-monitor' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Page', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Failure', 'site-monitor' ); ?></th><th><?php esc_html_e( 'HTTP', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Detected', 'site-monitor' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $failures ) : ?><tr><td colspan="4"><?php esc_html_e( 'No critical failures are recorded.', 'site-monitor' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $failures as $failure ) : ?>
					<tr><td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'site-monitor-pages', 'page_id' => (int) $failure['page_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $this->pageLabel( $failure ) ); ?></a></td><td><?php echo esc_html( (string) ( $failure['error_message'] ?: $failure['error_code'] ?: __( 'Critical failure', 'site-monitor' ) ) ); ?></td><td><?php echo null === $failure['http_status'] ? '—' : esc_html( (string) $failure['http_status'] ); ?></td><td><?php echo esc_html( (string) $failure['checked_at'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** @param array<string,mixed> $failure */
	private function pageLabel( array $failure ): string {
		$url = (string) $failure['url'];
		if ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			return 'דף הבית';
		}

		$post_id = (int) ( $failure['post_id'] ?? 0 );
		if ( ! $post_id ) {
			$post_id = url_to_postid( $url );
		}
		$title = $post_id ? trim( wp_strip_all_tags( get_the_title( $post_id ) ) ) : '';
		if ( '' !== $title ) {
			return $title;
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page && untrailingslashit( $url ) === untrailingslashit( (string) get_permalink( $posts_page ) ) ) {
			return get_the_title( $posts_page ) ?: 'בלוג';
		}

		$path = trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );
		if ( 'blog' === $path ) {
			return 'בלוג';
		}

		return '' !== $path ? str_replace( '-', ' ', $path ) : 'עמוד באתר';
	}

	private function summaryCard( string $label, int $value, string $tone, string $key ): void {
		echo '<div class="site-monitor-summary__card site-monitor-summary__card--' . esc_attr( $tone ) . '"><span>' . esc_html( $label ) . '</span><strong data-summary="' . esc_attr( $key ) . '">' . esc_html( (string) $value ) . '</strong></div>';
	}

	/** @param array<string,mixed> $settings
	 *  @return array{healthy:bool,message:string}
	 */
	private function schedulerHealth( array $settings ): array {
		$intervals = array( 'hourly' => HOUR_IN_SECONDS, 'site_monitor_6_hours' => 6 * HOUR_IN_SECONDS, 'site_monitor_12_hours' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS );
		$interval  = $intervals[ (string) ( $settings['frequency'] ?? 'daily' ) ] ?? DAY_IN_SECONDS;
		$last      = (string) get_option( 'site_monitor_last_successful_scheduled_execution', '' );
		$reference = $last ?: (string) get_option( 'site_monitor_activated_at', '' );

		if ( ! wp_next_scheduled( Scheduler::SCAN_HOOK ) ) {
			return array( 'healthy' => false, 'message' => __( 'No recurring scan is scheduled.', 'site-monitor' ) );
		}
		if ( $reference && strtotime( $reference . ' UTC' ) < time() - ( 2 * $interval ) ) {
			return array( 'healthy' => false, 'message' => __( 'Scheduled monitoring is overdue.', 'site-monitor' ) );
		}

		return array( 'healthy' => true, 'message' => $last ? __( 'Healthy', 'site-monitor' ) : __( 'Waiting for the first scheduled completion', 'site-monitor' ) );
	}

	private function renderNotice(): void {
		$notice   = sanitize_key( wp_unslash( (string) ( $_GET['sm_notice'] ?? '' ) ) );
		$messages = array( 'scan_started' => __( 'The scan was queued for background processing.', 'site-monitor' ), 'scan_locked' => __( 'A scan is already running. Wait for it to finish or for its lock to expire.', 'site-monitor' ) );
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice ' . ( 'scan_locked' === $notice ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	private function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( array( 'page' => 'site-monitor', 'sm_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
