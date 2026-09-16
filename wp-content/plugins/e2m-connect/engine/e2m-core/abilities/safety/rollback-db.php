<?php
/**
 * E2M Connect MCP - Rollback Database Backup.
 *
 * Restores a scoped database backup (taken automatically before a
 * content/DB-affecting task, or manually) to its exact point-in-time row-set.
 * Reversible: the current scoped state is captured first and its undo_id
 * returned. Find ids with e2m/list-backups (type=db).
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/rollback-db', [
	'label'       => __( '[Safety] Rollback DB Backup', 'e2mconnect' ),
	'description' => 'Restores a scoped database backup (post+meta, options, term+meta, or user+meta) by backup_id. Reverts changed values and removes rows added after the backup. Returns an undo_id.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'backup_id' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'backup_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'restored'  => [ 'type' => 'boolean' ],
			'row_count' => [ 'type' => 'integer' ],
			'undo_id'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_rollback_db_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Rollback DB Backup',
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
function e2m_engine_rollback_db_ability( array $input ) {
	if ( ! class_exists( 'E2M_DB_Backup' ) ) {
		return new WP_Error( 'e2m_backup_unavailable', __( 'Backup subsystem is not available.', 'e2mconnect' ) );
	}
	$backup_id = isset( $input['backup_id'] ) ? (string) $input['backup_id'] : '';
	if ( $backup_id === '' ) {
		return new WP_Error( 'e2m_backup_arg_missing', __( 'backup_id is required.', 'e2mconnect' ) );
	}
	return E2M_DB_Backup::restore( $backup_id );
}
