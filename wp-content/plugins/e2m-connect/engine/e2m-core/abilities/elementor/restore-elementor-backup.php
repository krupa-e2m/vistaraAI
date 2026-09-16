<?php
/**
 * E2M Connect MCP - Restore Elementor Backup.
 *
 * Restores a page's Elementor state from a file export taken by
 * e2m/backup-elementor-page or the gatekeeper. Reversible: the page's current
 * state is exported first and its undo_id returned.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/restore-elementor-backup', [
	'label'       => __( '[Elementor] Restore Backup', 'e2mconnect' ),
	'description' => 'Restores a page\'s Elementor data from a file backup (by backup_id). Snapshots the current state first and returns an undo_id.',
	'category'    => 'e2m-elementor',

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
			'restored' => [ 'type' => 'boolean' ],
			'post_id'  => [ 'type' => 'integer' ],
			'undo_id'  => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_restore_elementor_backup_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Restore Elementor Backup',
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
function e2m_engine_restore_elementor_backup_ability( array $input ) {
	if ( ! class_exists( 'E2M_Elementor_Backup' ) ) {
		return new WP_Error( 'e2m_backup_unavailable', __( 'Backup subsystem is not available.', 'e2mconnect' ) );
	}
	$backup_id = isset( $input['backup_id'] ) ? (string) $input['backup_id'] : '';
	if ( $backup_id === '' ) {
		return new WP_Error( 'e2m_backup_arg_missing', __( 'backup_id is required.', 'e2mconnect' ) );
	}
	return E2M_Elementor_Backup::restore_export( $backup_id );
}
