<?php
/**
 * E2M Connect MCP - Elementor Create Theme Template (Pro)
 *
 * Creates a Theme Builder template (header, footer, single, archive, 404,
 * search). The template is stored as an elementor_library post with its
 * _elementor_template_type set to the requested template kind so
 * Elementor Pro's Theme Builder picks it up automatically.
 *
 * Display conditions are NOT set here - use elementor-set-template-conditions
 * after creation.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-create-theme-template', [
	'label'       => __( '[Elementor] Create Theme Template', 'e2mconnect' ),
	'description' => 'Pro-only. Creates an Elementor Theme Builder template (header / footer / single / archive / 404 / search).',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'name'          => [ 'type' => 'string', 'minLength' => 1 ],
			'template_kind' => [ 'type' => 'string', 'enum' => [ 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404' ] ],
			'content'       => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ], 'description' => 'Optional native Elementor nodes to seed the template.' ],
		],
		'required'             => [ 'name', 'template_kind' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'template_id' => [ 'type' => 'integer' ],
			'kind'        => [ 'type' => 'string' ],
			'edit_url'    => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_create_theme_template_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Create Theme Template (Pro)',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-create-theme-template ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_create_theme_template_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to create theme templates.', 'e2mconnect' ) );
	}

	$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$kind    = sanitize_key( (string) ( $input['template_kind'] ?? '' ) );
	$content = isset( $input['content'] ) && is_array( $input['content'] ) ? $input['content'] : [];

	if ( $name === '' || $kind === '' ) {
		return new WP_Error( 'invalid_input', __( 'name and template_kind are required.', 'e2mconnect' ) );
	}

	$template_id = wp_insert_post(
		[
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'post_title'  => $name,
		],
		true
	);
	if ( is_wp_error( $template_id ) ) {
		return $template_id;
	}

	update_post_meta( (int) $template_id, '_elementor_template_type', $kind );
	update_post_meta( (int) $template_id, '_elementor_edit_mode', 'builder' );

	if ( $content !== [] ) {
		E2M_Elementor_Helper::write_post_data( (int) $template_id, $content );
	}

	return [
		'template_id' => (int) $template_id,
		'kind'        => $kind,
		'edit_url'    => (string) get_edit_post_link( (int) $template_id, 'raw' ),
	];
}
