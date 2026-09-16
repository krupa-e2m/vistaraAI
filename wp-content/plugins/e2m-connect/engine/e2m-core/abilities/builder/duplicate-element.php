<?php
/**
 * E2M Connect MCP - Duplicate Element
 *
 * Creates a deep copy of an element (and all its descendants) with freshly
 * generated IDs, then inserts the clone immediately after the original
 * (or at a custom position via position_offset).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/duplicate-element', [
	'label'       => __( '[Builder] Duplicate Element', 'e2mconnect' ),
	'description' => 'Clones an element (and its descendants) with new IDs and inserts the copy next to the original.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'         => [ 'type' => 'integer', 'minimum' => 1 ],
			'element_id'      => [ 'type' => 'string', 'minLength' => 1 ],
			'position_offset' => [ 'type' => 'integer', 'default' => 1, 'description' => 'Offset relative to the source. 1 = insert right after, 0 = insert before.' ],
		],
		'required'             => [ 'post_id', 'element_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [ 'type' => 'integer' ],
			'source_id'    => [ 'type' => 'string' ],
			'duplicate_id' => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_duplicate_element_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Duplicate Element',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the duplicate-element ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_duplicate_element_ability( array $input ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$offset     = isset( $input['position_offset'] ) ? (int) $input['position_offset'] : 1;

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

	$location = e2m_engine_locate_parent_and_index( $elements, $element_id );
	if ( $location === null ) {
		return new WP_Error( 'element_not_found', __( 'Element not found in tree.', 'e2mconnect' ) );
	}

	$source = E2M_Builder_Canonical::find( $elements, $element_id );
	if ( $source === null ) {
		return new WP_Error( 'element_not_found', __( 'Element not found in tree.', 'e2mconnect' ) );
	}

	$clone   = E2M_Builder_Canonical::clone_with_new_ids( $source, [ $adapter, 'generate_id' ] );
	$position = $location['index'] + $offset;
	E2M_Builder_Canonical::insert( $elements, $clone, $location['parent_id'], $position );

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'      => $post_id,
		'source_id'    => $element_id,
		'duplicate_id' => (string) $clone['id'],
	];
}

/**
 * Find the parent_id and child-index of $target inside the canonical tree.
 * Returns null when not found. Top-level nodes report parent_id = null.
 *
 * @param array<int, array<string, mixed>> $elements
 * @return array{parent_id: ?string, index: int}|null
 */
function e2m_engine_locate_parent_and_index( array $elements, string $target, ?string $parent_id = null ): ?array {
	foreach ( $elements as $index => $node ) {
		if ( isset( $node['id'] ) && (string) $node['id'] === $target ) {
			return [ 'parent_id' => $parent_id, 'index' => (int) $index ];
		}
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$hit = e2m_engine_locate_parent_and_index( $node['children'], $target, (string) ( $node['id'] ?? '' ) );
			if ( $hit !== null ) {
				return $hit;
			}
		}
	}
	return null;
}
