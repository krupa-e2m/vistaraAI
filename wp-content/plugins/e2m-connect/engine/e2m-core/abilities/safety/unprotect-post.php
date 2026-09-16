<?php
/**
 * E2M Connect MCP - Unprotect Post.
 *
 * Removes a post ID from the protected list. After this call, MCP tools
 * are free to edit / delete the post again. Paired with protect-post
 * and list-protected-posts.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/unprotect-post', [
	'label'       => __( '[Safety] Unprotect Post', 'e2mconnect' ),
	'description' => 'Removes a post ID from the protected list so MCP tools can write to it again.',
	'category'    => 'e2m-safety',

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
			'post_id'         => [ 'type' => 'integer' ],
			'protected_posts' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_unprotect_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Unprotect Post',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the unprotect-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_unprotect_post_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$saved = get_option( 'e2m_engine_settings', [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}
	$list = isset( $saved['protected_posts'] ) && is_array( $saved['protected_posts'] )
		? array_map( 'intval', $saved['protected_posts'] )
		: [];

	$list = array_values( array_filter( $list, static fn( int $id ) => $id !== $post_id ) );

	$saved['protected_posts'] = $list;
	update_option( 'e2m_engine_settings', $saved );
	e2m_engine_get_settings( true );

	E2M_Audit_Log::record(
		[
			'ability_name' => 'e2m/unprotect-post',
			'input'        => [ 'post_id' => $post_id ],
			'http_method'  => 'POST',
			'post_id'      => $post_id,
			'success'      => true,
		]
	);

	return [
		'post_id'         => $post_id,
		'protected_posts' => $list,
	];
}
