# Phase 7 quality-assurance record

Date: 2026-09-18

## Automated verification

- Standalone behavioral suite: 36/36 passed with PHP 8.3.14.
- Local WordPress integration suite: 21/21 assertions passed. The suite backed
  up and restored the plugin tables, Site Monitor options, and cron state.
- WordPress Coding Standards: ran with PHP_CodeSniffer 3.13.6 and WPCS 3.4.1,
  but failed with exit code 2. The full WordPress ruleset reported 723 errors
  and 258 warnings across 48 files; 159 are automatically fixable. Most are
  repository-wide PSR-4 filename/camelCase incompatibilities, missing
  docblocks, compact administration-template formatting, and direct database
  or output warnings in the test harness. This is an unresolved release gate,
  not a pass.
- PHP syntax: every first-party PHP file passed `php -l`.
- Composer metadata: `composer validate --no-check-publish` succeeded.
- Opt-out uninstall: tables, settings, and cron state remained unchanged.

The integration suite exercised activation, schema version 1.2.2 migration,
row preservation, deactivation preservation, interrupted-scan failure state,
cron clearing/recreation, expired-lock recovery, forced first-batch queue
failure, forced `wp_mail()` failure, 30-day retention boundaries, and opt-in
uninstall. Opt-in uninstall removed all plugin tables, options, scheduler
metadata, and cron events before the suite restored the original state.

## Security, privacy, and performance review

- Same-site scheme, host, credential, port, DNS/IP, and redirect checks were
  reviewed. Automated cases cover cross-site, credential-bearing, private-IP,
  non-HTTP, blocked-port, safe-redirect, disabled-redirect, and redirect-limit
  behavior. The remaining DNS transport limitation is recorded in
  `limitations-and-roadmap.md`.
- Administration actions require `manage_options` and scoped nonces. The REST
  route uses cookie authentication, a REST nonce, and `manage_options`.
  Unauthenticated REST returned HTTP 401, unauthenticated administration
  returned HTTP 302 to login, and an authenticated nonce-less row action was
  rejected without changing the page.
- Repositories prepare all dynamic values; the few interpolated table/index
  identifiers come only from the trusted WordPress prefix or fixed allowlists.
- Response bodies are bounded to 1 MiB by default and 5 MiB maximum, processed
  one page at a time, and never persisted. Batch size is clamped to 50.
- Scan cursor, page history, scan result, and retention queries use indexes.
  The batch query now has `(enabled,id)` and its reviewed plan uses that index
  without a filesort. URL search and offset pagination remain documented scale
  limitations.
- Stored personal data is limited to the configured notification email and the
  recipient/subject audit fields. Bodies, credentials, cookies, authorization
  headers, and monitored response bodies are not stored.

## Live WordPress validation

The connected local WordPress 7.1.1 administration rendered the Dashboard,
Monitored Pages, page detail, Scan History, scan detail, and Settings screens.
A manual background scan (#12) visibly changed from `Running` to `Completed`
through REST polling without a page reload. It checked the one enabled homepage
successfully with HTTP 200 in 2,711 ms, and both drill-downs displayed the new
result. Scheduler health was shown as healthy.

No PHP error-log entry was added during the Phase 7 run. The newest existing
entry was an unrelated cron warning at 2026-09-18 18:58:46 UTC; older Site
Monitor class-loading failures predated the completed administration phase.

## Not verified in Phase 7

- A real production server-cron installation and host-level outage detection.
- Real mailbox delivery, bounce handling, spam placement, or message opens.
- Multisite/network activation and uninstall.
- MariaDB, older supported MySQL releases, IPv6-only DNS, unusual proxies, and
  production TLS/DNS configurations.
- Large-dataset load, concurrency, and long-duration soak testing.
- Every historic schema/database combination; migration was tested by degrading
  the current local schema and preserving its rows.
- Transport-level DNS address pinning, JavaScript/browser rendering, visual
  regression checks, and authenticated frontend flows.

These boundaries are release limitations, not silently assumed passes.

## Release assessment

The monitored workflow and lifecycle behavior are suitable for a controlled
MVP/beta on the tested local stack. A standards-compliant public release is not
ready while the full WordPress Coding Standards run remains red. Resolve or
formally adopt and document a narrower project ruleset before treating coding
standards as a green release gate. Production rollout also requires a real cron
and acceptance of the limitations above.
