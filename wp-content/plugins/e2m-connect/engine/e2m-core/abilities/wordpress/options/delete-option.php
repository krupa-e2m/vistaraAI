<?php
/**
 * E2M Connect MCP - Delete Option
 *
 * Removes a wp_options row by key. Deletion is blocked for protected and
 * core-critical keys to avoid one-shot site breakage through MCP.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-option', [
	'label'       => __( '[Options] Delete Option', 'e2mconnect' ),
	'description' => 'Deletes a wp_options row by key. Protected and core-critical keys cannot be deleted.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'key' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'key' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'key'     => [ 'type' => 'string' ],
			'deleted' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_option_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Delete Option',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-option ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_option_ability( array $input ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete options.', 'e2mconnect' ) );
	}

	$key = isset( $input['key'] ) ? trim( (string) $input['key'] ) : '';
	if ( $key === '' ) {
		return new WP_Error( 'invalid_key', __( 'Option key is required.', 'e2mconnect' ) );
	}

	$core_critical = [
		'siteurl', 'home', 'blogname', 'admin_email', 'template', 'stylesheet',
		'db_version', 'active_plugins', 'permalink_structure',
	];
	if ( in_array( $key, $core_critical, true ) ) {
		return new WP_Error( 'core_protected', __( 'This option is critical to WordPress and cannot be deleted.', 'e2mconnect' ) );
	}

	$deleted = delete_option( $key );

	return [
		'key'     => $key,
		'deleted' => (bool) $deleted,
	];
}
