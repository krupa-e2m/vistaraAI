<?php
/**
 * E2M Connect MCP - Clear Audit Log.
 *
 * Truncates the audit log table. The act of truncating IS itself logged
 * (after the truncate) so the next slice of the log starts with a single
 * "log was cleared" entry for accountability. Restricted to manage_options
 * via the standard permission_callback - admins are trusted with this.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/clear-audit-log', [
	'label'       => __( '[Safety] Clear Audit Log', 'e2mconnect' ),
	'description' => 'Truncates the audit log. The truncate itself is recorded so the log starts with a "cleared" entry.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'                 => 'object',
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'deleted_rows' => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_clear_audit_log_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Clear Audit Log',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_clear_audit_log_ability( array $input ) {
	$deleted = E2M_Audit_Log::truncate();

	// Log the truncate AFTER it happens so the next slice of the log
	// starts with a single "log was cleared" entry for accountability.
	E2M_Audit_Log::record(
		[
			'ability_name' => 'e2m/clear-audit-log',
			'input'        => [],
			'http_method'  => 'POST',
			'success'      => true,
			'metadata'     => [ 'deleted_rows' => $deleted ],
		]
	);

	return [ 'deleted_rows' => $deleted ];
}
