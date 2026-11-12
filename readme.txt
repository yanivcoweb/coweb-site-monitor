=== Site Monitor ===
Contributors: site-monitor
Tags: monitoring, health, uptime
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: Proprietary

Monitor selected public website pages and record health failures.

== Description ==

This repository contains the foundation, monitoring engine, page discovery and
selection, batched scheduling, transition-based email notifications, the
administration interface, and Phase 7 lifecycle and failure-path QA.

Administrators receive separate Dashboard, Monitored Pages, Scan History, and
Settings screens. The dashboard reports page health, incidents, scheduler
health, recent failures, and live progress for background scans. Page and scan
records have drill-down views, and all administration routes require the
`manage_options` capability.

See `docs/administration.md` for configuration, permissions, and status-polling
details. Installation and uninstall behavior are in `docs/installation.md`.
WP-Cron production guidance is in `docs/cron.md`. Development commands are in
`docs/development.md`; known limits and the post-MVP roadmap are in
`docs/limitations-and-roadmap.md`.

== Installation ==

1. Copy the site-monitor directory into wp-content/plugins.
2. Activate Site Monitor in WordPress administration.
3. Discover and enable pages under Site Monitor > Monitored Pages.
4. Review Site Monitor > Settings and run one manual scan.

No Composer command is required on the production server.

== Configuration ==

The Settings screen controls scan frequency, request timeout, batch size,
history retention, notification recipient, failure threshold, and repeat
interval. Discovered pages remain disabled until an administrator enables
them. See `docs/installation.md` for defaults and accepted ranges.

== Uninstall ==

Deactivation and uninstall preserve data by default. Permanent uninstall
cleanup is opt-in with the boolean `SITE_MONITOR_REMOVE_DATA_ON_UNINSTALL`
constant. See `docs/installation.md` and back up before enabling cleanup.

== Limitations ==

The plugin cannot detect a complete outage of the WordPress host, does not run
a browser or JavaScript, and cannot prove mailbox delivery. See
`docs/limitations-and-roadmap.md` for the complete list and deferred roadmap.

== Changelog ==

= 0.1.0 =
* Added the plugin foundation, initial schema, HTTP checker, content validation,
  structured results, persistence repositories, and single-page monitor.
* Added paginated content discovery, custom URLs, SSRF controls, page selection,
  bulk actions, search, and manual page checks.
* Added WP-Cron frequencies, durable batched scans, overlap locking, recovery,
  progress display, retention cleanup, and background manual scans.
* Added configurable failure thresholds, repeat and recovery alerts, an
  extensible provider interface, email delivery through wp_mail(), and a
  durable audit trail of every delivery attempt.
* Added separate administration screens, health summaries, paginated history,
  page and scan drill-downs, validated settings, and authenticated live scan
  progress polling.
* Added Phase 7 lifecycle, failure-path, retention, locking, SSRF, and query
  performance QA; fixed interrupted-scan deactivation, expired-lock renewal,
  uninstall cleanup, and the batch-query index.
