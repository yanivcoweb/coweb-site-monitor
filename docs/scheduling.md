# Scheduling and batching

## Execution model

The recurring `site_monitor_run_scheduled_scan` event creates a durable scan record and immediately returns. It schedules `site_monitor_process_scan_batch` as a single event. Each batch reads enabled page IDs after the persisted cursor, checks at most the configured batch size (default 10, clamped to 1–50), saves results and counters, then schedules another batch when work remains.

The admin “Run scan now” action calls the same `ScanManager` with a `manual` trigger. It never performs page requests inside the initiating admin request. The button shows immediate feedback on submission. Counters and the cursor are persisted after each page, and the status card refreshes every five seconds while a scan is running. Status request errors are shown with automatic retries. On completion, the dashboard reloads to show the latest failures.

## Locking and recovery

`ScanLock` uses atomic `add_option()` acquisition with a random owner token and a 15-minute expiry. Only the owner can refresh or release it. An active lock rejects overlapping manual or scheduled scans. After expiry, a new scan can acquire the lock and any abandoned `running` scan is marked `failed` with `lock_expired`.

Loss of ownership during a batch marks that scan failed. Queueing failures and unexpected batch exceptions also mark the scan failed and release the lock, allowing later scans to proceed.

## Frequencies and health

Supported setting values are:

- `daily` (default)
- `site_monitor_12_hours`
- `site_monitor_6_hours`
- `hourly`

The scheduler reconciles the registered event with the configured frequency. The admin status card shows the next event, latest scan progress, last successful batch scan, and last successful scheduled completion. A missing recurring event or execution overdue by more than twice its interval produces a warning.

## Checks during a failure

The separate “Check frequency during a failure” setting supports 5, 10, 15, 30, or 60 minutes. Its default is “Use regular scan frequency”, which preserves existing behavior. It does not change the email Repeat interval.

When enabled, `site_monitor_check_incidents` starts an `incident` scan containing only enabled pages with a critical latest result or an open incident. This starts monitoring after the first critical result, even before the email failure threshold is reached. Open incidents remain eligible through warning results until a healthy result resolves them. Healthy pages without an open incident, disabled pages, and removed pages are excluded from subsequent batches.

These scans use the same queued batches, result history, notifications, and shared lock as regular scans. If another scan is running, the incident scan retries at the next interval. Saving settings reconciles the incident schedule; disabling the option or deactivating the plugin removes it. WP-Cron may execute checks later than their requested interval.

## Retention

A daily cleanup event removes notification attempts, results, and completed scans older than the configured retention window (default 30 days, clamped to 1–365). Latest status and current incident state remain on the page record. Deleted WordPress posts are disabled rather than silently monitored at stale permalinks.

## Limitation

WP-Cron is traffic driven. It may run late or not at all on a quiet or unavailable site. Complete outages still require a future external monitor.
