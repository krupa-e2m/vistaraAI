<?php
/**
 * E2M Connect MCP - List Users
 *
 * Lists WordPress users with search, role, and pagination filters. Requires
 * list_users capability, which maps to administrators by default.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-users', [
	'label'       => __( '[User] List Users', 'e2mconnect' ),
	'description' => 'Lists WordPress users with pagination, role filter, and search.',
	'category'    => 'e2m-users',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'role'     => [ 'type' => 'string', 'description' => 'Filter by role slug (e.g. "administrator", "editor").' ],
			'search'   => [ 'type' => 'string' ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'orderby'  => [ 'type' => 'string', 'enum' => [ 'login', 'email', 'registered', 'ID', 'display_name' ], 'default' => 'login' ],
			'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'ASC' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'users'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'user_id'      => [ 'type' => 'integer' ],
						'login'        => [ 'type' => 'string' ],
						'email'        => [ 'type' => 'string' ],
						'display_name' => [ 'type' => 'string' ],
						'registered'   => [ 'type' => 'string' ],
						'roles'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_users_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'List Users',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-users ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_users_ability( array $input ) {
	if ( ! current_user_can( 'list_users' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to list users.', 'e2mconnect' ) );
	}

	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$args = [
		'number'  => $per_page,
		'paged'   => $page,
		'orderby' => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'login',
		'order'   => ( isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'DESC' ) ? 'DESC' : 'ASC',
	];
	if ( ! empty( $input['role'] ) ) {
		$args['role'] = sanitize_key( (string) $input['role'] );
	}
	if ( ! empty( $input['search'] ) ) {
		$args['search']         = '*' . sanitize_text_field( (string) $input['search'] ) . '*';
		$args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
	}

	$query = new WP_User_Query( $args );

	$rows = [];
	foreach ( (array) $query->get_results() as $user ) {
		$rows[] = [
			'user_id'      => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'registered'   => mysql2date( 'c', $user->user_registered, false ),
			'roles'        => array_values( (array) $user->roles ),
		];
	}

	return [
		'total'    => (int) $query->get_total(),
		'page'     => $page,
		'per_page' => $per_page,
		'users'    => $rows,
	];
}
