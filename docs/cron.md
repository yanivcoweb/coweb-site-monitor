# WP-Cron and real server cron

## Default WP-Cron

Site Monitor registers ordinary WordPress cron events. On each eligible site request, WordPress checks whether an event is due. This requires no hosting configuration but does not guarantee exact execution time.

## Recommended production configuration

First add this to `wp-config.php` above the “stop editing” line:

```php
define( 'DISABLE_WP_CRON', true );
```

Then run WordPress cron every five minutes from the server. With WP-CLI:

```cron
*/5 * * * * cd /var/www/example.com/public && /usr/local/bin/wp cron event run --due-now --quiet
```

Or, when WP-CLI is unavailable:

```cron
*/5 * * * * /usr/bin/curl --fail --silent --show-error 'https://example.com/wp-cron.php?doing_wp_cron' >/dev/null
```

Replace paths and the URL with the actual installation. Use HTTPS in production. Do not expose credentials in the cron command.

Both approaches trigger the same WordPress hooks and Site Monitor batching logic. Do not create a separate script that calls monitoring classes directly.

After configuring server cron, verify **Site Monitor > Dashboard** shows a next
scan and that “Last scheduled completion” advances. Keep the runner interval at
five minutes or less if hourly Site Monitor scans are configured.

Do not use `DISABLE_WP_CRON` unless the replacement server job has been tested.
Server cron improves timing but cannot monitor an outage of the server that is
supposed to execute it.
