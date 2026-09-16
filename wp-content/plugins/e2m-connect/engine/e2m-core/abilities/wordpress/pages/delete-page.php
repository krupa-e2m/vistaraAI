<?php
/**
 * E2M Connect MCP - Delete Page
 *
 * Deletes a WordPress page by ID. Supports soft delete (move to Trash) and
 * hard delete (permanent removal) via a single force flag. Returns the final
 * resting state so MCP clients can render accurate status messaging.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-page', [
	'label'       => __( '[Page] Delete Page', 'e2mconnect' ),
	'description' => 'Deletes a WordPress page by page_id. Defaults to a soft delete (trash). Pass force=true to permanently remove the page.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'page_id' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'The page ID to delete.',
			],
			'force' => [
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'If true, permanently delete (bypass trash). Irreversible.',
			],
		],
		'required'             => [ 'page_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'page_id' => [ 'type' => 'integer' ],
			'state'   => [ 'type' => 'string', 'description' => '"trashed" or "deleted".' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_page_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Page',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-page ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_page_ability( array $input ) {
	$page_id = isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
	$force   = ! empty( $input['force'] );

	if ( $page_id <= 0 ) {
		return new WP_Error( 'invalid_page_id', __( 'A valid page_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $page_id );
	if ( ! $post || $post->post_type !== 'page' ) {
		return new WP_Error( 'page_not_found', __( 'Page not found.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'delete_page', $page_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete this page.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $page_id, $force );

	if ( $result === false || $result === null ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete page.', 'e2mconnect' ) );
	}

	return [
		'page_id' => $page_id,
		'state'   => $force ? 'deleted' : 'trashed',
	];
}
