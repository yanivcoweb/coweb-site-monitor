<?php
declare(strict_types=1);

namespace SiteMonitor\Admin;

use SiteMonitor\Core\Plugin;
use SiteMonitor\Database\PageRepository;
use SiteMonitor\Database\NotificationRepository;
use SiteMonitor\Database\ResultRepository;
use SiteMonitor\Monitoring\PageDiscovery;
use SiteMonitor\Monitoring\SameSiteUrlGuard;
use SiteMonitor\Monitoring\UrlNormalizer;
use Throwable;

final class Pages {
	private PageRepository $repository;

	public function __construct() {
		$this->repository = new PageRepository();
	}

	public function registerMenu(): void {
		add_submenu_page(
			'site-monitor',
			__( 'Monitored Pages', 'site-monitor' ),
			__( 'Monitored Pages', 'site-monitor' ),
			'manage_options',
			'site-monitor-pages',
			array( $this, 'render' )
		);
	}

	public function handleDiscover(): void {
		Access::authorize( 'site_monitor_discover' );
		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array( 'page' );
		$page       = isset( $_POST['discovery_page'] ) ? max( 1, absint( $_POST['discovery_page'] ) ) : 1;
		$result     = ( new PageDiscovery() )->discover( $post_types, $page );

		foreach ( $result['items'] as $item ) {
			$this->repository->upsert( $item );
		}

		$settings               = get_option( 'site_monitor_settings', array() );
		$settings               = is_array( $settings ) ? $settings : array();
		$settings['post_types'] = $post_types;
		update_option( 'site_monitor_settings', $settings, false );

		$this->redirect( 'discovered', count( $result['items'] ), $result['page'] < $result['pages'] ? $result['page'] + 1 : 0 );
	}

	public function handleAddUrl(): void {
		Access::authorize( 'site_monitor_add_url' );

		try {
			$url = ( new UrlNormalizer() )->normalize( sanitize_text_field( wp_unslash( (string) ( $_POST['url'] ?? '' ) ) ) );
			( new SameSiteUrlGuard() )->assertAllowed( $url );
			$this->repository->upsert( array( 'url' => $url, 'post_id' => null, 'source' => 'manual', 'enabled' => 1 ) );
			$this->redirect( 'added' );
		} catch ( Throwable $exception ) {
			$this->redirect( 'invalid_url' );
		}
	}

	public function handlePageAction(): void {
		Access::requireManageOptions();
		$page_id = absint( $_GET['page_id'] ?? 0 );
		$action  = sanitize_key( wp_unslash( (string) ( $_GET['page_action'] ?? '' ) ) );
		check_admin_referer( 'site_monitor_page_' . $page_id );

		if ( 'enable' === $action ) {
			$this->repository->setEnabled( array( $page_id ), true );
		} elseif ( 'disable' === $action ) {
			$this->repository->setEnabled( array( $page_id ), false );
		} elseif ( 'remove' === $action ) {
			$this->repository->remove( array( $page_id ) );
		} elseif ( 'check' === $action ) {
			try {
				$result = Plugin::instance()->monitor()->checkPage( $page_id );
				$this->redirect( 'checked_' . $result->status );
			} catch ( Throwable $exception ) {
				$this->redirect( 'check_failed' );
			}
		}

		$this->redirect( 'updated' );
	}

	public function handleBulkAction(): void {
		Access::authorize( 'site_monitor_bulk_pages' );
		$ids    = isset( $_POST['page_ids'] ) ? array_values( array_filter( array_unique( array_map( 'absint', (array) wp_unslash( $_POST['page_ids'] ) ) ) ) ) : array();
		$action = sanitize_key( wp_unslash( (string) ( $_POST['bulk_action'] ?? '' ) ) );

		if ( 'check' === $action ) {
			$failed = 0;
			foreach ( $ids as $page_id ) {
				try {
					Plugin::instance()->monitor()->checkPage( $page_id );
				} catch ( Throwable $exception ) {
					++$failed;
				}
			}
			$this->redirect( 'bulk_checked', count( $ids ), 0, $failed );
		} elseif ( 'enable' === $action ) {
			$this->repository->setEnabled( $ids, true );
		} elseif ( 'disable' === $action ) {
			$this->repository->setEnabled( $ids, false );
		} elseif ( 'remove' === $action ) {
			$this->repository->remove( $ids );
		}

		$this->redirect( 'updated', count( $ids ) );
	}

	public function render(): void {
		Access::requireManageOptions();
		$detail_id = absint( $_GET['page_id'] ?? 0 );
		if ( $detail_id > 0 ) {
			$this->renderDetail( $detail_id );
			return;
		}
		$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search       = sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) );
		$data         = $this->repository->paginate( $current_page, 20, $search );
		$settings     = get_option( 'site_monitor_settings', array() );
		$selected     = is_array( $settings ) ? (array) ( $settings['post_types'] ?? array( 'page' ) ) : array( 'page' );
		$post_types   = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
		unset( $post_types['attachment'] );

		$this->renderNotice();
		?>
		<div class="wrap site-monitor-admin">
			<h1><?php esc_html_e( 'Site Monitor — Monitored Pages', 'site-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Discover public WordPress content, then enable only the pages that should be monitored.', 'site-monitor' ); ?></p>

			<div class="card" style="max-width:none">
				<h2><?php esc_html_e( 'Discover WordPress content', 'site-monitor' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="site_monitor_discover">
					<input type="hidden" name="discovery_page" value="<?php echo esc_attr( max( 1, absint( $_GET['next_discovery_page'] ?? 1 ) ) ); ?>">
					<?php wp_nonce_field( 'site_monitor_discover' ); ?>
					<?php foreach ( $post_types as $post_type ) : ?>
						<label style="margin-inline-end:16px"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $selected, true ) ); ?>> <?php echo esc_html( $post_type->labels->name ); ?></label>
					<?php endforeach; ?>
					<?php submit_button( __( 'Discover next batch', 'site-monitor' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:none">
				<h2><?php esc_html_e( 'Add custom URL', 'site-monitor' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="site_monitor_add_url">
					<?php wp_nonce_field( 'site_monitor_add_url' ); ?>
					<label for="site-monitor-url" class="screen-reader-text"><?php esc_html_e( 'Custom URL', 'site-monitor' ); ?></label>
					<input id="site-monitor-url" type="url" class="regular-text" name="url" required placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
					<?php submit_button( __( 'Add URL', 'site-monitor' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="site-monitor-pages">
				<p class="search-box"><label class="screen-reader-text" for="site-monitor-search"><?php esc_html_e( 'Search URLs', 'site-monitor' ); ?></label><input id="site-monitor-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>"><button class="button"><?php esc_html_e( 'Search URLs', 'site-monitor' ); ?></button></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="site_monitor_bulk_pages">
				<?php wp_nonce_field( 'site_monitor_bulk_pages' ); ?>
				<div class="tablenav top"><div class="alignleft actions"><select name="bulk_action"><option value=""><?php esc_html_e( 'Bulk actions', 'site-monitor' ); ?></option><option value="check"><?php esc_html_e( 'Check', 'site-monitor' ); ?></option><option value="enable"><?php esc_html_e( 'Enable', 'site-monitor' ); ?></option><option value="disable"><?php esc_html_e( 'Disable', 'site-monitor' ); ?></option><option value="remove"><?php esc_html_e( 'Remove', 'site-monitor' ); ?></option></select><button class="button action"><?php esc_html_e( 'Apply', 'site-monitor' ); ?></button></div></div>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><td class="check-column"><input type="checkbox" id="cb-select-all-1"></td><th><?php esc_html_e( 'Page', 'site-monitor' ); ?></th><th><?php esc_html_e( 'URL', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Monitoring', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Last checked', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Response time', 'site-monitor' ); ?></th></tr></thead>
					<tbody>
					<?php if ( array() === $data['items'] ) : ?><tr><td colspan="7"><?php esc_html_e( 'No pages found. Run discovery or add a custom URL.', 'site-monitor' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $data['items'] as $item ) : ?>
						<tr><th class="check-column"><input type="checkbox" name="page_ids[]" value="<?php echo esc_attr( $item['id'] ); ?>"></th><td><strong><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'site-monitor-pages', 'page_id' => (int) $item['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $item['post_id'] ? get_the_title( (int) $item['post_id'] ) : __( 'Custom URL', 'site-monitor' ) ); ?></a></strong><div class="row-actions"><?php $this->renderActions( $item ); ?></div></td><td><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a></td><td><strong><?php echo esc_html( $item['enabled'] ? __( 'Enabled', 'site-monitor' ) : __( 'Disabled', 'site-monitor' ) ); ?></strong></td><td><span class="site-monitor-status site-monitor-status--<?php echo esc_attr( (string) $item['last_status'] ); ?>"><?php echo esc_html( ucfirst( (string) $item['last_status'] ) ); ?></span></td><td><?php echo esc_html( $item['last_checked_at'] ?: '—' ); ?></td><td><?php echo null === $item['last_response_time_ms'] ? '—' : esc_html( (string) $item['last_response_time_ms'] . ' ms' ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>
			<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $current_page, 'total' => max( 1, (int) ceil( $data['total'] / 20 ) ) ) ) ); ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $item */
	private function renderActions( array $item ): void {
		$id      = (int) $item['id'];
		$actions = array( $item['enabled'] ? 'disable' : 'enable', 'check', 'remove' );
		foreach ( $actions as $index => $action ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'site_monitor_page_action', 'page_action' => $action, 'page_id' => $id ), admin_url( 'admin-post.php' ) ), 'site_monitor_page_' . $id );
			echo $index ? ' | ' : '';
			echo '<a href="' . esc_url( $url ) . '">' . esc_html( ucfirst( $action ) ) . '</a>';
		}
	}

	private function redirect( string $notice, int $count = 0, int $next_page = 0, int $failed = 0 ): never {
		$url = add_query_arg( array_filter( array( 'page' => 'site-monitor-pages', 'sm_notice' => $notice, 'count' => $count, 'next_discovery_page' => $next_page, 'failed' => $failed ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function renderNotice(): void {
		$notice = sanitize_key( wp_unslash( (string) ( $_GET['sm_notice'] ?? '' ) ) );
		$messages = array(
			'discovered'  => sprintf( __( 'Discovery saved %d page(s).', 'site-monitor' ), absint( $_GET['count'] ?? 0 ) ),
			'added'       => __( 'The custom URL was added and enabled.', 'site-monitor' ),
			'updated'     => __( 'The monitored page list was updated.', 'site-monitor' ),
			'bulk_checked' => sprintf(
				__( 'Checked %1$d selected page(s). %2$d check(s) could not be completed.', 'site-monitor' ),
				absint( $_GET['count'] ?? 0 ),
				absint( $_GET['failed'] ?? 0 )
			),
			'invalid_url' => __( 'The URL was rejected. Only safe URLs on this website are allowed.', 'site-monitor' ),
			'checked_healthy'  => __( 'The page check completed successfully: healthy.', 'site-monitor' ),
			'checked_warning'  => __( 'The page check completed with a warning.', 'site-monitor' ),
			'checked_critical' => __( 'The page check detected a critical failure.', 'site-monitor' ),
			'checked_unknown'  => __( 'The page check could not determine website health.', 'site-monitor' ),
			'check_failed'     => __( 'The page check could not be saved. Review the server log.', 'site-monitor' ),
			'scan_started'     => __( 'The scan was queued for background processing.', 'site-monitor' ),
			'scan_locked'      => __( 'A scan is already running. Wait for it to finish or for its lock to expire.', 'site-monitor' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			$is_error   = in_array( $notice, array( 'invalid_url', 'checked_critical', 'check_failed', 'scan_locked' ), true );
			$has_failed = 'bulk_checked' === $notice && absint( $_GET['failed'] ?? 0 ) > 0;
			echo '<div class="notice ' . ( $is_error ? 'notice-error' : ( $has_failed ? 'notice-warning' : 'notice-success' ) ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	private function renderDetail( int $page_id ): void {
		$page = $this->repository->find( $page_id );
		if ( null === $page ) {
			wp_die( esc_html__( 'The monitored page does not exist.', 'site-monitor' ), esc_html__( 'Page not found', 'site-monitor' ), array( 'response' => 404 ) );
		}
		$results  = ( new ResultRepository() )->findByPage( $page_id );
		$attempts = ( new NotificationRepository() )->findByPage( $page_id );
		?>
		<div class="wrap site-monitor-admin">
			<h1><?php esc_html_e( 'Monitored Page Details', 'site-monitor' ); ?></h1>
			<p><a href="<?php echo esc_url( add_query_arg( 'page', 'site-monitor-pages', admin_url( 'admin.php' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to monitored pages', 'site-monitor' ); ?></a></p>
			<div class="card site-monitor-card"><h2><?php echo esc_html( (string) $page['url'] ); ?></h2><p><strong><?php esc_html_e( 'Current status:', 'site-monitor' ); ?></strong> <?php echo esc_html( ucfirst( (string) $page['last_status'] ) ); ?></p><p><strong><?php esc_html_e( 'Consecutive failures:', 'site-monitor' ); ?></strong> <?php echo esc_html( (string) $page['consecutive_failures'] ); ?></p><p><strong><?php esc_html_e( 'Incident:', 'site-monitor' ); ?></strong> <?php echo esc_html( 'open' === $page['notification_state'] ? __( 'Open', 'site-monitor' ) : __( 'None', 'site-monitor' ) ); ?></p></div>
			<h2><?php esc_html_e( 'Recent checks', 'site-monitor' ); ?></h2>
			<table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Time', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'site-monitor' ); ?></th><th><?php esc_html_e( 'HTTP', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Response', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Error', 'site-monitor' ); ?></th></tr></thead><tbody>
			<?php if ( array() === $results ) : ?><tr><td colspan="5"><?php esc_html_e( 'No checks recorded.', 'site-monitor' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $results as $result ) : ?><tr><td><?php echo esc_html( (string) $result['checked_at'] ); ?></td><td><?php echo esc_html( ucfirst( (string) $result['status'] ) ); ?></td><td><?php echo null === $result['http_status'] ? '—' : esc_html( (string) $result['http_status'] ); ?></td><td><?php echo null === $result['response_time_ms'] ? '—' : esc_html( (string) $result['response_time_ms'] . ' ms' ); ?></td><td><?php echo esc_html( (string) ( $result['error_message'] ?: '—' ) ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
			<h2><?php esc_html_e( 'Notification attempts', 'site-monitor' ); ?></h2>
			<table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Time', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Type', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Provider', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'site-monitor' ); ?></th><th><?php esc_html_e( 'Provider response', 'site-monitor' ); ?></th></tr></thead><tbody>
			<?php if ( array() === $attempts ) : ?><tr><td colspan="5"><?php esc_html_e( 'No notification attempts recorded.', 'site-monitor' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $attempts as $attempt ) : ?><tr><td><?php echo esc_html( (string) $attempt['attempted_at'] ); ?></td><td><?php echo esc_html( ucfirst( (string) $attempt['notification_type'] ) ); ?></td><td><?php echo esc_html( (string) $attempt['provider'] ); ?></td><td><?php echo esc_html( ucfirst( (string) $attempt['attempt_status'] ) ); ?></td><td><?php echo esc_html( (string) ( $attempt['provider_message'] ?: '—' ) ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}
}
