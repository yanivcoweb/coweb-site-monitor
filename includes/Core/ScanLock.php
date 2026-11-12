<?php
declare(strict_types=1);

namespace SiteMonitor\Core;

use Closure;

final class ScanLock {
	private const OPTION = 'site_monitor_scan_lock';

	private ?Closure $clock;

	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock;
	}

	public function acquire( int $ttl = 900 ): ?string {
		$now      = $this->now();
		$existing = get_option( self::OPTION, null );

		if ( is_array( $existing ) && (int) ( $existing['expires_at'] ?? 0 ) > $now ) {
			return null;
		}
		if ( false !== $existing && null !== $existing ) {
			delete_option( self::OPTION );
		}

		$token = wp_generate_uuid4();
		$lock  = array( 'token' => $token, 'expires_at' => $now + max( 60, $ttl ), 'acquired_at' => $now );

		return add_option( self::OPTION, $lock, '', false ) ? $token : null;
	}

	public function refresh( string $token, int $ttl = 900 ): bool {
		$lock = get_option( self::OPTION, null );
		if (
			! is_array( $lock )
			|| (int) ( $lock['expires_at'] ?? 0 ) <= $this->now()
			|| ! hash_equals( (string) ( $lock['token'] ?? '' ), $token )
		) {
			return false;
		}

		$lock['expires_at'] = $this->now() + max( 60, $ttl );

		update_option( self::OPTION, $lock, false );

		return true;
	}

	public function release( string $token ): bool {
		$lock = get_option( self::OPTION, null );
		if ( ! is_array( $lock ) || ! hash_equals( (string) ( $lock['token'] ?? '' ), $token ) ) {
			return false;
		}

		return delete_option( self::OPTION );
	}

	/** @return array<string,mixed>|null */
	public function current(): ?array {
		$lock = get_option( self::OPTION, null );

		return is_array( $lock ) ? $lock : null;
	}

	private function now(): int {
		return $this->clock ? (int) ( $this->clock )() : time();
	}
}
