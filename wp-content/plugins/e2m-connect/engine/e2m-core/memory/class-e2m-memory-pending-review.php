<?php
/**
 * E2M Connect — Memory Pending Review.
 *
 * Memories saved at or above the auto-save threshold (Strict 0.80,
 * Loose 0.40, Custom configurable) but without explicit user approval
 * land in status=pending_review. The user sees:
 *
 *   - An inline prompt on next session start ("5 memories pending,
 *     review them now?") delivered by the AI via the agent layer
 *   - A persistent badge in the WP admin page (Step 14 React UI)
 *
 * If the user takes no action within AUTO_PROMOTE_DAYS (7), the daily
 * cron promotes the memory to active. This keeps the queue from
 * stagnating while still giving the user a chance to reject.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Pending_Review {

	public const AUTO_PROMOTE_DAYS = 7;
	public const CRON_HOOK         = 'e2m_memory_pending_promote';

	public static function register(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run_auto_promote_scan' ] );
	}

	/**
	 * Count pending-review memories, optionally narrowed to one author.
	 * Used by the React admin badge and the inline session-start prompt.
	 */
	public static function count_pending( int $user_id = 0 ): int {
		$filters = [ 'status' => 'pending_review' ];
		if ( $user_id > 0 ) {
			$filters['author_id'] = $user_id;
		}
		return E2M_Memory_Repository::count( $filters );
	}

	/**
	 * Return up to $limit pending-review memories, optionally narrowed
	 * to one author. Caller is the React admin "review queue" view.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function list_pending( int $user_id = 0, int $limit = 20 ): array {
		$filters = [ 'status' => 'pending_review' ];
		if ( $user_id > 0 ) {
			$filters['author_id'] = $user_id;
		}
		$result = E2M_Memory_Repository::list( $filters, 1, $limit );
		return $result['memories'];
	}

	/**
	 * Promote (approve=true -> active) or reject (approve=false ->
	 * archived) a single pending-review memory.
	 */
	public static function promote_one( int $memory_id, bool $approve ): bool|WP_Error {
		$memory = E2M_Memory_Repository::get( $memory_id );
		if ( $memory === null ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}
		if ( $memory['status'] !== 'pending_review' ) {
			return new WP_Error( 'not_pending', __( 'Memory is not in pending review.', 'e2mconnect' ), [ 'status' => 409 ] );
		}
		return E2M_Memory_Repository::set_status( $memory_id, $approve ? 'active' : 'archived' );
	}

	/**
	 * Cron handler — promotes any pending_review memory older than
	 * AUTO_PROMOTE_DAYS. Returns count promoted for hookable observability.
	 */
	public static function run_auto_promote_scan(): int {
		if ( function_exists( 'e2m_memory_is_enabled' ) && ! e2m_memory_is_enabled() ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::AUTO_PROMOTE_DAYS * DAY_IN_SECONDS );

		global $wpdb;
		if ( ! E2M_Memory_Index_Table::exists() ) {
			return 0;
		}
		$table = E2M_Memory_Index_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT memory_id FROM {$table} WHERE status = %s AND updated_at < %s",
				'pending_review',
				$cutoff
			)
		);

		$promoted = 0;
		foreach ( $ids as $id ) {
			$result = E2M_Memory_Repository::set_status( (int) $id, 'active' );
			if ( $result === true ) {
				$promoted++;
			}
		}

		/**
		 * Fires after the auto-promote scan completes.
		 *
		 * @param int $promoted Number of memories promoted to active.
		 */
		do_action( 'e2m_memory_pending_promote_complete', $promoted );

		return $promoted;
	}
}
