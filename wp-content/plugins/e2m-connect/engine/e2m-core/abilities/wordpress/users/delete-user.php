<?php
/**
 * E2M Connect MCP - Delete User
 *
 * Deletes a user account. When reassign_to is supplied, the user's content is
 * transferred to that user ID; otherwise their posts are deleted per core's
 * default behaviour. Users cannot delete themselves.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-user', [
	'label'       => __( '[User] Delete User', 'e2mconnect' ),
	'description' => 'Deletes a WordPress user. Content can be reassigned to another user via reassign_to.',
	'category'    => 'e2m-users',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'reassign_to' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Optional user ID to inherit deleted user\'s content.' ],
		],
		'required'             => [ 'user_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'user_id' => [ 'type' => 'integer' ],
			'state'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_user_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Delete User',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-user ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_user_ability( array $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user_id', __( 'A valid user_id is required.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'delete_users' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete users.', 'e2mconnect' ) );
	}

	if ( $user_id === get_current_user_id() ) {
		return new WP_Error( 'cannot_delete_self', __( 'You cannot delete your own account through this ability.', 'e2mconnect' ) );
	}

	if ( ! get_userdata( $user_id ) ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	$reassign = isset( $input['reassign_to'] ) ? (int) $input['reassign_to'] : null;
	if ( $reassign !== null && $reassign > 0 && ! get_userdata( $reassign ) ) {
		return new WP_Error( 'reassign_target_missing', __( 'reassign_to user does not exist.', 'e2mconnect' ) );
	}

	$ok = wp_delete_user( $user_id, $reassign ?: null );
	if ( ! $ok ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete user.', 'e2mconnect' ) );
	}

	return [
		'user_id' => $user_id,
		'state'   => 'deleted',
	];
}
