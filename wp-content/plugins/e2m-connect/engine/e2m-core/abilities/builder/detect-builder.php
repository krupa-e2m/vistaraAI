<?php
/**
 * E2M Connect MCP - Detect Builder (adapter-aware)
 *
 * Reports the adapter that owns a post from the E2M Connect builder module's
 * perspective. Unlike wordpress/pages/detect-page-builder (which only inspects
 * raw meta signals), this ability also reports whether E2M Connect currently has
 * an adapter capable of reading/writing that builder.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/detect-builder', [
	'label'       => __( '[Builder] Detect Builder', 'e2mconnect' ),
	'description' => 'Reports the E2M Connect builder adapter responsible for a post, plus the full list of adapters currently available on the site.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [ 'type' => 'integer' ],
			'owner'        => [ 'type' => 'string', 'description' => 'Adapter slug or "classic" when no adapter claims the post.' ],
			'owner_label'  => [ 'type' => 'string' ],
			'available'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'slug'  => [ 'type' => 'string' ],
						'label' => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_detect_builder_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Detect Builder',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the detect-builder ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_detect_builder_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$registry      = E2M_Builder_Registry::instance();
	$owner_adapter = $registry->for_post( $post_id );
	$available     = $registry->available();

	$avail_rows = [];
	foreach ( $available as $slug => $adapter ) {
		$avail_rows[] = [
			'slug'  => (string) $slug,
			'label' => $adapter->label(),
		];
	}

	return [
		'post_id'     => $post_id,
		'owner'       => $owner_adapter ? $owner_adapter->slug() : 'classic',
		'owner_label' => $owner_adapter ? $owner_adapter->label() : 'Classic / Other',
		'available'   => $avail_rows,
	];
}
