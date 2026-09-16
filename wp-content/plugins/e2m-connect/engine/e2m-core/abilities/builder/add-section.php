<?php
/**
 * E2M Connect MCP - Add Section
 *
 * Inserts a "section"-style container for builders that still distinguish
 * sections from the newer flex containers (Elementor legacy, Bricks). On
 * Gutenberg the section lowers to a core/group, matching add-container's
 * behaviour since Gutenberg has no native section primitive.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-section', [
	'label'       => __( '[Builder] Add Section', 'e2mconnect' ),
	'description' => 'Inserts a section-style container. Maps to Elementor legacy section, Bricks section, or Gutenberg core/group.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'settings' => [ 'type' => 'object', 'additionalProperties' => true ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_section_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Section' ),
	],
] );

/**
 * Execute the add-section ability.
 *
 * Sections share the make_container() path because every adapter already
 * produces a section-shaped node appropriate to its builder. When a caller
 * wants the modern flex container explicitly, they should use add-container.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_section_ability( array $input ) {
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

	$section = $adapter->make_container( $settings );
	if ( $adapter->slug() === 'elementor' ) {
		// Force Elementor's legacy "section" kind for this endpoint.
		$section['kind'] = 'section';
		$section['type'] = 'section';
	}

	$tree     = $adapter->extract( $post_id );
	$elements = $tree['elements'] ?? [];

	$ok = E2M_Builder_Canonical::insert( $elements, $section, $parent_id, $position );
	if ( ! $ok ) {
		return new WP_Error( 'parent_not_found', __( 'Target parent element not found.', 'e2mconnect' ) );
	}

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => (string) ( $section['id'] ?? '' ),
		'builder'    => $adapter->slug(),
		'type'       => (string) ( $section['type'] ?? 'section' ),
	];
}
