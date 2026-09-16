<?php
/**
 * E2M Connect MCP - Add Container
 *
 * Inserts a flex/grid container into the builder tree. In Elementor this
 * produces a modern container element; in Gutenberg a core/group block; in
 * Bricks a section element. Children can be appended via move-element or
 * build-page after the container exists.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-container', [
	'label'       => __( '[Builder] Add Container', 'e2mconnect' ),
	'description' => 'Inserts a container element (flex/grid group). Children can be added later via move-element or build-page.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'settings' => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Builder-native container settings (flex direction, gap, padding, etc.).' ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_container_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Container' ),
	],
] );

/**
 * Execute the add-container ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_container_ability( array $input ) {
	$post_id   = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$parent_id = isset( $input['parent_id'] ) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : null;
	$position  = isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX;
	$settings  = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$adapter = E2M_Builder_Registry::instance()->for_post( $post_id )
		?? E2M_Builder_Registry::instance()->get( 'gutenberg' );
	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for this post.', 'e2mconnect' ) );
	}

	$container = $adapter->make_container( $settings );

	$tree     = $adapter->extract( $post_id );
	$elements = $tree['elements'] ?? [];

	$ok = E2M_Builder_Canonical::insert( $elements, $container, $parent_id, $position );
	if ( ! $ok ) {
		return new WP_Error( 'parent_not_found', __( 'Target parent element not found.', 'e2mconnect' ) );
	}

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => (string) ( $container['id'] ?? '' ),
		'builder'    => $adapter->slug(),
		'type'       => (string) ( $container['type'] ?? 'container' ),
	];
}
