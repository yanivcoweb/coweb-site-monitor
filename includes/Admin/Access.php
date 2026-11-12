<?php
declare(strict_types=1);

namespace SiteMonitor\Admin;

final class Access {
	public static function requireManageOptions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Site Monitor.', 'site-monitor' ), esc_html__( 'Access denied', 'site-monitor' ), array( 'response' => 403 ) );
		}
	}

	public static function authorize( string $nonce_action ): void {
		self::requireManageOptions();
		check_admin_referer( $nonce_action );
	}
}
