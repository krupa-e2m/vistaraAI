<?php
/**
 * E2M Connect MCP - Update Plugin
 *
 * Runs a single-plugin upgrade via core's Plugin_Upgrader. Intentionally does
 * NOT auto-deactivate / reactivate the plugin; WordPress keeps active plugins
 * active across upgrades on its own.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-plugin', [
	'label'       => __( '[Plugin] Update', 'e2mconnect' ),
	'description' => 'Upgrades a single plugin to the latest WordPress.org release.',
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

	'execute_callback'    => 'e2m_engine_update_plugin_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Update Plugin',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the update-plugin ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_plugin_ability( array $input ) {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to update plugins.', 'e2mconnect' ) );
	}

	$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';
	if ( $plugin_file === '' ) {
		return new WP_Error( 'invalid_plugin_file', __( 'plugin_file is required.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem(); // Initialise the WP filesystem abstraction layer (provided by file.php).
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->upgrade( $plugin_file );

	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( $result === false ) {
		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}
		return new WP_Error( 'upgrade_failed', __( 'Plugin upgrade failed.', 'e2mconnect' ) );
	}

	return [
		'plugin_file' => $plugin_file,
		'state'       => 'updated',
	];
}
