# Installation and configuration

## Requirements

- WordPress 6.4 or newer.
- PHP 8.1 or newer.
- A WordPress-supported MySQL or MariaDB database.
- Working loopback HTTP access from PHP to every monitored same-site URL.
- WP-Cron or a real server cron that invokes WordPress cron.

The production plugin contains its own autoloader. Composer is needed only for
development and quality checks.

## Install

1. Back up the WordPress database.
2. Copy the complete `site-monitor` directory to `wp-content/plugins/`.
3. Activate **Site Monitor** on the WordPress Plugins screen.
4. Open **Site Monitor > Monitored Pages**, run discovery, and enable only the
   pages that should be checked.
5. Review **Site Monitor > Settings**, especially the recipient, request
   timeout, batch size, retention period, and alert policy.
6. Run one manual scan from the dashboard and inspect its result in Scan
   History before relying on scheduled checks.

Activation creates or upgrades four tables using the active WordPress table
prefix: pages, scans, results, and notification attempts. It also creates the
recurring scan and daily retention events. Existing settings and history are
preserved during a version upgrade or reactivation.

## Configuration

| Setting | Default | Allowed range | Effect |
| --- | ---: | ---: | --- |
| Frequency | Daily | Hourly, 6 hours, 12 hours, daily | Recurring scan cadence. |
| Request timeout | 15 seconds | 1–60 | Per-request network timeout. |
| Batch size | 10 pages | 1–50 | Pages processed by one cron batch. |
| History retention | 30 days | 1–365 | Age limit for completed scans, results, and notification attempts. |
| Notification email | Site admin email | Valid email | Failure, repeat, and recovery recipient. |
| Failure threshold | 2 | 1–100 | Consecutive critical results required to open an incident. |
| Repeat interval | 24 hours | 0–8,760 | Reminder interval; `0` disables repeats. |

Discovered content is disabled by default. A custom URL is enabled when it is
added, but it must be an HTTP(S) URL on the exact WordPress site host and pass
the request-time URL safety checks.

## Deactivation and uninstall

Deactivation clears Site Monitor cron events and the scan lock. A running scan
is marked failed with the `deactivated` code so the administration UI does not
remain stuck in a running state. Tables, settings, and history are preserved.

Uninstall also preserves data by default. To opt in to permanent cleanup, add
the following to `wp-config.php` before uninstalling the plugin:

```php
define( 'SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL', true );
```

With that constant set to boolean `true`, uninstall permanently removes all
four plugin tables, all Site Monitor options and scheduler metadata, and all
Site Monitor cron events. Back up first; this cleanup is not reversible.

## Cron

See [cron.md](cron.md) for traffic-driven WP-Cron behavior and the recommended
server-cron configuration.
