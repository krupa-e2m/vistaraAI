<?php
/**
 * E2M Connect MCP - Create Menu Item
 *
 * Adds a new item to a menu. Supports three shapes:
 *   - Custom URL item   (type=custom, url, title required)
 *   - Post/page item    (type=post_type, object=post|page|..., object_id)
 *   - Taxonomy item     (type=taxonomy, object=category|..., object_id)
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-menu-item', [
	'label'       => __( '[Menu] Create Item', 'e2mconnect' ),
	'description' => 'Adds a new item to a nav menu. Supports custom URL, post_type, and taxonomy items.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'menu_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'title'       => [ 'type' => 'string', 'minLength' => 1 ],
			'type'        => [ 'type' => 'string', 'enum' => [ 'custom', 'post_type', 'taxonomy' ], 'default' => 'custom' ],
			'object'      => [ 'type' => 'string', 'description' => 'Object subtype slug (e.g. "page", "category"). Required for post_type and taxonomy.' ],
			'object_id'   => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post or term ID. Required for non-custom types.' ],
			'url'         => [ 'type' => 'string', 'description' => 'Custom URL. Required for type=custom.' ],
			'parent_id'   => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
			'position'    => [ 'type' => 'integer', 'minimum' => 0 ],
			'target'      => [ 'type' => 'string', 'enum' => [ '', '_blank' ], 'default' => '' ],
			'description' => [ 'type' => 'string' ],
			'classes'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
		'required'             => [ 'menu_id', 'title' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'item_id' => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_menu_item_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Menu Item',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-menu-item ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_menu_item_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit menus.', 'e2mconnect' ) );
	}

	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	$title   = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

	if ( $menu_id <= 0 || ! wp_get_nav_menu_object( $menu_id ) ) {
		return new WP_Error( 'invalid_menu_id', __( 'A valid menu_id is required.', 'e2mconnect' ) );
	}
	if ( $title === '' ) {
		return new WP_Error( 'invalid_title', __( 'Menu item title is required.', 'e2mconnect' ) );
	}

	$type   = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'custom';
	$object = isset( $input['object'] ) ? sanitize_key( (string) $input['object'] ) : '';
	$obj_id = isset( $input['object_id'] ) ? (int) $input['object_id'] : 0;
	$url    = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';

	if ( $type === 'custom' && $url === '' ) {
		return new WP_Error( 'missing_url', __( 'url is required for type=custom.', 'e2mconnect' ) );
	}
	if ( in_array( $type, [ 'post_type', 'taxonomy' ], true ) && ( $object === '' || $obj_id <= 0 ) ) {
		return new WP_Error( 'missing_object', __( 'object and object_id are required for post_type and taxonomy items.', 'e2mconnect' ) );
	}

	$payload = [
		'menu-item-title'       => $title,
		'menu-item-url'          => $url,
		'menu-item-type'         => $type,
		'menu-item-object'       => $object,
		'menu-item-object-id'    => $obj_id,
		'menu-item-parent-id'    => isset( $input['parent_id'] ) ? (int) $input['parent_id'] : 0,
		'menu-item-position'     => isset( $input['position'] ) ? (int) $input['position'] : 0,
		'menu-item-target'       => isset( $input['target'] ) ? sanitize_text_field( (string) $input['target'] ) : '',
		'menu-item-description'  => isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
		'menu-item-classes'      => isset( $input['classes'] ) && is_array( $input['classes'] ) ? implode( ' ', array_map( 'sanitize_html_class', $input['classes'] ) ) : '',
		'menu-item-status'       => 'publish',
	];

	$item_id = wp_update_nav_menu_item( $menu_id, 0, $payload );
	if ( is_wp_error( $item_id ) ) {
		return $item_id;
	}

	return [ 'item_id' => (int) $item_id ];
}
