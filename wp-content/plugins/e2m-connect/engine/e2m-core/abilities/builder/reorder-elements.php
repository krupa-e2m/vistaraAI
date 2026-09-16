<?php
/**
 * E2M Connect MCP - Reorder Elements
 *
 * Reorders the direct children of a parent (or the root level) to match the
 * supplied ID sequence. Only existing children are honoured; any IDs not
 * currently in the parent are ignored, and any child IDs not listed are
 * appended to preserve safety.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/reorder-elements', [
	'label'       => __( '[Builder] Reorder Elements', 'e2mconnect' ),
	'description' => 'Reorders the direct children of a parent element. Unlisted children are appended in their original order.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
			'parent_id' => [ 'type' => 'string', 'description' => 'Parent element ID. Empty string means the root.' ],
			'order'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
		'required'             => [ 'post_id', 'order' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'parent_id' => [ 'type' => 'string' ],
			'reordered' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_reorder_elements_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Reorder Elements',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the reorder-elements ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_reorder_elements_ability( array $input ) {
	$post_id   = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$parent_id = isset( $input['parent_id'] ) ? (string) $input['parent_id'] : '';
	$order     = isset( $input['order'] ) && is_array( $input['order'] ) ? array_map( 'strval', $input['order'] ) : [];

	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_input', __( 'A valid post_id is required.', 'e2mconnect' ) );
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

	// Resolve target list reference (root or a named parent's children).
	if ( $parent_id === '' ) {
		$target = &$elements;
	} else {
		$parent = &E2M_Builder_Canonical::find( $elements, $parent_id );
		if ( $parent === null ) {
			return new WP_Error( 'parent_not_found', __( 'Parent element not found.', 'e2mconnect' ) );
		}
		if ( ! isset( $parent['children'] ) || ! is_array( $parent['children'] ) ) {
			$parent['children'] = [];
		}
		$target = &$parent['children'];
	}

	// Index current children by ID so we can pull them out in order.
	$by_id = [];
	foreach ( $target as $child ) {
		$by_id[ (string) ( $child['id'] ?? '' ) ] = $child;
	}

	$reordered = [];
	foreach ( $order as $id ) {
		if ( isset( $by_id[ $id ] ) ) {
			$reordered[] = $by_id[ $id ];
			unset( $by_id[ $id ] );
		}
	}

	// Append any children that were not in the supplied order so nothing
	// disappears as a side effect of reordering.
	foreach ( $by_id as $leftover ) {
		$reordered[] = $leftover;
	}

	$target = $reordered;

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'   => $post_id,
		'parent_id' => $parent_id,
		'reordered' => true,
	];
}
