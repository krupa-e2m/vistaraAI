<?php
/**
 * E2M Connect MCP - Elementor Get Element Settings
 *
 * Reads the current settings blob for a specific element inside an
 * Elementor-powered post. Agents use this to fetch "ground truth" before
 * computing a patch, instead of assuming defaults.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-get-element-settings', [
	'label'       => __( '[Elementor] Get Element Settings', 'e2mconnect' ),
	'description' => 'Returns the current settings of a specific element by element_id inside an Elementor post.',
	'category'    => 'e2m-elementor',

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
			'post_id'     => [ 'type' => 'integer' ],
			'element_id'  => [ 'type' => 'string' ],
			'el_type'     => [ 'type' => 'string' ],
			'widget_type' => [ 'type' => 'string' ],
			'settings'    => [ 'type' => 'object', 'additionalProperties' => true ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_get_element_settings_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Get Element Settings',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-get-element-settings ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_get_element_settings_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';

	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( $element_id === '' ) {
		return new WP_Error( 'invalid_element_id', __( 'element_id is required.', 'e2mconnect' ) );
	}

	$data = E2M_Elementor_Helper::read_post_data( $post_id );
	$node = &E2M_Elementor_Helper::find_node( $data, $element_id );
	if ( $node === null ) {
		return new WP_Error( 'element_not_found', __( 'Element not found inside this post.', 'e2mconnect' ) );
	}

	return [
		'post_id'     => $post_id,
		'element_id'  => $element_id,
		'el_type'     => (string) ( $node['elType'] ?? 'widget' ),
		'widget_type' => (string) ( $node['widgetType'] ?? '' ),
		'settings'    => (array) ( $node['settings'] ?? [] ),
	];
}
