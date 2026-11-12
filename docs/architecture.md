# Site Monitor MVP architecture

## Decisions

The plugin is a modular monolith. Monitoring, persistence, scheduling, notifications, admin UI, and API integration remain separate namespaces, but run in one WordPress plugin. This keeps the MVP understandable while leaving stable seams for future modules and central reporting.

Production uses a tiny PSR-4-compatible autoloader committed with the plugin. `composer.json` mirrors the namespace mapping and supplies development-only quality tools; client servers do not need Composer.

Dedicated tables store pages, scans, and immutable per-page results. WordPress options are limited to small settings, schema version, scheduler metadata, and a short-lived scan lock. `dbDelta()` provides idempotent schema installation, while explicit schema versions permit later migrations.

## Responsibilities

- `Core`: bootstrap, lifecycle, scheduler coordination, and scan locking.
- `Database`: schema and repositories; repositories own all dynamic SQL.
- `Monitoring`: URL policy, discovery, HTTP checking, validation rules, result value objects, and scan orchestration.
- `Notifications`: transition policy plus provider interfaces; email is the only MVP provider.
- `Admin`: menus, list tables, settings, background actions, and escaped views.
- `API`: authenticated administration status endpoints and a future central-server adapter. No public scan trigger exists.

The monitoring engine will not depend on admin classes. Its HTTP client will be injected behind a narrow interface so mocked responses can test it independently.

## Final MVP directory structure

```text
site-monitor/
├── site-monitor.php
├── uninstall.php
├── readme.txt
├── composer.json
├── includes/
│   ├── autoload.php
│   ├── Core/            # Plugin, activation, scheduling, locking
│   ├── Database/        # Schema and repositories
│   ├── Monitoring/
│   │   ├── Contracts/   # HTTP client and validation contracts
│   │   ├── Rules/       # Deterministic content rules
│   │   └── Value/       # Results and structured errors
│   ├── Notifications/   # Transition policy and providers
│   ├── Admin/           # Menus, list tables, actions, settings
│   └── API/             # Authenticated REST and future reporting adapter
├── templates/admin/     # Escaped, presentation-only views
├── assets/css/
├── assets/js/
├── languages/
├── tests/
│   ├── Unit/
│   └── Integration/
└── docs/
```

Folders are added only when their phase introduces working code; empty scaffolding is avoided.

## Data model

`site_monitor_pages` stores canonical monitored targets, source/post linkage, validation configuration, current status, consecutive critical failures, and current incident/last alert metadata. A SHA-256 URL hash provides full-URL uniqueness without unsafe index lengths.

`site_monitor_scans` is the durable batch job. Its cursor and counters make scans resumable and observable.

`site_monitor_results` is append-only history. It stores structured classifications and compact diagnostic details, never response bodies, request credentials, cookies, or authorization headers.

`site_monitor_notification_attempts` is the append-only provider audit trail. It records the result and page that caused an attempt, notification type, provider, recipient, subject, provider outcome, and timestamp. Message bodies are not retained. A successful `wp_mail()` return is recorded as `accepted`, never as proof of delivery.

Foreign keys are intentionally omitted because WordPress installations vary in storage engine and table lifecycle. Repository code will preserve relationships and cleanup order.

## Extension points

Validation and notification providers use narrow interfaces. Additional notification providers can be registered with `site_monitor_notification_providers`; invalid objects are ignored. Public hooks use the `site_monitor_` prefix and sanitized/minimal payloads. Proposed scan hooks remain deferred until their value objects exist so the API is not frozen prematurely.

## Security boundary

The MVP only accepts public HTTP(S) URLs belonging to the site's normalized host. URL validation will occur before saving and before every request/redirect. DNS answers will be checked against loopback, private, link-local, reserved, and metadata destinations. Rebinding-resistant request handling and redirect-by-redirect validation are required; URL validation at form submission alone is insufficient.
