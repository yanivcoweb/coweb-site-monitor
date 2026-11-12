# Monitoring engine

## Flow

`Plugin::monitor()` lazily creates the default engine. `Monitor::checkPage()` loads a selected page, merges its validation configuration with safe global request settings, creates a one-page scan, executes the check, and persists the result plus latest page state. After persistence, the notification manager evaluates the result against the page's pre-check incident state.

`PageChecker` is independent of WordPress administration. It receives an HTTP client, URL guard, and content validator. Redirects are disabled in the underlying HTTP request and followed by `PageChecker`, up to five hops, so the guard examines every destination.

The default HTTP adapter uses `wp_safe_remote_get()`, SSL verification, no automatic redirects, a bounded timeout, and a one-megabyte response limit. It never persists the response body.

## Status and error classification

- HTTP/network/security failures are `critical`.
- Critical validation issues are `critical`; non-critical validation issues are `warning`.
- A passing response is `healthy`.
- Unexpected scanner exceptions are `unknown` and are not classified as website downtime.

Error types distinguish `http`, `network`, `timeout`, `dns`, `ssl`, `security`, `validation`, and `scanner`. Error codes provide the more specific machine-readable reason.

## Validation configuration

Page `validation_config` is JSON supporting:

- `minimum_length`: non-negative byte count.
- `required_text`: list of case-insensitive literal strings.
- `forbidden_text`: list of case-insensitive literal strings.
- `detect_wordpress_errors`: enabled by default.
- `wordpress_error_patterns`: additional case-insensitive literal strings.
- `allow_redirects`: enabled by default.
- `maximum_bytes`: clamped between 1 KiB and 5 MiB.

Additional rule objects implementing `ValidationRuleInterface` can be registered through `site_monitor_validation_rules`. Rules receive only the bounded response body and validation configuration.

## Current boundary

The same engine is used by one-page admin checks and Phase 4 scheduled/manual batches. Notification failures are isolated from monitoring persistence: they are logged and timestamped without changing the saved check result or aborting a scan.
