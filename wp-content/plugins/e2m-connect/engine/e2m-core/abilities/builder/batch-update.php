<?php
/**
 * E2M Connect MCP - Batch Update
 *
 * Applies many settings patches to a post in a single MCP round-trip. Each
 * operation supplies an element_id and a partial settings map; updates are
 * applied in list order to the same extracted tree before one final
 * inject-content write. Individual failures are recorded but do not abort
 * the batch (unless stop_on_error is true).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/batch-update', [
	'label'       => __( '[Builder] Batch Update', 'e2mconnect' ),
	'description' => 'Applies multiple element settings updates in a single call. Returns per-operation success/error.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
			'operations'    => [
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 100,
				'items'    => [
					'type'       => 'object',
					'properties' => [
						'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
						'settings'   => [ 'type' => 'object', 'additionalProperties' => true ],
					],
					'required'             => [ 'element_id', 'settings' ],
					'additionalProperties' => false,
				],
			],
			'stop_on_error' => [ 'type' => 'boolean', 'default' => false ],
		],
		'required'             => [ 'post_id', 'operations' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'results' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'element_id' => [ 'type' => 'string' ],
						'ok'         => [ 'type' => 'boolean' ],
						'error'      => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_batch_update_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Batch Update Elements',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the batch-update ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_batch_update_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$ops     = isset( $input['operations'] ) && is_array( $input['operations'] ) ? $input['operations'] : [];
	$halt    = ! empty( $input['stop_on_error'] );

	if ( $post_id <= 0 || $ops === [] ) {
		return new WP_Error( 'invalid_input', __( 'post_id and non-empty operations are required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$adapter = E2M_Builder_Registry::instance()->for_post( $post_id );
	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for this post.', 'e2mconnect' ) );
	}

	$tree     = $adapter->extract( $post_id );
	$elements = $tree['elements'] ?? [];

	$results = [];
	foreach ( $ops as $op ) {
		$element_id = isset( $op['element_id'] ) ? (string) $op['element_id'] : '';
		$patch      = isset( $op['settings'] ) && is_array( $op['settings'] ) ? $op['settings'] : [];

		$node = &E2M_Builder_Canonical::find( $elements, $element_id );
		if ( $node === null ) {
			$results[] = [
				'element_id' => $element_id,
				'ok'         => false,
				'error'      => 'element_not_found',
			];
			if ( $halt ) {
				break;
			}
			unset( $node );
			continue;
		}

		$current          = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
		$node['settings'] = E2M_Builder_Canonical::merge_settings( $current, $patch );
		unset( $node );

		$results[] = [
			'element_id' => $element_id,
			'ok'         => true,
			'error'      => '',
		];
	}

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id' => $post_id,
		'results' => $results,
	];
}
