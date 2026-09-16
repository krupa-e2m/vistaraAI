<?php
/**
 * E2M Connect MCP - Backup Elementor Page.
 *
 * Writes a timestamped JSON export of a page's full Elementor state to the
 * backup store on demand (the gatekeeper also does this automatically before
 * destructive Elementor writes). Restore with e2m/restore-elementor-backup.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/backup-elementor-page', [
	'label'       => __( '[Elementor] Backup Page', 'e2mconnect' ),
	'description' => 'Writes a timestamped JSON backup of a page\'s Elementor data, page settings, and content to the backup store. Returns a backup_id for later restore.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'task_id' => [ 'type' => 'string', 'description' => 'Optional id grouping backups from one logical task.' ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'backup_id' => [ 'type' => 'string' ],
			'is_elementor' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_backup_elementor_page_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Backup Elementor Page',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_backup_elementor_page_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! class_exists( 'E2M_Elementor_Backup' ) ) {
		return new WP_Error( 'e2m_backup_unavailable', __( 'Backup subsystem is not available.', 'e2mconnect' ) );
	}

	$is_elementor = E2M_Elementor_Backup::is_elementor_page( $post_id );
	$task_id      = isset( $input['task_id'] ) ? (string) $input['task_id'] : '';
	$backup_id    = $is_elementor ? E2M_Elementor_Backup::export_page( $post_id, 'manual:e2m/backup-elementor-page', $task_id ) : '';

	return [
		'post_id'      => $post_id,
		'backup_id'    => $backup_id,
		'is_elementor' => $is_elementor,
	];
}
