# Development and verification

## Setup

Use PHP 8.1 or newer and install the development-only dependencies:

```bash
composer install
```

Production deployments do not need `vendor/`; the plugin uses
`includes/autoload.php` at runtime.

## Automated checks

Run the standalone behavioral suite:

```bash
php tests/run.php
```

Run WordPress Coding Standards, excluding installed dependencies:

```bash
vendor/bin/phpcs --standard=WordPress --extensions=php --ignore=vendor .
```

As of the Phase 7 record, this strict command is a known failing release gate;
see `quality-assurance.md` for the measured baseline. Do not treat a run as a
pass based only on empty or truncated command output—check its exit code.

Lint every PHP file and validate Composer metadata:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
composer validate --no-check-publish
```

On PowerShell, the equivalent lint loop is:

```powershell
$files = rg --files -g '*.php' -g '!vendor/**'
foreach ($file in $files) { php -l $file }
```

## WordPress integration suite

`tests/wordpress-integration.php` exercises real activation, migration,
deactivation, cron, lock recovery, forced queue and email failures, retention,
and opt-in uninstall. It temporarily mutates and drops Site Monitor data, then
restores a table, option, and cron snapshot in a `finally` block.

Run it only on an idle, disposable local site. The script refuses non-local
WordPress environments and requires an explicit destructive-test flag:

```bash
SITE_MONITOR_WP_ROOT=/path/to/wordpress \
SITE_MONITOR_QA_ALLOW_DESTRUCTIVE=1 \
php tests/wordpress-integration.php
```

If the CLI database endpoint differs from `wp-config.php`, set
`SITE_MONITOR_QA_DB_HOST` to the test endpoint. Do not run this suite while a
real scan, deployment, or database maintenance task is active.

## Schema changes

Increment `Schema::VERSION` for every schema change. New migrations must be
idempotent, preserve existing rows, use trusted table names plus prepared
values, and be exercised on an older disposable schema. Activation and normal
plugin boot both call the schema installer when the stored version differs.

## Manual release checks

On a connected WordPress instance:

1. Render the dashboard, monitored-page list and detail, scan history and
   detail, and settings screen.
2. Start a manual background scan and observe `Running` change to `Completed`
   through authenticated REST polling without reloading.
3. Confirm unauthenticated REST access is denied and unauthenticated admin
   access redirects to login.
4. Confirm a state-changing request without its action nonce is rejected.
5. Inspect the PHP error log after the run.

Record exact commands, counts, live outcomes, and anything not exercised. A
release claim must not rely on an unrun check.
