<?php
/**
 * E2M Connect MCP - Search Stock Images
 *
 * Queries the Openverse Creative Commons search API and returns a list of
 * candidate images. Does NOT download anything - pair this with sideload-image
 * to persist a result into the Media Library with proper attribution.
 *
 * Openverse is ad-free, CC-licensed, and requires no API key for basic usage.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/search-stock-images', [
	'label'       => __( '[Media] Search Stock Images', 'e2mconnect' ),
	'description' => 'Searches the Openverse Creative Commons database and returns candidate images with license metadata. Does not download; use sideload-image to import a selection.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'query'       => [ 'type' => 'string', 'minLength' => 1 ],
			'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ],
			'orientation' => [ 'type' => 'string', 'enum' => [ 'tall', 'wide', 'square', 'any' ], 'default' => 'any' ],
			'license'     => [ 'type' => 'string', 'description' => 'Comma-separated Openverse license filter (e.g. "cc0,pdm,by").' ],
		],
		'required'             => [ 'query' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'results' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'              => [ 'type' => 'string' ],
						'title'           => [ 'type' => 'string' ],
						'url'             => [ 'type' => 'string' ],
						'thumbnail'       => [ 'type' => 'string' ],
						'source'          => [ 'type' => 'string' ],
						'creator'         => [ 'type' => 'string' ],
						'license'         => [ 'type' => 'string' ],
						'license_version' => [ 'type' => 'string' ],
						'license_url'     => [ 'type' => 'string' ],
						'foreign_landing_url' => [ 'type' => 'string' ],
						'width'           => [ 'type' => 'integer' ],
						'height'          => [ 'type' => 'integer' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_search_stock_images_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Search Stock Images',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the search-stock-images ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_search_stock_images_ability( array $input ) {
	$query = isset( $input['query'] ) ? trim( sanitize_text_field( (string) $input['query'] ) ) : '';
	if ( $query === '' ) {
		return new WP_Error( 'invalid_query', __( 'A non-empty query is required.', 'e2mconnect' ) );
	}

	$per_page    = isset( $input['per_page'] ) ? max( 1, min( 20, (int) $input['per_page'] ) ) : 10;
	$orientation = isset( $input['orientation'] ) ? sanitize_key( (string) $input['orientation'] ) : 'any';

	$params = [
		'q'             => $query,
		'page_size'     => $per_page,
	];

	if ( $orientation !== 'any' ) {
		$params['aspect_ratio'] = $orientation === 'wide'
			? 'wide'
			: ( $orientation === 'tall' ? 'tall' : 'square' );
	}

	if ( ! empty( $input['license'] ) ) {
		$params['license'] = sanitize_text_field( (string) $input['license'] );
	}

	$endpoint = add_query_arg( $params, 'https://api.openverse.engineering/v1/images/' );

	$response = wp_safe_remote_get(
		$endpoint,
		[
			'timeout' => 15,
			'headers' => [
				'Accept'     => 'application/json',
				'User-Agent' => 'E2M Connect-MCP/1.0',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'openverse_http_error', sprintf( 'Openverse returned HTTP %d.', $status ) );
	}

	$body    = (string) wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['results'] ) ) {
		return new WP_Error( 'invalid_response', __( 'Unexpected response format from Openverse.', 'e2mconnect' ) );
	}

	$results = [];
	foreach ( (array) $decoded['results'] as $item ) {
		$results[] = [
			'id'                  => (string) ( $item['id'] ?? '' ),
			'title'               => (string) ( $item['title'] ?? '' ),
			'url'                 => (string) ( $item['url'] ?? '' ),
			'thumbnail'           => (string) ( $item['thumbnail'] ?? '' ),
			'source'              => (string) ( $item['source'] ?? '' ),
			'creator'             => (string) ( $item['creator'] ?? '' ),
			'license'             => (string) ( $item['license'] ?? '' ),
			'license_version'     => (string) ( $item['license_version'] ?? '' ),
			'license_url'         => (string) ( $item['license_url'] ?? '' ),
			'foreign_landing_url' => (string) ( $item['foreign_landing_url'] ?? '' ),
			'width'               => (int) ( $item['width'] ?? 0 ),
			'height'              => (int) ( $item['height'] ?? 0 ),
		];
	}

	return [ 'results' => $results ];
}
