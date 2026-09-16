<?php
/**
 * E2M Connect MCP - Find Builder Targets
 *
 * Scans a set of posts and reports which ones contain an element of a given
 * type or a substring inside their builder settings. Useful for "where is
 * this button used?" workflows before running a bulk-pages-operation.
 *
 * The scan is bounded to avoid runaway queries: pass post_type, status, and
 * a hard cap so agents never pull the entire site in one call.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/find-builder-targets', [
	'label'       => __( '[Builder] Find Targets', 'e2mconnect' ),
	'description' => 'Scans posts for builder elements matching a type or substring. Returns the matching posts plus element IDs.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_type'  => [ 'type' => 'string', 'default' => 'any' ],
			'status'     => [ 'type' => 'string', 'default' => 'any' ],
			'type'       => [ 'type' => 'string', 'description' => 'Element type to search for.' ],
			'contains'   => [ 'type' => 'string', 'description' => 'Substring to match inside element settings JSON.' ],
			'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => 'Maximum posts to inspect.' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'scanned' => [ 'type' => 'integer' ],
			'matches' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer' ],
						'title'       => [ 'type' => 'string' ],
						'builder'     => [ 'type' => 'string' ],
						'element_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_find_builder_targets_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Find Builder Targets',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the find-builder-targets ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_find_builder_targets_ability( array $input ): array {
	$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'any';
	$status    = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
	$type      = isset( $input['type'] ) && $input['type'] !== '' ? (string) $input['type'] : null;
	$contains  = isset( $input['contains'] ) && $input['contains'] !== '' ? (string) $input['contains'] : null;
	$limit     = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;

	$query = new WP_Query(
		[
			'post_type'      => $post_type === 'any' ? 'any' : $post_type,
			'post_status'    => $status === 'any' ? 'any' : $status,
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]
	);

	$registry = E2M_Builder_Registry::instance();
	$matches  = [];

	foreach ( $query->posts as $post_id ) {
		$adapter = $registry->for_post( (int) $post_id );
		if ( ! $adapter ) {
			continue;
		}

		$tree     = $adapter->extract( (int) $post_id );
		$elements = $tree['elements'] ?? [];
		$hits     = E2M_Builder_Canonical::search( $elements, $type, $contains );

		if ( $hits === [] ) {
			continue;
		}

		$matches[] = [
			'post_id'     => (int) $post_id,
			'title'       => get_the_title( (int) $post_id ),
			'builder'     => $adapter->slug(),
			'element_ids' => array_values(
				array_map(
					static fn( array $hit ): string => (string) ( $hit['id'] ?? '' ),
					$hits
				)
			),
		];
	}

	return [
		'scanned' => count( $query->posts ),
		'matches' => $matches,
	];
}
