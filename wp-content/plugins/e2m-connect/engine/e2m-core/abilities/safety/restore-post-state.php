<?php
/**
 * E2M Connect MCP - Restore Post State.
 *
 * Writes a previously captured snapshot back into the post's content and
 * builder meta. The restore itself is destructive (overwrites current
 * state) and therefore triggers a fresh snapshot *before* the restore
 * lands - so the admin can always undo an undo.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/restore-post-state', [
	'label'       => __( '[Safety] Restore Post State', 'e2mconnect' ),
	'description' => 'Restores a post\'s content + builder meta from a previous snapshot. A new snapshot of the current state is captured first so the restore itself is reversible.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'snapshot_id' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'post_id', 'snapshot_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'          => [ 'type' => 'integer' ],
			'restored_from'    => [ 'type' => 'string' ],
			'rollback_snapshot'=> [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_restore_post_state_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Restore Post State',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_restore_post_state_ability( array $input ) {
	$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$snapshot_id = isset( $input['snapshot_id'] ) ? (string) $input['snapshot_id'] : '';

	if ( $post_id <= 0 || $snapshot_id === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and snapshot_id are required.', 'e2mconnect' ) );
	}
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	// The gatekeeper already captured a pre-write snapshot before this
	// ability was dispatched (destructive + post_id present). We just
	// discover its ID below so callers know where the rollback lives.
	$result = E2M_Meta_Snapshot::restore( $post_id, $snapshot_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$rollback_id = '';
	foreach ( E2M_Meta_Snapshot::list( $post_id ) as $entry ) {
		if ( strpos( (string) ( $entry['reason'] ?? '' ), 'e2m/restore-post-state' ) !== false ) {
			$rollback_id = (string) $entry['id'];
			break; // list() returns newest-first, so this is the right one
		}
	}

	return [
		'post_id'           => $post_id,
		'restored_from'     => $snapshot_id,
		'rollback_snapshot' => $rollback_id,
	];
}
