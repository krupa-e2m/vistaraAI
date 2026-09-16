<?php
/**
 * E2M Connect MCP - Bricks Manage Variables
 *
 * Manage Bricks CSS custom properties and design tokens. Bricks stores
 * global CSS variables in the `bricks_global_variables` option as an
 * array of { id, name, value } objects that get output as :root CSS
 * custom properties on the frontend.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-variables', [
	'label'       => __( '[Bricks] Manage Variables', 'e2mconnect' ),
	'description' => 'Manage Bricks CSS custom properties and design tokens — list, create, update, and delete global CSS variables.',
	'category'    => 'e2m-bricks',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform.',
			],
			'variable_id' => [
				'type'        => 'string',
				'description' => 'Variable ID for update or delete operations.',
			],
			'name' => [
				'type'        => 'string',
				'description' => 'CSS variable name without the -- prefix (e.g. "brand-primary", "spacing-lg").',
			],
			'value' => [
				'type'        => 'string',
				'description' => 'CSS value for the variable (e.g. "#3B82F6", "1.5rem", "16px 24px").',
			],
			'category' => [
				'type'        => 'string',
				'description' => 'Optional category for grouping variables (e.g. "colors", "spacing", "typography").',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'    => [ 'type' => 'string' ],
			'variables' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'variable'  => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted'   => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_bricks_manage_variables_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Variables',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-variables ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_variables_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Bricks CSS variables.', 'e2mconnect' ) );
	}

	$action    = sanitize_key( $input['action'] ?? 'list' );
	$variables = get_option( 'bricks_global_variables', [] );
	$variables = is_array( $variables ) ? $variables : [];

	switch ( $action ) {
		case 'list':
			return [ 'action' => 'list', 'variables' => array_values( $variables ) ];

		case 'create':
			if ( empty( $input['name'] ) || empty( $input['value'] ) ) {
				return new WP_Error( 'missing_fields', __( 'Both name and value are required to create a variable.', 'e2mconnect' ) );
			}
			$new_var = [
				'id'       => 'bv-' . wp_generate_password( 6, false, false ),
				'name'     => sanitize_text_field( $input['name'] ),
				'value'    => sanitize_text_field( $input['value'] ),
				'category' => sanitize_text_field( $input['category'] ?? '' ),
			];
			$variables[] = $new_var;
			update_option( 'bricks_global_variables', $variables );
			return [ 'action' => 'create', 'variable' => $new_var ];

		case 'update':
			if ( empty( $input['variable_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'variable_id is required to update a variable.', 'e2mconnect' ) );
			}
			$found = false;
			foreach ( $variables as &$var ) {
				if ( ( $var['id'] ?? '' ) === $input['variable_id'] ) {
					if ( isset( $input['name'] ) ) {
						$var['name'] = sanitize_text_field( $input['name'] );
					}
					if ( isset( $input['value'] ) ) {
						$var['value'] = sanitize_text_field( $input['value'] );
					}
					if ( isset( $input['category'] ) ) {
						$var['category'] = sanitize_text_field( $input['category'] );
					}
					$found   = true;
					$updated = $var;
					break;
				}
			}
			unset( $var );
			if ( ! $found ) {
				return new WP_Error( 'not_found', __( 'Variable not found.', 'e2mconnect' ) );
			}
			update_option( 'bricks_global_variables', $variables );
			return [ 'action' => 'update', 'variable' => $updated ];

		case 'delete':
			if ( empty( $input['variable_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'variable_id is required to delete a variable.', 'e2mconnect' ) );
			}
			$original  = count( $variables );
			$variables = array_values( array_filter( $variables, fn( $v ) => ( $v['id'] ?? '' ) !== $input['variable_id'] ) );
			update_option( 'bricks_global_variables', $variables );
			return [ 'action' => 'delete', 'deleted' => count( $variables ) < $original ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, create, update, or delete.', 'e2mconnect' ) );
	}
}
