<?php
/**
 * Main plugin coordinator.
 *
 * @package SiteMonitor
 */

declare(strict_types=1);

namespace SiteMonitor\Core;

use SiteMonitor\Admin\Admin;
use SiteMonitor\API\AdminStatusController;
use SiteMonitor\Database\Schema;
use SiteMonitor\Monitoring\Monitor;

final class Plugin {
	private static ?Plugin $instance = null;

	private bool $booted = false;

	private ?Monitor $monitor = null;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'site-monitor', false, dirname( plugin_basename( SITE_MONITOR_FILE ) ) . '/languages' );
		Schema::maybe_upgrade();
		( new AdminStatusController() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		( new Scheduler() )->register();

		/**
		 * Fires after the Site Monitor foundation has loaded.
		 *
		 * @param Plugin $plugin Plugin coordinator.
		 */
		do_action( 'site_monitor_loaded', $this );
	}

	public function monitor(): Monitor {
		$this->monitor ??= Monitor::createDefault();

		return $this->monitor;
	}
}
