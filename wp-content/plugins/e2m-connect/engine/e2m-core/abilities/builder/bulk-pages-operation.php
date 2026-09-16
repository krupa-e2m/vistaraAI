<?php
/**
 * E2M Connect MCP - Bulk Pages Operation
 *
 * Applies the same mutation across many posts in one call. Each target is
 * processed independently; per-post errors do not abort the batch. The
 * operation payload is identical to apply-patch's ops array, so callers can
 * compose a change once and fan it out.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bulk-pages-operation', [
	'label'       => __( '[Builder] Bulk Pages', 'e2mconnect' ),
	'description' => 'Applies the same builder patch across many posts. Uses the apply-patch op vocabulary (set, unset, remove, move, insert).',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_ids' => [
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 50,
				'items'    => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'ops'      => [
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 100,
				'items'    => [ 'type' => 'object', 'additionalProperties' => true ],
			],
		],
		'required'             => [ 'post_ids', 'ops' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'results' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
						'applied' => [ 'type' => 'integer' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_bulk_pages_operation_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bulk Pages Operation',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bulk-pages-operation ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bulk_pages_operation_ability( array $input ) {
	$post_ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? array_map( 'intval', $input['post_ids'] ) : [];
	$ops      = isset( $input['ops'] ) && is_array( $input['ops'] ) ? $input['ops'] : [];

	if ( $post_ids === [] || $ops === [] ) {
		return new WP_Error( 'invalid_input', __( 'post_ids and ops are required.', 'e2mconnect' ) );
	}

	$results = [];

	foreach ( $post_ids as $post_id ) {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			$results[] = [ 'post_id' => $post_id, 'applied' => 0, 'error' => 'invalid_post_id' ];
			continue;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$results[] = [ 'post_id' => $post_id, 'applied' => 0, 'error' => 'forbidden' ];
			continue;
		}

		$response = e2m_engine_apply_patch_ability(
			[
				'post_id' => $post_id,
				'ops'     => $ops,
			]
		);

		if ( is_wp_error( $response ) ) {
			$results[] = [
				'post_id' => $post_id,
				'applied' => 0,
				'error'   => (string) $response->get_error_code(),
			];
			continue;
		}

		$results[] = [
			'post_id' => $post_id,
			'applied' => isset( $response['applied'] ) ? (int) $response['applied'] : 0,
			'error'   => '',
		];
	}

	return [ 'results' => $results ];
}
