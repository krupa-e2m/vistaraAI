<?php
/**
 * E2M Connect MCP - Get Site Context
 *
 * Returns an "about this site" snapshot: identity, URLs, language, post type
 * and taxonomy summary, active theme, and WordPress/PHP versions. Designed
 * to be the first call an MCP agent makes when attaching to a new site.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-site-context', [
	'label'       => __( '[Site] Site Context', 'e2mconnect' ),
	'description' => 'Returns a snapshot of site identity, versions, registered post types and taxonomies, and the active theme.',
	'category'    => 'e2m-context',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'name'             => [ 'type' => 'string' ],
			'description'      => [ 'type' => 'string' ],
			'url'              => [ 'type' => 'string' ],
			'home_url'         => [ 'type' => 'string' ],
			'admin_url'        => [ 'type' => 'string' ],
			'language'         => [ 'type' => 'string' ],
			'timezone'         => [ 'type' => 'string' ],
			'wordpress_version'=> [ 'type' => 'string' ],
			'php_version'      => [ 'type' => 'string' ],
			'is_multisite'     => [ 'type' => 'boolean' ],
			'active_theme'     => [
				'type'       => 'object',
				'properties' => [
					'name'    => [ 'type' => 'string' ],
					'version' => [ 'type' => 'string' ],
					'parent'  => [ 'type' => 'string' ],
					'stylesheet' => [ 'type' => 'string' ],
					'template'   => [ 'type' => 'string' ],
				],
			],
			'post_types'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'taxonomies'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'counts'           => [
				'type'       => 'object',
				'properties' => [
					'posts'       => [ 'type' => 'integer' ],
					'pages'       => [ 'type' => 'integer' ],
					'users'       => [ 'type' => 'integer' ],
					'attachments' => [ 'type' => 'integer' ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_get_site_context_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'Get Site Context',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-site-context ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_get_site_context_ability( array $input ): array {
	$theme = wp_get_theme();

	$post_counts = wp_count_posts( 'post' );
	$page_counts = wp_count_posts( 'page' );
	$users       = (int) count_users()['total_users'];
	$attachments = wp_count_posts( 'attachment' );

	return [
		'name'             => (string) get_bloginfo( 'name' ),
		'description'      => (string) get_bloginfo( 'description' ),
		'url'              => (string) get_site_url(),
		'home_url'         => (string) get_home_url(),
		'admin_url'        => (string) admin_url(),
		'language'         => (string) get_bloginfo( 'language' ),
		'timezone'         => (string) wp_timezone_string(),
		'wordpress_version'=> (string) get_bloginfo( 'version' ),
		'php_version'      => PHP_VERSION,
		'is_multisite'     => is_multisite(),
		'active_theme'     => [
			'name'       => (string) $theme->get( 'Name' ),
			'version'    => (string) $theme->get( 'Version' ),
			'parent'     => $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : '',
			'stylesheet' => (string) get_stylesheet(),
			'template'   => (string) get_template(),
		],
		'post_types'       => array_values( get_post_types( [ 'public' => true ], 'names' ) ),
		'taxonomies'       => array_values( get_taxonomies( [ 'public' => true ], 'names' ) ),
		'counts'           => [
			'posts'       => isset( $post_counts->publish ) ? (int) $post_counts->publish : 0,
			'pages'       => isset( $page_counts->publish ) ? (int) $page_counts->publish : 0,
			'users'       => $users,
			'attachments' => isset( $attachments->inherit ) ? (int) $attachments->inherit : 0,
		],
	];
}
