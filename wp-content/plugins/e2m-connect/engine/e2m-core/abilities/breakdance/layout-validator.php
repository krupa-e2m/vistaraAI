<?php
/**
 * E2M Connect MCP – Breakdance Layout Validator
 *
 * Deep structural validation of a full Breakdance element tree:
 *
 *   • Decodes all known storage formats (plain array, tree_json_string wrapper,
 *     root-wrapper variants)
 *   • Required fields (id, type) on every element
 *   • Duplicate element IDs
 *   • Nesting guidance (Columns → Column, AdvancedAccordion → AdvancedAccordionContent, etc.)
 *   • Child-only element warnings (Column must be inside Columns)
 *   • Property enum validation for known design and content fields
 *   • Color format checks
 *
 * Actions:
 *   validate_layout — validate a full Breakdance element tree
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/breakdance-layout-validator', [
	'label'       => __( '[Breakdance] Layout Validator', 'e2mconnect' ),
	'description' => 'Deep structural validation of a full Breakdance element tree: duplicate IDs, missing required fields, nesting guidance, and property value checks.',
	'category'    => 'e2m-breakdance',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'validate_layout' ],
				'description' => 'validate_layout — validate a full Breakdance element tree.',
			],
			'content' => [
				'description' => 'Breakdance element tree. Can be a JSON string, array, {tree_json_string:"..."} wrapper, or {root:{children:[...]}} format.',
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
			'warnings' => [ 'type' => 'array', 'description' => 'Non-blocking hints (nesting guidance, enum mismatches).' ],
		],
	],

	'execute_callback'    => 'e2m_engine_breakdance_layout_validator',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Breakdance: Layout Validator',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute breakdance-layout-validator ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_breakdance_layout_validator( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_breakdance() ) {
		return new WP_Error( 'breakdance_missing', __( 'Breakdance Builder is not active on this site.', 'e2mconnect' ) );
	}

	$content = $input['content'];

	// Prefer Respira_Breakdance_Validator if available.
	if ( class_exists( 'Respira_Breakdance_Validator' ) ) {
		$validator = new Respira_Breakdance_Validator();
		$result    = $validator->validate_layout( is_string( $content ) ? json_decode( $content, true ) : $content );
		return array_merge( [ 'action' => 'validate_layout' ], $result );
	}

	$result = e2m_engine_breakdance_validate_layout_native( $content );
	return array_merge( [ 'action' => 'validate_layout' ], $result );
}

// ──────────────────────────────────────────────────────────────────────────────
// Native layout validator — mirrors Respira_Breakdance_Validator logic
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Validate a full Breakdance layout.
 *
 * @param mixed $content
 * @return array{valid:bool, errors:string[], warnings:string[]}
 */
function e2m_engine_breakdance_validate_layout_native( mixed $content ): array {
	$errors   = [];
	$warnings = [];

	// 1. Decode JSON string if needed.
	if ( is_string( $content ) ) {
		$content = json_decode( $content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [ 'valid' => false, 'errors' => [ 'Invalid JSON: ' . json_last_error_msg() ], 'warnings' => [] ];
		}
	}

	if ( ! is_array( $content ) ) {
		return [ 'valid' => false, 'errors' => [ 'Content must be an array or JSON string.' ], 'warnings' => [] ];
	}

	// 2. Unwrap {tree_json_string:"..."} format (Breakdance v2.6+).
	if ( isset( $content['tree_json_string'] ) && is_string( $content['tree_json_string'] ) ) {
		$decoded = json_decode( $content['tree_json_string'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [ 'valid' => false, 'errors' => [ 'Invalid JSON in tree_json_string: ' . json_last_error_msg() ], 'warnings' => [] ];
		}
		$content = $decoded;
	}

	// 3. Unwrap {root:{children:[...]}} or {type:"root",children:[...]} format.
	if ( isset( $content['root'] ) && is_array( $content['root'] ) ) {
		$content = $content['root']['children'] ?? $content['root'];
	} elseif ( isset( $content['type'] ) && $content['type'] === 'root' && isset( $content['children'] ) ) {
		$content = $content['children'];
	}

	if ( ! is_array( $content ) ) {
		return [ 'valid' => false, 'errors' => [ 'Content must resolve to an array of elements.' ], 'warnings' => [] ];
	}

	// 4. Validate recursively.
	$seen_ids = [];
	e2m_engine_breakdance_walk_validate( $content, $errors, $warnings, $seen_ids, null );

	// 5. Check for duplicate IDs.
	$counts = array_count_values( array_map( 'strval', $seen_ids ) );
	foreach ( $counts as $id => $count ) {
		if ( $count > 1 ) {
			$errors[] = "Duplicate element ID found: {$id}";
		}
	}

	// 6. M2 fix: Warn if no Section/DIV wrapper at root level.
	// Breakdance expects root-level elements to be Section or Div containers.
	// Content elements (Heading, Text, Button, etc.) at root render incorrectly.
	$section_like = [ 'Section', 'Div', 'Container', 'GlobalBlock', 'Header', 'Footer' ];
	$has_section  = false;
	$has_content_at_root = false;
	foreach ( $content as $root_el ) {
		if ( ! is_array( $root_el ) || empty( $root_el['type'] ) ) {
			continue;
		}
		$short = e2m_engine_breakdance_short_type( (string) $root_el['type'] );
		if ( in_array( $short, $section_like, true ) ) {
			$has_section = true;
		} else {
			$has_content_at_root = true;
		}
	}
	if ( $has_content_at_root && ! $has_section ) {
		$warnings[] = 'Nesting hint: Root-level elements should be wrapped in a Section or Div container. Content elements (Heading, Text, Button, etc.) at root level may not render correctly in Breakdance.';
	} elseif ( $has_content_at_root ) {
		$warnings[] = 'Nesting hint: Some root-level elements are not Section/Div containers. Consider wrapping content elements inside a Section.';
	}

	return [
		'valid'    => empty( $errors ),
		'errors'   => $errors,
		'warnings' => $warnings,
	];
}

/**
 * Nesting rules: parent short type => expected child short types.
 *
 * @return array<string,string[]>
 */
function e2m_engine_breakdance_nesting_rules(): array {
	return [
		'Columns'          => [ 'Column' ],
		'AdvancedAccordion'=> [ 'AdvancedAccordionContent' ],
		'AdvancedSlider'   => [ 'AdvancedSlide' ],
		'ContentToggle'    => [ 'ContentToggleContent' ],
	];
}

/**
 * Child-only elements: short type => expected parent short type.
 *
 * @return array<string,string>
 */
function e2m_engine_breakdance_child_only_elements(): array {
	return [
		'Column'                    => 'Columns',
		'AdvancedAccordionContent'  => 'AdvancedAccordion',
		'AdvancedSlide'             => 'AdvancedSlider',
		'ContentToggleContent'      => 'ContentToggle',
	];
}

/**
 * Recursively walk the element tree and collect errors and warnings.
 *
 * @param array      $elements
 * @param string[]   $errors      (by reference)
 * @param string[]   $warnings    (by reference)
 * @param int[]      $seen_ids    (by reference)
 * @param string|null $parent_type Short parent type (null at root).
 */
function e2m_engine_breakdance_walk_validate(
	array $elements,
	array &$errors,
	array &$warnings,
	array &$seen_ids,
	?string $parent_type
): void {
	$nesting    = e2m_engine_breakdance_nesting_rules();
	$child_only = e2m_engine_breakdance_child_only_elements();

	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			$errors[] = 'Element must be an array/object.';
			continue;
		}

		// Require 'id'.
		if ( ! isset( $element['id'] ) ) {
			$errors[] = 'Element is missing required "id" field.';
		} else {
			$seen_ids[] = $element['id'];
		}

		// Require 'type'.
		if ( empty( $element['type'] ) ) {
			$eid = $element['id'] ?? '(unknown)';
			$errors[] = "Element \"{$eid}\" is missing required \"type\" field.";
			continue;
		}

		$type_str  = (string) $element['type'];
		$short     = e2m_engine_breakdance_short_type( $type_str );
		$eid       = (string) ( $element['id'] ?? '(unknown)' );

		// Child-only placement check.
		if ( isset( $child_only[ $short ] ) ) {
			$expected_parent = $child_only[ $short ];
			if ( $parent_type !== $expected_parent ) {
				$actual = $parent_type ?? 'root';
				$warnings[] = "Nesting hint: \"{$short}\" should be a direct child of \"{$expected_parent}\" (found inside \"{$actual}\").";
			}
		}

		// Parent nesting guidance: validate children types.
		if ( isset( $nesting[ $short ] ) ) {
			$expected_children = $nesting[ $short ];
			$children          = $element['children'] ?? [];
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					if ( ! is_array( $child ) || empty( $child['type'] ) ) {
						continue;
					}
					$child_short = e2m_engine_breakdance_short_type( (string) $child['type'] );
					if ( ! in_array( $child_short, $expected_children, true ) ) {
						$warnings[] = "Nesting hint: \"{$short}\" (id={$eid}) should only contain " . implode( ', ', $expected_children ) . " children, found \"{$child_short}\".";
					}
				}
			}
		}

		// Validate data.content and data.design enum/color fields.
		$data   = $element['data'] ?? [];
		if ( is_array( $data ) ) {
			$content_data = $data['content'] ?? [];
			$design_data  = $data['design']  ?? [];

			if ( is_array( $content_data ) ) {
				e2m_engine_breakdance_validate_element_data( $content_data, $eid, $short, 'content', $errors, $warnings );
			}
			if ( is_array( $design_data ) ) {
				e2m_engine_breakdance_validate_element_data( $design_data, $eid, $short, 'design', $errors, $warnings );
			}
		}

		// Recurse into children.
		$children = $element['children'] ?? [];
		if ( is_array( $children ) && ! empty( $children ) ) {
			e2m_engine_breakdance_walk_validate( $children, $errors, $warnings, $seen_ids, $short );
		}
	}
}

/**
 * Validate a single element's data.content or data.design map.
 *
 * @param array<string,mixed> $data
 * @param string              $eid
 * @param string              $type_short
 * @param string              $section    'content' or 'design'
 * @param string[]            $errors     (by reference)
 * @param string[]            $warnings   (by reference)
 */
function e2m_engine_breakdance_validate_element_data(
	array $data,
	string $eid,
	string $type_short,
	string $section,
	array &$errors,
	array &$warnings
): void {
	$enums = [
		'tag'            => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'label', 'li' ],
		'display'        => [ 'block', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'none' ],
		'position'       => [ 'static', 'relative', 'absolute', 'fixed', 'sticky' ],
		'textAlign'      => [ 'left', 'center', 'right', 'justify' ],
		'flexDirection'  => [ 'row', 'row-reverse', 'column', 'column-reverse' ],
		'justifyContent' => [ 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly' ],
		'alignItems'     => [ 'flex-start', 'flex-end', 'center', 'stretch', 'baseline' ],
		'objectFit'      => [ 'cover', 'contain', 'fill', 'none', 'scale-down' ],
		'videoType'      => [ 'youtube', 'vimeo', 'self-hosted' ],
		'linkTarget'     => [ '_self', '_blank', '_parent', '_top' ],
		'order'          => [ 'ASC', 'DESC' ],
	];

	foreach ( $data as $key => $val ) {
		// Enum validation.
		if ( isset( $enums[ $key ] ) && is_string( $val ) && $val !== '' ) {
			if ( ! in_array( $val, $enums[ $key ], true ) ) {
				$warnings[] = "Property \"{$key}\" value \"{$val}\" in element \"{$eid}\" ({$type_short} data.{$section}) must be one of: " . implode( ', ', $enums[ $key ] ) . '.';
			}
		}

		// Color format check.
		if ( is_string( $val ) && $val !== '' && ( str_contains( $key, 'color' ) || str_contains( $key, 'Color' ) ) ) {
			if ( ! preg_match( '/^(#[0-9a-fA-F]{3,8}|rgb\(|rgba\(|hsl\(|hsla\(|[a-z]+$)/i', $val ) ) {
				$warnings[] = "Invalid color format for \"{$key}\" in element \"{$eid}\" ({$type_short} data.{$section}): \"{$val}\".";
			}
		}
	}
}

/**
 * Strip the EssentialElements\ namespace prefix from a type string.
 *
 * @param string $type  Namespaced type, e.g. 'EssentialElements\Heading'.
 * @return string       Short name, e.g. 'Heading'.
 */
function e2m_engine_breakdance_short_type( string $type ): string {
	if ( str_starts_with( $type, 'EssentialElements\\' ) ) {
		return substr( $type, strlen( 'EssentialElements\\' ) );
	}
	// Handle double-backslash as stored in some serialized formats.
	if ( str_starts_with( $type, 'EssentialElements\\\\' ) ) {
		return substr( $type, strlen( 'EssentialElements\\\\' ) );
	}
	return $type;
}
