<?php
/**
 * E2M Connect MCP - Diff Post States.
 *
 * Compares two snapshots on the same post and returns a key-level map
 * of what changed. Intended for admin review workflows: "here's what
 * the agent changed between snapshot A and snapshot B, approve or
 * reject". Keys that are identical are omitted.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/diff-post-states', [
	'label'       => __( '[Safety] Diff Post States', 'e2mconnect' ),
	'description' => 'Compares two snapshots on the same post and returns a key-level map of differences.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'from_id' => [ 'type' => 'string', 'minLength' => 1 ],
			'to_id'   => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'post_id', 'from_id', 'to_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'from_id' => [ 'type' => 'string' ],
			'to_id'   => [ 'type' => 'string' ],
			'changed_keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'diff'    => [ 'type' => 'object', 'additionalProperties' => true ],
		],
	],

	'execute_callback'    => 'e2m_engine_diff_post_states_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Diff Post States',
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
function e2m_engine_diff_post_states_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$from    = isset( $input['from_id'] ) ? (string) $input['from_id'] : '';
	$to      = isset( $input['to_id'] ) ? (string) $input['to_id'] : '';

	if ( $post_id <= 0 || $from === '' || $to === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id, from_id, and to_id are all required.', 'e2mconnect' ) );
	}

	$diff = E2M_Meta_Snapshot::diff( $post_id, $from, $to );
	if ( is_wp_error( $diff ) ) {
		return $diff;
	}

	return [
		'post_id'      => $post_id,
		'from_id'      => $from,
		'to_id'        => $to,
		'changed_keys' => array_keys( $diff ),
		'diff'         => $diff,
	];
}
