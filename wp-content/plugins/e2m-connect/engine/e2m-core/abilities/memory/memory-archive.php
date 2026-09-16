<?php
/**
 * E2M Connect — Memory: Archive (soft delete).
 *
 * Marks a memory as archived. The memory and its content stay in the
 * database; default reads exclude it. Users can restore from the React
 * admin UI in M5.
 *
 * AI agents only get archive, never hard-delete — that's an admin-only
 * action via the REST route in e2m-core/memory/class-e2m-memory-rest.php.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability(
	'e2m/memory-archive',
	[
		'label'       => __( '[Memory] Archive Memory', 'e2mconnect' ),
		'description' => __( 'Soft-delete a memory by setting its status to archived. The memory is preserved and the human site owner can restore it from the E2M Connect admin UI. Use this instead of deletion when you no longer think a memory is useful.', 'e2mconnect' ),
		'category'    => 'e2m-memory',

		'permission_callback' => 'e2m_memory_can_write',

		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Id of the memory to archive.' ],
			],
			'required'             => [ 'id' ],
			'additionalProperties' => false,
		],

		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer' ],
				'status'  => [ 'type' => 'string' ],
				'success' => [ 'type' => 'boolean' ],
			],
		],

		'meta' => [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'title'        => 'Archive a memory',
				'instructions' => 'Use when a memory is no longer useful but you do not want to permanently delete it. Archive is reversible from the admin UI. Hard delete is intentionally not available to agents.',
				'readonly'     => false,
				'destructive'  => false,
				'idempotent'   => true,
			],
		],

		'execute_callback' => 'e2m_memory_archive_execute',
	]
);

/**
 * @param array<string,mixed>|null $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_memory_archive_execute( ?array $input = null ): array|WP_Error {
	$input = is_array( $input ) ? $input : [];
	$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return new WP_Error( 'missing_id', __( 'Memory id is required.', 'e2mconnect' ), [ 'status' => 400 ] );
	}

	$memory = E2M_Memory_Repository::get( $id );
	if ( $memory === null ) {
		return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
	}

	if ( ! e2m_memory_user_can_edit( $memory ) ) {
		return new WP_Error( 'rest_forbidden', __( 'You cannot archive this memory.', 'e2mconnect' ), [ 'status' => 403 ] );
	}

	// Archiving a system memory requires admin escalation -- a junior
	// agent acting as a non-admin user shouldn't be able to silently kill
	// a site-wide rule.
	if ( $memory['visibility'] === 'system' && ! e2m_memory_can_escalate_to_system() ) {
		return new WP_Error(
			'system_visibility_requires_admin',
			__( 'Archiving system-visibility memories requires administrator privileges.', 'e2mconnect' ),
			[ 'status' => 403 ]
		);
	}

	$result = E2M_Memory_Repository::archive( $id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'id'      => $id,
		'status'  => 'archived',
		'success' => true,
	];
}
