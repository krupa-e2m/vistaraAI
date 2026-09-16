<?php
/**
 * E2M Connect MCP - Restore Elementor Revision.
 *
 * Restores a page's Elementor data from one of its native WordPress
 * revisions. Reversible: the current state is exported first and its undo_id
 * returned, and Elementor's CSS cache is flushed.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/restore-elementor-revision', [
	'label'       => __( '[Elementor] Restore Revision', 'e2mconnect' ),
	'description' => 'Restores a page\'s Elementor data from one of its native WordPress revisions (by revision_id). Snapshots the current state first and returns an undo_id.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'revision_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'post_id', 'revision_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'restored'    => [ 'type' => 'boolean' ],
			'post_id'     => [ 'type' => 'integer' ],
			'revision_id' => [ 'type' => 'integer' ],
			'undo_id'     => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_restore_elementor_revision_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Restore Elementor Revision',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_restore_elementor_revision_ability( array $input ) {
	if ( ! class_exists( 'E2M_Elementor_Backup' ) ) {
		return new WP_Error( 'e2m_backup_unavailable', __( 'Backup subsystem is not available.', 'e2mconnect' ) );
	}
	$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$revision_id = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
	if ( $post_id <= 0 || $revision_id <= 0 ) {
		return new WP_Error( 'e2m_backup_arg_missing', __( 'post_id and revision_id are required.', 'e2mconnect' ) );
	}
	return E2M_Elementor_Backup::restore_revision( $post_id, $revision_id );
}
