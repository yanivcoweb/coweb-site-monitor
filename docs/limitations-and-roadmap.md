# Limitations and roadmap

## Current limitations

- Site Monitor runs inside the monitored WordPress installation. It cannot
  detect a complete outage of WordPress, PHP, DNS, the database, or the host.
- WP-Cron is traffic driven and may be late on quiet sites. A real server cron
  improves scheduling but still cannot run when the host itself is unavailable.
- Checks validate the server-returned HTTP response only. JavaScript rendering,
  browser console failures, visual regressions, and authenticated user flows are
  outside the MVP.
- Email status is `accepted` or `failed` according to `wp_mail()`. Delivery,
  bounces, spam placement, and message opens are not observable.
- The same-site guard revalidates DNS and redirects and uses
  `wp_safe_remote_get()`, but it does not pin the validated address to the HTTP
  transport. Hosting-layer DNS rebinding resistance therefore still depends on
  WordPress and the configured HTTP transport.
- Exact `.local` hosts are allowed to resolve to private addresses for local
  development. Production hosts do not receive this exemption.
- Search uses a leading-wildcard URL query, and list screens use offset
  pagination. The scan cursor and histories are indexed, but very large history
  datasets have not been load-tested.
- Multisite/network activation, alternate database engines, IPv6-only hosting,
  proxy-specific networking, and a real production cron runner were not tested
  during the local Phase 7 pass.
- The local lifecycle suite verified a forced `wp_mail()` failure, not real
  mailbox delivery. It verified schema migration from a deliberately degraded
  current table, not every historic WordPress/database combination.

## Post-MVP roadmap

The following are intentionally deferred and are not commitments in version
0.1.0:

1. An external runner that can detect complete site and host outages.
2. Browser-based checks for JavaScript-rendered content and visual assertions.
3. Additional notification providers and delivery-status integrations.
4. Multisite-specific lifecycle and administration support.
5. Operational tooling such as WP-CLI commands, richer diagnostics, and export.
6. Keyset pagination and dedicated large-dataset performance testing.
7. Stronger transport-level address pinning where the WordPress HTTP stack and
   hosting environment can support it safely.
