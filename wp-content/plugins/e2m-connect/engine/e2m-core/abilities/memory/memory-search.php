<?php
/**
 * E2M Connect — Memory: Search.
 *
 * Relevance-ranked search across name + description + content. v1 uses
 * a LIKE-based ranker (title boost > excerpt boost > content baseline).
 * The M3 milestone in the blueprint replaces the ranking engine with
 * embedding cosine similarity without changing this ability's surface.
 *
 * Visibility filtering applied at the application layer after the
 * repository returns candidates.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability(
	'e2m/memory-search',
	[
		'label'       => __( '[Memory] Search Memories', 'e2mconnect' ),
		'description' => __( 'Relevance-ranked search across memory name, description, and content. Returns the top matches with full content. Bumps last_fetched_at on each returned memory.', 'e2mconnect' ),
		'category'    => 'e2m-memory',

		'permission_callback' => 'e2m_memory_can_read',

		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'query' => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Search query.',
				],
				'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
				'type'  => [
					'type'        => 'string',
					'enum'        => [ 'context', 'rules', 'profile', 'reference' ],
					'description' => 'Optionally restrict to one memory type.',
				],
			],
			'required'             => [ 'query' ],
			'additionalProperties' => false,
		],

		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'matches' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'              => [ 'type' => 'integer' ],
							'name'            => [ 'type' => 'string' ],
							'description'     => [ 'type' => 'string' ],
							'content'         => [ 'type' => 'string' ],
							'type'            => [ 'type' => 'string' ],
							'status'          => [ 'type' => 'string' ],
							'visibility'      => [ 'type' => 'string' ],
							'relevance_score' => [ 'type' => 'integer' ],
						],
					],
				],
				'query'   => [ 'type' => 'string' ],
			],
		],

		'meta' => [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'title'        => 'Search memories',
				'instructions' => 'Use natural language search terms. Returns up to 20 memories ranked by relevance. For known slugs prefer e2m/memory-get with the slug; this ability is for discovery, not direct lookup.',
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			],
		],

		'execute_callback' => 'e2m_memory_search_execute',
	]
);

/**
 * @param array<string,mixed>|null $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_memory_search_execute( ?array $input = null ): array|WP_Error {
	$input = is_array( $input ) ? $input : [];

	$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
	if ( $query === '' ) {
		return new WP_Error( 'empty_query', __( 'Query is required.', 'e2mconnect' ), [ 'status' => 400 ] );
	}

	$limit   = isset( $input['limit'] ) ? max( 1, min( 20, (int) $input['limit'] ) ) : 5;
	$filters = [];
	if ( isset( $input['type'] ) ) {
		$filters['type'] = (string) $input['type'];
	}

	$result = E2M_Memory_Repository::search( $query, $filters, $limit );

	$matches = [];
	foreach ( $result['matches'] as $match ) {
		if ( ! e2m_memory_user_can_see( $match ) ) {
			continue;
		}
		E2M_Memory_Repository::bump_fetch( (int) $match['id'] );
		$matches[] = [
			'id'              => $match['id'],
			'name'            => $match['name'],
			'description'     => $match['description'],
			'content'         => $match['content'],
			'type'            => $match['type'],
			'status'          => $match['status'],
			'visibility'      => $match['visibility'],
			'relevance_score' => (int) ( $match['relevance_score'] ?? 0 ),
		];
	}

	return [
		'matches' => $matches,
		'query'   => $query,
	];
}
