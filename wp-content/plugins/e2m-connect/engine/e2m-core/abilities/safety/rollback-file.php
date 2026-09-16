<?php
/**
 * E2M Connect MCP - Rollback File.
 *
 * Restores a single file to an earlier backup taken before an agent write.
 * Either pass a specific backup_id (from list-backups / a write ability's
 * response) or a target path to roll back to its most recent backup.
 *
 * The rollback is itself reversible: the file's current state is snapshotted
 * first and its undo id is returned.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/rollback-file', [
	'label'       => __( '[Safety] Rollback File', 'e2mconnect' ),
	'description' => 'Restores a file to an earlier pre-write backup. Pass backup_id for an exact point, or path to roll back to its most recent backup. Returns an undo_id so the rollback can itself be reverted.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'backup_id' => [ 'type' => 'string', 'description' => 'Exact backup record id to restore.' ],
			'path'      => [ 'type' => 'string', 'description' => 'Absolute file path; restores its most recent backup when backup_id is omitted.' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'restored' => [ 'type' => 'boolean' ],
			'target'   => [ 'type' => 'string' ],
			'undo_id'  => [ 'type' => 'string', 'description' => 'Backup id of the file state captured just before this rollback.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_rollback_file_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Rollback File',
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
function e2m_engine_rollback_file_ability( array $input ) {
	if ( ! class_exists( 'E2M_File_Backup' ) || ! class_exists( 'E2M_Backup_Store' ) ) {
		return new WP_Error( 'e2m_backup_unavailable', __( 'Backup subsystem is not available.', 'e2mconnect' ) );
	}

	$backup_id = isset( $input['backup_id'] ) ? (string) $input['backup_id'] : '';
	$path      = isset( $input['path'] ) ? (string) $input['path'] : '';

	if ( $backup_id === '' && $path === '' ) {
		return new WP_Error( 'e2m_backup_arg_missing', __( 'Provide either backup_id or path.', 'e2mconnect' ) );
	}

	// Resolve "latest for a path" to a concrete backup id.
	if ( $backup_id === '' ) {
		$resolved = function_exists( 'e2m_engine_resolve_path' ) ? e2m_engine_resolve_path( $path, require_real: false ) : $path;
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$rows = E2M_File_Backup::list_for_path( (string) $resolved );
		if ( empty( $rows ) ) {
			return new WP_Error( 'e2m_backup_none_for_path', __( 'No backups found for that path.', 'e2mconnect' ) );
		}
		$backup_id = (string) ( $rows[0]['id'] ?? '' );
	}

	return E2M_File_Backup::rollback( $backup_id );
}
