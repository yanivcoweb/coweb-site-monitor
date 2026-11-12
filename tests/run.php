<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'SITE_MONITOR_PATH', dirname( __DIR__ ) . '/' );
define( 'SITE_MONITOR_VERSION', '0.1.0' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

require SITE_MONITOR_PATH . 'includes/autoload.php';

$test_home_url = 'https://example.com/';
$test_options  = array();
$test_mail_result = true;
$test_current_user_can = true;
$test_actions = array();
$test_rest_routes = array();
$test_cron_events = array();
function __( string $text, string $domain = '' ): string { return $text; }
function wp_get_scheduled_event( string $hook ): object|false {
	global $test_cron_events;
	return $test_cron_events[$hook] ?? false;
}
function wp_next_scheduled( string $hook ): int|false {
	$event = wp_get_scheduled_event( $hook );
	return $event ? $event->timestamp : false;
}
function wp_clear_scheduled_hook( string $hook ): int {
	global $test_cron_events;
	unset( $test_cron_events[$hook] );
	return 1;
}
function wp_schedule_event( int $timestamp, string $schedule, string $hook ): bool {
	global $test_cron_events;
	$test_cron_events[$hook] = (object) array( 'timestamp' => $timestamp, 'schedule' => $schedule );
	return true;
}
function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function home_url( string $path = '' ): string {
	global $test_home_url;
	return rtrim( $test_home_url, '/' ) . '/' . ltrim( $path, '/' );
}
function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_SANITIZE_URL );
}
function get_option( string $name, mixed $default = false ): mixed {
	global $test_options;
	return $test_options[ $name ] ?? $default;
}
function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	global $test_options;
	if ( array_key_exists( $name, $test_options ) ) {
		return false;
	}
	$test_options[ $name ] = $value;
	return true;
}
function update_option( string $name, mixed $value, bool $autoload = true ): bool {
	global $test_options;
	$test_options[ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	global $test_options;
	if ( ! array_key_exists( $name, $test_options ) ) {
		return false;
	}
	unset( $test_options[ $name ] );
	return true;
}
function wp_generate_uuid4(): string {
	static $counter = 0;
	return '00000000-0000-4000-8000-' . str_pad( (string) ++$counter, 12, '0', STR_PAD_LEFT );
}
function sanitize_email( string $email ): string {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
}
function current_user_can( string $capability ): bool {
	global $test_current_user_can;
	return $test_current_user_can && 'manage_options' === $capability;
}
function is_email( string $email ): string|false {
	return filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function wp_mail( string|array $to, string $subject, string $message, string|array $headers = '', array $attachments = array() ): bool {
	global $test_mail_result;
	return $test_mail_result;
}
function add_action( string $hook, callable|array $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	global $test_actions;
	$test_actions[ $hook ][] = $callback;
	return true;
}
function register_rest_route( string $namespace, string $route, array $args, bool $override = false ): bool {
	global $test_rest_routes;
	$test_rest_routes[ $namespace . $route ] = $args;
	return true;
}

use SiteMonitor\Monitoring\ContentValidator;
use SiteMonitor\Monitoring\Contracts\HttpClientInterface;
use SiteMonitor\Monitoring\Contracts\UrlGuardInterface;
use SiteMonitor\Monitoring\Exception\HttpRequestException;
use SiteMonitor\Monitoring\PageChecker;
use SiteMonitor\Monitoring\SameSiteUrlGuard;
use SiteMonitor\Monitoring\UrlNormalizer;
use SiteMonitor\Monitoring\Value\HttpResponse;
use SiteMonitor\Core\ScanLock;
use SiteMonitor\Core\Settings;
use SiteMonitor\API\AdminStatusController;
use SiteMonitor\Monitoring\Value\CheckResult;
use SiteMonitor\Notifications\Contracts\NotificationProviderInterface;
use SiteMonitor\Notifications\Contracts\NotificationRepositoryInterface;
use SiteMonitor\Notifications\EmailNotificationProvider;
use SiteMonitor\Notifications\NotificationManager;
use SiteMonitor\Notifications\Value\DeliveryResult;
use SiteMonitor\Notifications\Value\Notification;

final class FakeHttpClient implements HttpClientInterface {
	/** @param list<HttpResponse|Throwable> $responses */
	public function __construct( private array $responses ) {}

	public function get( string $url, int $timeout, int $maximum_bytes ): HttpResponse {
		$response = array_shift( $this->responses );
		if ( $response instanceof Throwable ) {
			throw $response;
		}
		if ( ! $response instanceof HttpResponse ) {
			throw new RuntimeException( 'No fake response available.' );
		}

		return $response;
	}
}

final class AllowUrlGuard implements UrlGuardInterface {
	public function assertAllowed( string $url ): void {}
}

final class FakeNotificationProvider implements NotificationProviderInterface {
	/** @var list<Notification> */
	public array $sent = array();

	public function __construct( private readonly bool $accept = true ) {}

	public function id(): string {
		return 'fake';
	}

	public function send( Notification $notification ): DeliveryResult {
		$this->sent[] = $notification;

		return $this->accept ? DeliveryResult::accepted( 'Accepted for testing.' ) : DeliveryResult::failed( 'Rejected for testing.' );
	}
}

final class FakeNotificationRepository implements NotificationRepositoryInterface {
	/** @var list<array{result_id:int,notification:Notification,provider:string,delivery:DeliveryResult}> */
	public array $attempts = array();
	public int $opened = 0;
	public int $resolved = 0;
	public ?bool $lastAccepted = null;

	public function openIncident( Notification $notification ): void {
		++$this->opened;
	}

	public function resolveIncident( int $page_id, string $resolved_at ): void {
		++$this->resolved;
	}

	public function recordAttempt( int $result_id, Notification $notification, string $provider, DeliveryResult $delivery ): void {
		$this->attempts[] = compact( 'result_id', 'notification', 'provider', 'delivery' );
	}

	public function markAttempt( int $page_id, string $attempted_at, bool $accepted ): void {
		$this->lastAccepted = $accepted;
	}
}

/** @param list<HttpResponse|Throwable> $responses */
function checker( array $responses ): PageChecker {
	return new PageChecker( new FakeHttpClient( $responses ), new AllowUrlGuard(), new ContentValidator() );
}

function response( int $status, string $body = '<html>Valid page content</html>', array $headers = array() ): HttpResponse {
	return new HttpResponse( $status, $body, $headers, 12 );
}

function assert_same( mixed $expected, mixed $actual, string $name ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( sprintf( '%s: expected %s, got %s', $name, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
}

/** @return array<string,mixed> */
function notification_page( array $overrides = array() ): array {
	return array_merge(
		array(
			'id'                           => 7,
			'url'                          => 'https://example.com/contact/',
			'consecutive_failures'         => 0,
			'notification_state'           => 'none',
			'last_notification_attempt_at' => null,
			'incident_started_at'          => null,
			'incident_failure_type'        => null,
			'incident_http_status'         => null,
			'incident_error_message'       => null,
		),
		$overrides
	);
}

function notification_result( string $status, string $checked_at = '2026-09-17 10:00:00' ): CheckResult {
	return new CheckResult(
		$status,
		'https://example.com/contact/',
		'https://example.com/contact/',
		'critical' === $status ? 500 : 200,
		25,
		'critical' === $status ? 'http' : 'none',
		'critical' === $status ? 'http_error' : null,
		'critical' === $status ? 'The server returned HTTP 500.' : null,
		array(),
		false,
		$checked_at
	);
}

/** @return array{0:NotificationManager,1:FakeNotificationRepository,2:FakeNotificationProvider} */
function notification_manager( bool $accept = true ): array {
	$repository = new FakeNotificationRepository();
	$provider   = new FakeNotificationProvider( $accept );
	$manager    = new NotificationManager( $repository, array( $provider ), 'Example Website', 'https://example.com/wp-admin/admin.php?page=site-monitor' );

	return array( $manager, $repository, $provider );
}

function notification_settings( int $threshold = 1, int $repeat_hours = 24 ): array {
	return array(
		'alert_failure_threshold' => $threshold,
		'alert_repeat_hours'      => $repeat_hours,
		'notification_email'      => 'alerts@example.com',
	);
}

$tests = array(
	'HTTP 200 is healthy' => static function (): void {
		assert_same( 'healthy', checker( array( response( 200 ) ) )->check( 'https://example.com/' )->status, __FUNCTION__ );
	},
	'HTTP 404 is critical' => static function (): void {
		assert_same( 'critical', checker( array( response( 404 ) ) )->check( 'https://example.com/' )->status, __FUNCTION__ );
	},
	'HTTP 500 is critical' => static function (): void {
		assert_same( 'http_error', checker( array( response( 500 ) ) )->check( 'https://example.com/' )->errorCode, __FUNCTION__ );
	},
	'timeout is classified' => static function (): void {
		$error = new HttpRequestException( 'timeout', 'http_request_failed', 'Timed out.' );
		assert_same( 'timeout', checker( array( $error, $error ) )->check( 'https://example.com/' )->errorType, __FUNCTION__ );
	},
	'transient timeout is confirmed before reporting failure' => static function (): void {
		$error = new HttpRequestException( 'timeout', 'http_request_failed', 'Timed out.' );
		assert_same( 'healthy', checker( array( $error, response( 200 ) ) )->check( 'https://example.com/' )->status, __FUNCTION__ );
	},
	'HTTP failure after timeout remains critical' => static function (): void {
		$error = new HttpRequestException( 'timeout', 'http_request_failed', 'Timed out.' );
		assert_same( 'http_error', checker( array( $error, response( 500 ) ) )->check( 'https://example.com/' )->errorCode, __FUNCTION__ );
	},
	'DNS failure is classified' => static function (): void {
		$error = new HttpRequestException( 'dns', 'http_request_failed', 'Could not resolve host.' );
		assert_same( 'dns', checker( array( $error ) )->check( 'https://example.com/' )->errorType, __FUNCTION__ );
	},
	'SSL failure is classified' => static function (): void {
		$error = new HttpRequestException( 'ssl', 'http_request_failed', 'Certificate failed.' );
		assert_same( 'ssl', checker( array( $error ) )->check( 'https://example.com/' )->errorType, __FUNCTION__ );
	},
	'unexpected redirect is critical' => static function (): void {
		$result = checker( array( response( 301, '', array( 'location' => 'https://example.com/new' ) ) ) )->check( 'https://example.com/', array( 'allow_redirects' => false ) );
		assert_same( 'unexpected_redirect', $result->errorCode, __FUNCTION__ );
	},
	'safe redirect is followed' => static function (): void {
		$result = checker( array( response( 301, '', array( 'location' => 'https://example.com/new' ) ), response( 200 ) ) )->check( 'https://example.com/' );
		assert_same( true, $result->redirected, __FUNCTION__ );
		assert_same( 'healthy', $result->status, __FUNCTION__ );
	},
	'empty HTML is critical' => static function (): void {
		assert_same( 'empty_content', checker( array( response( 200, '  ' ) ) )->check( 'https://example.com/' )->errorCode, __FUNCTION__ );
	},
	'missing required text is warning' => static function (): void {
		$result = checker( array( response( 200 ) ) )->check( 'https://example.com/', array( 'required_text' => array( 'Expected heading' ) ) );
		assert_same( 'warning', $result->status, __FUNCTION__ );
	},
	'forbidden text is critical' => static function (): void {
		$result = checker( array( response( 200, '<p>Internal server error</p>' ) ) )->check( 'https://example.com/', array( 'forbidden_text' => array( 'Internal server error' ) ) );
		assert_same( 'forbidden_text_found', $result->errorCode, __FUNCTION__ );
	},
	'HTTP 200 database error is critical' => static function (): void {
		$result = checker( array( response( 200, 'Error establishing a database connection' ) ) )->check( 'https://example.com/' );
		assert_same( 'wordpress_error_detected', $result->errorCode, __FUNCTION__ );
	},
	'minimum content length is warning' => static function (): void {
		$result = checker( array( response( 200, 'short' ) ) )->check( 'https://example.com/', array( 'minimum_length' => 100 ) );
		assert_same( 'warning', $result->status, __FUNCTION__ );
	},
	'internal scanner failure is unknown' => static function (): void {
		$result = checker( array( new RuntimeException( 'Bug.' ) ) )->check( 'https://example.com/' );
		assert_same( 'unknown', $result->status, __FUNCTION__ );
	},
	'URL normalization removes fragments' => static function (): void {
		$result = ( new UrlNormalizer() )->normalize( 'HTTPS://Example.COM//about/#section' );
		assert_same( 'https://example.com/about/', $result, __FUNCTION__ );
	},
	'cross-site URL is rejected' => static function (): void {
		try {
			( new SameSiteUrlGuard() )->assertAllowed( 'https://different.example/' );
			throw new RuntimeException( 'Unsafe URL was accepted.' );
		} catch ( SiteMonitor\Monitoring\Exception\UnsafeUrlException $error ) {
			assert_same( 'The URL must belong to this website.', $error->getMessage(), __FUNCTION__ );
		}
	},
	'credential-bearing URL is rejected' => static function (): void {
		try {
			( new SameSiteUrlGuard() )->assertAllowed( 'https://user:password@example.com/' );
			throw new RuntimeException( 'Credential URL was accepted.' );
		} catch ( SiteMonitor\Monitoring\Exception\UnsafeUrlException $error ) {
			assert_same( 'URLs containing credentials are not allowed.', $error->getMessage(), __FUNCTION__ );
		}
	},
	'private same-site address is rejected' => static function (): void {
		global $test_home_url;
		$test_home_url = 'http://127.0.0.1/';
		try {
			( new SameSiteUrlGuard() )->assertAllowed( 'http://127.0.0.1/' );
			throw new RuntimeException( 'Private URL was accepted.' );
		} catch ( SiteMonitor\Monitoring\Exception\UnsafeUrlException $error ) {
			assert_same( 'The URL resolves to a non-public network address.', $error->getMessage(), __FUNCTION__ );
		} finally {
			$test_home_url = 'https://example.com/';
		}
	},
	'active scan lock prevents overlap' => static function (): void {
		global $test_options;
		$test_options = array();
		$now = 1000;
		$lock = new ScanLock( static fn (): int => $now );
		$token = $lock->acquire( 60 );
		assert_same( true, is_string( $token ), __FUNCTION__ );
		assert_same( null, $lock->acquire( 60 ), __FUNCTION__ );
	},
	'expired scan lock is recovered' => static function (): void {
		global $test_options;
		$test_options = array();
		$now = 1000;
		$lock = new ScanLock( static function () use ( &$now ): int { return $now; } );
		$first = $lock->acquire( 60 );
		$now = 1061;
		$second = $lock->acquire( 60 );
		assert_same( true, is_string( $second ) && $second !== $first, __FUNCTION__ );
		assert_same( false, $lock->release( (string) $first ), __FUNCTION__ );
		assert_same( true, $lock->release( (string) $second ), __FUNCTION__ );
	},
	'expired scan lock cannot be refreshed' => static function (): void {
		global $test_options;
		$test_options = array();
		$now = 1000;
		$lock = new ScanLock( static function () use ( &$now ): int { return $now; } );
		$token = $lock->acquire( 60 );
		$now = 1061;
		assert_same( false, $lock->refresh( (string) $token, 60 ), __FUNCTION__ );
	},
	'new qualifying failure sends one alert' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$manager->handle( notification_page(), 101, notification_result( 'critical' ), notification_settings() );
		assert_same( 1, count( $provider->sent ), __FUNCTION__ );
		assert_same( 'failure', $provider->sent[0]->type, __FUNCTION__ );
		assert_same( 1, $repository->opened, __FUNCTION__ );
		assert_same( 1, count( $repository->attempts ), __FUNCTION__ );
	},
	'unchanged failure is suppressed before repeat interval' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$page = notification_page( array( 'notification_state' => 'open', 'consecutive_failures' => 3, 'last_notification_attempt_at' => '2026-09-17 09:30:00' ) );
		$manager->handle( $page, 102, notification_result( 'critical' ), notification_settings() );
		assert_same( 0, count( $provider->sent ), __FUNCTION__ );
		assert_same( 0, count( $repository->attempts ), __FUNCTION__ );
	},
	'healthy result sends recovery for open incident' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$page = notification_page( array( 'notification_state' => 'open', 'incident_started_at' => '2026-09-17 08:00:00', 'incident_failure_type' => 'http/http_error', 'incident_http_status' => 500, 'incident_error_message' => 'HTTP 500' ) );
		$manager->handle( $page, 103, notification_result( 'healthy' ), notification_settings() );
		assert_same( 'recovery', $provider->sent[0]->type, __FUNCTION__ );
		assert_same( 1, $repository->resolved, __FUNCTION__ );
	},
	'repeat alert sends after configured interval' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$page = notification_page( array( 'notification_state' => 'open', 'consecutive_failures' => 4, 'last_notification_attempt_at' => '2026-09-16 10:00:00' ) );
		$manager->handle( $page, 104, notification_result( 'critical' ), notification_settings( 1, 24 ) );
		assert_same( 'repeat', $provider->sent[0]->type, __FUNCTION__ );
		assert_same( 1, count( $repository->attempts ), __FUNCTION__ );
	},
	'failure threshold delays initial alert' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$manager->handle( notification_page(), 105, notification_result( 'critical' ), notification_settings( 2 ) );
		assert_same( 0, count( $provider->sent ), __FUNCTION__ );
		$manager->handle( notification_page( array( 'consecutive_failures' => 1 ) ), 106, notification_result( 'critical' ), notification_settings( 2 ) );
		assert_same( 1, count( $provider->sent ), __FUNCTION__ );
		assert_same( 1, $repository->opened, __FUNCTION__ );
	},
	'failed delivery attempt is recorded as failed' => static function (): void {
		global $test_mail_result;
		$test_mail_result = false;
		$repository       = new FakeNotificationRepository();
		$manager          = new NotificationManager( $repository, array( new EmailNotificationProvider() ), 'Example Website', 'https://example.com/wp-admin/admin.php?page=site-monitor' );
		$manager->handle( notification_page(), 107, notification_result( 'critical' ), notification_settings() );
		assert_same( false, $repository->attempts[0]['delivery']->accepted, __FUNCTION__ );
		assert_same( false, $repository->lastAccepted, __FUNCTION__ );
		assert_same( 'wp_mail() rejected the message.', $repository->attempts[0]['delivery']->message, __FUNCTION__ );
		$test_mail_result = true;
	},
	'admin settings are allowlisted and clamped' => static function (): void {
		$settings = Settings::sanitize(
			array(
				'frequency'               => 'every_second',
				'request_timeout'         => 999,
				'batch_size'              => 0,
				'retention_days'          => 9999,
				'alert_failure_threshold' => -2,
				'alert_repeat_hours'      => 99999,
				'notification_email'      => 'alerts@example.com',
			),
			array( 'post_types' => array( 'page', 'post' ) )
		);
		assert_same( 'daily', $settings['frequency'], __FUNCTION__ );
		assert_same( 60, $settings['request_timeout'], __FUNCTION__ );
		assert_same( 1, $settings['batch_size'], __FUNCTION__ );
		assert_same( 365, $settings['retention_days'], __FUNCTION__ );
		assert_same( 1, $settings['alert_failure_threshold'], __FUNCTION__ );
		assert_same( 8760, $settings['alert_repeat_hours'], __FUNCTION__ );
		assert_same( array( 'page', 'post' ), $settings['post_types'], __FUNCTION__ );
	},
	'REST status requires manage options' => static function (): void {
		global $test_current_user_can;
		$controller = new AdminStatusController();
		$test_current_user_can = false;
		assert_same( false, $controller->permissions(), __FUNCTION__ );
		$test_current_user_can = true;
		assert_same( true, $controller->permissions(), __FUNCTION__ );
	},
	'REST status registers a protected GET route' => static function (): void {
		global $test_actions, $test_rest_routes;
		$test_actions     = array();
		$test_rest_routes = array();
		$controller       = new AdminStatusController();
		$controller->register();
		assert_same( 1, count( $test_actions['rest_api_init'] ?? array() ), __FUNCTION__ );
		$controller->registerRoutes();
		$route = $test_rest_routes['site-monitor/v1/status'] ?? array();
		assert_same( 'GET', $route['methods'] ?? null, __FUNCTION__ );
		assert_same( array( $controller, 'permissions' ), $route['permission_callback'] ?? null, __FUNCTION__ );
	},
	'invalid notification email is rejected by normalization' => static function (): void {
		$settings = Settings::sanitize( array( 'notification_email' => 'not-an-email' ) );
		assert_same( '', $settings['notification_email'], __FUNCTION__ );
	},
	'non-HTTP URL is rejected' => static function (): void {
		try {
			( new SameSiteUrlGuard() )->assertAllowed( 'ftp://example.com/file' );
			throw new RuntimeException( 'Non-HTTP URL was accepted.' );
		} catch ( SiteMonitor\Monitoring\Exception\UnsafeUrlException $error ) {
			assert_same( 'Only absolute HTTP(S) URLs are allowed.', $error->getMessage(), __FUNCTION__ );
		}
	},
	'nonstandard URL port is rejected' => static function (): void {
		try {
			( new SameSiteUrlGuard() )->assertAllowed( 'https://example.com:444/' );
			throw new RuntimeException( 'Blocked port was accepted.' );
		} catch ( SiteMonitor\Monitoring\Exception\UnsafeUrlException $error ) {
			assert_same( 'The URL uses a blocked port.', $error->getMessage(), __FUNCTION__ );
		}
	},
	'redirect limit is enforced' => static function (): void {
		$redirects = array_fill( 0, 6, response( 301, '', array( 'location' => '/again' ) ) );
		$result = checker( $redirects )->check( 'https://example.com/' );
		assert_same( 'too_many_redirects', $result->errorCode, __FUNCTION__ );
	},
	'repeat alerts can be disabled' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$page = notification_page( array( 'notification_state' => 'open', 'consecutive_failures' => 4, 'last_notification_attempt_at' => '2026-09-01 10:00:00' ) );
		$manager->handle( $page, 108, notification_result( 'critical' ), notification_settings( 1, 0 ) );
		assert_same( 0, count( $provider->sent ), __FUNCTION__ );
		assert_same( 0, count( $repository->attempts ), __FUNCTION__ );
	},
	'incident check frequency is independent of email repeats' => static function (): void {
		$settings = Settings::sanitize( array( 'incident_check_minutes' => 5, 'alert_repeat_hours' => 48 ) );
		assert_same( 5, $settings['incident_check_minutes'], __FUNCTION__ );
		assert_same( 48, $settings['alert_repeat_hours'], __FUNCTION__ );
		assert_same( 0, Settings::sanitize( array( 'incident_check_minutes' => 7 ) )['incident_check_minutes'], __FUNCTION__ );
		assert_same( 0, Settings::defaults()['incident_check_minutes'], __FUNCTION__ );
	},
	'incident schedule changes and can be disabled without altering regular scans' => static function (): void {
		global $test_options, $test_cron_events;
		$test_cron_events = array();
		$scheduler = new \SiteMonitor\Core\Scheduler();
		$test_options['site_monitor_settings'] = array( 'frequency' => 'daily', 'incident_check_minutes' => 5 );
		$scheduler->ensureScheduled();
		assert_same( 'site_monitor_incident_5', wp_get_scheduled_event( $scheduler::INCIDENT_HOOK )->schedule, __FUNCTION__ );
		assert_same( 300, $scheduler->addSchedules( array() )['site_monitor_incident_5']['interval'], __FUNCTION__ );
		$regular_event = wp_get_scheduled_event( $scheduler::SCAN_HOOK );
		$scheduler->ensureScheduled();
		assert_same( 3, count( $test_cron_events ), __FUNCTION__ );
		$test_options['site_monitor_settings']['incident_check_minutes'] = 15;
		$scheduler->ensureScheduled();
		assert_same( 'site_monitor_incident_15', wp_get_scheduled_event( $scheduler::INCIDENT_HOOK )->schedule, __FUNCTION__ );
		$test_options['site_monitor_settings']['incident_check_minutes'] = 0;
		$scheduler->ensureScheduled();
		assert_same( false, wp_get_scheduled_event( $scheduler::INCIDENT_HOOK ), __FUNCTION__ );
		assert_same( $regular_event, wp_get_scheduled_event( $scheduler::SCAN_HOOK ), __FUNCTION__ );
	},
	'warning does not resolve an open incident' => static function (): void {
		[ $manager, $repository, $provider ] = notification_manager();
		$page = notification_page( array( 'notification_state' => 'open', 'incident_started_at' => '2026-09-17 08:00:00' ) );
		$manager->handle( $page, 109, notification_result( 'warning' ), notification_settings() );
		assert_same( 0, count( $provider->sent ), __FUNCTION__ );
		assert_same( 0, $repository->resolved, __FUNCTION__ );
	},
);

$passed = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		++$passed;
		echo "PASS {$name}\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "FAIL {$name}: {$error->getMessage()}\n" );
	}
}

echo sprintf( "%d/%d tests passed.\n", $passed, count( $tests ) );
exit( $passed === count( $tests ) ? 0 : 1 );
