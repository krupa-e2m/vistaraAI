<?php
/**
 * E2M Connect MCP - Apply Patch
 *
 * Applies a structured list of mutation ops (set / unset / insert / remove
 * / move) to a post's builder tree in one transaction. Richer than
 * batch-update, which only handles settings merges: apply-patch can also
 * restructure the tree.
 *
 * Supported op shapes:
 *   { "op": "set",    "element_id": "...", "settings": {...} }     (merge)
 *   { "op": "unset",  "element_id": "...", "keys": ["title",...] } (remove keys)
 *   { "op": "remove", "element_id": "..." }                        (delete node)
 *   { "op": "move",   "element_id": "...", "parent_id": "...", "position": 0 }
 *   { "op": "insert", "node": {...canonical...}, "parent_id": "...", "position": 0 }
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/apply-patch', [
	'label'       => __( '[Builder] Apply Patch', 'e2mconnect' ),
	'description' => 'Applies a sequence of structural ops (set, unset, remove, move, insert) to a post\'s builder tree in one call.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'ops'     => [
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 100,
				'items'    => [ 'type' => 'object', 'additionalProperties' => true ],
			],
		],
		'required'             => [ 'post_id', 'ops' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'applied' => [ 'type' => 'integer' ],
			'errors'  => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_apply_patch_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Apply Builder Patch',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the apply-patch ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_apply_patch_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$ops     = isset( $input['ops'] ) && is_array( $input['ops'] ) ? $input['ops'] : [];

	if ( $post_id <= 0 || $ops === [] ) {
		return new WP_Error( 'invalid_input', __( 'post_id and at least one op are required.', 'e2mconnect' ) );
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

	$applied = 0;
	$errors  = [];

	foreach ( $ops as $i => $op ) {
		$op_name = isset( $op['op'] ) ? sanitize_key( (string) $op['op'] ) : '';
		$success = false;

		switch ( $op_name ) {
			case 'set':
				$success = e2m_engine_patch_op_set( $elements, (array) $op );
				break;
			case 'unset':
				$success = e2m_engine_patch_op_unset( $elements, (array) $op );
				break;
			case 'remove':
				$success = e2m_engine_patch_op_remove( $elements, (array) $op );
				break;
			case 'move':
				$success = e2m_engine_patch_op_move( $elements, (array) $op );
				break;
			case 'insert':
				$success = e2m_engine_patch_op_insert( $elements, (array) $op );
				break;
			default:
				$errors[] = [ 'index' => $i, 'reason' => 'unknown_op' ];
				continue 2;
		}

		if ( $success ) {
			++$applied;
		} else {
			$errors[] = [ 'index' => $i, 'op' => $op_name, 'reason' => 'failed' ];
		}
	}

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id' => $post_id,
		'applied' => $applied,
		'errors'  => $errors,
	];
}

/**
 * @param array<int, array<string, mixed>> $elements
 * @param array<string, mixed>             $op
 */
function e2m_engine_patch_op_set( array &$elements, array $op ): bool {
	$id    = isset( $op['element_id'] ) ? (string) $op['element_id'] : '';
	$patch = isset( $op['settings'] ) && is_array( $op['settings'] ) ? $op['settings'] : [];
	if ( $id === '' ) {
		return false;
	}
	$node = &E2M_Builder_Canonical::find( $elements, $id );
	if ( $node === null ) {
		return false;
	}
	$current          = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
	$node['settings'] = E2M_Builder_Canonical::merge_settings( $current, $patch );
	return true;
}

/**
 * @param array<int, array<string, mixed>> $elements
 * @param array<string, mixed>             $op
 */
function e2m_engine_patch_op_unset( array &$elements, array $op ): bool {
	$id   = isset( $op['element_id'] ) ? (string) $op['element_id'] : '';
	$keys = isset( $op['keys'] ) && is_array( $op['keys'] ) ? $op['keys'] : [];
	if ( $id === '' || $keys === [] ) {
		return false;
	}
	$node = &E2M_Builder_Canonical::find( $elements, $id );
	if ( $node === null ) {
		return false;
	}
	foreach ( $keys as $key ) {
		unset( $node['settings'][ (string) $key ] );
	}
	return true;
}

/**
 * @param array<int, array<string, mixed>> $elements
 * @param array<string, mixed>             $op
 */
function e2m_engine_patch_op_remove( array &$elements, array $op ): bool {
	$id = isset( $op['element_id'] ) ? (string) $op['element_id'] : '';
	if ( $id === '' ) {
		return false;
	}
	return E2M_Builder_Canonical::remove( $elements, $id ) !== null;
}

/**
 * @param array<int, array<string, mixed>> $elements
 * @param array<string, mixed>             $op
 */
function e2m_engine_patch_op_move( array &$elements, array $op ): bool {
	$id        = isset( $op['element_id'] ) ? (string) $op['element_id'] : '';
	$parent_id = isset( $op['parent_id'] ) ? (string) $op['parent_id'] : '';
	$position  = isset( $op['position'] ) ? (int) $op['position'] : PHP_INT_MAX;
	if ( $id === '' ) {
		return false;
	}
	$removed = E2M_Builder_Canonical::remove( $elements, $id );
	if ( $removed === null ) {
		return false;
	}
	return E2M_Builder_Canonical::insert( $elements, $removed, $parent_id === '' ? null : $parent_id, $position );
}

/**
 * @param array<int, array<string, mixed>> $elements
 * @param array<string, mixed>             $op
 */
function e2m_engine_patch_op_insert( array &$elements, array $op ): bool {
	$node      = isset( $op['node'] ) && is_array( $op['node'] ) ? $op['node'] : [];
	$parent_id = isset( $op['parent_id'] ) ? (string) $op['parent_id'] : '';
	$position  = isset( $op['position'] ) ? (int) $op['position'] : PHP_INT_MAX;
	if ( $node === [] ) {
		return false;
	}
	return E2M_Builder_Canonical::insert( $elements, $node, $parent_id === '' ? null : $parent_id, $position );
}
