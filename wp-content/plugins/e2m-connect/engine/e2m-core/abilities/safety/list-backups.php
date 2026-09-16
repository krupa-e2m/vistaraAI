<?php
/**
 * E2M Connect MCP - List Backups.
 *
 * Returns records from the unified backup store (file / Elementor / DB),
 * newest first. Optionally filtered by type, target path, or task id. Pair
 * with rollback-file (and, in later phases, rollback-db / Elementor restore)
 * to undo a change.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-backups', [
	'label'       => __( '[Safety] List Backups', 'e2mconnect' ),
	'description' => 'Lists backups taken before agent changes (theme/template files, Elementor pages, scoped DB rows), newest first. Filter by type, target path, or task id.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'type'    => [ 'type' => 'string', 'enum' => [ 'file', 'elementor', 'db' ], 'description' => 'Only return this backup type.' ],
			'target'  => [ 'type' => 'string', 'description' => 'Only return backups for this exact target (absolute file path or post id).' ],
			'task_id' => [ 'type' => 'string', 'description' => 'Only return backups grouped under this task id.' ],
			'limit'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'count'   => [ 'type' => 'integer' ],
			'backups' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'          => [ 'type' => 'string' ],
						'type'        => [ 'type' => 'string' ],
						'reason'      => [ 'type' => 'string' ],
						'target'      => [ 'type' => 'string' ],
						'created_at'  => [ 'type' => 'string' ],
						'user_login'  => [ 'type' => 'string' ],
						'task_id'     => [ 'type' => 'string' ],
						'rel_path'    => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_backups_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Backups',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_backups_ability( array $input ) {
	$filter = [];
	if ( ! empty( $input['type'] ) ) {
		$filter['type'] = (string) $input['type'];
	}
	if ( ! empty( $input['target'] ) ) {
		$filter['target'] = (string) $input['target'];
	}
	if ( ! empty( $input['task_id'] ) ) {
		$filter['task_id'] = (string) $input['task_id'];
	}
	$limit = isset( $input['limit'] ) ? max( 1, min( 500, (int) $input['limit'] ) ) : 100;

	$rows = class_exists( 'E2M_Backup_Store' ) ? E2M_Backup_Store::list( $filter ) : [];
	$rows = array_slice( $rows, 0, $limit );

	$backups = array_map(
		static function ( array $r ): array {
			return [
				'id'         => (string) ( $r['id'] ?? '' ),
				'type'       => (string) ( $r['type'] ?? '' ),
				'reason'     => (string) ( $r['reason'] ?? '' ),
				'target'     => (string) ( $r['target'] ?? '' ),
				'created_at' => (string) ( $r['created_at'] ?? '' ),
				'user_login' => (string) ( $r['user_login'] ?? '' ),
				'task_id'    => (string) ( $r['task_id'] ?? '' ),
				'rel_path'   => (string) ( $r['meta']['rel_path'] ?? '' ),
			];
		},
		$rows
	);

	return [
		'count'   => count( $backups ),
		'backups' => $backups,
	];
}
