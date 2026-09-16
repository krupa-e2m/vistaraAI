<?php
/**
 * E2M Connect MCP - Protect Post.
 *
 * Adds a post ID to the protected list. MCP tools then refuse to write
 * to it. Intended as a lightweight "critical content" firewall: pin
 * the homepage, the checkout page, a signed landing page, etc.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/protect-post', [
	'label'       => __( '[Safety] Protect Post', 'e2mconnect' ),
	'description' => 'Adds a post ID to the protected list. MCP tools will refuse to write to protected posts.',
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

	'execute_callback'    => 'e2m_engine_protect_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Protect Post',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the protect-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_protect_post_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$saved   = get_option( 'e2m_engine_settings', [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}
	$list = isset( $saved['protected_posts'] ) && is_array( $saved['protected_posts'] )
		? array_map( 'intval', $saved['protected_posts'] )
		: [];

	if ( ! in_array( $post_id, $list, true ) ) {
		$list[] = $post_id;
	}

	$saved['protected_posts'] = $list;
	update_option( 'e2m_engine_settings', $saved );
	e2m_engine_get_settings( true ); // flush cache

	E2M_Audit_Log::record(
		[
			'ability_name' => 'e2m/protect-post',
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
