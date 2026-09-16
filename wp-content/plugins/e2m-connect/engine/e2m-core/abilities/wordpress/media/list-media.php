<?php
/**
 * E2M Connect MCP - List Media
 *
 * Browses the WordPress Media Library with pagination, search and mime-type
 * filters. Returns URL + metadata for each attachment so agents can decide
 * whether to re-use an existing file before uploading a new one.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-media', [
	'label'       => __( '[Media] List Media', 'e2mconnect' ),
	'description' => 'Lists attachments in the Media Library with pagination, search, and MIME-type filters.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'search'    => [ 'type' => 'string' ],
			'mime_type' => [ 'type' => 'string', 'description' => 'Filter by MIME (e.g. "image", "image/jpeg", "video").' ],
			'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'      => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'orderby'   => [ 'type' => 'string', 'enum' => [ 'date', 'title', 'ID' ], 'default' => 'date' ],
			'order'     => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'media'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [ 'type' => 'integer' ],
						'title'         => [ 'type' => 'string' ],
						'slug'          => [ 'type' => 'string' ],
						'mime_type'     => [ 'type' => 'string' ],
						'url'           => [ 'type' => 'string' ],
						'filesize'      => [ 'type' => 'integer' ],
						'width'         => [ 'type' => 'integer' ],
						'height'        => [ 'type' => 'integer' ],
						'alt'           => [ 'type' => 'string' ],
						'date'          => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_media_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'List Media',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-media ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_media_ability( array $input ): array {
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'date',
		'order'          => ( isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
		'no_found_rows'  => false,
	];

	if ( ! empty( $input['search'] ) ) {
		$args['s'] = sanitize_text_field( (string) $input['search'] );
	}

	if ( ! empty( $input['mime_type'] ) ) {
		$args['post_mime_type'] = sanitize_text_field( (string) $input['mime_type'] );
	}

	$query = new WP_Query( $args );

	$rows = [];
	foreach ( $query->posts as $attachment ) {
		$meta = wp_get_attachment_metadata( $attachment->ID );

		$rows[] = [
			'attachment_id' => (int) $attachment->ID,
			'title'         => get_the_title( $attachment ),
			'slug'          => (string) $attachment->post_name,
			'mime_type'     => (string) $attachment->post_mime_type,
			'url'           => (string) wp_get_attachment_url( $attachment->ID ),
			'filesize'      => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0,
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'alt'           => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'date'          => mysql2date( 'c', $attachment->post_date_gmt, false ),
		];
	}

	return [
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => $per_page,
		'media'    => $rows,
	];
}
