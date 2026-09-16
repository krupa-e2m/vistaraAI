<?php
/**
 * E2M Connect — Memory: Get.
 *
 * Returns the full content of a single memory by id OR slug. Slug-callable
 * memories are the "skill" pattern — agents fetch a known playbook by
 * name via this ability instead of relying on relevance ranking.
 *
 * Every successful read bumps last_fetched_at on the memory so the decay
 * system (Step 10) can tell which entries are still in play.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability(
	'e2m/memory-get',
	[
		'label'       => __( '[Memory] Get Memory', 'e2mconnect' ),
		'description' => __( 'Fetch the full body of one memory by id OR slug. Use slug to load named playbooks (skills) the site owner has authored. Reading bumps the memory\'s last-fetched timestamp.', 'e2mconnect' ),
		'category'    => 'e2m-memory',

		'permission_callback' => 'e2m_memory_can_read',

		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'   => [ 'type' => 'integer', 'description' => 'Memory id.' ],
				'slug' => [ 'type' => 'string',  'description' => 'Slug for slug-callable memories. Mutually exclusive with id.' ],
			],
			'additionalProperties' => false,
		],

		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'              => [ 'type' => 'integer' ],
				'name'            => [ 'type' => 'string' ],
				'description'     => [ 'type' => 'string' ],
				'content'         => [ 'type' => 'string' ],
				'type'            => [ 'type' => 'string' ],
				'status'          => [ 'type' => 'string' ],
				'visibility'      => [ 'type' => 'string' ],
				'confidence'      => [ 'type' => 'number' ],
				'tags'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'slug'            => [ 'type' => 'string' ],
				'source_audit_id' => [ 'type' => 'integer' ],
				'updated_at'      => [ 'type' => 'string' ],
			],
		],

		'meta' => [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'title'       => 'Get a memory',
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		],

		'execute_callback' => 'e2m_memory_get_execute',
	]
);

/**
 * @param array<string,mixed>|null $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_memory_get_execute( ?array $input = null ): array|WP_Error {
	$input = is_array( $input ) ? $input : [];

	$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$slug = isset( $input['slug'] ) ? trim( (string) $input['slug'] ) : '';

	if ( $id <= 0 && $slug === '' ) {
		return new WP_Error(
			'missing_identifier',
			__( 'Provide either id or slug to fetch a memory.', 'e2mconnect' ),
			[ 'status' => 400 ]
		);
	}

	$memory = $id > 0
		? E2M_Memory_Repository::get( $id )
		: E2M_Memory_Repository::get_by_slug( $slug );

	if ( $memory === null ) {
		return new WP_Error(
			'not_found',
			__( 'Memory not found.', 'e2mconnect' ),
			[ 'status' => 404 ]
		);
	}

	// Visibility filter — return 404 (not 403) so we don't leak existence
	// to users who shouldn't see the memory at all.
	if ( ! e2m_memory_user_can_see( $memory ) ) {
		return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
	}

	// Decay signal — record that the AI looked at this memory.
	E2M_Memory_Repository::bump_fetch( (int) $memory['id'] );

	return $memory;
}
