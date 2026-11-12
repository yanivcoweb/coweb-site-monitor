# MVP development checklist

## Phase 1 — foundation

- [x] Inspect the existing WordPress workspace.
- [x] Define the modular MVP architecture and risks.
- [x] Add plugin bootstrap and production autoloader.
- [x] Add activation/deactivation services.
- [x] Add versioned pages, scans, and results tables.
- [x] Add conservative settings and opt-in uninstall cleanup.
- [x] Confirm activation and tables in a running WordPress instance.

## Phase 2 — monitoring engine

- [x] Add immutable result/error value objects and structured codes.
- [x] Add injectable WordPress HTTP client and safe redirect policy.
- [x] Add validation rule interface and empty, length, required, forbidden, and WordPress-error rules.
- [x] Add repositories and single-page monitor orchestration.
- [x] Add mocked HTTP and content tests.
- [x] Verify result, scan, and latest-page persistence through a live manual check.

## Phase 3 — page discovery

- [x] Paginate published public post types through WordPress APIs.
- [x] Exclude private, draft, trash, and password-protected content by default.
- [x] Canonicalize/deduplicate URLs and track permalink changes.
- [x] Add same-site SSRF policy with DNS and redirect validation tests.
- [x] Add selection, custom URL, enable/disable, remove, search, pagination, and bulk actions.

## Phase 4 — scheduling

- [x] Register frequency schedules and cleanup job.
- [x] Implement expiring atomic scan lock and recovery.
- [x] Persist scan queue/cursor and process bounded batches.
- [x] Add background manual scan initiation and progress.
- [x] Track last/next scan and scheduler health.
- [x] Test duplicate prevention and expired-lock recovery; live-test scheduled and manual processing.
- [ ] Exercise a forced failed-batch continuation in WordPress integration testing.
- [x] Document real server cron.

## Phase 5 — notifications

- [x] Add notifier interface, manager, and email provider.
- [x] Persist delivery attempts and notification state.
- [x] Implement failure threshold, transition, repeat, and recovery policies.
- [x] Add notification tests including failed `wp_mail()` attempts.

## Phase 6 — administration

- [x] Build Dashboard, Monitored Pages, Scan History, and Settings screens.
- [x] Add capability, nonce, sanitization, escaping, and REST permission controls.
- [x] Add summary/failure tables and drill-down views.
- [x] Add lightweight scan progress updates without exposing secrets.
- [x] Paginate page lists, scan lists, and scan-result drill-downs.
- [x] Verify all four screens, both drill-downs, unchanged settings submission, live polling, and rejected unauthorized/nonce-less requests in WordPress.
- [x] Run the Phase 6 automated suite, PHP syntax checks, Composer validation, and PHP error-log review.

## Phase 7 — quality assurance

- [x] Run coding standards and automated test suites.
- [x] Test activation, migrations, deactivation preservation, and opt-in uninstall.
- [x] Test monitoring/scheduler/notification failure scenarios and retention.
- [x] Review SSRF, authorization, privacy, memory, and query performance.
- [x] Complete installation, configuration, cron, development, and roadmap docs.
- [x] Record every unverified requirement before MVP release.

The commands, live outcomes, fixes, and remaining unverified behavior are
recorded in [`quality-assurance.md`](quality-assurance.md).
