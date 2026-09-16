<?php
/**
 * E2M Connect MCP – Divi Manage Presets
 *
 * Full CRUD for Divi global presets (Divi 4 + Divi 5).
 *
 * Actions:
 *   list       — list all presets, optionally filtered by module_type / scope
 *   get        — get a single preset by UUID
 *   upsert     — create or update a preset (upserts by kind + module_type + name)
 *   bulk_upsert — batch create/update presets in one call
 *   resolve    — merge-preview a preset + local attr overrides (no page write)
 *   delete     — delete a preset by UUID
 *
 * Requires Divi theme or Divi Builder plugin. Works with both Divi 4 (shortcode
 * presets stored in et_divi option) and Divi 5 (block-based preset store).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/divi-manage-presets', [
	'label'       => __( '[Divi] Manage Presets', 'e2mconnect' ),
	'description' => 'Full CRUD for Divi global presets. Supports list, get, upsert, bulk_upsert, resolve (merge-preview without writing), and delete. Works with both Divi 4 and Divi 5.',
	'category'    => 'e2m-divi',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'upsert', 'bulk_upsert', 'resolve', 'delete' ],
				'description' => 'Operation to perform on Divi presets.',
			],
			// list / get filters
			'module_type' => [
				'type'        => 'string',
				'description' => 'Filter by Divi module type slug, e.g. "divi/button". Used in list and get.',
			],
			'scope' => [
				'type'        => 'string',
				'enum'        => [ 'module', 'group' ],
				'description' => 'Filter by scope: "module" or "group". Used in list.',
			],
			// get / resolve / delete
			'preset_id' => [
				'type'        => 'string',
				'description' => 'UUID of the preset. Required for get, resolve, delete.',
			],
			// upsert / bulk_upsert fields
			'name'        => [ 'type' => 'string', 'description' => 'Human-readable preset name. Required for upsert.' ],
			'kind'        => [ 'type' => 'string', 'enum' => [ 'module', 'group' ], 'description' => '"module" or "group". Required for upsert.' ],
			'is_default'  => [ 'type' => 'boolean', 'description' => 'Mark as default for its module type.' ],
			'attrs'       => [ 'type' => 'object', 'description' => 'Preset attribute key→value map. Required for upsert.', 'additionalProperties' => true ],
			// bulk_upsert
			'presets' => [
				'type'        => 'array',
				'description' => 'Array of preset specs for bulk_upsert: each {name, kind, module_type, attrs, is_default?}.',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'name'        => [ 'type' => 'string' ],
						'kind'        => [ 'type' => 'string', 'enum' => [ 'module', 'group' ] ],
						'module_type' => [ 'type' => 'string' ],
						'is_default'  => [ 'type' => 'boolean' ],
						'attrs'       => [ 'type' => 'object', 'additionalProperties' => true ],
					],
					'required' => [ 'name', 'kind', 'module_type', 'attrs' ],
				],
			],
			// resolve overrides
			'override_attrs' => [
				'type'        => 'object',
				'description' => 'Local attribute overrides to merge on top of the preset in resolve.',
				'additionalProperties' => true,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'    => [ 'type' => 'string' ],
			'presets'   => [ 'type' => 'array' ],
			'preset'    => [ 'type' => 'object' ],
			'count'     => [ 'type' => 'integer' ],
			'created'   => [ 'type' => 'integer' ],
			'updated'   => [ 'type' => 'integer' ],
			'failed'    => [ 'type' => 'array' ],
			'deleted'   => [ 'type' => 'boolean' ],
			'available' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_divi_manage_presets',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Divi: Manage Presets',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute divi-manage-presets ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_manage_presets( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_divi() ) {
		return new WP_Error( 'divi_missing', __( 'Divi theme or Divi Builder plugin is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			if ( ! class_exists( 'Respira_Divi_Presets' ) ) {
				return e2m_engine_divi_list_presets_native( $input );
			}
			$module_type = ! empty( $input['module_type'] ) ? sanitize_text_field( $input['module_type'] ) : null;
			$scope_raw   = $input['scope'] ?? '';
			$scope       = in_array( $scope_raw, [ 'module', 'group' ], true ) ? $scope_raw : null;
			$result      = Respira_Divi_Presets::list_presets( $module_type, $scope );
			return array_merge( [ 'action' => 'list', 'available' => true ], $result );

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['preset_id'] ) ) {
				return new WP_Error( 'missing_preset_id', __( 'preset_id is required for the get action.', 'e2mconnect' ) );
			}
			$preset_id = sanitize_text_field( $input['preset_id'] );
			if ( class_exists( 'Respira_Divi_Presets' ) ) {
				$preset = Respira_Divi_Presets::find_by_id( $preset_id );
			} else {
				$preset = e2m_engine_divi_find_preset_native( $preset_id );
			}
			if ( ! $preset ) {
				return new WP_Error( 'preset_not_found', __( 'No preset found with that ID.', 'e2mconnect' ) );
			}
			return [ 'action' => 'get', 'preset' => $preset ];

		// ── Upsert ────────────────────────────────────────────────────────────
		case 'upsert':
			foreach ( [ 'name', 'kind', 'module_type', 'attrs' ] as $req ) {
				if ( empty( $input[ $req ] ) && $input[ $req ] !== 0 ) {
					return new WP_Error( "missing_{$req}", sprintf( __( '"%s" is required for upsert.', 'e2mconnect' ), $req ) );
				}
			}
			if ( ! class_exists( 'Respira_Divi_Presets' ) ) {
				return e2m_engine_divi_upsert_preset_native( $input );
			}
			$spec = [
				'name'        => sanitize_text_field( $input['name'] ),
				'kind'        => sanitize_key( $input['kind'] ),
				'module_type' => sanitize_text_field( $input['module_type'] ),
				'attrs'       => is_array( $input['attrs'] ) ? $input['attrs'] : [],
			];
			if ( isset( $input['is_default'] ) ) {
				$spec['is_default'] = (bool) $input['is_default'];
			}
			$result = Respira_Divi_Presets::upsert_preset( $spec );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array_merge( [ 'action' => 'upsert' ], $result );

		// ── Bulk Upsert ───────────────────────────────────────────────────────
		case 'bulk_upsert':
			$presets_input = is_array( $input['presets'] ?? null ) ? $input['presets'] : [];
			if ( empty( $presets_input ) ) {
				return new WP_Error( 'missing_presets', __( '"presets" array is required and must not be empty.', 'e2mconnect' ) );
			}
			if ( ! class_exists( 'Respira_Divi_Presets' ) ) {
				return e2m_engine_divi_bulk_upsert_native( $presets_input );
			}
			$results = [];
			$created = 0;
			$updated = 0;
			$failed  = [];
			foreach ( $presets_input as $idx => $spec ) {
				$res = Respira_Divi_Presets::upsert_preset( is_array( $spec ) ? $spec : [] );
				if ( is_wp_error( $res ) ) {
					$failed[] = [
						'index'   => (int) $idx,
						'name'    => is_array( $spec ) ? ( $spec['name'] ?? '' ) : '',
						'code'    => $res->get_error_code(),
						'message' => $res->get_error_message(),
					];
					continue;
				}
				$results[] = $res;
				'created' === $res['status'] ? $created++ : $updated++;
			}
			return [
				'action'  => 'bulk_upsert',
				'presets' => $results,
				'created' => $created,
				'updated' => $updated,
				'failed'  => $failed,
			];

		// ── Resolve (merge-preview) ────────────────────────────────────────────
		case 'resolve':
			if ( empty( $input['preset_id'] ) || empty( $input['module_type'] ) ) {
				return new WP_Error( 'missing_fields', __( 'preset_id and module_type are required for resolve.', 'e2mconnect' ) );
			}
			if ( ! class_exists( 'Respira_Divi_Presets' ) ) {
				return new WP_Error( 'divi_intel_unavailable', __( 'Divi preset intelligence not loaded — resolve requires Respira_Divi_Presets class.', 'e2mconnect' ) );
			}
			$preset_id    = sanitize_text_field( $input['preset_id'] );
			$module_type  = sanitize_text_field( $input['module_type'] );
			$override_attrs = is_array( $input['override_attrs'] ?? null ) ? $input['override_attrs'] : [];
			$preview      = $override_attrs;
			$apply        = Respira_Divi_Presets::apply_preset( $preview, $preset_id, $module_type );
			if ( is_wp_error( $apply ) ) {
				return $apply;
			}
			return [
				'action'      => 'resolve',
				'preset_id'   => $preset_id,
				'module_type' => $module_type,
				'attrs'       => $preview,
				'merged'      => true,
			];

		// ── Delete ────────────────────────────────────────────────────────────
		case 'delete':
			if ( empty( $input['preset_id'] ) ) {
				return new WP_Error( 'missing_preset_id', __( 'preset_id is required for the delete action.', 'e2mconnect' ) );
			}
			$preset_id = sanitize_text_field( $input['preset_id'] );
			return e2m_engine_divi_delete_preset( $preset_id );

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, upsert, bulk_upsert, resolve, delete.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Native (non-Respira) fallback helpers — read/write et_divi option directly
// ──────────────────────────────────────────────────────────────────────────────

/**
 * List Divi presets from et_divi option (native fallback).
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function e2m_engine_divi_list_presets_native( array $input ): array {
	$raw = e2m_engine_divi_get_raw_presets();

	$module_type_filter = ! empty( $input['module_type'] ) ? $input['module_type'] : null;
	$scope_filter       = in_array( $input['scope'] ?? '', [ 'module', 'group' ], true ) ? $input['scope'] : null;

	$presets = [];
	foreach ( $raw as $kind_key => $by_type ) {
		if ( ! is_array( $by_type ) ) {
			continue;
		}
		foreach ( $by_type as $type_key => $preset_list ) {
			if ( $module_type_filter && $type_key !== $module_type_filter ) {
				continue;
			}
			if ( $scope_filter && $kind_key !== $scope_filter ) {
				continue;
			}
			if ( ! is_array( $preset_list ) ) {
				continue;
			}
			foreach ( $preset_list as $id => $preset ) {
				$presets[] = array_merge( [ 'id' => $id, 'kind' => $kind_key, 'module_type' => $type_key ], (array) $preset );
			}
		}
	}

	return [
		'action'     => 'list',
		'presets'    => $presets,
		'count'      => count( $presets ),
		'available'  => true,
		'updated_at' => 0,
	];
}

/**
 * Find a preset by UUID in et_divi option (native fallback).
 *
 * @param string $preset_id
 * @return array<string,mixed>|null
 */
function e2m_engine_divi_find_preset_native( string $preset_id ): ?array {
	$raw = e2m_engine_divi_get_raw_presets();
	foreach ( $raw as $kind_key => $by_type ) {
		if ( ! is_array( $by_type ) ) {
			continue;
		}
		foreach ( $by_type as $type_key => $preset_list ) {
			if ( ! is_array( $preset_list ) ) {
				continue;
			}
			foreach ( $preset_list as $id => $preset ) {
				if ( (string) $id === $preset_id ) {
					return array_merge( [ 'id' => $id, 'kind' => $kind_key, 'module_type' => $type_key ], (array) $preset );
				}
			}
		}
	}
	return null;
}

/**
 * Upsert a preset directly into et_divi option (native fallback).
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function e2m_engine_divi_upsert_preset_native( array $input ): array {
	$kind        = sanitize_key( $input['kind'] );
	$module_type = sanitize_text_field( $input['module_type'] );
	$name        = sanitize_text_field( $input['name'] );
	$attrs       = is_array( $input['attrs'] ) ? $input['attrs'] : [];

	$et_options = get_option( 'et_divi', [] );
	if ( ! is_array( $et_options ) ) {
		$et_options = [];
	}

	if ( ! isset( $et_options['presets'][ $kind ][ $module_type ] ) ) {
		$et_options['presets'][ $kind ][ $module_type ] = [];
	}

	// Try to find existing by name for upsert.
	$existing_id = null;
	foreach ( $et_options['presets'][ $kind ][ $module_type ] as $id => $p ) {
		if ( isset( $p['name'] ) && $p['name'] === $name ) {
			$existing_id = (string) $id;
			break;
		}
	}

	$status = 'created';
	if ( $existing_id ) {
		$status = 'updated';
		$preset_id = $existing_id;
	} else {
		$preset_id = e2m_engine_divi_mint_uuid();
	}

	$et_options['presets'][ $kind ][ $module_type ][ $preset_id ] = [
		'name'  => $name,
		'attrs' => $attrs,
	];

	update_option( 'et_divi', $et_options, false );

	return [
		'action' => 'upsert',
		'status' => $status,
		'id'     => $preset_id,
		'preset' => $et_options['presets'][ $kind ][ $module_type ][ $preset_id ],
	];
}

/**
 * Bulk upsert presets natively.
 *
 * @param array<int,array> $specs
 * @return array<string,mixed>
 */
function e2m_engine_divi_bulk_upsert_native( array $specs ): array {
	$results = [];
	$created = 0;
	$updated = 0;
	$failed  = [];

	foreach ( $specs as $idx => $spec ) {
		if ( ! is_array( $spec ) || empty( $spec['name'] ) || empty( $spec['kind'] ) || empty( $spec['module_type'] ) ) {
			$failed[] = [ 'index' => $idx, 'code' => 'invalid_spec', 'message' => 'Missing required fields.' ];
			continue;
		}
		$res       = e2m_engine_divi_upsert_preset_native( $spec );
		$results[] = $res;
		'created' === $res['status'] ? $created++ : $updated++;
	}

	return [
		'action'  => 'bulk_upsert',
		'presets' => $results,
		'created' => $created,
		'updated' => $updated,
		'failed'  => $failed,
	];
}

/**
 * Delete a preset by UUID from et_divi option.
 *
 * @param string $preset_id
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_delete_preset( string $preset_id ): array|WP_Error {
	$et_options = get_option( 'et_divi', [] );
	if ( ! is_array( $et_options ) ) {
		return new WP_Error( 'preset_not_found', __( 'No presets found in Divi settings.', 'e2mconnect' ) );
	}

	$raw     = $et_options['presets'] ?? [];
	$deleted = false;

	foreach ( $raw as $kind => $by_type ) {
		if ( ! is_array( $by_type ) ) {
			continue;
		}
		foreach ( $by_type as $type => $preset_list ) {
			if ( isset( $preset_list[ $preset_id ] ) ) {
				unset( $et_options['presets'][ $kind ][ $type ][ $preset_id ] );
				$deleted = true;
				break 2;
			}
		}
	}

	if ( ! $deleted ) {
		// Also try Respira_Divi_Presets raw data store.
		if ( class_exists( 'Respira_Divi_Presets' ) && method_exists( 'Respira_Divi_Presets', 'get_raw_data' ) ) {
			$data = Respira_Divi_Presets::get_raw_data();
			foreach ( $data as $kind => $by_type ) {
				if ( ! is_array( $by_type ) ) {
					continue;
				}
				foreach ( $by_type as $type_key => $preset_list ) {
					if ( ! is_array( $preset_list ) ) {
						continue;
					}
					foreach ( $preset_list as $idx => $p ) {
						$pid = $p['id'] ?? $p['_id'] ?? null;
						if ( $pid === $preset_id ) {
							// Remove from et_divi option's matching structure.
							$et_options['presets'][ $kind ][ $type_key ] = array_filter(
								(array) ( $et_options['presets'][ $kind ][ $type_key ] ?? [] ),
								fn( $pr ) => ( $pr['id'] ?? $pr['_id'] ?? null ) !== $preset_id
							);
							$deleted = true;
							break 3;
						}
					}
				}
			}
		}
	}

	if ( ! $deleted ) {
		return new WP_Error( 'preset_not_found', __( 'Preset not found with that ID.', 'e2mconnect' ) );
	}

	update_option( 'et_divi', $et_options, false );

	return [
		'action'    => 'delete',
		'preset_id' => $preset_id,
		'deleted'   => true,
	];
}

/**
 * Get the raw preset storage from et_divi option.
 *
 * @return array<string,mixed>
 */
function e2m_engine_divi_get_raw_presets(): array {
	if ( class_exists( 'Respira_Divi_Presets' ) && method_exists( 'Respira_Divi_Presets', 'get_raw_data' ) ) {
		return (array) Respira_Divi_Presets::get_raw_data();
	}
	$et_options = get_option( 'et_divi', [] );
	return is_array( $et_options['presets'] ?? null ) ? $et_options['presets'] : [];
}

/**
 * Mint a UUID v4 for native preset creation.
 *
 * @return string
 */
function e2m_engine_divi_mint_uuid(): string {
	$bytes = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : openssl_random_pseudo_bytes( 16 );
	$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
	$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
}
