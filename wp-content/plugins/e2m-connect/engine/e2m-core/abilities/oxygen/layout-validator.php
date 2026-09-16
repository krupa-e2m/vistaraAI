<?php
/**
 * E2M Connect MCP – Oxygen Layout Validator
 *
 * Deep structural validation of a full Oxygen Builder component tree —
 * not just per-component settings, but the whole layout:
 *
 *   • Required fields (id, name) on every component
 *   • Duplicate component IDs
 *   • Nesting guidance (ct_column inside ct_new_columns, etc.)
 *   • ct_parent reference consistency
 *   • Per-component option validation (color formats, URLs, enums, numerics)
 *   • JSON `code` wrapper format (Oxygen's native storage format)
 *
 * Actions:
 *   validate_layout  — validate a full component tree
 *   validate_tree    — alias for validate_layout (same result)
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/oxygen-layout-validator', [
	'label'       => __( '[Oxygen] Layout Validator', 'e2mconnect' ),
	'description' => 'Deep structural validation of a full Oxygen Builder component tree: duplicate IDs, missing required fields, nesting guidance, ct_parent consistency, and per-option type validation.',
	'category'    => 'e2m-oxygen',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'validate_layout', 'validate_tree' ],
				'description' => 'validate_layout / validate_tree — both accept the same input and return the same result.',
			],
			'content' => [
				'description' => 'Full Oxygen component tree. Either a JSON-encoded string wrapped in {"code":"..."} (Oxygen native format) or a plain array of component objects.',
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
			'warnings' => [ 'type' => 'array', 'description' => 'Non-blocking hints (nesting guidance, unknown props).' ],
		],
	],

	'execute_callback'    => 'e2m_engine_oxygen_layout_validator',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Oxygen: Layout Validator',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute oxygen-layout-validator ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_oxygen_layout_validator( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_oxygen() ) {
		return new WP_Error( 'oxygen_missing', __( 'Oxygen Builder is not active on this site.', 'e2mconnect' ) );
	}

	$content = $input['content'];

	// Prefer Respira_Oxygen_Validator if available.
	if ( class_exists( 'Respira_Oxygen_Validator' ) ) {
		$validator = new Respira_Oxygen_Validator();
		$result    = $validator->validate_layout( is_string( $content ) ? json_decode( $content, true ) : $content );
		return array_merge( [ 'action' => sanitize_key( $input['action'] ) ], $result );
	}

	// Native validation.
	$result = e2m_engine_oxygen_validate_layout_native( $content );
	return array_merge( [ 'action' => sanitize_key( $input['action'] ) ], $result );
}

// ──────────────────────────────────────────────────────────────────────────────
// Native layout validator — mirrors Respira_Oxygen_Validator logic
// ──────────────────────────────────────────────────────────────────────────────

/** Nesting guidance: parent_type => allowed child types ('*' means any). */
const E2M_OXYGEN_NESTING = [
	'ct_new_columns' => [ 'ct_column' ],
	'ct_slider'      => [ 'ct_slide' ],
	'ct_header_row'  => [ 'oxy_header_left', 'oxy_header_center', 'oxy_header_right' ],
	'ct_column'      => [ '*' ],
	'ct_section'     => [ '*' ],
	'ct_div_block'   => [ '*' ],
	'ct_slide'       => [ '*' ],
];

/**
 * Validate a full Oxygen layout.
 *
 * @param mixed $content
 * @return array{valid:bool, errors:string[], warnings:string[]}
 */
function e2m_engine_oxygen_validate_layout_native( mixed $content ): array {
	$errors   = [];
	$warnings = [];

	// Decode if needed.
	if ( is_string( $content ) ) {
		$content = json_decode( $content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [ 'valid' => false, 'errors' => [ 'Invalid JSON: ' . json_last_error_msg() ], 'warnings' => [] ];
		}
	}

	// Unwrap Oxygen native `{"code":"..."}` format.
	if ( is_array( $content ) && isset( $content['code'] ) ) {
		$decoded = json_decode( $content['code'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [ 'valid' => false, 'errors' => [ 'Invalid JSON in code key: ' . json_last_error_msg() ], 'warnings' => [] ];
		}
		$content = $decoded;
	}

	if ( ! is_array( $content ) ) {
		return [ 'valid' => false, 'errors' => [ 'Content must be an array.' ], 'warnings' => [] ];
	}

	$component_ids = [];
	e2m_engine_oxygen_walk_validate( $content, $errors, $component_ids );

	// Duplicate IDs.
	$counts = array_count_values( $component_ids );
	foreach ( $counts as $id => $count ) {
		if ( $count > 1 ) {
			$errors[] = "Duplicate component ID found: {$id}";
		}
	}

	// Nesting guidance warnings.
	$warnings = array_merge( $warnings, e2m_engine_oxygen_check_nesting( $content ) );

	// ct_parent consistency warnings.
	$warnings = array_merge( $warnings, e2m_engine_oxygen_check_parent_refs( $content, $component_ids ) );

	return [
		'valid'    => empty( $errors ),
		'errors'   => $errors,
		'warnings' => $warnings,
	];
}

/**
 * Walk the component tree and collect errors + IDs.
 *
 * @param array    $tree
 * @param string[] $errors        (by reference)
 * @param string[] $component_ids (by reference)
 */
function e2m_engine_oxygen_walk_validate( array $tree, array &$errors, array &$component_ids ): void {
	foreach ( $tree as $component ) {
		if ( ! is_array( $component ) ) {
			continue;
		}

		// Allow 'type' as alias for 'name'.
		if ( ! isset( $component['name'] ) && isset( $component['type'] ) ) {
			$component['name'] = $component['type'];
		}

		if ( ! isset( $component['id'] ) ) {
			$errors[] = 'Component is missing required "id" field.';
			continue;
		}

		if ( ! isset( $component['name'] ) ) {
			$errors[] = "Component \"{$component['id']}\" is missing required \"name\" (type) field.";
		}

		$component_ids[] = (string) $component['id'];

		// Validate options.
		if ( isset( $component['options'] ) ) {
			if ( ! is_array( $component['options'] ) ) {
				$errors[] = "Component \"{$component['id']}\" options must be an array.";
			} else {
				e2m_engine_oxygen_validate_options( $component['name'] ?? '', $component['options'], (string) $component['id'], $errors );
			}
		}

		// Recurse children.
		if ( isset( $component['children'] ) ) {
			if ( ! is_array( $component['children'] ) ) {
				$errors[] = "Component \"{$component['id']}\" children must be an array.";
			} else {
				e2m_engine_oxygen_walk_validate( $component['children'], $errors, $component_ids );
			}
		}
	}
}

/**
 * Validate a component's options array.
 *
 * @param string   $type
 * @param array    $options
 * @param string   $cid
 * @param string[] $errors (by reference)
 */
function e2m_engine_oxygen_validate_options( string $type, array $options, string $cid, array &$errors ): void {
	$numeric_props = [ 'zoom', 'speed', 'start', 'end', 'duration', 'percent', 'rating', 'posts_per_page', 'active_tab', 'active_item', 'mobile_breakpoint', 'rows', 'column-count', 'columns', 'flex-grow', 'flex-shrink', 'opacity', 'z-index' ];
	$bool_props    = [ 'autoplay', 'loop', 'controls', 'multiple_open', 'open', 'arrows', 'dots', 'overlay', 'marker', 'required' ];
	$array_props   = [ 'items', 'slides', 'tabs', 'images', 'fields', 'features' ];
	$enum_props    = [
		'tag'        => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'label', 'li' ],
		'target'     => [ '_self', '_blank', '_parent', '_top' ],
		'object-fit' => [ 'cover', 'contain', 'fill', 'none', 'scale-down' ],
		'display'    => [ 'block', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'none' ],
		'position'   => [ 'static', 'relative', 'absolute', 'fixed', 'sticky' ],
	];

	foreach ( $options as $key => $val ) {
		if ( in_array( $key, $numeric_props, true ) && ! empty( $val ) && ! is_numeric( $val ) ) {
			$errors[] = "Property \"{$key}\" in component \"{$cid}\" ({$type}) must be numeric.";
		}
		if ( in_array( $key, $bool_props, true ) && ! is_bool( $val ) && ! in_array( $val, [ 'true', 'false', '1', '0', 1, 0, 'yes', 'no' ], true ) ) {
			$errors[] = "Property \"{$key}\" in component \"{$cid}\" ({$type}) must be boolean.";
		}
		if ( in_array( $key, $array_props, true ) && ! empty( $val ) && ! is_array( $val ) ) {
			$errors[] = "Property \"{$key}\" in component \"{$cid}\" ({$type}) must be an array.";
		}
		if ( isset( $enum_props[ $key ] ) && ! empty( $val ) && ! in_array( $val, $enum_props[ $key ], true ) ) {
			$errors[] = "Property \"{$key}\" value \"{$val}\" in component \"{$cid}\" ({$type}) must be one of: " . implode( ', ', $enum_props[ $key ] ) . '.';
		}
		// Color format.
		if ( ( str_contains( $key, 'color' ) ) && is_string( $val ) && ! empty( $val ) ) {
			if ( ! preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\(|[a-z]+)/', $val ) ) {
				$errors[] = "Invalid color format for \"{$key}\" in component \"{$cid}\" ({$type}).";
			}
		}
		// URL format.
		if ( in_array( $key, [ 'url', 'button_url' ], true ) && ! empty( $val ) && is_string( $val ) && $val !== '#' ) {
			if ( ! filter_var( $val, FILTER_VALIDATE_URL ) && ! str_starts_with( $val, '/' ) ) {
				$errors[] = "Invalid URL format for \"{$key}\" in component \"{$cid}\" ({$type}).";
			}
		}
		// Email.
		if ( $key === 'email_to' && ! empty( $val ) && is_string( $val ) && ! is_email( $val ) ) {
			$errors[] = "Invalid email format for \"email_to\" in component \"{$cid}\" ({$type}).";
		}
	}
}

/**
 * Check nesting guidance across the tree.
 *
 * @param array $components
 * @return string[]
 */
function e2m_engine_oxygen_check_nesting( array $components ): array {
	$warnings = [];
	foreach ( $components as $comp ) {
		if ( ! is_array( $comp ) || ! isset( $comp['name'] ) ) {
			continue;
		}
		$parent_type = $comp['name'];
		$children    = $comp['children'] ?? [];
		$nesting     = E2M_OXYGEN_NESTING;

		if ( ! empty( $children ) && isset( $nesting[ $parent_type ] ) ) {
			$allowed = $nesting[ $parent_type ];
			if ( ! in_array( '*', $allowed, true ) ) {
				foreach ( $children as $child ) {
					if ( ! is_array( $child ) || ! isset( $child['name'] ) ) {
						continue;
					}
					if ( ! in_array( $child['name'], $allowed, true ) ) {
						$warnings[] = "Nesting hint: \"{$child['name']}\" is typically not a direct child of \"{$parent_type}\". Expected: " . implode( ', ', $allowed ) . '.';
					}
				}
			}
		}
		if ( ! empty( $children ) ) {
			$warnings = array_merge( $warnings, e2m_engine_oxygen_check_nesting( $children ) );
		}
	}
	return $warnings;
}

/**
 * Check ct_parent reference consistency.
 *
 * @param array    $components
 * @param string[] $all_ids
 * @return string[]
 */
function e2m_engine_oxygen_check_parent_refs( array $components, array $all_ids ): array {
	$warnings = [];
	foreach ( $components as $comp ) {
		if ( ! is_array( $comp ) ) {
			continue;
		}
		$parent_ref = $comp['options']['ct_parent'] ?? null;
		if ( $parent_ref !== null && $parent_ref !== 0 && ! empty( $parent_ref ) && ! in_array( (string) $parent_ref, $all_ids, true ) ) {
			$warnings[] = "Component \"{$comp['id']}\" references ct_parent \"{$parent_ref}\" which was not found in the tree.";
		}
		if ( ! empty( $comp['children'] ) && is_array( $comp['children'] ) ) {
			$warnings = array_merge( $warnings, e2m_engine_oxygen_check_parent_refs( $comp['children'], $all_ids ) );
		}
	}
	return $warnings;
}
