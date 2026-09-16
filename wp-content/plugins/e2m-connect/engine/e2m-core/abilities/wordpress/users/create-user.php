<?php
/**
 * E2M Connect MCP - Create User
 *
 * Creates a new WordPress user with role assignment. Password is optional;
 * when omitted, a strong random one is generated and the caller may send it
 * out-of-band. Requires create_users capability.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-user', [
	'label'       => __( '[User] Create User', 'e2mconnect' ),
	'description' => 'Creates a new WordPress user. If password is omitted, a strong random one is generated.',
	'category'    => 'e2m-users',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'login'        => [ 'type' => 'string', 'minLength' => 1 ],
			'email'        => [ 'type' => 'string', 'format' => 'email' ],
			'password'     => [ 'type' => 'string', 'description' => 'Optional. Auto-generated when omitted.' ],
			'display_name' => [ 'type' => 'string' ],
			'first_name'   => [ 'type' => 'string' ],
			'last_name'    => [ 'type' => 'string' ],
			'url'          => [ 'type' => 'string' ],
			'role'         => [ 'type' => 'string', 'default' => 'subscriber' ],
			'description'  => [ 'type' => 'string' ],
		],
		'required'             => [ 'login', 'email' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id'           => [ 'type' => 'integer' ],
			'generated_password'=> [ 'type' => 'string', 'description' => 'Returned only when password was auto-generated.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_user_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Create User',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-user ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_user_ability( array $input ) {
	if ( ! current_user_can( 'create_users' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to create users.', 'e2mconnect' ) );
	}

	$login = isset( $input['login'] ) ? sanitize_user( (string) $input['login'], true ) : '';
	$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';

	if ( $login === '' || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_input', __( 'Valid login and email are required.', 'e2mconnect' ) );
	}

	$generated = false;
	$password  = isset( $input['password'] ) ? (string) $input['password'] : '';
	if ( $password === '' ) {
		$password  = wp_generate_password( 24, true, true );
		$generated = true;
	}

	$user_id = wp_insert_user(
		[
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => isset( $input['display_name'] ) ? sanitize_text_field( (string) $input['display_name'] ) : $login,
			'first_name'   => isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '',
			'last_name'    => isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '',
			'user_url'     => isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '',
			'role'         => isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : 'subscriber',
			'description'  => isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
		]
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$out = [ 'user_id' => (int) $user_id ];
	if ( $generated ) {
		$out['generated_password'] = $password;
	}
	return $out;
}
