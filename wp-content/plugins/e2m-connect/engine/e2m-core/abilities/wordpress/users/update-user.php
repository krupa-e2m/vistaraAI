<?php
/**
 * E2M Connect MCP - Update User
 *
 * Partial update of a user profile. Role changes require promote_users. When
 * the user updates themselves, edit_user capability is enough; admins editing
 * others require edit_users.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-user', [
	'label'       => __( '[User] Update User', 'e2mconnect' ),
	'description' => 'Updates a user profile (display name, email, password, role, bio, URL).',
	'category'    => 'e2m-users',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
			'email'        => [ 'type' => 'string' ],
			'display_name' => [ 'type' => 'string' ],
			'first_name'   => [ 'type' => 'string' ],
			'last_name'    => [ 'type' => 'string' ],
			'url'          => [ 'type' => 'string' ],
			'description'  => [ 'type' => 'string' ],
			'password'     => [ 'type' => 'string' ],
			'role'         => [ 'type' => 'string' ],
		],
		'required'             => [ 'user_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id'        => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_user_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Update User',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-user ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_user_ability( array $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user_id', __( 'A valid user_id is required.', 'e2mconnect' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this user.', 'e2mconnect' ) );
	}

	if ( isset( $input['role'] ) && ! current_user_can( 'promote_users' ) ) {
		return new WP_Error( 'forbidden_role', __( 'You do not have permission to change user roles.', 'e2mconnect' ) );
	}

	$patch   = [ 'ID' => $user_id ];
	$touched = [];

	$scalar_map = [
		'email'        => 'user_email',
		'display_name' => 'display_name',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
		'url'          => 'user_url',
		'description'  => 'description',
		'password'     => 'user_pass',
	];

	foreach ( $scalar_map as $in_key => $db_key ) {
		if ( ! array_key_exists( $in_key, $input ) ) {
			continue;
		}
		$value = (string) $input[ $in_key ];
		$patch[ $db_key ] = match ( $in_key ) {
			'email'       => sanitize_email( $value ),
			'url'         => esc_url_raw( $value ),
			'description' => sanitize_textarea_field( $value ),
			'password'    => $value,
			default       => sanitize_text_field( $value ),
		};
		$touched[] = $in_key;
	}

	if ( array_key_exists( 'role', $input ) ) {
		$patch['role'] = sanitize_key( (string) $input['role'] );
		$touched[]     = 'role';
	}

	if ( count( $patch ) <= 1 ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	$result = wp_update_user( $patch );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'user_id'        => $user_id,
		'updated_fields' => $touched,
	];
}
