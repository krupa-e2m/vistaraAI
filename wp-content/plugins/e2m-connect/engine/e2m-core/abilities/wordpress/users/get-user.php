<?php
/**
 * E2M Connect MCP - Get User
 *
 * Returns a single user record. Email and capabilities are only included for
 * callers who have list_users; other callers get a reduced public profile so
 * we don't leak PII through MCP.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-user', [
	'label'       => __( '[User] Get User', 'e2mconnect' ),
	'description' => 'Retrieves a single WordPress user by user_id. Sensitive fields (email, roles) require list_users capability.',
	'category'    => 'e2m-users',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'user_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id'      => [ 'type' => 'integer' ],
			'login'        => [ 'type' => 'string' ],
			'email'        => [ 'type' => 'string' ],
			'display_name' => [ 'type' => 'string' ],
			'nicename'     => [ 'type' => 'string' ],
			'url'          => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
			'registered'   => [ 'type' => 'string' ],
			'roles'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_user_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Get User',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-user ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_user_ability( array $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user_id', __( 'A valid user_id is required.', 'e2mconnect' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'e2mconnect' ) );
	}

	$can_see_private = current_user_can( 'list_users' ) || get_current_user_id() === $user_id;

	return [
		'user_id'      => (int) $user->ID,
		'login'        => (string) $user->user_login,
		'email'        => $can_see_private ? (string) $user->user_email : '',
		'display_name' => (string) $user->display_name,
		'nicename'     => (string) $user->user_nicename,
		'url'          => (string) $user->user_url,
		'description'  => (string) get_user_meta( $user_id, 'description', true ),
		'registered'   => mysql2date( 'c', $user->user_registered, false ),
		'roles'        => $can_see_private ? array_values( (array) $user->roles ) : [],
	];
}
