<?php
declare(strict_types=1);

namespace SiteMonitor\API;

use SiteMonitor\Database\PageRepository;
use SiteMonitor\Database\ScanRepository;

final class AdminStatusController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'site-monitor/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
	}

	public function permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function status(): \WP_REST_Response {
		$summary = ( new PageRepository() )->summary();
		$scan    = ( new ScanRepository() )->latest();
		$latest  = null;

		if ( is_array( $scan ) ) {
			$latest = array(
				'id'            => (int) $scan['id'],
				'status'        => sanitize_key( (string) $scan['status'] ),
				'total_pages'   => (int) $scan['total_pages'],
				'checked_pages' => (int) $scan['checked_pages'],
				'failed_pages'  => (int) $scan['failed_pages'],
				'started_at'    => (string) $scan['started_at'],
				'completed_at'  => null === $scan['completed_at'] ? null : (string) $scan['completed_at'],
			);
		}

		return new \WP_REST_Response( array( 'summary' => $summary, 'latest_scan' => $latest ), 200 );
	}
}
