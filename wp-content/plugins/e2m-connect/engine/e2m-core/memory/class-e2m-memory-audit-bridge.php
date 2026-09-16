<?php
/**
 * E2M Connect — Memory ↔ Audit Log bridge.
 *
 * Writes audit-log entries for every memory mutation (save / archive /
 * apply / fetch) and links them back to the memory record via the
 * `source_audit_id` meta field. Pinning a memory pins its source audit
 * entry so the daily pruner leaves it alone — that's what makes
 * "receipt" click-throughs in the admin UI work months after the fact.
 *
 * The audit log itself uses SHA-256 hashes (not raw content) for the
 * input payload, so saving a memory never leaks the content into the
 * log — matches the existing safety-pattern.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Audit_Bridge {

	/**
	 * Statuses whose linked audit entries are pinned (immune to pruning).
	 * Archived / stale memories let the pruner do its job.
	 */
	private const PIN_STATUSES = [ 'active', 'always', 'pinned' ];

	/**
	 * Record a save (create or update) of a memory and return the audit
	 * entry's id so the caller can persist it as the memory's
	 * source_audit_id. The input is hashed by E2M_Audit_Log so we don't
	 * leak content into the log.
	 *
	 * @param array<string,mixed> $payload Sanitized input that produced the save.
	 * @return int Audit-log entry id, or 0 on failure.
	 */
	public static function record_save( int $memory_id, array $payload, bool $was_create ): int {
		$type       = (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_TYPE, true );
		$status     = (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_STATUS, true );
		$visibility = (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_VISIBILITY, true );

		E2M_Audit_Log::record(
			[
				'ability_name' => $was_create ? 'e2m/memory-save' : 'e2m/memory-update',
				'input'        => $payload,
				'http_method'  => self::current_method(),
				'post_id'      => $memory_id,
				'success'      => true,
				'metadata'     => [
					'memory_id'            => $memory_id,
					'memory_type'          => $type,
					'memory_status'        => $status,
					'memory_visibility'    => $visibility,
					'operation'            => $was_create ? 'create' : 'update',
					'referenced_by_memory' => in_array( $status, self::PIN_STATUSES, true ),
				],
			]
		);

		return E2M_Audit_Log::last_insert_id();
	}

	/**
	 * Record an archive event (soft delete).
	 */
	public static function record_archive( int $memory_id ): int {
		E2M_Audit_Log::record(
			[
				'ability_name' => 'e2m/memory-archive',
				'http_method'  => self::current_method(),
				'post_id'      => $memory_id,
				'success'      => true,
				'metadata'     => [
					'memory_id'            => $memory_id,
					'operation'            => 'archive',
					// Archive frees the source audit entry from the pin.
					'referenced_by_memory' => false,
				],
			]
		);
		return E2M_Audit_Log::last_insert_id();
	}

	/**
	 * Record a hard-delete event. Always audited — even though the memory
	 * itself is gone, the deletion record remains for forensic review.
	 */
	public static function record_hard_delete( int $memory_id, array $snapshot = [] ): int {
		E2M_Audit_Log::record(
			[
				'ability_name' => 'e2m/memory-hard-delete',
				'http_method'  => self::current_method(),
				'post_id'      => $memory_id,
				'success'      => true,
				'metadata'     => array_merge(
					$snapshot,
					[
						'memory_id'            => $memory_id,
						'operation'            => 'hard_delete',
						'referenced_by_memory' => false,
					]
				),
			]
		);
		return E2M_Audit_Log::last_insert_id();
	}

	/**
	 * Record a fetch — the AI looked at a memory. Used for decay signal.
	 * Kept cheap because it fires on every memory-get / memory-search call.
	 */
	public static function record_fetch( int $memory_id ): int {
		E2M_Audit_Log::record(
			[
				'ability_name' => 'e2m/memory-fetch',
				'http_method'  => self::current_method(),
				'post_id'      => $memory_id,
				'success'      => true,
				'metadata'     => [
					'memory_id' => $memory_id,
					'operation' => 'fetch',
				],
			]
		);
		return E2M_Audit_Log::last_insert_id();
	}

	/**
	 * Apply pin state on the audit entry referenced by a memory whose
	 * status just changed. Called by the repository's set_status() path.
	 */
	public static function reconcile_pin( int $memory_id ): void {
		$audit_id = (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, true );
		if ( $audit_id <= 0 ) {
			return;
		}
		$status = (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_STATUS, true );
		if ( in_array( $status, self::PIN_STATUSES, true ) ) {
			E2M_Audit_Log::pin_entry( $audit_id );
		} else {
			E2M_Audit_Log::unpin_entry( $audit_id );
		}
	}

	/**
	 * Best-effort HTTP method for the current request. Matches the
	 * existing audit-log pattern in record() callers across the plugin.
	 */
	private static function current_method(): string {
		$method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
		return strtoupper( sanitize_text_field( $method ) );
	}
}
