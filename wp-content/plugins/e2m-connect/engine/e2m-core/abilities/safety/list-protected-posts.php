<?php
/**
 * E2M Connect MCP - List Protected Posts.
 *
 * Returns the current list of post IDs that MCP refuses to write to,
 * enriched with title/type/status so admins (and agents) can review the
 * list without calling get-post for each row.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-protected-posts', [
	'label'       => __( '[Safety] Protected Posts', 'e2mconnect' ),
	'description' => 'Returns the list of post IDs MCP refuses to write to, with their titles and types.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'protected_posts' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
						'title'   => [ 'type' => 'string' ],
						'type'    => [ 'type' => 'string' ],
						'status'  => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_protected_posts_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Protected Posts',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-protected-posts ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_protected_posts_ability( array $input ): array {
	$settings = e2m_engine_get_settings();
	$ids      = array_map( 'intval', (array) ( $settings['protected_posts'] ?? [] ) );

	$rows = [];
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			// Silently drop ghost IDs - the post was deleted outside MCP.
			continue;
		}
		$rows[] = [
			'post_id' => (int) $post->ID,
			'title'   => get_the_title( $post ),
			'type'    => (string) $post->post_type,
			'status'  => (string) $post->post_status,
		];
	}

	return [ 'protected_posts' => $rows ];
}
