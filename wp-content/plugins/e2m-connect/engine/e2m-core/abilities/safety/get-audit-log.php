<?php
/**
 * E2M Connect MCP - Get Audit Log.
 *
 * Returns a paginated slice of the MCP audit log. Intentionally separate
 * from analytics: analytics can be disabled and has an opinionated
 * retention story; the audit log is the operator's immutable record of
 * every destructive call and exists regardless of analytics toggles.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-audit-log', [
	'label'       => __( '[Safety] Audit Log', 'e2mconnect' ),
	'description' => 'Returns a paginated slice of the destructive-op audit log with filtering by ability, user, post, success, and time range.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'ability_name' => [ 'type' => 'string' ],
			'user_id'      => [ 'type' => 'integer', 'minimum' => 0 ],
			'post_id'      => [ 'type' => 'integer', 'minimum' => 0 ],
			'success'      => [ 'type' => 'boolean' ],
			'from'         => [ 'type' => 'string', 'description' => 'ISO 8601 lower bound on timestamp_gmt.' ],
			'to'           => [ 'type' => 'string', 'description' => 'ISO 8601 upper bound on timestamp_gmt.' ],
			'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ],
			'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
		],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'entries'  => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_audit_log_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Audit Log',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-audit-log ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_get_audit_log_ability( array $input ): array {
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 500, (int) $input['per_page'] ) ) : 50;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$filters = [];
	foreach ( [ 'ability_name', 'user_id', 'post_id', 'success', 'from', 'to' ] as $key ) {
		if ( array_key_exists( $key, $input ) ) {
			$filters[ $key ] = $input[ $key ];
		}
	}

	return [
		'total'    => E2M_Audit_Log::count( $filters ),
		'page'     => $page,
		'per_page' => $per_page,
		'entries'  => E2M_Audit_Log::fetch( $filters, $per_page, ( $page - 1 ) * $per_page ),
	];
}
