<?php
/**
 * E2M Connect MCP - Get Theme Docs
 *
 * Extracts machine-readable metadata from the active theme: registered
 * supports, menu locations, image sizes, template parts, and a listing of
 * every file under /templates and /parts so agents can target template
 * overrides without guessing paths.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-theme-docs', [
	'label'       => __( '[Site] Theme Docs', 'e2mconnect' ),
	'description' => 'Reports the active theme\'s supports, menu locations, image sizes, and template files so agents can target the right override.',
	'category'    => 'e2m-context',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'name'            => [ 'type' => 'string' ],
			'version'         => [ 'type' => 'string' ],
			'stylesheet'      => [ 'type' => 'string' ],
			'template'        => [ 'type' => 'string' ],
			'is_block_theme'  => [ 'type' => 'boolean' ],
			'supports'        => [ 'type' => 'array',  'items' => [ 'type' => 'string' ] ],
			'menu_locations'  => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ],
			'image_sizes'     => [
				'type'                 => 'object',
				'additionalProperties' => [
					'type'       => 'object',
					'properties' => [
						'width'  => [ 'type' => 'integer' ],
						'height' => [ 'type' => 'integer' ],
						'crop'   => [ 'type' => 'boolean' ],
					],
				],
			],
			'template_files'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'template_parts'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'block_patterns'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_theme_docs_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Theme Docs',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-theme-docs ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_get_theme_docs_ability( array $input ): array {
	$theme = wp_get_theme();

	$supports = [];
	foreach ( [
		'title-tag', 'custom-logo', 'custom-background', 'custom-header',
		'post-thumbnails', 'post-formats', 'html5', 'responsive-embeds',
		'editor-styles', 'wp-block-styles', 'align-wide', 'block-templates',
		'appearance-tools',
	] as $feature ) {
		if ( current_theme_supports( $feature ) ) {
			$supports[] = $feature;
		}
	}

	global $_wp_additional_image_sizes;
	$image_sizes = [];
	foreach ( get_intermediate_image_sizes() as $size ) {
		if ( in_array( $size, [ 'thumbnail', 'medium', 'medium_large', 'large' ], true ) ) {
			$image_sizes[ $size ] = [
				'width'  => (int) get_option( $size . '_size_w' ),
				'height' => (int) get_option( $size . '_size_h' ),
				'crop'   => (bool) get_option( $size . '_crop' ),
			];
		} elseif ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
			$image_sizes[ $size ] = [
				'width'  => (int) $_wp_additional_image_sizes[ $size ]['width'],
				'height' => (int) $_wp_additional_image_sizes[ $size ]['height'],
				'crop'   => (bool) $_wp_additional_image_sizes[ $size ]['crop'],
			];
		}
	}

	$template_dir   = get_stylesheet_directory();
	$template_files = e2m_engine_collect_theme_files( $template_dir, [ 'php', 'html' ], 200 );

	$parts_dir     = $template_dir . '/parts';
	$template_parts = is_dir( $parts_dir ) ? e2m_engine_collect_theme_files( $parts_dir, [ 'html', 'php' ], 100 ) : [];

	$patterns_dir   = $template_dir . '/patterns';
	$block_patterns = is_dir( $patterns_dir ) ? e2m_engine_collect_theme_files( $patterns_dir, [ 'php' ], 100 ) : [];

	return [
		'name'           => (string) $theme->get( 'Name' ),
		'version'        => (string) $theme->get( 'Version' ),
		'stylesheet'     => (string) get_stylesheet(),
		'template'       => (string) get_template(),
		'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
		'supports'       => $supports,
		'menu_locations' => (array) get_registered_nav_menus(),
		'image_sizes'    => $image_sizes,
		'template_files' => $template_files,
		'template_parts' => $template_parts,
		'block_patterns' => $block_patterns,
	];
}

/**
 * Walk a directory and collect relative paths for a whitelist of extensions.
 *
 * @param string $root
 * @param array<int,string> $extensions
 * @param int $cap
 * @return array<int,string>
 */
function e2m_engine_collect_theme_files( string $root, array $extensions, int $cap ): array {
	if ( ! is_dir( $root ) ) {
		return [];
	}
	$rel   = [];
	$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$ext = strtolower( (string) $file->getExtension() );
		if ( ! in_array( $ext, $extensions, true ) ) {
			continue;
		}
		$path  = wp_normalize_path( $file->getPathname() );
		$rel[] = ltrim( str_replace( wp_normalize_path( $root ), '', $path ), '/' );
		if ( count( $rel ) >= $cap ) {
			break;
		}
	}
	return $rel;
}
