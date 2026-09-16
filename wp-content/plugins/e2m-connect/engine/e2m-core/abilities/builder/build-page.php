<?php
/**
 * E2M Connect MCP - Build Page
 *
 * The headline composite tool: accepts a declarative canonical tree and
 * writes it directly into a post. Existing content is replaced wholesale -
 * this is the "paste a blueprint and go" endpoint for agents that have
 * composed a page in memory.
 *
 * Missing IDs in the input tree are backfilled by the adapter so callers
 * can author intent-only payloads without worrying about id generation.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/build-page', [
	'label'       => __( '[Builder] Build Page', 'e2mconnect' ),
	'description' => 'Replaces a post\'s builder content with a declarative canonical tree. Missing element IDs are generated automatically.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
			'elements' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'builder'  => [ 'type' => 'string', 'enum' => [ 'auto', 'elementor', 'gutenberg', 'bricks' ], 'default' => 'auto' ],
		],
		'required'             => [ 'post_id', 'elements' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'     => [ 'type' => 'integer' ],
			'builder'     => [ 'type' => 'string' ],
			'node_count'  => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_build_page_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'Build Page',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the build-page ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_build_page_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$forced  = isset( $input['builder'] ) ? sanitize_key( (string) $input['builder'] ) : 'auto';
	$adapter = $forced === 'auto'
		? E2M_Builder_Registry::instance()->for_post( $post_id ) ?? E2M_Builder_Registry::instance()->get( 'gutenberg' )
		: E2M_Builder_Registry::instance()->get( $forced );

	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for this post.', 'e2mconnect' ) );
	}

	$elements = isset( $input['elements'] ) && is_array( $input['elements'] ) ? $input['elements'] : [];
	$elements = e2m_engine_backfill_ids( $elements, [ $adapter, 'generate_id' ] );

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	$node_count = count( E2M_Builder_Canonical::flatten( $elements ) );

	return [
		'post_id'    => $post_id,
		'builder'    => $adapter->slug(),
		'node_count' => $node_count,
	];
}

/**
 * Walk a canonical tree and fill in any missing "id" fields with fresh IDs
 * from the adapter's generator. Existing IDs are preserved so callers may
 * supply partial payloads for incremental builds.
 *
 * @param array<int, array<string, mixed>> $elements
 * @param callable                         $id_generator
 * @return array<int, array<string, mixed>>
 */
function e2m_engine_backfill_ids( array $elements, callable $id_generator ): array {
	foreach ( $elements as &$node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		if ( empty( $node['id'] ) ) {
			$node['id'] = (string) $id_generator();
		}
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$node['children'] = e2m_engine_backfill_ids( $node['children'], $id_generator );
		}
	}
	unset( $node );
	return $elements;
}
