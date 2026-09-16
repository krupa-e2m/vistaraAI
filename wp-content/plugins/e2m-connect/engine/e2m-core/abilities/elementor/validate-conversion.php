<?php
/**
 * E2M Connect MCP - Elementor Validate Conversion
 *
 * Runs a lightweight consistency check on an Elementor post's data so an
 * agent can flag obviously broken content before publishing. The checks
 * are advisory (no mutation); callers decide what to do with the report.
 *
 * Checks performed:
 *   - JSON decodes cleanly and is an array
 *   - every node has a non-empty id and elType
 *   - widget nodes have a widgetType
 *   - ids are unique across the tree
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-validate-conversion', [
	'label'       => __( '[Elementor] Validate Conversion', 'e2mconnect' ),
	'description' => 'Validates a post\'s Elementor data for structural issues (missing ids/types, duplicate ids, bad JSON). Read-only.',
	'category'    => 'e2m-elementor',

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
			'valid'     => [ 'type' => 'boolean' ],
			'issues'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'code'    => [ 'type' => 'string' ],
						'message' => [ 'type' => 'string' ],
						'id'      => [ 'type' => 'string' ],
					],
				],
			],
			'node_count' => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_validate_conversion_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Validate Conversion',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-validate-conversion ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_validate_conversion_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$issues    = [];
	$seen_ids  = [];
	$count     = 0;
	$raw       = get_post_meta( $post_id, '_elementor_data', true );

	if ( is_string( $raw ) && $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$issues[] = [ 'code' => 'bad_json', 'message' => 'Elementor data is not valid JSON.', 'id' => '' ];
			$decoded  = [];
		}
	} elseif ( is_array( $raw ) ) {
		$decoded = $raw;
	} else {
		$decoded = [];
	}

	e2m_engine_elementor_validate_walk( $decoded, $issues, $seen_ids, $count );

	return [
		'post_id'    => $post_id,
		'valid'      => $issues === [],
		'issues'     => $issues,
		'node_count' => $count,
	];
}

/**
 * Depth-first walk over the native Elementor tree collecting issues.
 *
 * @param array<int, array<string, mixed>> $nodes
 * @param array<int, array<string, string>> $issues Accumulator for issues.
 * @param array<string, bool>               $seen_ids
 */
function e2m_engine_elementor_validate_walk( array $nodes, array &$issues, array &$seen_ids, int &$count ): void {
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		++$count;

		$id      = (string) ( $node['id'] ?? '' );
		$el_type = (string) ( $node['elType'] ?? '' );

		if ( $id === '' ) {
			$issues[] = [ 'code' => 'missing_id', 'message' => 'Element is missing an id.', 'id' => '' ];
		} elseif ( isset( $seen_ids[ $id ] ) ) {
			$issues[] = [ 'code' => 'duplicate_id', 'message' => 'Duplicate element id.', 'id' => $id ];
		} else {
			$seen_ids[ $id ] = true;
		}

		if ( $el_type === '' ) {
			$issues[] = [ 'code' => 'missing_el_type', 'message' => 'Element is missing elType.', 'id' => $id ];
		}

		if ( $el_type === 'widget' && empty( $node['widgetType'] ) ) {
			$issues[] = [ 'code' => 'missing_widget_type', 'message' => 'Widget node has no widgetType.', 'id' => $id ];
		}

		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			e2m_engine_elementor_validate_walk( $node['elements'], $issues, $seen_ids, $count );
		}
	}
}
