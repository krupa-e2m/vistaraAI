<?php
/**
 * E2M Connect — Memory subsystem bootstrap.
 *
 * Loads the memory CPT + index-table classes and wires the registration
 * hook. Loaded from e2m-core/plugin_loader.php::load_core_files() so
 * the post type exists before abilities register on the `init` action.
 *
 * Repository, validator, confidence, audit-bridge, and ability files load
 * in later steps. This bootstrap is intentionally minimal to keep first-
 * load cost near zero — the heavy work is deferred to REST and admin.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────
// Master subsystem flag. When disabled:
//   - MCP abilities don't register (gated in load_memory_abilities)
//   - Discover-abilities context injector early-returns
//   - Cron handlers no-op so memories don't decay or auto-promote
//   - Admin tab still loads (so the user can re-enable + browse data)
//   - CPT + index table + repository stay available so existing data
//     remains accessible
//
// Default: enabled. Stored in its own option (autoload=yes; checked on
// most loads).
// ─────────────────────────────────────────────────────────────────────
const E2M_MEMORY_ENABLED_OPTION = 'e2m_memory_enabled';

function e2m_memory_is_enabled(): bool {
	$value = get_option( E2M_MEMORY_ENABLED_OPTION, '1' );
	// Stored as '1' / '0' string so update_option doesn't short-circuit
	// when the existing value is the same boolean. Coerce defensively.
	if ( is_bool( $value ) ) {
		return $value;
	}
	return $value !== '0' && $value !== '' && $value !== false;
}

function e2m_memory_set_enabled( bool $enabled ): void {
	update_option( E2M_MEMORY_ENABLED_OPTION, $enabled ? '1' : '0', true );
}

require_once __DIR__ . '/class-e2m-memory-cpt.php';
require_once __DIR__ . '/class-e2m-memory-index-table.php';
require_once __DIR__ . '/class-e2m-memory-permissions.php';
require_once __DIR__ . '/class-e2m-memory-repository.php';
require_once __DIR__ . '/class-e2m-memory-validator.php';
require_once __DIR__ . '/class-e2m-memory-confidence.php';
require_once __DIR__ . '/class-e2m-memory-similarity.php';
require_once __DIR__ . '/class-e2m-memory-settings.php';
require_once __DIR__ . '/class-e2m-memory-rest.php';
require_once __DIR__ . '/class-e2m-memory-context-injector.php';
require_once __DIR__ . '/class-e2m-memory-decay.php';
require_once __DIR__ . '/class-e2m-memory-pending-review.php';
require_once __DIR__ . '/class-e2m-memory-caps.php';
require_once __DIR__ . '/class-e2m-memory-conflicts.php';
require_once __DIR__ . '/class-e2m-memory-seeds.php';

// Admin page (PHP-rendered Memory tab). Loaded eagerly so the
// admin_init POST handler can wire itself before the request runs;
// the file is small + lazy on its hot paths so the cost is negligible
// on non-admin requests.
if ( is_admin() ) {
	require_once __DIR__ . '/admin/memory-page.php';
	add_action( 'admin_init', 'e2m_memory_admin_handle_post' );
}

// REST routes register on rest_api_init; safe to call on every request,
// the callback adds nothing if rest_api_init already fired.
E2M_Memory_REST::register();

// Memory context injector hooks the e2m_engine_server_instructions filter
// at priorities 5 (prepend always-block) and 20 (append slug index).
E2M_Memory_Context_Injector::register();

// Decay cron handler. The cron itself is scheduled on activation in
// e2m-os.php; this registers the action callback so the event
// actually fires.
E2M_Memory_Decay::register();

// Pending-review auto-promote cron. Same pattern: hook registered here,
// scheduled in e2m-os.php on activation.
E2M_Memory_Pending_Review::register();

/**
 * Register the CPT on init priority 5 — abilities and admin code register
 * on later priorities, so they can safely assume the post type exists.
 */
add_action( 'init', [ 'E2M_Memory_CPT', 'register' ], 5 );

/**
 * Keep the denormalized index in sync with the CPT. Any write through
 * E2M_Memory_Repository goes through wp_insert_post / wp_update_post,
 * which fires save_post_e2m_memory; raw external writes that bypass
 * the repository still get indexed correctly via the same hook.
 */
add_action(
	'save_post_' . E2M_Memory_CPT::POST_TYPE,
	static function ( int $post_id ): void {
		E2M_Memory_Repository::sync_index( $post_id );
	},
	10,
	1
);

/**
 * Drop the index row when a memory is hard-deleted. before_delete_post
 * fires for all post types — the repository method itself checks the
 * post type so non-memory deletions are no-ops.
 */
add_action(
	'before_delete_post',
	static function ( int $post_id ): void {
		E2M_Memory_Repository::delete_index_row( $post_id );
	},
	10,
	1
);

/**
 * Self-heal: if the index table is somehow missing on an admin load
 * (manual code-activate, restored DB without our table, etc.), create it
 * on the next admin pageview. Cheap check — single SHOW TABLES query.
 */
add_action(
	'admin_init',
	static function (): void {
		if ( ! E2M_Memory_Index_Table::exists() ) {
			E2M_Memory_Index_Table::maybe_create_table();
		}
	}
);
