# Administration

## Access model

Every Site Monitor administration screen and action requires the WordPress
`manage_options` capability. State-changing forms and row actions also require
an action-specific WordPress nonce. The status endpoint at
`/wp-json/site-monitor/v1/status` uses WordPress cookie authentication and a
REST nonce, then checks `manage_options`; it is not a public status feed and
does not expose scan-lock tokens or notification data.

## Screens

### Dashboard

The dashboard shows enabled, healthy, warning, critical, and open-incident
counts; the latest scan counters; recurring scheduler health and timestamps;
and the ten most recent critical results. “Run scan now” creates a durable scan
and queues its first batch instead of making monitored requests in the admin
request.

While the latest scan is running, the page requests the protected status
endpoint after one second and then every five seconds. It updates the scan
status, counters, health summary, and button state without reloading. Temporary
request failures retry after ten seconds.

### Monitored Pages

Administrators can discover public WordPress content in bounded batches, add a
same-site custom URL, search stored URLs, and paginate the list at 20 rows per
page. Individual and bulk actions support enable, disable, and remove; an
individual page can also be checked immediately. The page drill-down displays
current health, failure streak and incident state, up to 50 recent checks, and
up to 50 recent notification attempts.

### Scan History

Scan History lists 20 durable scan records per page. A scan drill-down shows
its trigger, status, progress, optional error, and all result records in pages
of 50.

### Settings

The settings form controls scan frequency, request timeout, batch size, history
retention, notification recipient, failure threshold, and repeat interval.
Server-side normalization allowlists the frequency, clamps numeric values, and
sanitizes the email address. Discovery-only settings are preserved when this
form is saved.

The defaults and accepted ranges are documented in
[`installation.md`](installation.md). Changing the frequency is reconciled on
the next WordPress `init`; no direct cron editing is required.

## Stored and displayed data

Administration tables display URLs, status values, HTTP status, response time,
compact error messages, scheduler timestamps, and notification delivery audit
metadata. Dynamic output is escaped for its HTML or URL context. Monitoring
response bodies, credentials, cookies, authorization headers, scan-lock tokens,
and notification message bodies are not exposed by these screens or the status
endpoint.

The plugin stores monitored URLs, compact error descriptions, response timing,
the configured notification address, and notification subjects/recipients.
Response bodies, mail bodies, cookies, credentials, and authorization headers
are not retained. WordPress privacy export/erase integration is not provided
because this operational data is not associated with WordPress user IDs.

## Phase 6 verification

The four administration screens, page and scan drill-downs, and an unchanged
settings submission were exercised in the connected local WordPress instance.
A live manual scan visibly moved from Running to Completed through background
polling without a page reload. The same run recorded one timeout result for the
local homepage; that monitoring outcome is preserved rather than treated as an
administration failure. An unauthenticated REST request returned HTTP 401, an
unauthenticated admin request redirected to login, and an authenticated row
action without its nonce was rejected.

The standalone automated suite, PHP syntax checks, Composer validation, and PHP
error-log review are recorded in the completion report for the Phase 6 work.
