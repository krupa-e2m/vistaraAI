<?php
/**
 * E2M Connect MCP - Elementor List Templates
 *
 * Lists the saved Elementor templates in the local library. Filters by
 * template type (page, section, container, header, footer, etc.) so
 * agents can pick the right template for the current context.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-list-templates', [
	'label'       => __( '[Elementor] List Templates', 'e2mconnect' ),
	'description' => 'Lists saved Elementor templates filtered by template_type (page, section, container, popup, etc.).',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'template_type' => [ 'type' => 'string', 'description' => 'Filter by Elementor template_type meta.' ],
			'per_page'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
			'page'          => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'     => [ 'type' => 'integer' ],
			'templates' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'template_id' => [ 'type' => 'integer' ],
						'title'       => [ 'type' => 'string' ],
						'type'        => [ 'type' => 'string' ],
						'modified'    => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_list_templates_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: List Templates',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-list-templates ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_list_templates_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 50;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$type     = isset( $input['template_type'] ) ? sanitize_key( (string) $input['template_type'] ) : '';

	$args = [
		'post_type'      => 'elementor_library',
		'post_status'    => 'any',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'no_found_rows'  => false,
	];
	if ( $type !== '' ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$args['meta_key']   = '_elementor_template_type';
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$args['meta_value'] = $type;
	}

	$query = new WP_Query( $args );

	$rows = [];
	foreach ( $query->posts as $post ) {
		$rows[] = [
			'template_id' => (int) $post->ID,
			'title'       => get_the_title( $post ),
			'type'        => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
			'modified'    => mysql2date( 'c', $post->post_modified_gmt, false ),
		];
	}

	return [
		'total'     => (int) $query->found_posts,
		'templates' => $rows,
	];
}
