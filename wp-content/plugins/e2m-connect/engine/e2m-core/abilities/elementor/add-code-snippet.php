<?php
/**
 * E2M Connect MCP - Elementor Add Code Snippet (Pro)
 *
 * Creates an entry in Elementor Pro's Custom Code module so a CSS/JS/HTML
 * fragment runs site-wide at the requested location. Snippets are stored
 * as elementor_snippet posts under the hood.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-add-code-snippet', [
	'label'       => __( '[Elementor] Add Code Snippet', 'e2mconnect' ),
	'description' => 'Pro-only. Creates an Elementor Custom Code snippet that runs site-wide at the chosen location.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'title'    => [ 'type' => 'string', 'minLength' => 1 ],
			'code'     => [ 'type' => 'string', 'minLength' => 1 ],
			'location' => [ 'type' => 'string', 'enum' => [ 'head', 'body_start', 'body_end' ], 'default' => 'head' ],
			'priority' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ],
			'enabled'  => [ 'type' => 'boolean', 'default' => true ],
		],
		'required'             => [ 'title', 'code' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'snippet_id' => [ 'type' => 'integer' ],
			'location'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_add_code_snippet_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Add Code Snippet (Pro)',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-add-code-snippet ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_add_code_snippet_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'Managing site-wide code snippets requires manage_options.', 'e2mconnect' ) );
	}

	$title    = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$code     = (string) ( $input['code'] ?? '' );
	$location = isset( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : 'head';
	$priority = isset( $input['priority'] ) ? max( 1, min( 100, (int) $input['priority'] ) ) : 10;
	$enabled  = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : true;

	$snippet_id = wp_insert_post(
		[
			'post_type'    => 'elementor_snippet',
			'post_status'  => $enabled ? 'publish' : 'draft',
			'post_title'   => $title,
			'post_content' => $code,
		],
		true
	);

	if ( is_wp_error( $snippet_id ) ) {
		return $snippet_id;
	}

	update_post_meta( (int) $snippet_id, '_elementor_snippet_location', $location );
	update_post_meta( (int) $snippet_id, '_elementor_snippet_priority', $priority );

	return [
		'snippet_id' => (int) $snippet_id,
		'location'   => $location,
	];
}
