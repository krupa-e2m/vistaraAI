<?php
/**
 * E2M Connect MCP - List Plugins
 *
 * Lists every plugin installed on the site with activation state, version,
 * author and network-activation status. A useful starting point before any
 * install / activate / deactivate operation.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-plugins', [
	'label'       => __( '[Plugin] List Plugins', 'e2mconnect' ),
	'description' => 'Lists every installed plugin with activation state, version, and author.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'status' => [ 'type' => 'string', 'enum' => [ 'all', 'active', 'inactive', 'network_active' ], 'default' => 'all' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'plugins' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'plugin_file'    => [ 'type' => 'string' ],
						'name'           => [ 'type' => 'string' ],
						'slug'           => [ 'type' => 'string' ],
						'version'        => [ 'type' => 'string' ],
						'author'         => [ 'type' => 'string' ],
						'description'    => [ 'type' => 'string' ],
						'plugin_uri'     => [ 'type' => 'string' ],
						'status'         => [ 'type' => 'string' ],
						'network_active' => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_plugins_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'List Plugins',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-plugins ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_plugins_ability( array $input ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to view plugins.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all             = get_plugins();
	$active          = (array) get_option( 'active_plugins', [] );
	$network_active  = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', [] ) : [];
	$status_filter   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'all';

	$rows = [];
	foreach ( $all as $file => $data ) {
		$is_active         = in_array( $file, $active, true );
		$is_network_active = isset( $network_active[ $file ] );

		$status = $is_network_active ? 'network_active' : ( $is_active ? 'active' : 'inactive' );

		if ( $status_filter !== 'all' && $status !== $status_filter ) {
			continue;
		}

		$rows[] = [
			'plugin_file'    => (string) $file,
			'name'           => (string) ( $data['Name'] ?? '' ),
			'slug'           => dirname( $file ) !== '.' ? (string) dirname( $file ) : (string) pathinfo( $file, PATHINFO_FILENAME ),
			'version'        => (string) ( $data['Version'] ?? '' ),
			'author'         => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
			'description'    => wp_strip_all_tags( (string) ( $data['Description'] ?? '' ) ),
			'plugin_uri'     => (string) ( $data['PluginURI'] ?? '' ),
			'status'         => $status,
			'network_active' => $is_network_active,
		];
	}

	return [ 'plugins' => $rows ];
}
