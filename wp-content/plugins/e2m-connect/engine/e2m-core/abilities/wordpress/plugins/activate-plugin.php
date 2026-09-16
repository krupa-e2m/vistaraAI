<?php
/**
 * E2M Connect MCP - Activate Plugin
 *
 * Activates a plugin by its canonical plugin_file path (e.g.
 * "akismet/akismet.php"). Supports optional network-wide activation on
 * multisite installs.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/activate-plugin', [
	'label'       => __( '[Plugin] Activate', 'e2mconnect' ),
	'description' => 'Activates an installed plugin by its plugin_file path.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'plugin_file' => [ 'type' => 'string', 'minLength' => 1 ],
			'network'     => [ 'type' => 'boolean', 'default' => false ],
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

	'execute_callback'    => 'e2m_engine_activate_plugin_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Activate Plugin',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the activate-plugin ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_activate_plugin_ability( array $input ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to activate plugins.', 'e2mconnect' ) );
	}

	$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';
	$network     = ! empty( $input['network'] );

	if ( $plugin_file === '' ) {
		return new WP_Error( 'invalid_plugin_file', __( 'plugin_file is required.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$result = activate_plugin( $plugin_file, '', $network );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'plugin_file' => $plugin_file,
		'state'       => $network ? 'network_active' : 'active',
	];
}
