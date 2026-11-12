# Technical risks and controls

- **WP-Cron reliability:** traffic-triggered execution can be late or absent. The dashboard will compare the last successful execution with the configured interval. Documentation will recommend a real cron invoking `wp-cron.php`; both paths call the same scheduler service.
- **Complete outages:** an in-site plugin cannot run when WordPress, PHP, DNS, or the host is down. The MVP documents this limitation; reliable outage detection belongs to the future external service.
- **Large sites:** discovery is paginated and opt-in. Scans persist progress and process bounded batches (default 10), with an expiring atomic lock and retryable continuation jobs.
- **Resource consumption:** requests have bounded timeouts, safe batch limits, no frontend execution, and no stored bodies. Response-size limiting will use HTTP API streaming where compatible and validation will use a bounded body.
- **JavaScript-rendered pages:** the MVP validates server-returned HTML only. It cannot observe client-side rendering or JavaScript errors; browser checks are explicitly deferred.
- **Duplicate notifications:** notification decisions use persisted incident state, consecutive-critical-failure thresholds, and last-attempt timestamps. Unchanged failures are suppressed until the configured repeat interval; a scan lock reduces racing transitions.
- **Email delivery certainty:** `wp_mail()` only reports whether WordPress accepted the message for processing. Attempts are recorded as `accepted` or `failed`; the MVP cannot confirm mailbox delivery, bounces, or opens.
- **SSRF:** same-site host allowlisting is necessary but not sufficient. Save-time and request-time scheme/host checks, DNS/IP classification, redirect validation, blocked ports, no credentials, and WordPress safe HTTP APIs will be combined. DNS rebinding and mixed DNS results require fail-closed behavior.
- **Schema evolution:** `dbDelta()` has formatting constraints and no rollback. Every change will be versioned, idempotent, and tested against a disposable WordPress database before release.
