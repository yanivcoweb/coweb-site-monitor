# Page discovery and selection

## Discovery

`PageDiscovery` queries published IDs through `WP_Query` in bounded batches of 50. Administrators choose from public, UI-visible post types; attachments are excluded. Draft, private, trashed, and password-protected content cannot enter discovery.

Discovery writes pages as disabled. This prevents activation from silently monitoring a large site. Re-running discovery is idempotent: a unique post ID updates an existing record when its permalink changes, while a SHA-256 hash prevents duplicate full URLs.

The selected post types are retained in `site_monitor_settings`. A subsequent discovery batch can be requested when the query reports additional pages.

## URL policy

Custom URLs are normalized before validation and storage. Fragments are discarded because they are not sent in HTTP requests. Only absolute HTTP(S) URLs on the exact WordPress site host are accepted. Credentials and unexpected ports are rejected.

Before every request and redirect, the host is resolved and every returned address must be public. Loopback, private, link-local, reserved, metadata, unresolved, mixed-safe/unsafe, and cross-site destinations fail closed. `wp_safe_remote_get()` provides a second WordPress-level URL safety check.

An exact `.local` installation host and its configured port are permitted only to support local development. Public production hosts receive the full public-address restriction.

## Administration

The Phase 6 `Site Monitor > Monitored Pages` screen provides the discovery and
selection workflow:

- select post types and discover the next bounded batch;
- add a same-site custom URL;
- search and paginate stored targets;
- enable, disable, remove, or manually check one target;
- enable, disable, or remove selected targets in bulk.

All state-changing requests require `manage_options` and a scoped WordPress nonce. Output is escaped. New custom URLs are enabled explicitly; discovered WordPress content remains disabled until selected.

The list is paginated at 20 records per page. Selecting a page title opens a
drill-down containing current state, recent checks, and notification attempts.

## Verified local behavior

The plugin was active in WordPress 7.1 and migrated the database schema to version 1.1.1. Discovery stored 11 published pages. The homepage was enabled and checked from the page table; the HTTP 200 result was persisted as healthy with response time and check timestamp. A cross-site custom URL was rejected.
