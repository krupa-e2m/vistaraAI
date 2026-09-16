<?php
/**
 * E2M Connect MCP - Get Server Compatibility
 *
 * Reports a quick environment snapshot (PHP version, extensions, memory,
 * upload limits, WordPress prerequisites) used by E2M Connect and common page
 * builders. Lets agents verify a site is ready for a workflow before firing
 * long-running operations that would otherwise fail late.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-server-compatibility', [
	'label'       => __( '[Site] Server Compatibility', 'e2mconnect' ),
	'description' => 'Returns server environment info (PHP, extensions, memory, uploads, WP prerequisites) with pass/warn/fail flags.',
	'category'    => 'e2m-context',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'php'             => [
				'type'       => 'object',
				'properties' => [
					'version'       => [ 'type' => 'string' ],
					'min_required'  => [ 'type' => 'string' ],
					'meets_minimum' => [ 'type' => 'boolean' ],
				],
			],
			'wordpress'       => [
				'type'       => 'object',
				'properties' => [
					'version'       => [ 'type' => 'string' ],
					'min_required'  => [ 'type' => 'string' ],
					'meets_minimum' => [ 'type' => 'boolean' ],
				],
			],
			'extensions'      => [
				'type'                 => 'object',
				'additionalProperties' => [ 'type' => 'boolean' ],
			],
			'limits'          => [
				'type'       => 'object',
				'properties' => [
					'memory_limit'        => [ 'type' => 'string' ],
					'max_execution_time'  => [ 'type' => 'integer' ],
					'upload_max_filesize' => [ 'type' => 'string' ],
					'post_max_size'       => [ 'type' => 'string' ],
					'max_input_vars'      => [ 'type' => 'integer' ],
				],
			],
			'multisite'       => [ 'type' => 'boolean' ],
			'ssl'             => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_server_compatibility_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Server Compatibility',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-server-compatibility ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_get_server_compatibility_ability( array $input ): array {
	$php_min = '8.0';
	$wp_min  = '6.5';

	$extensions_to_probe = [ 'curl', 'json', 'mbstring', 'openssl', 'xml', 'zip', 'gd', 'imagick', 'fileinfo' ];
	$ext_map             = [];
	foreach ( $extensions_to_probe as $ext ) {
		$ext_map[ $ext ] = extension_loaded( $ext );
	}

	return [
		'php' => [
			'version'       => PHP_VERSION,
			'min_required'  => $php_min,
			'meets_minimum' => version_compare( PHP_VERSION, $php_min, '>=' ),
		],
		'wordpress' => [
			'version'       => (string) get_bloginfo( 'version' ),
			'min_required'  => $wp_min,
			'meets_minimum' => version_compare( (string) get_bloginfo( 'version' ), $wp_min, '>=' ),
		],
		'extensions' => $ext_map,
		'limits'     => [
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
			'max_input_vars'      => (int) ini_get( 'max_input_vars' ),
		],
		'multisite' => is_multisite(),
		'ssl'       => is_ssl(),
	];
}
