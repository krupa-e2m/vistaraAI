<?php
/**
 * E2M Connect MCP - Delete Plugin
 *
 * Removes a plugin from disk. Active plugins must be deactivated first; this
 * ability refuses the operation otherwise rather than silently deactivating
 * behind the caller's back.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-plugin', [
	'label'       => __( '[Plugin] Delete', 'e2mconnect' ),
	'description' => 'Removes a plugin from disk. The plugin must already be deactivated.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'plugin_file' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'plugin_file' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'plugin_file' => [ 'type' => 'string' ],
			'state'       => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_plugin_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Delete Plugin',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-plugin ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_plugin_ability( array $input ) {
	if ( ! current_user_can( 'delete_plugins' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete plugins.', 'e2mconnect' ) );
	}

	$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';
	if ( $plugin_file === '' ) {
		return new WP_Error( 'invalid_plugin_file', __( 'plugin_file is required.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! function_exists( 'is_plugin_active' ) ) { // Satisfies direct-use requirement for plugin.php.
		return new WP_Error( 'missing_function', __( 'WordPress plugin functions unavailable.', 'e2mconnect' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem(); // Initialise the WP filesystem abstraction layer (provided by file.php).

	if ( is_plugin_active( $plugin_file ) ) {
		return new WP_Error( 'plugin_active', __( 'Deactivate the plugin before deleting it.', 'e2mconnect' ) );
	}

	$result = delete_plugins( [ $plugin_file ] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( $result === false || $result === null ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete plugin.', 'e2mconnect' ) );
	}

	return [
		'plugin_file' => $plugin_file,
		'state'       => 'deleted',
	];
}
