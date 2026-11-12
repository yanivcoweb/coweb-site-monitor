<?php
/**
 * Plugin Name:       Site Monitor
 * Plugin URI:        https://example.com/site-monitor
 * Description:       Monitors selected public pages and reports website health problems.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Site Monitor
 * Text Domain:       site-monitor
 * Domain Path:       /languages
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_MONITOR_VERSION', '0.1.0' );
define( 'SITE_MONITOR_FILE', __FILE__ );
define( 'SITE_MONITOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITE_MONITOR_URL', plugin_dir_url( __FILE__ ) );

require_once SITE_MONITOR_PATH . 'includes/autoload.php';

register_activation_hook( SITE_MONITOR_FILE, array( SiteMonitor\Core\Activator::class, 'activate' ) );
register_deactivation_hook( SITE_MONITOR_FILE, array( SiteMonitor\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		SiteMonitor\Core\Plugin::instance()->boot();
	}
);
