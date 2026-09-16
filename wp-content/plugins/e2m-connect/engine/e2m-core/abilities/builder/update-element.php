<?php
/**
 * E2M Connect MCP - Update Element
 *
 * Partial-update of a single element identified by ID. The supplied settings
 * merge on top of the existing settings (top-level keys replaced); omitted
 * keys are preserved. Works across all three builders because we mutate on
 * the canonical tree and re-inject.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-element', [
	'label'       => __( '[Builder] Update Element', 'e2mconnect' ),
	'description' => 'Updates a single element\'s settings by element_id. Settings merge at the top level; omitted keys are preserved.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
			'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
			'settings'   => [ 'type' => 'object', 'additionalProperties' => true ],
		],
		'required'             => [ 'post_id', 'element_id', 'settings' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'element_id' => [ 'type' => 'string' ],
			'updated'    => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_element_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Element',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-element ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_element_ability( array $input ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$patch      = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

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

	$node = &E2M_Builder_Canonical::find( $elements, $element_id );
	if ( $node === null ) {
		return new WP_Error( 'element_not_found', __( 'Element not found in tree.', 'e2mconnect' ) );
	}

	$current           = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
	$node['settings']  = E2M_Builder_Canonical::merge_settings( $current, $patch );

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'updated'    => true,
	];
}
