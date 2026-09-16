<?php
/**
 * E2M Connect MCP - Install Plugin
 *
 * Installs a plugin from the WordPress.org directory by slug using the core
 * Plugin_Upgrader machinery. Does NOT activate - pair with activate-plugin.
 * Requires install_plugins capability and a writable plugins directory.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/install-plugin', [
	'label'       => __( '[Plugin] Install', 'e2mconnect' ),
	'description' => 'Installs a plugin from the WordPress.org directory by its slug. Does not activate.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'slug' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'WordPress.org plugin slug (e.g. "wordpress-seo").' ],
		],
		'required'             => [ 'slug' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'slug'        => [ 'type' => 'string' ],
			'plugin_file' => [ 'type' => 'string' ],
			'version'     => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_install_plugin_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Install Plugin',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the install-plugin ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_install_plugin_ability( array $input ) {
	if ( ! current_user_can( 'install_plugins' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to install plugins.', 'e2mconnect' ) );
	}

	$slug = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
	if ( $slug === '' ) {
		return new WP_Error( 'invalid_slug', __( 'A valid WordPress.org plugin slug is required.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem(); // Initialise the WP filesystem abstraction layer (provided by file.php).
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$api = plugins_api(
		'plugin_information',
		[
			'slug'   => $slug,
			'fields' => [ 'short_description' => false, 'sections' => false, 'banners' => false ],
		]
	);
	if ( is_wp_error( $api ) ) {
		return $api;
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( (string) $api->download_link );

	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( $result === false ) {
		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}
		return new WP_Error( 'install_failed', __( 'Plugin install failed.', 'e2mconnect' ) );
	}

	$plugin_file = $upgrader->plugin_info();

	return [
		'slug'        => $slug,
		'plugin_file' => (string) $plugin_file,
		'version'     => (string) $api->version,
	];
}
