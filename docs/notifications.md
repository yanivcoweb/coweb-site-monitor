# Notifications

## Incident policy

Only a `critical` monitoring result is a qualifying page failure. Warnings do not open incidents, and `unknown` scanner failures are not treated as website downtime. `consecutive_failures` therefore counts consecutive critical results and resets on every non-critical result.

An incident opens when the critical streak reaches `alert_failure_threshold` (default 2, clamped to 1–100). The opening result sends one failure notification. Later unchanged critical results are suppressed until `alert_repeat_hours` has elapsed since the most recent delivery attempt. A repeat value of `0` disables repeat alerts. The value is otherwise clamped to 8,760 hours.

An open incident closes only when the page returns a `healthy` result. That transition sends one recovery notification. A failure that recovers before reaching the threshold does not generate either message.

The settings are stored in the existing `site_monitor_settings` option:

- `notification_email`: recipient used by the email provider.
- `alert_failure_threshold`: consecutive critical results required to open an incident.
- `alert_repeat_hours`: interval for unresolved-incident reminders; `0` disables them.

The Phase 6 Settings screen provides an administration form for these values.
Inputs are capability- and nonce-protected, normalized against explicit ranges,
and rejected when the notification address does not sanitize to a valid email.

## Providers and message contents

`NotificationProviderInterface` is the provider seam and `NotificationManager` owns transition policy. The MVP `EmailNotificationProvider` calls `wp_mail()`. Additional interface implementations may be registered through the `site_monitor_notification_providers` filter.

Failure, repeat, and recovery messages include the website name, monitored URL, original failure type, HTTP status when available, error description, detection time in UTC, and Site Monitor dashboard link.

## Persistence and delivery semantics

Current incident state is stored on `site_monitor_pages`. Every provider call is stored in `site_monitor_notification_attempts`, including failed calls. The audit record stores the recipient and subject but not the email body.

When `wp_mail()` returns `true`, the attempt is marked `accepted`: this means WordPress accepted it for processing, not that the destination mailbox received it. `false`, invalid recipients, and thrown provider errors are recorded as `failed`. Repeat suppression uses the last attempt time, regardless of outcome, to prevent a broken mail transport from generating an attempt on every scan.

Notification attempts follow the configured history-retention period.
