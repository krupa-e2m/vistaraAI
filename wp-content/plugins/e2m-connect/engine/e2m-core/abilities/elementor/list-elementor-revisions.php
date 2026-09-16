<?php
/**
 * E2M Connect MCP - List Elementor Revisions.
 *
 * Lists a page's native WordPress revisions that carry Elementor data, newest
 * first. Elementor stores _elementor_data into each editor-save revision, so
 * these double as a free recovery path. Restore with
 * e2m/restore-elementor-revision.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-elementor-revisions', [
	'label'       => __( '[Elementor] List Revisions', 'e2mconnect' ),
	'description' => 'Lists a page\'s native WordPress revisions that contain Elementor data (newest first), usable as a recovery layer.',
	'category'    => 'e2m-elementor',

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
			'post_id'   => [ 'type' => 'integer' ],
			'revisions' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'revision_id'        => [ 'type' => 'integer' ],
						'created_at'         => [ 'type' => 'string' ],
						'author'             => [ 'type' => 'integer' ],
						'has_elementor_data' => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_elementor_revisions_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Elementor Revisions',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_elementor_revisions_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! class_exists( 'E2M_Elementor_Backup' ) ) {
		return new WP_Error( 'e2m_backup_unavailable', __( 'Backup subsystem is not available.', 'e2mconnect' ) );
	}
	return [
		'post_id'   => $post_id,
		'revisions' => E2M_Elementor_Backup::list_revisions( $post_id ),
	];
}
