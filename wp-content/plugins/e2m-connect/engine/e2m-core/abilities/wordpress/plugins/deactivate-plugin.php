<?php
/**
 * E2M Connect MCP - Deactivate Plugin
 *
 * Deactivates one or more plugins by their plugin_file path. Multisite callers
 * can specify network-wide scope.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/deactivate-plugin', [
	'label'       => __( '[Plugin] Deactivate', 'e2mconnect' ),
	'description' => 'Deactivates one or more plugins by plugin_file path.',
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

	'execute_callback'    => 'e2m_engine_deactivate_plugin_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Deactivate Plugin',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the deactivate-plugin ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_deactivate_plugin_ability( array $input ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to deactivate plugins.', 'e2mconnect' ) );
	}

	$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';
	$network     = ! empty( $input['network'] );

	if ( $plugin_file === '' ) {
		return new WP_Error( 'invalid_plugin_file', __( 'plugin_file is required.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( [ $plugin_file ], false, $network );

	return [
		'plugin_file' => $plugin_file,
		'state'       => 'inactive',
	];
}
