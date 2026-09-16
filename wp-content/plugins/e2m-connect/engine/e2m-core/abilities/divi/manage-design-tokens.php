<?php
/**
 * E2M Connect MCP – Divi Manage Design Tokens
 *
 * Read and write Divi 5 global design tokens stored in the et_divi
 * WordPress theme option:
 *
 *   global_colors     — gcid-* keyed array of {color, status, label}
 *   global_variables  — array of {id, label, value, status, type, order}
 *
 * Actions:
 *   read   — read global_colors and/or global_variables
 *   write  — upsert tokens (existing tokens not in the payload are preserved)
 *   delete — remove a token by ID
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/divi-manage-design-tokens', [
	'label'       => __( '[Divi 5] Manage Design Tokens', 'e2mconnect' ),
	'description' => 'Read and write Divi 5 global design tokens (global colors gcid-* and global variables gvid-*) stored in the et_divi theme option. Actions: read, write (upsert), delete.',
	'category'    => 'e2m-divi',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'delete' ],
				'description' => 'read — retrieve tokens; write — upsert tokens; delete — remove a token by ID.',
			],
			// read / delete filter
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'colors', 'variables', 'all' ],
				'description' => 'Which token group to target. Default: all.',
				'default'     => 'all',
			],
			// delete
			'token_id' => [
				'type'        => 'string',
				'description' => 'Token ID to delete — a gcid-* or gvid-* string.',
			],
			// write — global_colors
			'global_colors' => [
				'type'        => 'array',
				'description' => 'Array of [gcid, {color, status, label}] tuples to upsert.',
				'items'       => [ 'type' => 'array' ],
			],
			// write — global_variables
			'global_variables' => [
				'type'        => 'array',
				'description' => 'Array of {id, label, value, status, type, order?} objects to upsert.',
				'items'       => [ 'type' => 'object' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'            => [ 'type' => 'string' ],
			'global_colors'     => [ 'type' => 'array' ],
			'global_variables'  => [ 'type' => 'array' ],
			'updated_colors'    => [ 'type' => 'integer' ],
			'updated_variables' => [ 'type' => 'integer' ],
			'deleted'           => [ 'type' => 'boolean' ],
			'saved'             => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_divi_manage_design_tokens',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Divi 5: Manage Design Tokens',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute divi-manage-design-tokens ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_manage_design_tokens( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_divi() ) {
		return new WP_Error( 'divi_missing', __( 'Divi theme or Divi Builder plugin is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );
	$type   = in_array( $input['type'] ?? 'all', [ 'colors', 'variables', 'all' ], true )
		? ( $input['type'] ?? 'all' )
		: 'all';

	switch ( $action ) {

		// ── Read ──────────────────────────────────────────────────────────────
		case 'read':
			$et_options = get_option( 'et_divi', [] );
			$result     = [ 'action' => 'read' ];

			if ( $type === 'colors' || $type === 'all' ) {
				$raw_colors = $et_options['global_colors'] ?? [];
				$colors     = [];
				if ( is_array( $raw_colors ) ) {
					foreach ( $raw_colors as $gcid => $data ) {
						$colors[] = [ $gcid, $data ];
					}
				}
				$result['global_colors'] = $colors;
			}

			if ( $type === 'variables' || $type === 'all' ) {
				$raw_vars = $et_options['global_variables'] ?? [];
				$result['global_variables'] = is_array( $raw_vars ) ? array_values( $raw_vars ) : [];
			}

			return $result;

		// ── Write (upsert) ────────────────────────────────────────────────────
		case 'write':
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'forbidden', __( 'You do not have permission to update Divi theme options.', 'e2mconnect' ) );
			}

			$et_options        = get_option( 'et_divi', [] );
			$updated_colors    = 0;
			$updated_variables = 0;

			// Colors
			if ( ! empty( $input['global_colors'] ) && is_array( $input['global_colors'] ) ) {
				$existing = is_array( $et_options['global_colors'] ?? null ) ? $et_options['global_colors'] : [];
				foreach ( $input['global_colors'] as $tuple ) {
					if ( ! is_array( $tuple ) || count( $tuple ) < 2 ) {
						continue;
					}
					[ $gcid, $data ] = $tuple;
					$gcid = sanitize_text_field( (string) $gcid );
					if ( ! str_starts_with( $gcid, 'gcid-' ) ) {
						continue; // Enforce gcid- prefix.
					}
					$existing[ $gcid ] = is_array( $data ) ? $data : [];
					$updated_colors++;
				}
				$et_options['global_colors'] = $existing;
			}

			// Variables
			if ( ! empty( $input['global_variables'] ) && is_array( $input['global_variables'] ) ) {
				$existing = is_array( $et_options['global_variables'] ?? null ) ? $et_options['global_variables'] : [];
				// Index by id for upsert.
				$indexed = [];
				foreach ( $existing as $var ) {
					if ( is_array( $var ) && isset( $var['id'] ) ) {
						$indexed[ $var['id'] ] = $var;
					}
				}
				foreach ( $input['global_variables'] as $var ) {
					if ( ! is_array( $var ) || empty( $var['id'] ) ) {
						continue;
					}
					$gvid = sanitize_text_field( (string) $var['id'] );
					$indexed[ $gvid ] = array_merge( $indexed[ $gvid ] ?? [], $var );
					$updated_variables++;
				}
				$et_options['global_variables'] = array_values( $indexed );
			}

			if ( $updated_colors === 0 && $updated_variables === 0 ) {
				return [
					'action'            => 'write',
					'updated_colors'    => 0,
					'updated_variables' => 0,
					'saved'             => false,
					'note'              => 'No tokens provided — nothing was updated.',
				];
			}

			update_option( 'et_divi', $et_options, false );

			return [
				'action'            => 'write',
				'updated_colors'    => $updated_colors,
				'updated_variables' => $updated_variables,
				'saved'             => true,
			];

		// ── Delete ────────────────────────────────────────────────────────────
		case 'delete':
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'forbidden', __( 'You do not have permission to update Divi theme options.', 'e2mconnect' ) );
			}
			if ( empty( $input['token_id'] ) ) {
				return new WP_Error( 'missing_token_id', __( 'token_id (gcid-* or gvid-*) is required for delete.', 'e2mconnect' ) );
			}

			$token_id   = sanitize_text_field( $input['token_id'] );
			$et_options = get_option( 'et_divi', [] );
			$deleted    = false;

			if ( str_starts_with( $token_id, 'gcid-' ) ) {
				if ( isset( $et_options['global_colors'][ $token_id ] ) ) {
					unset( $et_options['global_colors'][ $token_id ] );
					$deleted = true;
				}
			} elseif ( str_starts_with( $token_id, 'gvid-' ) ) {
				$vars = is_array( $et_options['global_variables'] ?? null ) ? $et_options['global_variables'] : [];
				$new  = array_values( array_filter( $vars, fn( $v ) => ( $v['id'] ?? '' ) !== $token_id ) );
				if ( count( $new ) < count( $vars ) ) {
					$et_options['global_variables'] = $new;
					$deleted = true;
				}
			}

			if ( ! $deleted ) {
				return new WP_Error( 'token_not_found', sprintf( __( 'Token "%s" not found in Divi global settings.', 'e2mconnect' ), $token_id ) );
			}

			update_option( 'et_divi', $et_options, false );

			return [
				'action'   => 'delete',
				'token_id' => $token_id,
				'deleted'  => true,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: read, write, delete.', 'e2mconnect' ) );
	}
}
