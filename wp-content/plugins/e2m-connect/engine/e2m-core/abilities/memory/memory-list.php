<?php
/**
 * E2M Connect — Memory: List.
 *
 * Returns the index — id, name, description, type, status, visibility,
 * tags — for every memory the current user can see. No content. The AI
 * uses this to decide which memories it wants to fetch in full via
 * memory-get.
 *
 * Visibility filtering happens at the application layer because the
 * repository can't know "the current user" without leaking auth context
 * into a data class. We pull a wide candidate set from the index then
 * filter via e2m_memory_user_can_see().
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability(
	'e2m/memory-list',
	[
		'label'       => __( '[Memory] List Memories (Index)', 'e2mconnect' ),
		'description' => __( 'Returns the concise memory index (id, name, description, type, status, visibility) for every memory the current user can see. Token-cheap; call this first to decide which memories to load in full via e2m/memory-get.', 'e2mconnect' ),
		'category'    => 'e2m-memory',

		'permission_callback' => 'e2m_memory_can_read',

		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'type'             => [
					'type'        => 'string',
					'enum'        => [ 'context', 'rules', 'profile', 'reference' ],
					'description' => 'Filter by memory type.',
				],
				'status'           => [
					'type'        => 'string',
					'enum'        => [ 'active', 'always', 'stale', 'disputed', 'archived', 'pinned', 'pending_review' ],
					'description' => 'Filter by status. By default archived memories are excluded.',
				],
				'visibility'       => [
					'type'        => 'string',
					'enum'        => [ 'private', 'team', 'system' ],
					'description' => 'Filter by visibility.',
				],
				'has_slug'         => [
					'type'        => 'boolean',
					'description' => 'When true, only return slug-callable memories.',
				],
				'include_archived' => [
					'type'        => 'boolean',
					'description' => 'When true, include archived memories in the response.',
				],
				'page'             => [ 'type' => 'integer', 'minimum' => 1 ],
				'per_page'         => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ],
			],
			'additionalProperties' => false,
		],

		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'conflicts' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'ids'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
							'topic'  => [ 'type' => 'string' ],
							'reason' => [ 'type' => 'string' ],
						],
					],
				],
				'memories' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'integer' ],
							'name'        => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'type'        => [ 'type' => 'string' ],
							'status'      => [ 'type' => 'string' ],
							'visibility'  => [ 'type' => 'string' ],
							'tags'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
							'slug'        => [ 'type' => 'string' ],
							'updated_at'  => [ 'type' => 'string' ],
						],
					],
				],
				'total'    => [ 'type' => 'integer' ],
				'page'     => [ 'type' => 'integer' ],
				'per_page' => [ 'type' => 'integer' ],
			],
		],

		'meta' => [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'title'        => 'List memories (index only)',
				'instructions' => 'This is the index entry point. Returns name + description + type + status only -- no content. Pick memories whose description looks relevant to the current task, then call e2m/memory-get to load the full body. Memories with status=stale or disputed are returned but you should verify against live state before applying.',
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			],
		],

		'execute_callback' => 'e2m_memory_list_execute',
	]
);

/**
 * @param array<string,mixed>|null $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_memory_list_execute( ?array $input = null ): array|WP_Error {
	$input = is_array( $input ) ? $input : [];

	$filters = [];
	if ( isset( $input['type'] ) ) {
		$filters['type'] = (string) $input['type'];
	}
	if ( isset( $input['status'] ) ) {
		$filters['status'] = (string) $input['status'];
	}
	if ( isset( $input['visibility'] ) ) {
		$filters['visibility'] = (string) $input['visibility'];
	}
	if ( isset( $input['has_slug'] ) ) {
		$filters['has_slug'] = (bool) $input['has_slug'];
	}
	if ( ! empty( $input['include_archived'] ) ) {
		$filters['include_archived'] = true;
	}

	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : E2M_Memory_Repository::LIST_DEFAULT_PER_PAGE;
	$per_page = max( 1, min( E2M_Memory_Repository::LIST_MAX_PER_PAGE, $per_page ) );

	$result = E2M_Memory_Repository::list( $filters, $page, $per_page );

	// Apply per-user visibility filter and trim payload to index-only.
	$index = [];
	foreach ( $result['memories'] as $memory ) {
		if ( ! e2m_memory_user_can_see( $memory ) ) {
			continue;
		}
		$index[] = [
			'id'          => $memory['id'],
			'name'        => $memory['name'],
			'description' => $memory['description'],
			'type'        => $memory['type'],
			'status'      => $memory['status'],
			'visibility'  => $memory['visibility'],
			'tags'        => $memory['tags'],
			'slug'        => $memory['slug'],
			'updated_at'  => $memory['updated_at'],
		];
	}

	// Lazy decay evaluation — examines up to LAZY_EVAL_PER_REQUEST
	// returned memories so drift between the nightly cron runs gets
	// caught on read. Bounded so list latency stays predictable.
	if ( class_exists( 'E2M_Memory_Decay' ) ) {
		E2M_Memory_Decay::lazy_evaluate( $result['memories'] );
	}

	// Conflict detection -- runs against the visibility-filtered set so
	// we don't surface conflicts the user can't even see. The AI's
	// discipline contract (MEMORY.md, Step 17) tells it to ask the user
	// when conflicts exist rather than picking silently.
	$conflicts = class_exists( 'E2M_Memory_Conflicts' )
		? E2M_Memory_Conflicts::detect_in_set( $result['memories'] )
		: [];

	return [
		'memories'  => $index,
		'conflicts' => $conflicts,
		'total'     => count( $index ),
		'page'      => $result['page'],
		'per_page'  => $result['per_page'],
	];
}
