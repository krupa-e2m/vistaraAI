<?php
/**
 * E2M Connect MCP - Bricks Manage Theme Styles
 *
 * Control Bricks theme-level typography and layout styles. Bricks stores
 * theme styles in the `bricks_theme_styles` option — a collection of style
 * sets each targeting different element selectors with typography, color,
 * spacing, and layout rules.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-theme-styles', [
	'label'       => __( '[Bricks] Manage Theme Styles', 'e2mconnect' ),
	'description' => 'Control Bricks theme-level typography and layout styles — list, create, update, and delete theme style sets.',
	'category'    => 'e2m-bricks',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform.',
			],
			'style_id' => [
				'type'        => 'string',
				'description' => 'Style set ID for get, update, or delete operations.',
			],
			'label' => [
				'type'        => 'string',
				'description' => 'Human-readable label for the theme style set (e.g. "Headings", "Body Text").',
			],
			'settings' => [
				'type'                 => 'object',
				'description'          => 'Style settings to apply — typography (font-family, font-size, line-height, etc.), color, spacing, layout.',
				'additionalProperties' => true,
			],
			'selectors' => [
				'type'        => 'array',
				'description' => 'CSS selectors this style set targets (e.g. ["h1", "h2", ".site-title"]).',
				'items'       => [ 'type' => 'string' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'  => [ 'type' => 'string' ],
			'styles'  => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'style'   => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_bricks_manage_theme_styles_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Theme Styles',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-theme-styles ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_theme_styles_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Bricks theme styles.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] ?? 'list' );
	$styles = get_option( 'bricks_theme_styles', [] );
	$styles = is_array( $styles ) ? $styles : [];

	switch ( $action ) {
		case 'list':
			return [ 'action' => 'list', 'styles' => array_values( $styles ) ];

		case 'get':
			if ( empty( $input['style_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'style_id is required.', 'e2mconnect' ) );
			}
			foreach ( $styles as $style ) {
				if ( ( $style['id'] ?? '' ) === $input['style_id'] ) {
					return [ 'action' => 'get', 'style' => $style ];
				}
			}
			return new WP_Error( 'not_found', __( 'Theme style not found.', 'e2mconnect' ) );

		case 'create':
			if ( empty( $input['label'] ) ) {
				return new WP_Error( 'missing_label', __( 'A label is required to create a theme style.', 'e2mconnect' ) );
			}
			$new_style = [
				'id'        => 'bts-' . wp_generate_password( 6, false, false ),
				'label'     => sanitize_text_field( $input['label'] ),
				'selectors' => array_map( 'sanitize_text_field', (array) ( $input['selectors'] ?? [] ) ),
				'settings'  => is_array( $input['settings'] ?? null ) ? $input['settings'] : [],
			];
			$styles[] = $new_style;
			update_option( 'bricks_theme_styles', $styles );
			return [ 'action' => 'create', 'style' => $new_style ];

		case 'update':
			if ( empty( $input['style_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'style_id is required to update a theme style.', 'e2mconnect' ) );
			}
			$found = false;
			foreach ( $styles as &$style ) {
				if ( ( $style['id'] ?? '' ) === $input['style_id'] ) {
					if ( isset( $input['label'] ) ) {
						$style['label'] = sanitize_text_field( $input['label'] );
					}
					if ( isset( $input['selectors'] ) ) {
						$style['selectors'] = array_map( 'sanitize_text_field', (array) $input['selectors'] );
					}
					if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
						$style['settings'] = array_merge( (array) ( $style['settings'] ?? [] ), $input['settings'] );
					}
					$found   = true;
					$updated = $style;
					break;
				}
			}
			unset( $style );
			if ( ! $found ) {
				return new WP_Error( 'not_found', __( 'Theme style not found.', 'e2mconnect' ) );
			}
			update_option( 'bricks_theme_styles', $styles );
			return [ 'action' => 'update', 'style' => $updated ];

		case 'delete':
			if ( empty( $input['style_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'style_id is required to delete a theme style.', 'e2mconnect' ) );
			}
			$original = count( $styles );
			$styles   = array_values( array_filter( $styles, fn( $s ) => ( $s['id'] ?? '' ) !== $input['style_id'] ) );
			update_option( 'bricks_theme_styles', $styles );
			return [ 'action' => 'delete', 'deleted' => count( $styles ) < $original ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, get, create, update, or delete.', 'e2mconnect' ) );
	}
}
