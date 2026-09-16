<?php
/**
 * E2M Connect MCP - Bricks Manage Color Palette
 *
 * Manage the Bricks Builder global color palette and color variables.
 * Bricks stores the color palette in the `bricks_color_palette` option
 * as an array of color groups, each containing color entries.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-color-palette', [
	'label'       => __( '[Bricks] Manage Color Palette', 'e2mconnect' ),
	'description' => 'Manage the Bricks Builder global color palette — list, add, update, and delete colors and color groups.',
	'category'    => 'e2m-bricks',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'add_color', 'update_color', 'delete_color', 'add_group', 'delete_group' ],
				'description' => 'Operation to perform on the color palette.',
			],
			'group_id' => [
				'type'        => 'string',
				'description' => 'ID of the color group.',
			],
			'group_name' => [
				'type'        => 'string',
				'description' => 'Name for a new color group.',
			],
			'color_id' => [
				'type'        => 'string',
				'description' => 'ID of the color entry to update or delete.',
			],
			'name' => [
				'type'        => 'string',
				'description' => 'Human-readable color name (e.g. "Brand Blue").',
			],
			'value' => [
				'type'        => 'string',
				'description' => 'CSS color value — hex, rgb(), hsl(), or CSS custom property (e.g. "#3B82F6", "rgb(59,130,246)").',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'  => [ 'type' => 'string' ],
			'palette' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'color'   => [ 'type' => 'object', 'additionalProperties' => true ],
			'group'   => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_bricks_manage_color_palette_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Color Palette',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-color-palette ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_color_palette_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage the Bricks color palette.', 'e2mconnect' ) );
	}

	$action  = sanitize_key( $input['action'] ?? 'list' );
	$palette = get_option( 'bricks_color_palette', [] );
	$palette = is_array( $palette ) ? $palette : [];

	switch ( $action ) {
		case 'list':
			return [ 'action' => 'list', 'palette' => $palette ];

		case 'add_group':
			if ( empty( $input['group_name'] ) ) {
				return new WP_Error( 'missing_group_name', __( 'group_name is required to add a color group.', 'e2mconnect' ) );
			}
			$new_group = [
				'id'     => 'bg-' . wp_generate_password( 6, false, false ),
				'name'   => sanitize_text_field( $input['group_name'] ),
				'colors' => [],
			];
			$palette[] = $new_group;
			update_option( 'bricks_color_palette', $palette );
			return [ 'action' => 'add_group', 'group' => $new_group ];

		case 'delete_group':
			if ( empty( $input['group_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'group_id is required to delete a color group.', 'e2mconnect' ) );
			}
			$original = count( $palette );
			$palette  = array_values( array_filter( $palette, fn( $g ) => ( $g['id'] ?? '' ) !== $input['group_id'] ) );
			update_option( 'bricks_color_palette', $palette );
			return [ 'action' => 'delete_group', 'deleted' => count( $palette ) < $original ];

		case 'add_color':
			if ( empty( $input['group_id'] ) || empty( $input['name'] ) || empty( $input['value'] ) ) {
				return new WP_Error( 'missing_fields', __( 'group_id, name, and value are required to add a color.', 'e2mconnect' ) );
			}
			$new_color = [
				'id'    => 'bc-' . wp_generate_password( 6, false, false ),
				'name'  => sanitize_text_field( $input['name'] ),
				'value' => sanitize_text_field( $input['value'] ),
			];
			$found = false;
			foreach ( $palette as &$group ) {
				if ( ( $group['id'] ?? '' ) === $input['group_id'] ) {
					$group['colors'][] = $new_color;
					$found = true;
					break;
				}
			}
			unset( $group );
			if ( ! $found ) {
				return new WP_Error( 'group_not_found', __( 'Color group not found.', 'e2mconnect' ) );
			}
			update_option( 'bricks_color_palette', $palette );
			return [ 'action' => 'add_color', 'color' => $new_color ];

		case 'update_color':
			if ( empty( $input['color_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'color_id is required to update a color.', 'e2mconnect' ) );
			}
			$found = false;
			foreach ( $palette as &$group ) {
				if ( ! isset( $group['colors'] ) ) {
					continue;
				}
				foreach ( $group['colors'] as &$color ) {
					if ( ( $color['id'] ?? '' ) === $input['color_id'] ) {
						if ( isset( $input['name'] ) ) {
							$color['name'] = sanitize_text_field( $input['name'] );
						}
						if ( isset( $input['value'] ) ) {
							$color['value'] = sanitize_text_field( $input['value'] );
						}
						$found   = true;
						$updated = $color;
						break 2;
					}
				}
				unset( $color );
			}
			unset( $group );
			if ( ! $found ) {
				return new WP_Error( 'not_found', __( 'Color not found.', 'e2mconnect' ) );
			}
			update_option( 'bricks_color_palette', $palette );
			return [ 'action' => 'update_color', 'color' => $updated ];

		case 'delete_color':
			if ( empty( $input['color_id'] ) ) {
				return new WP_Error( 'missing_id', __( 'color_id is required to delete a color.', 'e2mconnect' ) );
			}
			$deleted = false;
			foreach ( $palette as &$group ) {
				if ( ! isset( $group['colors'] ) ) {
					continue;
				}
				$before = count( $group['colors'] );
				$group['colors'] = array_values( array_filter( $group['colors'], fn( $c ) => ( $c['id'] ?? '' ) !== $input['color_id'] ) );
				if ( count( $group['colors'] ) < $before ) {
					$deleted = true;
					break;
				}
			}
			unset( $group );
			update_option( 'bricks_color_palette', $palette );
			return [ 'action' => 'delete_color', 'deleted' => $deleted ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action.', 'e2mconnect' ) );
	}
}
