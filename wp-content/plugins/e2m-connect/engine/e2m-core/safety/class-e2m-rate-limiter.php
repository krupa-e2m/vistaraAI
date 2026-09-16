<?php
/**
 * E2M Connect MCP - Rate Limiter.
 *
 * Token-bucket rate limiter keyed on (user_id, client_ip). Every ability
 * call decrements the matching bucket by 1. When the bucket is empty the
 * request is rejected with a WP_Error. Buckets refill by the configured
 * ops-per-minute rate, evaluated lazily on the next call.
 *
 * Storage: WordPress transients (one per key). Cheap to read/write, and
 * autoloads nothing on normal page loads because we only touch them from
 * MCP routes.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Rate_Limiter {

	/**
	 * Check whether the current caller can perform an operation right
	 * now. On success, decrement the bucket. On failure return WP_Error.
	 *
	 * @return true|WP_Error
	 */
	public static function check(): bool|WP_Error {
		$settings = e2m_engine_get_settings();
		$cap      = (int) ( $settings['rate_limit_ops_per_min'] ?? 60 );
		// 0 disables the limiter (useful in CI / dev where suites legitimately
		// exceed any production cap). Otherwise it is always on.
		if ( $cap <= 0 ) {
			return true;
		}

		$key   = self::bucket_key();
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = [ 'tokens' => $cap, 'updated_at' => time() ];
		}

		// Lazy refill: compute how many tokens have regenerated since the
		// last call and clamp to the cap.
		$elapsed     = max( 0, time() - (int) ( $state['updated_at'] ?? time() ) );
		$regen       = (int) floor( $elapsed * ( $cap / 60 ) );
		$tokens_now  = min( $cap, (int) $state['tokens'] + $regen );

		if ( $tokens_now < 1 ) {
			return new WP_Error(
				'e2m_rate_limit_exceeded',
				sprintf(
					/* translators: %1$d rate cap, %2$d retry seconds. */
					__( 'Rate limit exceeded (%1$d ops/min). Retry in %2$d seconds.', 'e2mconnect' ),
					$cap,
					self::seconds_until_refill( $cap )
				),
				[ 'status' => 429, 'rate_limit_cap' => $cap ]
			);
		}

		set_transient(
			$key,
			[
				'tokens'     => $tokens_now - 1,
				'updated_at' => time(),
			],
			5 * MINUTE_IN_SECONDS
		);

		return true;
	}

	/**
	 * Compute a stable per-caller bucket key. Prefers user ID, falls
	 * back to client IP for unauthenticated contexts (never happens in
	 * the current flow since MCP requires auth, but kept defensive).
	 */
	private static function bucket_key(): string {
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			return 'e2m_rl_user_' . $uid;
		}
		$ip = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
		return 'e2m_rl_ip_' . md5( $ip );
	}

	/**
	 * Approximate seconds until one token regenerates at the current cap.
	 */
	private static function seconds_until_refill( int $cap ): int {
		return $cap > 0 ? (int) ceil( 60 / $cap ) : 60;
	}
}
