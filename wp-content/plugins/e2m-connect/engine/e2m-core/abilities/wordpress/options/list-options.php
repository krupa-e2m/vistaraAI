<?php
/**
 * E2M Connect MCP - List Options
 *
 * Reads safe, whitelisted site options as a single payload (site identity,
 * reading settings, timezone, permalink, etc.). Arbitrary option lookups are
 * handled by get-option; this ability is the curated "about this site" view.
 *
 * A deny list blocks well-known private keys so MCP clients can't enumerate
 * secrets or tokens through a generic listing call.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-options', [
	'label'       => __( '[Options] List Options', 'e2mconnect' ),
	'description' => 'Returns a curated snapshot of public WordPress site options (identity, reading, permalink, timezone, etc.).',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'                 => 'object',
		'properties'           => [
			'options' => [ 'type' => 'object', 'additionalProperties' => true ],
		],
	],

	'execute_callback'    => 'e2m_engine_list_options_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'List Options',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-options ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_options_ability( array $input ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to read site options.', 'e2mconnect' ) );
	}

	$safe_keys = [
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'start_of_week',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'default_category',
		'default_post_format',
		'default_comment_status',
		'default_ping_status',
		'comments_per_page',
		'comment_moderation',
		'comment_registration',
		'posts_per_page',
		'posts_per_rss',
		'permalink_structure',
		'category_base',
		'tag_base',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'WPLANG',
		'blog_public',
		'template',
		'stylesheet',
	];

	$snapshot = [];
	foreach ( $safe_keys as $key ) {
		$snapshot[ $key ] = get_option( $key );
	}

	return [ 'options' => $snapshot ];
}
