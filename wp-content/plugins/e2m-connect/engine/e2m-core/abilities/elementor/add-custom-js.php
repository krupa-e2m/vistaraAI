<?php
/**
 * E2M Connect MCP - Elementor Add Custom JS
 *
 * Saves a JavaScript snippet to post meta and enqueues it via
 * wp_add_inline_script() on the matching page — no manual <script>
 * tag construction involved.
 *
 * Pro users should prefer elementor-add-code-snippet, which hooks into
 * Elementor Pro's dedicated Custom Code module instead.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-add-custom-js', [
	'label'       => __( '[Elementor] Add Custom JS', 'e2mconnect' ),
	'description' => 'Inserts a JavaScript snippet wrapped in an HTML widget at the bottom of a post\'s Elementor tree.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
			'code'     => [ 'type' => 'string', 'minLength' => 1, 'description' => 'JavaScript source code (without <script> tags).' ],
			'location' => [ 'type' => 'string', 'enum' => [ 'append', 'prepend' ], 'default' => 'append' ],
		],
		'required'             => [ 'post_id', 'code' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'element_id' => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_add_custom_js_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Add Custom JS',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-add-custom-js ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_add_custom_js_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$code     = (string) ( $input['code'] ?? '' );
	$location = isset( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : 'append';

	if ( $post_id <= 0 || ! get_post( $post_id ) || trim( $code ) === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and code are required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		return new WP_Error( 'forbidden', __( 'Adding custom JS requires unfiltered_html capability.', 'e2mconnect' ) );
	}

	$id = 'e2m-cjs-' . strtolower( substr( (string) wp_generate_password( 7, false, false ), 0, 7 ) );

	// Retrieve existing snippets saved to this post, append/prepend the new one.
	$existing = get_post_meta( $post_id, '_e2m_custom_js', true );
	$snippets = is_array( $existing ) ? $existing : [];

	$entry = [ 'id' => $id, 'code' => $code ];
	if ( $location === 'prepend' ) {
		array_unshift( $snippets, $entry );
	} else {
		$snippets[] = $entry;
	}

	update_post_meta( $post_id, '_e2m_custom_js', $snippets );

	return [
		'post_id'    => $post_id,
		'element_id' => $id,
	];
}
