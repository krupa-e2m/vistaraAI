<?php
/**
 * E2M Connect MCP - Elementor Manage Global Classes
 *
 * List, create, update, and delete reusable CSS classes that are stored
 * in the active Elementor kit and can be applied globally across all
 * Elementor pages.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-manage-global-classes', [
	'label'       => __( '[Elementor] Manage Global Classes', 'e2mconnect' ),
	'description' => 'List, create, update, and delete reusable CSS classes stored in the Elementor kit, applied globally across all pages.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform on global classes.',
			],
			'class_id' => [
				'type'        => 'string',
				'description' => 'Unique ID of the class to update or delete.',
			],
			'label' => [
				'type'        => 'string',
				'description' => 'Human-readable class name (e.g. "card-hover").',
			],
			'css' => [
				'type'        => 'string',
				'description' => 'Raw CSS declarations to store for this class (e.g. "color: red; font-size: 14px;").',
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

	'execute_callback'    => 'e2m_engine_elementor_manage_global_classes_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Manage Global Classes',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-manage-global-classes ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_manage_global_classes_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Elementor global classes.', 'e2mconnect' ) );
	}

	$kit_id = e2m_engine_elementor_active_kit_id();
	if ( $kit_id <= 0 ) {
		return new WP_Error( 'kit_missing', __( 'No active Elementor kit was found.', 'e2mconnect' ) );
	}

	$action   = sanitize_key( $input['action'] ?? 'list' );
	$settings = (array) get_post_meta( $kit_id, '_elementor_page_settings', true );
	$classes  = isset( $settings['global_classes'] ) && is_array( $settings['global_classes'] )
		? $settings['global_classes']
		: [];

	switch ( $action ) {
		case 'list':
			return [ 'action' => 'list', 'classes' => $classes ];

		case 'create':
			if ( empty( $input['label'] ) ) {
				return new WP_Error( 'missing_label', __( 'A label is required to create a global class.', 'e2mconnect' ) );
			}
			$new_class = [
				'_id'   => 'gc-' . wp_generate_uuid4(),
				'label' => sanitize_text_field( $input['label'] ),
				'css'   => wp_kses_post( $input['css'] ?? '' ),
			];
			$classes[]                    = $new_class;
			$settings['global_classes']   = $classes;
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
			delete_post_meta( $kit_id, '_elementor_css' );
			return [ 'action' => 'create', 'class' => $new_class ];

		case 'update':
			if ( empty( $input['class_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'class_id is required to update a global class.', 'e2mconnect' ) );
			}
			$found = false;
			foreach ( $classes as &$cls ) {
				if ( ( $cls['_id'] ?? '' ) === $input['class_id'] ) {
					if ( isset( $input['label'] ) ) {
						$cls['label'] = sanitize_text_field( $input['label'] );
					}
					if ( isset( $input['css'] ) ) {
						$cls['css'] = wp_kses_post( $input['css'] );
					}
					$found     = true;
					$updated   = $cls;
					break;
				}
			}
			unset( $cls );
			if ( ! $found ) {
				return new WP_Error( 'not_found', __( 'Global class not found.', 'e2mconnect' ) );
			}
			$settings['global_classes'] = $classes;
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
			delete_post_meta( $kit_id, '_elementor_css' );
			return [ 'action' => 'update', 'class' => $updated ];

		case 'delete':
			if ( empty( $input['class_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'class_id is required to delete a global class.', 'e2mconnect' ) );
			}
			$original_count             = count( $classes );
			$classes                    = array_values( array_filter( $classes, fn( $c ) => ( $c['_id'] ?? '' ) !== $input['class_id'] ) );
			$settings['global_classes'] = $classes;
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
			delete_post_meta( $kit_id, '_elementor_css' );
			return [ 'action' => 'delete', 'deleted' => count( $classes ) < $original_count ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, create, update, or delete.', 'e2mconnect' ) );
	}
}
