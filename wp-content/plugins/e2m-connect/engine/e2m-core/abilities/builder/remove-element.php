<?php
/**
 * E2M Connect MCP - Remove Element
 *
 * Deletes a single element from the builder tree by ID. Descendants are
 * removed along with the element. The operation is destructive - callers
 * that need preview behaviour should snapshot via extract-content first.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/remove-element', [
	'label'       => __( '[Builder] Remove Element', 'e2mconnect' ),
	'description' => 'Deletes an element (and its descendants) from the builder tree.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
			'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'post_id', 'element_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'element_id' => [ 'type' => 'string' ],
			'removed'    => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_remove_element_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Remove Element',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the remove-element ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_remove_element_ability( array $input ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';

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

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'removed'    => true,
	];
}
