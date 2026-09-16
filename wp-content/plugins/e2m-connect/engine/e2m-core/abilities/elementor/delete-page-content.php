<?php
/**
 * E2M Connect MCP - Elementor Delete Page Content
 *
 * Clears the Elementor content of a post (_elementor_data) while leaving
 * the post itself intact. Useful when an agent wants to rebuild a page
 * from scratch with build-page. Does not remove the post or flip it out
 * of builder mode - use update_page with status='trash' for that.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-delete-page-content', [
	'label'       => __( '[Elementor] Delete Page Content', 'e2mconnect' ),
	'description' => 'Wipes the Elementor content of a post while leaving the post intact.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'cleared' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_delete_page_content_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Delete Page Content',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-delete-page-content ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_delete_page_content_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	// Write an empty JSON array so Elementor still recognises the post as
	// builder-authored but with no content; the alternative of deleting
	// the meta entirely tends to flip the post back to "classic" mode.
	update_post_meta( $post_id, '_elementor_data', wp_slash( '[]' ) );
	delete_post_meta( $post_id, '_elementor_css' );

	return [
		'post_id' => $post_id,
		'cleared' => true,
	];
}
