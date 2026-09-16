<?php
/**
 * E2M Connect MCP - Elementor Import Template
 *
 * Accepts a JSON envelope (typically produced by elementor-export-page)
 * and writes it into either a new template library entry or an existing
 * post. Every node is re-keyed so imports never collide with existing IDs.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-import-template', [
	'label'       => __( '[Elementor] Import Template', 'e2mconnect' ),
	'description' => 'Imports an Elementor JSON envelope into a template library entry (default) or an existing target post.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'content'       => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'page_settings' => [ 'type' => 'object', 'additionalProperties' => true ],
			'name'          => [ 'type' => 'string' ],
			'template_type' => [ 'type' => 'string', 'default' => 'page' ],
			'target_post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'mode'          => [ 'type' => 'string', 'enum' => [ 'append', 'replace' ], 'default' => 'replace' ],
		],
		'required'             => [ 'content' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'description' => 'ID of the created template or updated post.' ],
			'kind'    => [ 'type' => 'string', 'description' => '"template" for a new library entry, "post" when target_post_id was supplied.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_import_template_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Import Template',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-import-template ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_import_template_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$content = isset( $input['content'] ) && is_array( $input['content'] ) ? $input['content'] : [];
	if ( $content === [] ) {
		return new WP_Error( 'empty_content', __( 'content must be a non-empty array of Elementor nodes.', 'e2mconnect' ) );
	}

	$content = array_map( 'e2m_engine_elementor_rekey_tree', $content );

	$target_post_id = isset( $input['target_post_id'] ) ? (int) $input['target_post_id'] : 0;
	$page_settings  = isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ? $input['page_settings'] : [];

	// Mode A: write into an existing target post.
	if ( $target_post_id > 0 ) {
		if ( ! get_post( $target_post_id ) ) {
			return new WP_Error( 'target_missing', __( 'target_post_id not found.', 'e2mconnect' ) );
		}
		if ( ! current_user_can( 'edit_post', $target_post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to edit the target post.', 'e2mconnect' ) );
		}
		$mode      = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'replace';
		$existing  = $mode === 'append' ? E2M_Elementor_Helper::read_post_data( $target_post_id ) : [];
		$next_data = array_merge( $existing, $content );

		$write = E2M_Elementor_Helper::write_post_data( $target_post_id, $next_data );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		if ( $page_settings !== [] ) {
			update_post_meta( $target_post_id, '_elementor_page_settings', $page_settings );
			delete_post_meta( $target_post_id, '_elementor_css' );
		}

		return [ 'post_id' => $target_post_id, 'kind' => 'post' ];
	}

	// Mode B: create a new template library entry.
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to create templates.', 'e2mconnect' ) );
	}

	$name = sanitize_text_field( (string) ( $input['name'] ?? 'Imported Template' ) );
	$type = isset( $input['template_type'] ) ? sanitize_key( (string) $input['template_type'] ) : 'page';

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

	E2M_Elementor_Helper::write_post_data( (int) $template_id, $content );
	update_post_meta( (int) $template_id, '_elementor_template_type', $type );

	if ( $page_settings !== [] ) {
		update_post_meta( (int) $template_id, '_elementor_page_settings', $page_settings );
	}

	return [ 'post_id' => (int) $template_id, 'kind' => 'template' ];
}
