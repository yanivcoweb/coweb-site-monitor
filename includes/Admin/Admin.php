<?php
declare(strict_types=1);

namespace SiteMonitor\Admin;

use SiteMonitor\Database\ScanRepository;

final class Admin {
	public function register(): void {
		$dashboard = new Dashboard();
		$pages     = new Pages();
		$history   = new ScanHistory();
		$settings  = new SettingsPage();

		add_action( 'admin_menu', array( $dashboard, 'registerMenu' ) );
		add_action( 'admin_menu', array( $pages, 'registerMenu' ) );
		add_action( 'admin_menu', array( $history, 'registerMenu' ) );
		add_action( 'admin_menu', array( $settings, 'registerMenu' ) );
		add_action( 'admin_post_site_monitor_run_scan', array( $dashboard, 'handleRunScan' ) );
		add_action( 'admin_post_site_monitor_discover', array( $pages, 'handleDiscover' ) );
		add_action( 'admin_post_site_monitor_add_url', array( $pages, 'handleAddUrl' ) );
		add_action( 'admin_post_site_monitor_page_action', array( $pages, 'handlePageAction' ) );
		add_action( 'admin_post_site_monitor_bulk_pages', array( $pages, 'handleBulkAction' ) );
		add_action( 'admin_post_site_monitor_save_settings', array( $settings, 'handleSave' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	public function enqueueAssets( string $hook_suffix ): void {
		$allowed = array(
			'toplevel_page_site-monitor',
			'site-monitor_page_site-monitor-pages',
			'site-monitor_page_site-monitor-history',
			'site-monitor_page_site-monitor-settings',
		);
		if ( ! in_array( $hook_suffix, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style( 'site-monitor-admin', SITE_MONITOR_URL . 'assets/css/admin.css', array(), SITE_MONITOR_VERSION );
		wp_enqueue_script( 'site-monitor-admin', SITE_MONITOR_URL . 'assets/js/admin.js', array(), (string) filemtime( SITE_MONITOR_PATH . 'assets/js/admin.js' ), true );
		$latest = ( new ScanRepository() )->latest();
		wp_localize_script(
			'site-monitor-admin',
			'siteMonitorAdmin',
			array(
				'scanRunning' => is_array( $latest ) && 'running' === $latest['status'],
				'statusUrl'   => esc_url_raw( rest_url( 'site-monitor/v1/status' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'labels'      => array(
					'checked' => __( 'checked', 'site-monitor' ),
					'failed'  => __( 'failed', 'site-monitor' ),
					'starting' => __( 'Starting scan…', 'site-monitor' ),
					'scanning' => __( 'Scanning…', 'site-monitor' ),
					'running' => __( 'Scanning in the background. Progress updates after each page; a page may take a while to respond.', 'site-monitor' ),
					'completed' => __( 'Scan completed. The page will refresh to show the latest results.', 'site-monitor' ),
					'scanFailed' => __( 'The scan failed. Check Scan History for details.', 'site-monitor' ),
					'statusError' => __( 'Unable to refresh scan progress. Retrying automatically…', 'site-monitor' ),
				),
			)
		);
	}
}
