<?php
/**
 * E2M Connect MCP - Elementor Add Custom CSS (Pro)
 *
 * Writes page-level custom CSS via Elementor Pro's Custom CSS module. The
 * CSS is stored in the post's _elementor_page_settings blob under the
 * `custom_css` key - Elementor Pro reads this automatically on render.
 *
 * Ability registers unconditionally; callers without Elementor Pro get a
 * consistent elementor_pro_required error code.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-add-custom-css', [
	'label'       => __( '[Elementor] Add Custom CSS', 'e2mconnect' ),
	'description' => 'Pro-only. Attaches page-level custom CSS via Elementor Pro\'s Custom CSS module.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'css'     => [ 'type' => 'string', 'minLength' => 1 ],
			'mode'    => [ 'type' => 'string', 'enum' => [ 'replace', 'append' ], 'default' => 'replace' ],
		],
		'required'             => [ 'post_id', 'css' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'bytes'   => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_add_custom_css_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Add Custom CSS (Pro)',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-add-custom-css ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_add_custom_css_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$css     = (string) ( $input['css'] ?? '' );
	$mode    = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'replace';

	if ( $post_id <= 0 || ! get_post( $post_id ) || trim( $css ) === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and css are required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$settings = (array) get_post_meta( $post_id, '_elementor_page_settings', true );
	$current  = isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '';

	$settings['custom_css'] = $mode === 'append' && $current !== ''
		? $current . "\n" . $css
		: $css;

	update_post_meta( $post_id, '_elementor_page_settings', $settings );
	delete_post_meta( $post_id, '_elementor_css' );

	return [
		'post_id' => $post_id,
		'bytes'   => strlen( (string) $settings['custom_css'] ),
	];
}
