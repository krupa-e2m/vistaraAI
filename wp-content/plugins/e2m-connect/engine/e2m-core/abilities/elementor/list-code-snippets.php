<?php
/**
 * E2M Connect MCP - Elementor List Code Snippets (Pro)
 *
 * Lists every elementor_snippet post, grouped by location. Useful for
 * auditing site-wide code before making changes.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-list-code-snippets', [
	'label'       => __( '[Elementor] List Code Snippets', 'e2mconnect' ),
	'description' => 'Pro-only. Lists Elementor Custom Code snippets with their location, priority, and enabled state.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'location' => [ 'type' => 'string', 'enum' => [ 'any', 'head', 'body_start', 'body_end' ], 'default' => 'any' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'snippets' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'snippet_id' => [ 'type' => 'integer' ],
						'title'      => [ 'type' => 'string' ],
						'location'   => [ 'type' => 'string' ],
						'priority'   => [ 'type' => 'integer' ],
						'enabled'    => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_list_code_snippets_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: List Code Snippets (Pro)',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-list-code-snippets ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_list_code_snippets_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}

	$filter = isset( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : 'any';

	$posts = get_posts(
		[
			'post_type'      => 'elementor_snippet',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		]
	);

	$rows = [];
	foreach ( (array) $posts as $post ) {
		$location = (string) get_post_meta( $post->ID, '_elementor_snippet_location', true );
		if ( $filter !== 'any' && $location !== $filter ) {
			continue;
		}
		$rows[] = [
			'snippet_id' => (int) $post->ID,
			'title'      => get_the_title( $post ),
			'location'   => $location !== '' ? $location : 'head',
			'priority'   => (int) get_post_meta( $post->ID, '_elementor_snippet_priority', true ) ?: 10,
			'enabled'    => $post->post_status === 'publish',
		];
	}

	return [ 'snippets' => $rows ];
}
