<?php
/**
 * E2M Connect MCP - Bricks Manage Global Classes
 *
 * Create, list, update, and delete global CSS classes in Bricks Builder.
 * Bricks stores global classes in the `bricks_global_classes` option as
 * an array of { id, name, settings } objects.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-global-classes', [
	'label'       => __( '[Bricks] Manage Global Classes', 'e2mconnect' ),
	'description' => 'Create, list, update, and delete global CSS classes in Bricks Builder — applied reusably across all pages.',
	'category'    => 'e2m-bricks',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform.',
			],
			'class_id' => [
				'type'        => 'string',
				'description' => 'Class ID for update or delete operations.',
			],
			'name' => [
				'type'        => 'string',
				'description' => 'CSS class name (without the dot, e.g. "card-hover").',
			],
			'settings' => [
				'type'                 => 'object',
				'description'          => 'Bricks style settings object for this class (e.g. color, typography, spacing).',
				'additionalProperties' => true,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'  => [ 'type' => 'string' ],
			'classes' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'class'   => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_bricks_manage_global_classes_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Global Classes',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-global-classes ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_global_classes_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Bricks global classes.', 'e2mconnect' ) );
	}

	$action  = sanitize_key( $input['action'] ?? 'list' );
	$classes = get_option( 'bricks_global_classes', [] );
	$classes = is_array( $classes ) ? $classes : [];

	switch ( $action ) {
		case 'list':
			return [ 'action' => 'list', 'classes' => array_values( $classes ) ];

		case 'create':
			if ( empty( $input['name'] ) ) {
				return new WP_Error( 'missing_name', __( 'A class name is required to create a global class.', 'e2mconnect' ) );
			}
			$new_class = [
				'id'       => 'bc-' . wp_generate_password( 6, false, false ),
				'name'     => sanitize_html_class( $input['name'] ),
				'settings' => is_array( $input['settings'] ?? null ) ? $input['settings'] : [],
			];
			$classes[] = $new_class;
			update_option( 'bricks_global_classes', $classes );
			return [ 'action' => 'create', 'class' => $new_class ];

		case 'update':
			if ( empty( $input['class_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'class_id is required to update a global class.', 'e2mconnect' ) );
			}
			$found = false;
			foreach ( $classes as &$cls ) {
				if ( ( $cls['id'] ?? '' ) === $input['class_id'] ) {
					if ( isset( $input['name'] ) ) {
						$cls['name'] = sanitize_html_class( $input['name'] );
					}
					if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
						$cls['settings'] = array_merge( (array) ( $cls['settings'] ?? [] ), $input['settings'] );
					}
					$found   = true;
					$updated = $cls;
					break;
				}
			}
			unset( $cls );
			if ( ! $found ) {
				return new WP_Error( 'not_found', __( 'Global class not found.', 'e2mconnect' ) );
			}
			update_option( 'bricks_global_classes', $classes );
			return [ 'action' => 'update', 'class' => $updated ];

		case 'delete':
			if ( empty( $input['class_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'class_id is required to delete a global class.', 'e2mconnect' ) );
			}
			$original = count( $classes );
			$classes  = array_values( array_filter( $classes, fn( $c ) => ( $c['id'] ?? '' ) !== $input['class_id'] ) );
			update_option( 'bricks_global_classes', $classes );
			return [ 'action' => 'delete', 'deleted' => count( $classes ) < $original ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, create, update, or delete.', 'e2mconnect' ) );
	}
}
