<?php
/**
 * E2M Connect — Memory permission callbacks.
 *
 * Memory abilities use a different capability baseline than the
 * site-management abilities elsewhere in the plugin. The existing
 * e2m_engine_permission_callback() requires manage_options (admin only);
 * memory needs to be reachable for editors and authors too, so authors
 * can save their own preferences.
 *
 * Three tiers:
 *   - e2m_memory_can_read()   -> any logged-in user, with visibility filtering
 *   - e2m_memory_can_write()  -> edit_posts minimum
 *   - e2m_memory_can_escalate_to_system() -> manage_options
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission gate for read-only memory abilities (get / list / search).
 * Returns true for any logged-in user; visibility filtering happens at
 * the repository read layer.
 */
function e2m_memory_can_read(): bool|WP_Error {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'Authentication is required to read memories.', 'e2mconnect' ),
			[ 'status' => 401 ]
		);
	}
	// Memories may carry sensitive site/brand context. Restrict to Editor-and-above
	// (Editors + Administrators on a standard WP install).
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to read memories.', 'e2mconnect' ),
			[ 'status' => 403 ]
		);
	}
	return true;
}

/**
 * Permission gate for write memory abilities (save / archive). Requires
 * edit_posts. System-visibility writes are checked separately by the
 * ability so non-admins can still save team-visibility memories.
 */
function e2m_memory_can_write(): bool|WP_Error {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'Authentication is required to save memories.', 'e2mconnect' ),
			[ 'status' => 401 ]
		);
	}
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to save memories.', 'e2mconnect' ),
			[ 'status' => 403 ]
		);
	}
	return true;
}

/**
 * Whether the current user can create or modify a system-visibility
 * memory. System visibility means the memory applies regardless of
 * which user is connected to the agent — effectively a site-wide rule —
 * so we require manage_options.
 */
function e2m_memory_can_escalate_to_system(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Permission gate for hard-delete. Admin-only and intentionally not
 * exposed as an MCP ability — only callable from the React admin via
 * REST with nonce verification.
 */
function e2m_memory_can_hard_delete(): bool|WP_Error {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'Hard delete requires administrator privileges.', 'e2mconnect' ),
			[ 'status' => 403 ]
		);
	}
	return true;
}

/**
 * Whether the current user can SEE a memory. Visibility rules:
 *   - private: only the author
 *   - team:    any logged-in user
 *   - system:  any logged-in user
 *
 * @param array<string,mixed> $memory Shaped memory record from the repository.
 */
function e2m_memory_user_can_see( array $memory ): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$visibility = (string) ( $memory['visibility'] ?? 'team' );
	if ( $visibility === 'team' || $visibility === 'system' ) {
		return true;
	}
	// private — author only (or admin).
	$author_id = (int) ( $memory['author_id'] ?? 0 );
	return $author_id === get_current_user_id() || current_user_can( 'manage_options' );
}

/**
 * Whether the current user can EDIT a memory. Authors edit their own;
 * admins can edit anything; team/system editing follows edit_posts
 * gating with the system-escalation check applied separately.
 */
function e2m_memory_user_can_edit( array $memory ): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	$author_id = (int) ( $memory['author_id'] ?? 0 );
	$visibility = (string) ( $memory['visibility'] ?? 'team' );
	if ( $visibility === 'private' ) {
		return $author_id === get_current_user_id();
	}
	// team or system: edit_posts permitted (system escalation enforced
	// separately during writes).
	return true;
}
