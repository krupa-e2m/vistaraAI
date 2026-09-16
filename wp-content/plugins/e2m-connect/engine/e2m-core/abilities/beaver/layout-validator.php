<?php
/**
 * E2M Connect MCP – Beaver Builder Layout Validator
 *
 * Deep structural validation of a Beaver Builder flat node map —
 * not just per-module settings, but the whole layout:
 *
 *   • Required node fields (node ID, type, parent, position, settings)
 *   • Hierarchy consistency (rows have columns, modules have settings->type)
 *   • Color, URL, and numeric field format checking
 *   • Flexible / tree-format payload support (children/modules/elements keys)
 *
 * Actions:
 *   validate_layout — validate a full flat node map (or flexible tree)
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/beaver-layout-validator', [
	'label'       => __( '[Beaver] Layout Validator', 'e2mconnect' ),
	'description' => 'Deep structural validation of a Beaver Builder flat node map: node field requirements, hierarchy consistency, color formats, URL validation, and numeric type checks.',
	'category'    => 'e2m-beaver',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'validate_layout' ],
				'description' => 'validate_layout — validate a Beaver Builder flat node map or flexible tree.',
			],
			'content' => [
				'description' => 'Flat node map (keyed by node ID) or JSON string of a Beaver Builder layout.',
			],
		],
		'required'             => [ 'action', 'content' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'valid'    => [ 'type' => 'boolean' ],
			'errors'   => [ 'type' => 'array', 'description' => 'Blocking errors that must be fixed.' ],
			'warnings' => [ 'type' => 'array', 'description' => 'Non-blocking hints.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_beaver_layout_validator',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Beaver: Layout Validator',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute beaver-layout-validator ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_beaver_layout_validator( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_beaver() ) {
		return new WP_Error( 'beaver_missing', __( 'Beaver Builder is not active on this site.', 'e2mconnect' ) );
	}

	$content = $input['content'];

	// Prefer Respira_Beaver_Validator if available.
	if ( class_exists( 'Respira_Beaver_Validator' ) ) {
		$validator = new Respira_Beaver_Validator();
		$result    = $validator->validate_layout( is_string( $content ) ? json_decode( $content, true ) : $content );
		return array_merge( [ 'action' => sanitize_key( $input['action'] ) ], $result );
	}

	// Native validation.
	$result = e2m_engine_beaver_validate_layout_native( $content );
	return array_merge( [ 'action' => sanitize_key( $input['action'] ) ], $result );
}

// ──────────────────────────────────────────────────────────────────────────────
// Native layout validator
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Recursively cast stdClass objects to arrays.
 *
 * @param mixed $data
 * @return mixed
 */
function e2m_engine_beaver_deep_to_array( mixed $data ): mixed {
	if ( $data instanceof stdClass ) {
		$data = (array) $data;
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $k => $v ) {
			$data[ $k ] = e2m_engine_beaver_deep_to_array( $v );
		}
	}
	return $data;
}

/**
 * Validate a Beaver Builder layout (flat node map or flexible tree).
 *
 * @param mixed $content  Raw layout data — flat node map, tree, or JSON string.
 * @return array{valid:bool, errors:string[], warnings:string[]}
 */
function e2m_engine_beaver_validate_layout_native( mixed $content ): array {
	$errors   = [];
	$warnings = [];

	// 1. Decode JSON string if given.
	if ( is_string( $content ) ) {
		$content = json_decode( $content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'valid'    => false,
				'errors'   => [ 'Invalid JSON: ' . json_last_error_msg() ],
				'warnings' => [],
			];
		}
	}

	// 2. Cast all stdClass to arrays recursively.
	$content = e2m_engine_beaver_deep_to_array( $content );

	if ( ! is_array( $content ) ) {
		return [
			'valid'    => false,
			'errors'   => [ 'Content must be an array or JSON object.' ],
			'warnings' => [],
		];
	}

	// 3. Detect flexible/tree format vs BB flat node map.
	$is_flexible = false;
	foreach ( $content as $node ) {
		if ( is_array( $node ) ) {
			// Flexible if it has tree-specific keys.
			if ( array_key_exists( 'children', $node ) || array_key_exists( 'modules', $node ) || array_key_exists( 'elements', $node ) ) {
				$is_flexible = true;
				break;
			}
			// Flexible if it has types other than the standard BB types.
			$t = $node['type'] ?? '';
			if ( ! in_array( $t, [ 'row', 'column-group', 'column', 'module', '' ], true ) ) {
				$is_flexible = true;
				break;
			}
		}
	}

	if ( $is_flexible ) {
		$flex_result = e2m_engine_beaver_validate_flexible_nodes( $content );
		$errors      = array_merge( $errors, $flex_result['errors'] );
		$warnings    = array_merge( $warnings, $flex_result['warnings'] );
	} else {
		// BB flat node map: the map is keyed by node ID.
		// Normalise: accept both an associative map (ID => node) and a plain list.
		$nodes = [];
		foreach ( $content as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			// If the node doesn't have a 'node' key, treat the array key as the node ID.
			if ( ! isset( $node['node'] ) && is_string( $key ) ) {
				$node['node'] = $key;
			}
			$nodes[] = $node;
		}

		// Validate each node.
		$row_nodes    = [];
		$col_grp_nodes = [];
		$col_nodes    = [];
		$mod_nodes    = [];

		foreach ( $nodes as $node ) {
			$node_id = $node['node'] ?? null;
			$type    = $node['type'] ?? null;

			if ( ! $node_id ) {
				$errors[] = 'A node is missing the required "node" (ID) field.';
				continue;
			}
			if ( ! $type ) {
				$errors[] = "Node \"{$node_id}\" is missing the required \"type\" field.";
				continue;
			}
			if ( ! in_array( $type, [ 'row', 'column-group', 'column', 'module' ], true ) ) {
				$warnings[] = "Node \"{$node_id}\" has unexpected type \"{$type}\". Expected: row, column-group, column, module.";
			}

			if ( ! isset( $node['position'] ) && $node['position'] !== 0 ) {
				$warnings[] = "Node \"{$node_id}\" is missing the \"position\" field.";
			}

			switch ( $type ) {
				case 'row':
					$row_nodes[ $node_id ] = $node;
					break;
				case 'column-group':
					$col_grp_nodes[ $node_id ] = $node;
					break;
				case 'column':
					$col_nodes[ $node_id ] = $node;
					break;
				case 'module':
					$mod_nodes[ $node_id ] = $node;
					// Modules need settings with a type.
					$settings = $node['settings'] ?? null;
					if ( ! is_array( $settings ) ) {
						$errors[] = "Module node \"{$node_id}\" is missing a \"settings\" array.";
					} elseif ( empty( $settings['type'] ) ) {
						$errors[] = "Module node \"{$node_id}\" settings is missing required \"type\" field.";
					} else {
						// Validate known settings properties.
						e2m_engine_beaver_validate_module_settings( $node_id, $settings['type'], $settings, $errors, $warnings );
					}
					break;
			}
		}

		// Check parent references: column-group nodes should reference an existing row.
		foreach ( $col_grp_nodes as $id => $node ) {
			$parent = $node['parent'] ?? null;
			if ( $parent && ! isset( $row_nodes[ $parent ] ) ) {
				$warnings[] = "Column-group \"{$id}\" references parent \"{$parent}\" which is not a row node.";
			}
		}

		// Check column nodes should reference existing column-group.
		foreach ( $col_nodes as $id => $node ) {
			$parent = $node['parent'] ?? null;
			if ( $parent && ! isset( $col_grp_nodes[ $parent ] ) ) {
				$warnings[] = "Column \"{$id}\" references parent \"{$parent}\" which is not a column-group node.";
			}
		}

		// Check module nodes should reference existing column.
		foreach ( $mod_nodes as $id => $node ) {
			$parent = $node['parent'] ?? null;
			if ( ! $parent ) {
				// M1 fix: orphan module at root level — no row/column parent.
				$warnings[] = "Module \"{$id}\" has no parent. BB requires modules to be nested inside a column (row → column-group → column → module).";
			} elseif ( ! isset( $col_nodes[ $parent ] ) ) {
				$warnings[] = "Module \"{$id}\" references parent \"{$parent}\" which is not a column node.";
			}
		}

		// Warn if there are modules but no rows (fully flat/orphan layout).
		if ( ! empty( $mod_nodes ) && empty( $row_nodes ) ) {
			$warnings[] = 'Layout has module nodes but no row nodes. BB requires the hierarchy: row → column-group → column → module.';
		}
	}

	return [
		'valid'    => empty( $errors ),
		'errors'   => $errors,
		'warnings' => $warnings,
	];
}

/**
 * Recursively validate flexible/tree format nodes.
 *
 * @param array  $nodes  Array of node objects.
 * @param string $path   Current path in the tree (for error messages).
 * @return array{errors:string[], warnings:string[]}
 */
function e2m_engine_beaver_validate_flexible_nodes( array $nodes, string $path = 'root' ): array {
	$errors   = [];
	$warnings = [];

	foreach ( $nodes as $i => $node ) {
		if ( ! is_array( $node ) ) {
			$errors[] = "Node at {$path}[{$i}] is not an object/array.";
			continue;
		}

		$node_path = "{$path}[{$i}]";
		$node_id   = $node['node'] ?? $node['id'] ?? null;
		$label     = $node_id ? "\"{$node_id}\"" : "at {$node_path}";

		// Each node must have a type string.
		if ( ! isset( $node['type'] ) || ! is_string( $node['type'] ) ) {
			$errors[] = "Node {$label}: missing or non-string \"type\" field.";
		}

		// attributes/settings must be array if present.
		foreach ( [ 'attributes', 'settings' ] as $key ) {
			if ( isset( $node[ $key ] ) && ! is_array( $node[ $key ] ) ) {
				$errors[] = "Node {$label}: \"{$key}\" must be an array/object, got " . gettype( $node[ $key ] ) . '.';
			}
		}

		// children/modules/elements must be array if present.
		foreach ( [ 'children', 'modules', 'elements' ] as $key ) {
			if ( isset( $node[ $key ] ) ) {
				if ( ! is_array( $node[ $key ] ) ) {
					$errors[] = "Node {$label}: \"{$key}\" must be an array, got " . gettype( $node[ $key ] ) . '.';
				} else {
					$child_result = e2m_engine_beaver_validate_flexible_nodes( $node[ $key ], "{$node_path}.{$key}" );
					$errors       = array_merge( $errors, $child_result['errors'] );
					$warnings     = array_merge( $warnings, $child_result['warnings'] );
				}
			}
		}
	}

	return [ 'errors' => $errors, 'warnings' => $warnings ];
}

/**
 * Validate module settings values (colors, URLs, numerics).
 *
 * @param string   $node_id   Node identifier for error messages.
 * @param string   $type      Module type slug.
 * @param array    $settings  Settings array.
 * @param string[] $errors    Errors array (by reference).
 * @param string[] $warnings  Warnings array (by reference).
 */
function e2m_engine_beaver_validate_module_settings( string $node_id, string $type, array $settings, array &$errors, array &$warnings ): void {
	$numeric_fields = [ 'size', 'height', 'width', 'speed', 'pause', 'number', 'max_number', 'posts_per_page', 'zoom', 'border_radius', 'padding_top', 'padding_bottom', 'padding_left', 'padding_right', 'gap', 'icon_size', 'bg_size', 'mobile_breakpoint', 'transitionspeed', 'responsive_height', 'mobile_height' ];
	$url_fields     = [ 'link', 'button_url', 'photo_src', 'video_url', 'audio_url' ];
	$color_fields   = [ 'color', 'bg_color', 'text_color', 'name_color', 'label_color', 'content_text_color', 'border_color', 'icon_color', 'hover_color', 'hover_bg_color', 'btn_bg_color', 'btn_text_color', 'button_bg_color', 'button_text_color', 'link_color', 'link_hover_color', 'title_color', 'amount_color', 'duration_color', 'features_text_color', 'number_color', 'company_color', 'label_active_color', 'label_inactive_color' ];

	foreach ( $settings as $key => $val ) {
		if ( $key === 'type' || $val === '' || $val === null ) {
			continue;
		}

		// Numeric validation.
		if ( in_array( $key, $numeric_fields, true ) && ! is_numeric( $val ) ) {
			$errors[] = "Module \"{$node_id}\" ({$type}): \"{$key}\" must be numeric, got \"" . wp_kses_post( (string) $val ) . '".';
		}

		// Color validation — BB stores hex without # prefix.
		if ( in_array( $key, $color_fields, true ) && is_string( $val ) && $val !== '' ) {
			if ( ! preg_match( '/^#?[0-9a-fA-F]{3,8}$/', $val ) ) {
				$warnings[] = "Module \"{$node_id}\" ({$type}): \"{$key}\" value \"{$val}\" does not look like a valid hex color.";
			}
		}

		// URL validation.
		if ( in_array( $key, $url_fields, true ) && is_string( $val ) && $val !== '' && $val !== '#' ) {
			if ( ! filter_var( $val, FILTER_VALIDATE_URL ) && ! str_starts_with( $val, '/' ) ) {
				$warnings[] = "Module \"{$node_id}\" ({$type}): \"{$key}\" value \"{$val}\" does not look like a valid URL.";
			}
		}
	}
}
