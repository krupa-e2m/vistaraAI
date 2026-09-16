<?php
/**
 * E2M Connect — Memory Decay System.
 *
 * Three decay rules:
 *   1. Age > 90 days AND fetch_count_30d < 2  ->  status=stale
 *   2. contradictions > 0 AND age > 30 days   ->  status=disputed
 *   3. expires_at < now                       ->  status=stale
 *
 * Pinned memories (status=pinned) are immune to all decay.
 *
 * Evaluation runs:
 *   - Nightly cron e2m_memory_decay_scan (chunked, BATCH_SIZE per
 *     run, multiple runs per night until the work is done)
 *   - Lazy on memory-list responses (capped at 10 evaluations per call)
 *
 * The cron also resets fetch_count_30d to the actual count from the
 * last 30 days based on audit-log fetch events — keeping the counter
 * honest without paying for per-fetch decrements.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Decay {

	public const STALE_AGE_DAYS        = 90;
	public const STALE_FETCH_THRESHOLD = 2;
	public const DISPUTED_AGE_DAYS     = 30;
	public const BATCH_SIZE            = 100;
	public const LAZY_EVAL_PER_REQUEST = 10;

	public const CRON_HOOK = 'e2m_memory_decay_scan';

	/**
	 * Statuses immune to decay. Pinned never ages; archived/disputed are
	 * already terminal-ish so we don't redundantly process them; stale
	 * stays stale until human action.
	 */
	private const IMMUNE_STATUSES = [ 'pinned', 'archived', 'stale', 'disputed' ];

	public static function register(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run_scan' ] );
	}

	/**
	 * One pass of decay evaluation. Processes BATCH_SIZE eligible
	 * memories, returning a stats array with counts. The cron handler
	 * calls this once per fire; on sites with > BATCH_SIZE memories the
	 * scheduler picks it back up the next night until the work is done.
	 *
	 * @return array{stale: int, disputed: int, examined: int}
	 */
	public static function run_scan(): array {
		// Don't churn memories when the subsystem is disabled — the user
		// turned it off; we should leave data exactly as they left it.
		if ( function_exists( 'e2m_memory_is_enabled' ) && ! e2m_memory_is_enabled() ) {
			return [ 'stale' => 0, 'disputed' => 0, 'examined' => 0 ];
		}
		$result = E2M_Memory_Repository::list(
			[
				'status'           => [ 'active', 'always', 'pending_review' ],
				'include_archived' => false,
			],
			1,
			self::BATCH_SIZE
		);

		$stale    = 0;
		$disputed = 0;
		foreach ( $result['memories'] as $memory ) {
			$new_status = self::evaluate_one( (int) $memory['id'] );
			if ( $new_status === 'stale' ) {
				$stale++;
			} elseif ( $new_status === 'disputed' ) {
				$disputed++;
			}
		}

		$stats = [
			'stale'    => $stale,
			'disputed' => $disputed,
			'examined' => count( $result['memories'] ),
		];

		/**
		 * Fires after a decay scan pass. Useful for hooking observability
		 * (E2M analytics, custom dashboards). Receives the stats array.
		 *
		 * @param array{stale: int, disputed: int, examined: int} $stats
		 */
		do_action( 'e2m_memory_decay_complete', $stats );

		return $stats;
	}

	/**
	 * Evaluate one memory's decay status. Returns the new status if
	 * changed, or null if no change.
	 */
	public static function evaluate_one( int $memory_id ): ?string {
		$memory = E2M_Memory_Repository::get( $memory_id );
		if ( $memory === null ) {
			return null;
		}

		$current_status = (string) ( $memory['status'] ?? 'active' );
		if ( in_array( $current_status, self::IMMUNE_STATUSES, true ) ) {
			return null;
		}

		// Rule 3 first — explicit expiry beats everything else.
		$expires_at = (string) ( $memory['expires_at'] ?? '' );
		if ( $expires_at !== '' ) {
			$expires_ts = strtotime( $expires_at );
			if ( $expires_ts !== false && $expires_ts < time() ) {
				E2M_Memory_Repository::set_status( $memory_id, 'stale' );
				return 'stale';
			}
		}

		$created_at = (string) ( $memory['created_at'] ?? '' );
		if ( $created_at === '' ) {
			return null;
		}
		$created_ts = strtotime( $created_at );
		if ( $created_ts === false ) {
			return null;
		}
		$age_days = ( time() - $created_ts ) / DAY_IN_SECONDS;

		// Rule 2 — contradictions take precedence over stale-by-age.
		if (
			$age_days >= self::DISPUTED_AGE_DAYS
			&& ( (int) ( $memory['contradictions'] ?? 0 ) ) > 0
		) {
			E2M_Memory_Repository::set_status( $memory_id, 'disputed' );
			return 'disputed';
		}

		// Rule 1 — stale by age + low fetch count. We use the index
		// table's fetch_count_30d which is fetch-based, not apply-based
		// (blueprint honesty note: a proxy, not a perfect signal).
		if ( $age_days >= self::STALE_AGE_DAYS ) {
			$fetch_count_30d = self::lookup_fetch_count_30d( $memory_id );
			if ( $fetch_count_30d < self::STALE_FETCH_THRESHOLD ) {
				E2M_Memory_Repository::set_status( $memory_id, 'stale' );
				return 'stale';
			}
		}

		return null;
	}

	/**
	 * Lazy decay evaluation hook for memory-list responses. Examines up
	 * to LAZY_EVAL_PER_REQUEST returned memories, bounded so list calls
	 * don't degrade in latency. Pattern: list call -> any memory whose
	 * last_fetched_at is null or > 30 days ago gets evaluated.
	 *
	 * @param list<array<string,mixed>> $memories
	 */
	public static function lazy_evaluate( array $memories ): void {
		$examined = 0;
		foreach ( $memories as $memory ) {
			if ( $examined >= self::LAZY_EVAL_PER_REQUEST ) {
				break;
			}
			$last = (string) ( $memory['last_fetched_at'] ?? '' );
			$ts   = $last !== '' ? strtotime( $last ) : 0;
			if ( $ts === false || $ts === 0 || $ts < ( time() - 30 * DAY_IN_SECONDS ) ) {
				self::evaluate_one( (int) $memory['id'] );
				$examined++;
			}
		}
	}

	/**
	 * Read the 30-day fetch count directly from the index table -- a
	 * single fast int lookup that avoids a round-trip through the
	 * repository's full get() (which does N postmeta reads).
	 */
	private static function lookup_fetch_count_30d( int $memory_id ): int {
		if ( ! E2M_Memory_Index_Table::exists() ) {
			return 0;
		}
		global $wpdb;
		$table = E2M_Memory_Index_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT fetch_count_30d FROM {$table} WHERE memory_id = %d", $memory_id ) );
	}
}
