<?php
/**
 * Lightweight production autoloader.
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SiteMonitor\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file           = SITE_MONITOR_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
