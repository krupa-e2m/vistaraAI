<?php
/**
 * E2M Connect MCP - Elementor Set Dynamic Tag (Pro)
 *
 * Binds a dynamic tag (post title, ACF field, etc.) to a specific setting
 * key on an existing element. Elementor Pro stores dynamic bindings under
 * a "__dynamic__" key inside the element's settings, so we mutate that
 * alongside the normal settings map.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-set-dynamic-tag', [
	'label'       => __( '[Elementor] Set Dynamic Tag', 'e2mconnect' ),
	'description' => 'Pro-only. Binds a dynamic tag to a specific setting key on an Elementor element.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
			'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
			'setting'    => [ 'type' => 'string', 'minLength' => 1, 'description' => 'The setting key to bind (e.g. "title", "image", "editor").' ],
			'tag_name'   => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Dynamic tag name (from list-dynamic-tags).' ],
			'tag_settings' => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Settings for the dynamic tag (e.g. { key: "my_acf_field" } for ACF bindings).' ],
		],
		'required'             => [ 'post_id', 'element_id', 'setting', 'tag_name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'element_id' => [ 'type' => 'string' ],
			'setting'    => [ 'type' => 'string' ],
			'bound'      => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_set_dynamic_tag_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Set Dynamic Tag (Pro)',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-set-dynamic-tag ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_set_dynamic_tag_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id      = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id   = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$setting      = isset( $input['setting'] ) ? sanitize_key( (string) $input['setting'] ) : '';
	$tag_name     = isset( $input['tag_name'] ) ? sanitize_key( (string) $input['tag_name'] ) : '';
	$tag_settings = isset( $input['tag_settings'] ) && is_array( $input['tag_settings'] ) ? $input['tag_settings'] : [];

	if ( $post_id <= 0 || $element_id === '' || $setting === '' || $tag_name === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id, element_id, setting, and tag_name are all required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$data = E2M_Elementor_Helper::read_post_data( $post_id );
	$node = &E2M_Elementor_Helper::find_node( $data, $element_id );
	if ( $node === null ) {
		return new WP_Error( 'element_not_found', __( 'Element not found inside the post.', 'e2mconnect' ) );
	}

	$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
	if ( ! isset( $settings['__dynamic__'] ) || ! is_array( $settings['__dynamic__'] ) ) {
		$settings['__dynamic__'] = [];
	}

	// Elementor Pro expects a shortcode-shaped string like
	// [elementor-tag id="uuid" name="post-title" settings="%7B...%7D"].
	$tag_uuid = wp_generate_uuid4();
	$encoded  = rawurlencode( wp_json_encode( $tag_settings ) ?: '{}' );
	$shortcode = sprintf(
		'[elementor-tag id="%s" name="%s" settings="%s"]',
		$tag_uuid,
		$tag_name,
		$encoded
	);

	$settings['__dynamic__'][ $setting ] = $shortcode;
	$node['settings']                    = $settings;

	$write = E2M_Elementor_Helper::write_post_data( $post_id, $data );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'setting'    => $setting,
		'bound'      => true,
	];
}
