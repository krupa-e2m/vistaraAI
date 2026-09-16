<?php
/**
 * E2M Connect MCP - Find Element
 *
 * Searches the builder tree of a post and returns nodes that match type
 * and/or a string inside their settings. Used as the discovery step before
 * update/move/remove operations so agents can pinpoint the exact element.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/find-element', [
	'label'       => __( '[Builder] Find Element', 'e2mconnect' ),
	'description' => 'Searches a post\'s builder tree for elements by type and/or a substring inside their settings. Returns matching nodes with full canonical metadata.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
			'type'     => [ 'type' => 'string', 'description' => 'Element type to match (e.g. "heading", "core/paragraph").' ],
			'contains' => [ 'type' => 'string', 'description' => 'Case-insensitive substring matched against the element\'s settings JSON.' ],
			'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'builder' => [ 'type' => 'string' ],
			'matches' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_find_element_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'Find Element',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the find-element ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_find_element_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$adapter = E2M_Builder_Registry::instance()->for_post( $post_id );
	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for this post.', 'e2mconnect' ) );
	}

	$tree     = $adapter->extract( $post_id );
	$elements = $tree['elements'] ?? [];
	$limit    = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;
	$type     = isset( $input['type'] ) && $input['type'] !== '' ? (string) $input['type'] : null;
	$contains = isset( $input['contains'] ) && $input['contains'] !== '' ? (string) $input['contains'] : null;

	$hits = E2M_Builder_Canonical::search( $elements, $type, $contains );
	if ( count( $hits ) > $limit ) {
		$hits = array_slice( $hits, 0, $limit );
	}

	return [
		'post_id' => $post_id,
		'builder' => $adapter->slug(),
		'matches' => $hits,
	];
}
