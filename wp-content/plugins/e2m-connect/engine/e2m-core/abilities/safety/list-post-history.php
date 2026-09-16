<?php
/**
 * E2M Connect MCP - List Post History.
 *
 * Returns the ordered snapshot trail for a post. Snapshots are captured
 * automatically by the safety gatekeeper before any destructive MCP
 * write that touches the post's builder meta, bounded by the retention
 * count in settings (default 10). Newest first.
 *
 * Paired with get-post-state, diff-post-states, and restore-post-state.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-post-history', [
	'label'       => __( '[Safety] Post History', 'e2mconnect' ),
	'description' => 'Returns the ordered pre-write snapshot trail for a post (newest first).',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'snapshots' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'         => [ 'type' => 'string' ],
						'created_at' => [ 'type' => 'string' ],
						'user_id'    => [ 'type' => 'integer' ],
						'user_login' => [ 'type' => 'string' ],
						'reason'     => [ 'type' => 'string' ],
						'size_bytes' => [ 'type' => 'integer' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_post_history_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Post History',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_post_history_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	return [
		'post_id'   => $post_id,
		'snapshots' => E2M_Meta_Snapshot::list( $post_id ),
	];
}
