<?php
/**
 * E2M Connect MCP - Update Menu Item
 *
 * Partial update for an existing menu item. WordPress' wp_update_nav_menu_item
 * requires passing the full current payload; this ability reads the current
 * state, merges the supplied patch, and writes the result in one round-trip.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-menu-item', [
	'label'       => __( '[Menu] Update Item', 'e2mconnect' ),
	'description' => 'Updates an existing menu item. Only supplied fields are changed.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'item_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'title'       => [ 'type' => 'string' ],
			'url'         => [ 'type' => 'string' ],
			'parent_id'   => [ 'type' => 'integer', 'minimum' => 0 ],
			'position'    => [ 'type' => 'integer', 'minimum' => 0 ],
			'target'      => [ 'type' => 'string', 'enum' => [ '', '_blank' ] ],
			'description' => [ 'type' => 'string' ],
			'classes'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'xfn'         => [ 'type' => 'string' ],
			'attr_title'  => [ 'type' => 'string' ],
		],
		'required'             => [ 'item_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'item_id'        => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_menu_item_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Menu Item',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-menu-item ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_menu_item_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit menus.', 'e2mconnect' ) );
	}

	$item_id = isset( $input['item_id'] ) ? (int) $input['item_id'] : 0;
	if ( $item_id <= 0 ) {
		return new WP_Error( 'invalid_item_id', __( 'A valid item_id is required.', 'e2mconnect' ) );
	}

	$existing = wp_setup_nav_menu_item( get_post( $item_id ) );
	if ( ! $existing || empty( $existing->ID ) ) {
		return new WP_Error( 'item_not_found', __( 'Menu item not found.', 'e2mconnect' ) );
	}

	$menu_ids = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );
	$menu_id  = ! is_wp_error( $menu_ids ) && is_array( $menu_ids ) && isset( $menu_ids[0] ) ? (int) $menu_ids[0] : 0;

	// Seed with current values, so omitted fields survive.
	$payload = [
		'menu-item-title'        => $existing->title,
		'menu-item-url'          => $existing->url,
		'menu-item-type'         => $existing->type,
		'menu-item-object'       => $existing->object,
		'menu-item-object-id'    => $existing->object_id,
		'menu-item-parent-id'    => $existing->menu_item_parent,
		'menu-item-position'     => $existing->menu_order,
		'menu-item-target'       => $existing->target,
		'menu-item-description'  => $existing->description,
		'menu-item-classes'      => is_array( $existing->classes ) ? implode( ' ', $existing->classes ) : (string) $existing->classes,
		'menu-item-xfn'          => $existing->xfn,
		'menu-item-attr-title'   => $existing->attr_title,
		'menu-item-status'       => 'publish',
	];

	$touched = [];

	$field_map = [
		'title'       => 'menu-item-title',
		'url'         => 'menu-item-url',
		'parent_id'   => 'menu-item-parent-id',
		'position'    => 'menu-item-position',
		'target'      => 'menu-item-target',
		'description' => 'menu-item-description',
		'xfn'         => 'menu-item-xfn',
		'attr_title'  => 'menu-item-attr-title',
	];

	foreach ( $field_map as $in_key => $payload_key ) {
		if ( ! array_key_exists( $in_key, $input ) ) {
			continue;
		}
		$payload[ $payload_key ] = match ( $in_key ) {
			'title', 'description', 'attr_title' => sanitize_text_field( (string) $input[ $in_key ] ),
			'url'                                => esc_url_raw( (string) $input[ $in_key ] ),
			'parent_id', 'position'              => (int) $input[ $in_key ],
			default                              => sanitize_text_field( (string) $input[ $in_key ] ),
		};
		$touched[] = $in_key;
	}

	if ( isset( $input['classes'] ) && is_array( $input['classes'] ) ) {
		$payload['menu-item-classes'] = implode( ' ', array_map( 'sanitize_html_class', $input['classes'] ) );
		$touched[]                    = 'classes';
	}

	if ( $touched === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	$result = wp_update_nav_menu_item( $menu_id, $item_id, $payload );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'item_id'        => $item_id,
		'updated_fields' => $touched,
	];
}
