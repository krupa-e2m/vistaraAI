<?php
/**
 * E2M Connect MCP - Get Builder Info
 *
 * Reports which page builders are installed on the site and whether each is
 * currently active. Used by agents to route layout operations to the right
 * E2M Connect builder sub-category (elementor, gutenberg, bricks).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-builder-info', [
	'label'       => __( '[Site] Builder Info', 'e2mconnect' ),
	'description' => 'Reports installed and active page builders (Elementor, Gutenberg, Bricks, Divi, Beaver, Breakdance, etc.).',
	'category'    => 'e2m-context',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'builders' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'slug'      => [ 'type' => 'string' ],
						'name'      => [ 'type' => 'string' ],
						'installed' => [ 'type' => 'boolean' ],
						'active'    => [ 'type' => 'boolean' ],
						'version'   => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_get_builder_info_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Builder Info',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-builder-info ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_get_builder_info_ability( array $input ): array {
	$probes = [
		'elementor'   => [
			'name'         => 'Elementor',
			'active_const' => 'ELEMENTOR_VERSION',
			'plugin_file'  => 'elementor/elementor.php',
		],
		'elementor-pro' => [
			'name'         => 'Elementor Pro',
			'active_const' => 'ELEMENTOR_PRO_VERSION',
			'plugin_file'  => 'elementor-pro/elementor-pro.php',
		],
		'gutenberg'   => [
			'name'         => 'Gutenberg (core block editor)',
			'active_const' => '',
			'plugin_file'  => 'gutenberg/gutenberg.php',
			'always_installed' => true,
		],
		'bricks'      => [
			'name'         => 'Bricks Builder',
			'active_const' => 'BRICKS_VERSION',
			'plugin_file'  => '',
			'is_theme'     => true,
			'theme_slug'   => 'bricks',
		],
		'divi'        => [
			'name'         => 'Divi',
			'active_const' => 'ET_BUILDER_VERSION',
			'plugin_file'  => 'divi-builder/divi-builder.php',
			'is_theme'     => true,
			'theme_slug'   => 'Divi',
		],
		'beaver'      => [
			'name'         => 'Beaver Builder',
			'active_const' => 'FL_BUILDER_VERSION',
			'plugin_file'  => 'beaver-builder-lite-version/fl-builder.php',
		],
		'breakdance'  => [
			'name'         => 'Breakdance',
			'active_const' => 'BREAKDANCE_VERSION',
			'plugin_file'  => 'breakdance/plugin.php',
		],
	];

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed_plugins = get_plugins();

	$rows = [];
	foreach ( $probes as $slug => $probe ) {
		$installed = ! empty( $probe['always_installed'] )
			|| ( ! empty( $probe['plugin_file'] ) && isset( $installed_plugins[ $probe['plugin_file'] ] ) )
			|| ( ! empty( $probe['is_theme'] ) && wp_get_theme( (string) ( $probe['theme_slug'] ?? '' ) )->exists() );

		$active = false;
		if ( ! empty( $probe['active_const'] ) ) {
			$active = defined( $probe['active_const'] );
		} elseif ( $slug === 'gutenberg' ) {
			$active = function_exists( 'register_block_type' );
		}

		$version = '';
		if ( ! empty( $probe['active_const'] ) && defined( $probe['active_const'] ) ) {
			$version = (string) constant( $probe['active_const'] );
		} elseif ( ! empty( $probe['plugin_file'] ) && isset( $installed_plugins[ $probe['plugin_file'] ]['Version'] ) ) {
			$version = (string) $installed_plugins[ $probe['plugin_file'] ]['Version'];
		}

		$rows[] = [
			'slug'      => $slug,
			'name'      => (string) $probe['name'],
			'installed' => $installed,
			'active'    => $active,
			'version'   => $version,
		];
	}

	return [ 'builders' => $rows ];
}
