<?php
/**
 * E2M Connect MCP – WPBakery Layout Validator
 *
 * Deep structural validation of a WPBakery layout — whether passed as a
 * E2M Connect tree array ({type, attributes, content, children}) or as a raw
 * WPBakery shortcode string.
 *
 * Checks:
 *   • Every node has a "type" field
 *   • Nesting rules (vc_row → vc_column, vc_accordion → vc_accordion_tab, etc.)
 *   • Top-level elements should be vc_row or vc_section
 *   • Width attribute on vc_column must be a valid fraction
 *   • Unknown shortcodes (non vc_* not in registry) trigger a warning
 *
 * Delegates to Respira_WPBakery_Validator when available (array format).
 *
 * Actions:
 *   validate_layout          — validate tree array or raw shortcode string
 *   validate_shortcode_string — parse a raw WPBakery shortcode string then validate
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/wpbakery-layout-validator', [
	'label'       => __( '[WPBakery] Layout Validator', 'e2mconnect' ),
	'description' => 'Validate a WPBakery layout tree or raw shortcode string: nesting rules, vc_column widths, unknown shortcodes, and missing required fields. Returns {valid, errors, warnings}.',
	'category'    => 'e2m-wpbakery',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'validate_layout', 'validate_shortcode_string' ],
				'description' => 'validate_layout — accepts tree array or raw shortcode string; validate_shortcode_string — parses raw shortcode string first.',
			],
			'content' => [
				'description' => 'Layout to validate. For validate_layout: array of {type, attributes, content, children} nodes OR a raw WPBakery shortcode string. For validate_shortcode_string: raw shortcode string.',
			],
			'shortcode_string' => [
				'type'        => 'string',
				'description' => 'Raw WPBakery shortcode string for validate_shortcode_string action.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'valid'    => [ 'type' => 'boolean' ],
			'errors'   => [ 'type' => 'array', 'description' => 'Blocking errors that must be fixed.' ],
			'warnings' => [ 'type' => 'array', 'description' => 'Non-blocking hints (nesting guidance, unknown shortcodes).' ],
		],
	],

	'execute_callback'    => 'e2m_engine_wpbakery_layout_validator',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'WPBakery: Layout Validator',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute wpbakery-layout-validator ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_wpbakery_layout_validator( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_wpbakery() ) {
		return new WP_Error( 'wpbakery_missing', __( 'WPBakery Page Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── validate_layout ────────────────────────────────────────────────────
		case 'validate_layout':
			$content = $input['content'] ?? null;
			if ( $content === null ) {
				return new WP_Error( 'missing_content', __( '"content" is required for validate_layout.', 'e2mconnect' ) );
			}

			// If content is a string, check if it's JSON or a raw shortcode string.
			if ( is_string( $content ) ) {
				$decoded = json_decode( $content, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
					$content = $decoded;
				} else {
					// Treat as raw WPBakery shortcode string — parse it first.
					$content = e2m_engine_wpbakery_parse_shortcode_string( $content );
				}
			}

			if ( is_array( $content ) && class_exists( 'Respira_WPBakery_Validator' ) ) {
				$validator = new Respira_WPBakery_Validator();
				$result    = $validator->validate_layout( $content );
				return array_merge( [ 'action' => 'validate_layout' ], (array) $result );
			}

			$result = e2m_engine_wpbakery_validate_layout_native( is_array( $content ) ? $content : [] );
			return array_merge( [ 'action' => 'validate_layout' ], $result );

		// ── validate_shortcode_string ─────────────────────────────────────────
		case 'validate_shortcode_string':
			$shortcode_string = $input['shortcode_string'] ?? ( is_string( $input['content'] ?? null ) ? $input['content'] : '' );
			if ( $shortcode_string === '' ) {
				return new WP_Error( 'missing_shortcode_string', __( '"shortcode_string" is required for validate_shortcode_string.', 'e2mconnect' ) );
			}

			$tree   = e2m_engine_wpbakery_parse_shortcode_string( $shortcode_string );
			$result = e2m_engine_wpbakery_validate_layout_native( $tree );

			return array_merge( [ 'action' => 'validate_shortcode_string', 'parsed_tree' => $tree ], $result );

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: validate_layout, validate_shortcode_string.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Native validator
// ──────────────────────────────────────────────────────────────────────────────

/** Valid vc_column / vc_column_inner width values. */
const E2M_WPBAKERY_VALID_WIDTHS = [ '1/1', '1/2', '1/3', '1/4', '2/3', '3/4' ];

/** Required child types per parent shortcode. Empty array means any child is allowed. */
const E2M_WPBAKERY_NESTING_RULES = [
	'vc_row'       => [ 'vc_column', 'vc_column_inner' ],
	'vc_row_inner' => [ 'vc_column_inner' ],
	'vc_accordion' => [ 'vc_accordion_tab' ],
	'vc_tabs'      => [ 'vc_tab' ],
];

/**
 * Validate a WPBakery layout tree natively.
 *
 * @param array<int,array<string,mixed>> $content Array of {type, attributes, content, children} nodes.
 * @return array{valid:bool, errors:string[], warnings:string[]}
 */
function e2m_engine_wpbakery_validate_layout_native( array $content ): array {
	$errors   = [];
	$warnings = [];

	if ( empty( $content ) ) {
		$warnings[] = 'Layout is empty — no shortcodes found.';
		return [ 'valid' => true, 'errors' => $errors, 'warnings' => $warnings ];
	}

	// Top-level check: each root node should be vc_row or vc_section.
	foreach ( $content as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$type = $node['type'] ?? '';
		if ( $type !== '' && $type !== 'vc_row' && $type !== 'vc_section' ) {
			$warnings[] = "Top-level element \"{$type}\" is not a vc_row or vc_section. WPBakery expects the outermost elements to be rows or sections.";
		}
	}

	// Recurse through the tree.
	e2m_engine_wpbakery_walk_validate( $content, $errors, $warnings, null );

	return [
		'valid'    => empty( $errors ),
		'errors'   => $errors,
		'warnings' => $warnings,
	];
}

/**
 * Recursively walk the tree and collect errors and warnings.
 *
 * @param array    $nodes
 * @param string[] $errors   (by reference)
 * @param string[] $warnings (by reference)
 * @param string|null $parent_type
 */
function e2m_engine_wpbakery_walk_validate( array $nodes, array &$errors, array &$warnings, ?string $parent_type ): void {
	$nesting = E2M_WPBAKERY_NESTING_RULES;

	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}

		// Required: type field.
		if ( ! isset( $node['type'] ) || $node['type'] === '' ) {
			$errors[] = 'A node is missing the required "type" field.';
			continue;
		}

		$type = (string) $node['type'];

		// Nesting check: validate this node against its parent's allowed children.
		if ( $parent_type !== null && isset( $nesting[ $parent_type ] ) ) {
			$allowed = $nesting[ $parent_type ];
			if ( ! in_array( $type, $allowed, true ) ) {
				$errors[] = "Nesting error: \"{$type}\" is not a valid child of \"{$parent_type}\". Expected: " . implode( ', ', $allowed ) . '.';
			}
		}

		// vc_column width validation.
		if ( $type === 'vc_column' || $type === 'vc_column_inner' ) {
			$width = (string) ( $node['attributes']['width'] ?? '' );
			if ( $width !== '' && ! in_array( $width, E2M_WPBAKERY_VALID_WIDTHS, true ) ) {
				$errors[] = "Invalid \"width\" attribute \"{$width}\" on {$type}. Must be one of: " . implode( ', ', E2M_WPBAKERY_VALID_WIDTHS ) . '.';
			}
		}

		// Warn on unknown shortcodes (not in vc_* namespace and not in registry).
		if ( ! str_starts_with( $type, 'vc_' ) ) {
			$in_registry = e2m_engine_wpbakery_find_shortcode( $type ) !== null;
			if ( ! $in_registry ) {
				$warnings[] = "Unknown shortcode \"{$type}\" — not in the vc_* namespace and not found in the shortcode registry. Ensure the element is registered before rendering.";
			}
		}

		// Recurse children.
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			e2m_engine_wpbakery_walk_validate( $node['children'], $errors, $warnings, $type );
		}
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Shortcode string parser
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Parse a raw WPBakery shortcode string into the E2M Connect tree format.
 *
 * Handles both self-closing shortcodes ([vc_spacer /]) and wrapping
 * shortcodes ([vc_row]...[/vc_row]). Parses recursively.
 *
 * @param string $content Raw WPBakery shortcode string from post_content.
 * @return array<int,array<string,mixed>>  Array of {type, attributes, content, children} nodes.
 */
function e2m_engine_wpbakery_parse_shortcode_string( string $content ): array {
	$nodes   = [];
	$content = trim( $content );

	if ( $content === '' ) {
		return $nodes;
	}

	// Pattern captures: 1=tag, 2=attrs, 3=inner content (null for self-closing).
	$pattern = '/\[([a-zA-Z0-9_]+)((?:\s[^\]]*?)?)\s*(?:\/)?\](?:(.*?)\[\/\1\])?/s';

	$offset = 0;
	$len    = strlen( $content );

	while ( $offset < $len ) {
		// Find the next opening shortcode tag.
		if ( ! preg_match( '/\[([a-zA-Z0-9_]+)((?:\s[^\]\/]*)?)\s*\/?]/s', $content, $open_match, PREG_OFFSET_CAPTURE, $offset ) ) {
			break;
		}

		$tag        = $open_match[1][0];
		$attrs_str  = trim( $open_match[2][0] );
		$open_start = (int) $open_match[0][1];
		$open_end   = $open_start + strlen( $open_match[0][0] );

		// Determine if self-closing.
		$is_self_closing = str_ends_with( rtrim( $open_match[0][0], ']' ), '/' );

		if ( $is_self_closing ) {
			$nodes[] = [
				'type'       => $tag,
				'attributes' => e2m_engine_wpbakery_parse_shortcode_attrs( $attrs_str ),
				'content'    => '',
				'children'   => [],
			];
			$offset = $open_end;
			continue;
		}

		// Find the matching closing tag, accounting for nesting.
		$close_pattern = '/\[\/' . preg_quote( $tag, '/' ) . '\]/';
		$inner_offset  = $open_end;
		$depth         = 1;
		$close_pos     = -1;

		// Scan for matching close by counting open/close pairs.
		$scan = $open_end;
		while ( $scan < $len ) {
			$next_open  = false;
			$next_close = false;

			if ( preg_match( '/\[' . preg_quote( $tag, '/' ) . '(?:\s[^\]]*?)?\s*\/?]/s', $content, $om, PREG_OFFSET_CAPTURE, $scan ) ) {
				$next_open = (int) $om[0][1];
			}
			if ( preg_match( $close_pattern, $content, $cm, PREG_OFFSET_CAPTURE, $scan ) ) {
				$next_close = (int) $cm[0][1];
			}

			if ( $next_close === false ) {
				// No closing tag found — treat as no inner content.
				$offset = $open_end;
				break 2;
			}

			if ( $next_open !== false && $next_open < $next_close ) {
				$depth++;
				$scan = $next_open + 1;
			} else {
				$depth--;
				if ( $depth === 0 ) {
					$close_pos  = $next_close;
					$close_end  = $close_pos + strlen( "[/{$tag}]" );
					break;
				}
				$scan = $next_close + 1;
			}
		}

		if ( $close_pos === -1 ) {
			$offset = $open_end;
			continue;
		}

		$inner_content = substr( $content, $open_end, $close_pos - $open_end );
		$attributes    = e2m_engine_wpbakery_parse_shortcode_attrs( $attrs_str );

		// Recurse into inner content to build children.
		$children = e2m_engine_wpbakery_parse_shortcode_string( $inner_content );

		// If no children parsed, inner_content is raw text content.
		$text_content = empty( $children ) ? trim( $inner_content ) : '';

		$nodes[] = [
			'type'       => $tag,
			'attributes' => $attributes,
			'content'    => $text_content,
			'children'   => $children,
		];

		$offset = $close_end ?? $open_end + 1;
	}

	return $nodes;
}

/**
 * Parse a shortcode attributes string into a key => value array.
 *
 * Handles: key="value", key='value', key=value (unquoted).
 *
 * @param string $attrs_string Raw attribute string from inside the shortcode tag.
 * @return array<string,string>
 */
function e2m_engine_wpbakery_parse_shortcode_attrs( string $attrs_string ): array {
	$attrs = [];
	if ( trim( $attrs_string ) === '' ) {
		return $attrs;
	}

	// Match key="value", key='value', or key=value patterns.
	$pattern = '/([a-zA-Z0-9_\-]+)\s*=\s*(?:"([^"]*?)"|\'([^\']*?)\'|([^\s\]]+))/';
	if ( preg_match_all( $pattern, $attrs_string, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			$key   = $m[1];
			$value = $m[2] !== '' ? $m[2] : ( $m[3] !== '' ? $m[3] : $m[4] );
			$attrs[ $key ] = $value;
		}
	}

	return $attrs;
}
