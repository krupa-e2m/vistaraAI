<?php
/**
 * E2M Connect MCP - Get Post State.
 *
 * Returns the full stored state for a specific snapshot, including the
 * raw builder meta payloads (Elementor data, Bricks content, Gutenberg
 * post_content). Agents typically call this right before restore-post-
 * state to surface a preview diff to the operator.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-post-state', [
	'label'       => __( '[Safety] Get Post State', 'e2mconnect' ),
	'description' => 'Returns the full state (post_content + builder meta) stored under a specific snapshot_id.',
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
			'id'         => [ 'type' => 'string' ],
			'created_at' => [ 'type' => 'string' ],
			'user_id'    => [ 'type' => 'integer' ],
			'user_login' => [ 'type' => 'string' ],
			'reason'     => [ 'type' => 'string' ],
			'state'      => [ 'type' => 'object', 'additionalProperties' => true ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_post_state_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Post State',
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
function e2m_engine_get_post_state_ability( array $input ) {
	$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$snapshot_id = isset( $input['snapshot_id'] ) ? (string) $input['snapshot_id'] : '';
	if ( $post_id <= 0 || $snapshot_id === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and snapshot_id are required.', 'e2mconnect' ) );
	}

	$entry = E2M_Meta_Snapshot::get( $post_id, $snapshot_id );
	if ( $entry === null ) {
		return new WP_Error( 'snapshot_not_found', __( 'Snapshot not found for this post.', 'e2mconnect' ) );
	}

	return [
		'id'         => (string) ( $entry['id'] ?? '' ),
		'created_at' => (string) ( $entry['created_at'] ?? '' ),
		'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
		'user_login' => (string) ( $entry['user_login'] ?? '' ),
		'reason'     => (string) ( $entry['reason'] ?? '' ),
		'state'      => (array) ( $entry['state'] ?? [] ),
	];
}
