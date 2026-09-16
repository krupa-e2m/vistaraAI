<?php
/**
 * E2M Connect MCP - Elementor Manage Variables
 *
 * Manage Elementor design tokens: global colors, fonts, spacing, and
 * radius variables stored in the active kit. Supports list, create,
 * update, and delete operations for each variable type.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-manage-variables', [
	'label'       => __( '[Elementor] Manage Variables', 'e2mconnect' ),
	'description' => 'Manage Elementor design tokens — global colors, fonts, and spacing variables — stored in the active kit.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform.',
			],
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'color', 'font', 'spacing', 'radius' ],
				'description' => 'Variable type to operate on.',
			],
			'variable_id' => [
				'type'        => 'string',
				'description' => 'ID of the variable to update or delete.',
			],
			'label' => [
				'type'        => 'string',
				'description' => 'Human-readable variable name (e.g. "Brand Primary").',
			],
			'value' => [
				'type'        => 'string',
				'description' => 'Variable value. Color: hex/rgb/hsl. Font: family name. Spacing/Radius: CSS length (e.g. "16px").',
			],
		],
		'required'             => [ 'action', 'type' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'    => [ 'type' => 'string' ],
			'type'      => [ 'type' => 'string' ],
			'variables' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'variable'  => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted'   => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_manage_variables_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Manage Variables',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-manage-variables ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_manage_variables_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Elementor variables.', 'e2mconnect' ) );
	}

	$kit_id = e2m_engine_elementor_active_kit_id();
	if ( $kit_id <= 0 ) {
		return new WP_Error( 'kit_missing', __( 'No active Elementor kit was found.', 'e2mconnect' ) );
	}

	$action   = sanitize_key( $input['action'] ?? 'list' );
	$type     = sanitize_key( $input['type'] ?? '' );
	$settings = (array) get_post_meta( $kit_id, '_elementor_page_settings', true );

	// Map variable type to the kit settings key.
	$key_map = [
		'color'   => 'e2m_vars_colors',
		'font'    => 'e2m_vars_fonts',
		'spacing' => 'e2m_vars_spacing',
		'radius'  => 'e2m_vars_radius',
	];

	if ( ! isset( $key_map[ $type ] ) ) {
		return new WP_Error( 'invalid_type', __( 'Invalid type. Use color, font, spacing, or radius.', 'e2mconnect' ) );
	}

	$settings_key = $key_map[ $type ];
	$variables    = isset( $settings[ $settings_key ] ) && is_array( $settings[ $settings_key ] )
		? $settings[ $settings_key ]
		: [];

	switch ( $action ) {
		case 'list':
			return [ 'action' => 'list', 'type' => $type, 'variables' => $variables ];

		case 'create':
			if ( empty( $input['label'] ) || empty( $input['value'] ) ) {
				return new WP_Error( 'missing_fields', __( 'Both label and value are required to create a variable.', 'e2mconnect' ) );
			}
			$new_var = [
				'_id'   => 'var-' . wp_generate_uuid4(),
				'label' => sanitize_text_field( $input['label'] ),
				'value' => sanitize_text_field( $input['value'] ),
				'type'  => $type,
			];
			$variables[]               = $new_var;
			$settings[ $settings_key ] = $variables;
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
			delete_post_meta( $kit_id, '_elementor_css' );
			return [ 'action' => 'create', 'type' => $type, 'variable' => $new_var ];

		case 'update':
			if ( empty( $input['variable_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'variable_id is required to update a variable.', 'e2mconnect' ) );
			}
			$found = false;
			foreach ( $variables as &$var ) {
				if ( ( $var['_id'] ?? '' ) === $input['variable_id'] ) {
					if ( isset( $input['label'] ) ) {
						$var['label'] = sanitize_text_field( $input['label'] );
					}
					if ( isset( $input['value'] ) ) {
						$var['value'] = sanitize_text_field( $input['value'] );
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
			$settings[ $settings_key ] = $variables;
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
			delete_post_meta( $kit_id, '_elementor_css' );
			return [ 'action' => 'update', 'type' => $type, 'variable' => $updated ];

		case 'delete':
			if ( empty( $input['variable_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'variable_id is required to delete a variable.', 'e2mconnect' ) );
			}
			$original_count            = count( $variables );
			$variables                 = array_values( array_filter( $variables, fn( $v ) => ( $v['_id'] ?? '' ) !== $input['variable_id'] ) );
			$settings[ $settings_key ] = $variables;
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
			delete_post_meta( $kit_id, '_elementor_css' );
			return [ 'action' => 'delete', 'type' => $type, 'deleted' => count( $variables ) < $original_count ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, create, update, or delete.', 'e2mconnect' ) );
	}
}
