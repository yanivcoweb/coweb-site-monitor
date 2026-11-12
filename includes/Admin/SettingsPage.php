<?php
declare(strict_types=1);

namespace SiteMonitor\Admin;

use SiteMonitor\Core\Settings;

final class SettingsPage {
	public function registerMenu(): void {
		add_submenu_page( 'site-monitor', __( 'Site Monitor Settings', 'site-monitor' ), __( 'Settings', 'site-monitor' ), 'manage_options', 'site-monitor-settings', array( $this, 'render' ) );
	}

	public function handleSave(): void {
		Access::authorize( 'site_monitor_save_settings' );
		$input    = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();
		$existing = Settings::get();
		$settings = Settings::sanitize( $input, $existing );
		if ( '' === $settings['notification_email'] ) {
			$this->redirect( 'invalid_email' );
		}
		update_option( 'site_monitor_settings', $settings, false );
		( new \SiteMonitor\Core\Scheduler() )->ensureScheduled();
		$this->redirect( 'settings_saved' );
	}

	public function render(): void {
		Access::requireManageOptions();
		$settings = Settings::get();
		$this->renderNotice();
		?>
		<div class="wrap site-monitor-admin">
			<h1><?php esc_html_e( 'Site Monitor Settings', 'site-monitor' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="site_monitor_save_settings">
				<?php wp_nonce_field( 'site_monitor_save_settings' ); ?>
				<h2><?php esc_html_e( 'Scanning', 'site-monitor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="site-monitor-frequency"><?php esc_html_e( 'Frequency', 'site-monitor' ); ?></label></th><td><select id="site-monitor-frequency" name="settings[frequency]"><?php foreach ( array( 'hourly' => __( 'Hourly', 'site-monitor' ), 'site_monitor_6_hours' => __( 'Every 6 hours', 'site-monitor' ), 'site_monitor_12_hours' => __( 'Every 12 hours', 'site-monitor' ), 'daily' => __( 'Daily', 'site-monitor' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['frequency'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th scope="row"><label for="site-monitor-incident-check-minutes"><?php esc_html_e( 'Check frequency during a failure', 'site-monitor' ); ?></label></th><td><select id="site-monitor-incident-check-minutes" name="settings[incident_check_minutes]"><?php foreach ( array( 0, 5, 10, 15, 30, 60 ) as $minutes ) : ?><option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( (int) $settings['incident_check_minutes'], $minutes ); ?>><?php echo esc_html( 0 === $minutes ? __( 'Use regular scan frequency', 'site-monitor' ) : sprintf( __( 'Every %d minutes', 'site-monitor' ), $minutes ) ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Extra checks for enabled pages from their first critical failure until recovery. Email reminders still follow Repeat interval. Checks depend on WP-Cron execution.', 'site-monitor' ); ?></p></td></tr>
					<?php $this->numberRow( 'request_timeout', __( 'Request timeout', 'site-monitor' ), (int) $settings['request_timeout'], 1, 60, __( 'Seconds per page request.', 'site-monitor' ) ); ?>
					<?php $this->numberRow( 'batch_size', __( 'Batch size', 'site-monitor' ), (int) $settings['batch_size'], 1, 50, __( 'Pages processed in one background batch.', 'site-monitor' ) ); ?>
					<?php $this->numberRow( 'retention_days', __( 'History retention', 'site-monitor' ), (int) $settings['retention_days'], 1, 365, __( 'Days to retain scans, results, and notification attempts.', 'site-monitor' ) ); ?>
				</table>
				<h2><?php esc_html_e( 'Notifications', 'site-monitor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="site-monitor-notification-email"><?php esc_html_e( 'Notification email', 'site-monitor' ); ?></label></th><td><input id="site-monitor-notification-email" class="regular-text" type="email" required name="settings[notification_email]" value="<?php echo esc_attr( (string) $settings['notification_email'] ); ?>"><p class="description"><?php esc_html_e( 'Failure, repeat, and recovery alerts and a summary of every completed manual scan are sent here.', 'site-monitor' ); ?></p></td></tr>
					<?php $this->numberRow( 'alert_failure_threshold', __( 'Failure threshold', 'site-monitor' ), (int) $settings['alert_failure_threshold'], 1, 100, __( 'Consecutive critical results required to open an incident.', 'site-monitor' ) ); ?>
					<?php $this->numberRow( 'alert_repeat_hours', __( 'Repeat interval', 'site-monitor' ), (int) $settings['alert_repeat_hours'], 0, 8760, __( 'Hours between unresolved-incident reminders. Use 0 to disable repeats.', 'site-monitor' ) ); ?>
				</table>
				<?php submit_button( __( 'Save settings', 'site-monitor' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function numberRow( string $key, string $label, int $value, int $min, int $max, string $description ): void {
		$id = 'site-monitor-' . str_replace( '_', '-', $key );
		?><tr><th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input id="<?php echo esc_attr( $id ); ?>" class="small-text" type="number" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" required><p class="description"><?php echo esc_html( $description ); ?></p></td></tr><?php
	}

	private function renderNotice(): void {
		$notice = sanitize_key( wp_unslash( (string) ( $_GET['sm_notice'] ?? '' ) ) );
		if ( 'settings_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'site-monitor' ) . '</p></div>';
		} elseif ( 'invalid_email' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Enter a valid notification email address.', 'site-monitor' ) . '</p></div>';
		}
	}

	private function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( array( 'page' => 'site-monitor-settings', 'sm_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
