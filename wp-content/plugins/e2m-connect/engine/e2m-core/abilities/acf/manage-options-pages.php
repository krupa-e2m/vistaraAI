<?php
/**
 * E2M Connect MCP - ACF Manage Options Pages
 *
 * Manage ACF options pages and their global field values. Options pages
 * are an ACF Pro feature — this ability is gated on ACF Pro availability.
 *
 * With ACF Pro:
 *   - List all registered options pages
 *   - Read / write field values stored on a specific options page
 *   - Register a new options page programmatically
 *
 * Without ACF Pro:
 *   - Falls back gracefully with a clear error message explaining that
 *     options pages require ACF Pro.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/acf-manage-options-pages', [
	'label'       => __( '[ACF] Manage Options Pages', 'e2mconnect' ),
	'description' => 'Manage ACF options pages and their global field values. Requires ACF Pro for creating options pages; reading/writing values works with any registered options page.',
	'category'    => 'e2m-acf',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'read', 'write', 'read_all', 'register' ],
				'description' => 'list — list all options pages; read — get a field value; write — update a field value; read_all — get all fields; register — create a new options page (requires ACF Pro).',
			],
			'page_slug' => [
				'type'        => 'string',
				'description' => 'Options page slug (e.g. "site-settings", "global-options"). Used as the ACF post_id for field operations.',
			],
			'field_key_or_name' => [
				'type'        => 'string',
				'description' => 'ACF field key or name to read/write.',
			],
			'value' => [
				'description' => 'New value for the field (write action).',
			],
			'page_title' => [
				'type'        => 'string',
				'description' => 'Human-readable title for the new options page (register action).',
			],
			'menu_slug' => [
				'type'        => 'string',
				'description' => 'URL slug for the new options page (register action).',
			],
			'parent_slug' => [
				'type'        => 'string',
				'description' => 'Parent menu slug if this is a sub-page (e.g. "options-general.php").',
			],
			'capability' => [
				'type'        => 'string',
				'description' => 'WordPress capability required to view this options page. Defaults to "manage_options".',
				'default'     => 'manage_options',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'    => [ 'type' => 'string' ],
			'pages'     => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'page_slug' => [ 'type' => 'string' ],
			'field'     => [ 'type' => 'string' ],
			'value'     => [ 'description' => 'Field value (type varies).' ],
			'fields'    => [ 'type' => 'object', 'additionalProperties' => true ],
			'updated'   => [ 'type' => 'boolean' ],
			'registered' => [ 'type' => 'boolean' ],
			'pro_required' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_acf_manage_options_pages_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'ACF: Manage Options Pages',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the acf-manage-options-pages ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_acf_manage_options_pages_ability( array $input ) {
	if ( ! e2m_engine_has_acf() ) {
		return new WP_Error( 'acf_missing', __( 'Advanced Custom Fields is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage ACF options pages.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] ?? 'list' );

	// ── Register a new options page — Pro only ───────────────────────────────
	if ( $action === 'register' ) {
		if ( ! e2m_engine_has_acf_pro() || ! function_exists( 'acf_add_options_page' ) ) {
			return [
				'action'       => 'register',
				'pro_required' => true,
				'registered'   => false,
				'message'      => __( 'Creating options pages requires ACF Pro. Upgrade to ACF Pro to use this feature.', 'e2mconnect' ),
			];
		}

		if ( empty( $input['page_title'] ) || empty( $input['menu_slug'] ) ) {
			return new WP_Error( 'missing_fields', __( 'page_title and menu_slug are required to register an options page.', 'e2mconnect' ) );
		}

		$page_args = [
			'page_title'  => sanitize_text_field( $input['page_title'] ),
			'menu_title'  => sanitize_text_field( $input['page_title'] ),
			'menu_slug'   => sanitize_key( $input['menu_slug'] ),
			'capability'  => sanitize_key( $input['capability'] ?? 'manage_options' ),
		];

		if ( ! empty( $input['parent_slug'] ) ) {
			$page_args['parent_slug'] = sanitize_text_field( $input['parent_slug'] );
		}

		acf_add_options_page( $page_args );

		return [
			'action'      => 'register',
			'registered'  => true,
			'menu_slug'   => $page_args['menu_slug'],
			'page_title'  => $page_args['page_title'],
		];
	}

	// ── List registered options pages ───────────────────────────────────────
	if ( $action === 'list' ) {
		$pages = e2m_engine_acf_get_registered_options_pages();
		return [ 'action' => 'list', 'pages' => $pages ];
	}

	// All remaining actions need a page_slug.
	$page_slug = sanitize_key( $input['page_slug'] ?? '' );
	if ( $page_slug === '' ) {
		return new WP_Error( 'missing_page_slug', __( 'page_slug is required for read, write, and read_all actions.', 'e2mconnect' ) );
	}

	// ACF uses the options page slug directly as the post_id for get_field/update_field.
	$acf_post_id = $page_slug;

	if ( $action === 'read_all' ) {
		$all_fields = get_fields( $acf_post_id );
		return [
			'action'    => 'read_all',
			'page_slug' => $page_slug,
			'fields'    => is_array( $all_fields ) ? $all_fields : [],
		];
	}

	$field_key_or_name = sanitize_text_field( $input['field_key_or_name'] ?? '' );
	if ( $field_key_or_name === '' ) {
		return new WP_Error( 'missing_field', __( 'field_key_or_name is required for read and write actions.', 'e2mconnect' ) );
	}

	if ( $action === 'read' ) {
		$value = get_field( $field_key_or_name, $acf_post_id );
		return [
			'action'    => 'read',
			'page_slug' => $page_slug,
			'field'     => $field_key_or_name,
			'value'     => $value,
		];
	}

	if ( $action === 'write' ) {
		if ( ! array_key_exists( 'value', $input ) ) {
			return new WP_Error( 'missing_value', __( 'A value is required for the write action.', 'e2mconnect' ) );
		}
		$updated = update_field( $field_key_or_name, $input['value'], $acf_post_id );
		return [
			'action'    => 'write',
			'page_slug' => $page_slug,
			'field'     => $field_key_or_name,
			'updated'   => (bool) $updated,
		];
	}

	return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, read, write, read_all, or register.', 'e2mconnect' ) );
}

/**
 * Return all ACF-registered options pages.
 * Works regardless of Pro status by reading the acf_options_pages store.
 *
 * @return array<int, array<string, mixed>>
 */
function e2m_engine_acf_get_registered_options_pages(): array {
	$pages = [];

	// ACF Pro stores options pages in acf()->options_page->pages.
	if ( function_exists( 'acf' ) ) {
		$instance = acf();
		if ( isset( $instance->options_page ) && is_object( $instance->options_page ) ) {
			$raw = $instance->options_page->pages ?? [];
			foreach ( $raw as $slug => $page ) {
				$pages[] = [
					'slug'       => $slug,
					'page_title' => $page['page_title'] ?? '',
					'menu_title' => $page['menu_title'] ?? '',
					'capability' => $page['capability'] ?? 'manage_options',
					'parent'     => $page['parent_slug'] ?? '',
				];
			}
			return $pages;
		}
	}

	// Fallback: scan wp_options for keys prefixed with "options_" that look
	// like ACF field values stored on a custom options page.
	global $wpdb;
	$option_keys = $wpdb->get_col(
		"SELECT DISTINCT option_name FROM {$wpdb->options}
		WHERE option_name LIKE '_acf_option_page_%'
		LIMIT 50"
	);
	foreach ( $option_keys as $key ) {
		$slug    = str_replace( '_acf_option_page_', '', $key );
		$pages[] = [ 'slug' => $slug, 'page_title' => $slug ];
	}

	return $pages;
}
