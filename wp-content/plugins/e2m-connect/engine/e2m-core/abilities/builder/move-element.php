<?php
/**
 * E2M Connect MCP - Move Element
 *
 * Relocates an element to a new parent/position in the builder tree. The
 * element preserves its own ID and children; only its location in the tree
 * changes. Pass parent_id="" to move to the root level.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/move-element', [
	'label'       => __( '[Builder] Move Element', 'e2mconnect' ),
	'description' => 'Moves an element to a different parent and/or position. Pass parent_id="" to move to the root.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
			'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
			'parent_id'  => [ 'type' => 'string', 'description' => 'New parent element ID. Empty string = root.' ],
			'position'   => [ 'type' => 'integer', 'minimum' => 0, 'description' => '0-based position inside the new parent (or root).' ],
		],
		'required'             => [ 'post_id', 'element_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'element_id' => [ 'type' => 'string' ],
			'moved'      => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_move_element_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Move Element',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the move-element ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_move_element_ability( array $input ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$parent_id  = isset( $input['parent_id'] ) ? (string) $input['parent_id'] : '';
	$position   = isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX;

	if ( $post_id <= 0 || $element_id === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and element_id are required.', 'e2mconnect' ) );
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

	$removed = E2M_Builder_Canonical::remove( $elements, $element_id );
	if ( $removed === null ) {
		return new WP_Error( 'element_not_found', __( 'Element not found in tree.', 'e2mconnect' ) );
	}

	$effective_parent = $parent_id === '' ? null : $parent_id;
	$inserted         = E2M_Builder_Canonical::insert( $elements, $removed, $effective_parent, $position );
	if ( ! $inserted ) {
		return new WP_Error( 'parent_not_found', __( 'Target parent element not found.', 'e2mconnect' ) );
	}

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'moved'      => true,
	];
}
