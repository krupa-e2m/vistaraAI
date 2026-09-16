<?php
/**
 * E2M Connect MCP – WPBakery Shortcode Schema
 *
 * Expose structured JSON-schema definitions for WPBakery shortcodes and
 * document the builder's storage and hierarchy conventions.
 *
 * Delegates to Respira_WPBakery_Shortcode_Schema when available; otherwise
 * derives schemas from the built-in shortcode registry.
 *
 * Actions:
 *   get_schema       — schema for a single shortcode
 *   multi_schema     — schemas for an array of shortcodes
 *   structure_notes  — builder storage, hierarchy, and encoding documentation
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/wpbakery-shortcode-schema', [
	'label'       => __( '[WPBakery] Shortcode Schema', 'e2mconnect' ),
	'description' => 'Return JSON-schema definitions for WPBakery shortcodes. Understand shortcode param types, required fields, and storage notes (base64 for vc_raw_html, fraction widths, meta keys).',
	'category'    => 'e2m-wpbakery',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'get_schema', 'multi_schema', 'structure_notes' ],
				'description' => 'get_schema — schema for one shortcode; multi_schema — schemas for many; structure_notes — builder documentation.',
			],
			'shortcode' => [
				'type'        => 'string',
				'description' => 'Shortcode tag for get_schema, e.g. "vc_row", "vc_column_text".',
			],
			'shortcodes' => [
				'type'        => 'array',
				'description' => 'Array of shortcode tags for multi_schema.',
				'items'       => [ 'type' => 'string' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'          => [ 'type' => 'string' ],
			'schema'          => [ 'type' => 'object' ],
			'schemas'         => [ 'type' => 'object' ],
			'structure_notes' => [ 'type' => 'object' ],
		],
	],

	'execute_callback'    => 'e2m_engine_wpbakery_shortcode_schema',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'WPBakery: Shortcode Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute wpbakery-shortcode-schema ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_wpbakery_shortcode_schema( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_wpbakery() ) {
		return new WP_Error( 'wpbakery_missing', __( 'WPBakery Page Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── get_schema ────────────────────────────────────────────────────────
		case 'get_schema':
			if ( empty( $input['shortcode'] ) ) {
				return new WP_Error( 'missing_shortcode', __( '"shortcode" is required for get_schema.', 'e2mconnect' ) );
			}
			$tag = sanitize_text_field( $input['shortcode'] );

			if ( class_exists( 'Respira_WPBakery_Shortcode_Schema' ) ) {
				$gen    = new Respira_WPBakery_Shortcode_Schema();
				$result = $gen->get_builder_schema( [ $tag ] );
				return [
					'action' => 'get_schema',
					'schema' => $result,
				];
			}

			$schema = e2m_engine_wpbakery_build_shortcode_schema( $tag );
			if ( empty( $schema ) ) {
				return new WP_Error( 'shortcode_not_found', sprintf( __( 'No shortcode found with tag "%s".', 'e2mconnect' ), $tag ) );
			}

			return [
				'action' => 'get_schema',
				'schema' => $schema,
			];

		// ── multi_schema ──────────────────────────────────────────────────────
		case 'multi_schema':
			if ( empty( $input['shortcodes'] ) || ! is_array( $input['shortcodes'] ) ) {
				return new WP_Error( 'missing_shortcodes', __( '"shortcodes" array is required for multi_schema.', 'e2mconnect' ) );
			}

			$tags = array_map( 'sanitize_text_field', $input['shortcodes'] );

			if ( class_exists( 'Respira_WPBakery_Shortcode_Schema' ) ) {
				$gen     = new Respira_WPBakery_Shortcode_Schema();
				$schemas = $gen->get_builder_schema( $tags );
				return [
					'action'  => 'multi_schema',
					'schemas' => $schemas,
				];
			}

			$schemas = [];
			foreach ( $tags as $tag ) {
				$schemas[ $tag ] = e2m_engine_wpbakery_build_shortcode_schema( $tag );
			}

			return [
				'action'  => 'multi_schema',
				'schemas' => $schemas,
			];

		// ── structure_notes ───────────────────────────────────────────────────
		case 'structure_notes':
			return [
				'action'          => 'structure_notes',
				'structure_notes' => [
					'storage'          => 'Shortcodes stored directly in post_content as standard WordPress shortcode syntax.',
					'hierarchy'        => 'vc_row → vc_column (or vc_column_inner inside vc_row_inner) → content elements. vc_section wraps vc_row elements.',
					'version_constant' => 'WPB_VC_VERSION — holds the installed WPBakery version string.',
					'meta_key'         => '_wpb_vc_js_status = "true" marks posts built with WPBakery. Used for list_pages detection.',
					'raw_html'         => 'vc_raw_html shortcode content is base64-encoded to prevent conflicts with the shortcode parser. Encode content with base64_encode() before inserting; decode with base64_decode() when reading.',
					'raw_js'           => 'vc_raw_js content is also base64-encoded, same pattern as vc_raw_html.',
					'column_widths'    => 'vc_column and vc_column_inner accept width values: "1/1" (100%), "1/2" (50%), "1/3" (33.33%), "2/3" (66.66%), "1/4" (25%), "3/4" (75%).',
					'shortcode_tree_format' => [
						'description' => 'E2M Connect uses an intermediate tree format: {type, attributes:{...}, content:"...", children:[...]}. Use e2m/wpbakery-manage-pages write action to persist.',
						'type'        => 'string — the shortcode tag, e.g. "vc_row", "vc_column_text".',
						'attributes'  => 'object — key/value pairs matching the shortcode param_names.',
						'content'     => 'string — inner text content (for vc_column_text, vc_raw_html, etc.).',
						'children'    => 'array — nested shortcode nodes in the same format.',
					],
					'tagdiv_compat'    => 'TagDiv Composer (Newspaper theme) bundles a modified WPBakery. Detected via TD_COMPOSER constant or td_api_module class.',
					'api_class'        => 'WPBMap::getAllShortCodes() returns all registered shortcodes as an associative array keyed by tag name.',
				],
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: get_schema, multi_schema, structure_notes.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Native schema builder
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Build a JSON-schema-style descriptor for a single WPBakery shortcode.
 *
 * @param string $shortcode Shortcode tag, e.g. "vc_row".
 * @return array<string,mixed>
 */
function e2m_engine_wpbakery_build_shortcode_schema( string $shortcode ): array {
	$info = e2m_engine_wpbakery_find_shortcode( $shortcode );
	if ( ! $info ) {
		return [];
	}

	$params     = $info['params'] ?? [];
	$properties = [];
	$required   = [];

	foreach ( $params as $param ) {
		$pname = $param['param_name'] ?? '';
		if ( $pname === '' ) {
			continue;
		}
		$properties[ $pname ] = e2m_engine_wpbakery_param_schema( $pname, $param['type'] ?? 'textfield' );
		if ( ! empty( $param['required'] ) ) {
			$required[] = $pname;
		}
	}

	return [
		'shortcode'   => $shortcode,
		'title'       => $info['title'] ?? $shortcode,
		'description' => $info['description'] ?? '',
		'category'    => $info['category'] ?? '',
		'schema'      => [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => true,
		],
	];
}

/**
 * Map a WPBakery param type to a JSON-schema descriptor.
 *
 * @param string $param_name
 * @param string $type WPBakery param type string.
 * @return array<string,mixed>
 */
function e2m_engine_wpbakery_param_schema( string $param_name, string $type ): array {
	switch ( $type ) {
		case 'textfield':
			return [ 'type' => 'string', 'description' => "Text field: {$param_name}." ];

		case 'textarea':
			return [ 'type' => 'string', 'description' => "Textarea: {$param_name}." ];

		case 'textarea_html':
			return [ 'type' => 'string', 'description' => "HTML textarea: {$param_name}. Accepts HTML markup." ];

		case 'attach_image':
			return [ 'type' => [ 'integer', 'string' ], 'description' => "Attachment ID (integer) for: {$param_name}." ];

		case 'attach_images':
			return [ 'type' => 'string', 'description' => "Comma-separated attachment IDs for: {$param_name}." ];

		case 'checkbox':
			return [ 'type' => 'string', 'enum' => [ '', 'true', 'false', '1', '0' ], 'description' => "Checkbox: {$param_name}. WPBakery stores as string 'true'/'false'." ];

		case 'dropdown':
			return [ 'type' => 'string', 'description' => "Dropdown select: {$param_name}. See shortcode documentation for allowed values." ];

		case 'colorpicker':
			return [ 'type' => 'string', 'description' => "Hex or RGBA color for: {$param_name}, e.g. \"#0066cc\" or \"rgba(0,102,204,1)\"." ];

		case 'link':
			return [ 'type' => 'string', 'description' => "URL string or WPBakery link JSON for: {$param_name}, e.g. \"url:https://example.com|title:Click me|target:_blank\"." ];

		case 'el_id':
			return [ 'type' => 'string', 'description' => "Element HTML ID for: {$param_name}. Must be unique on the page." ];

		case 'el_class':
			return [ 'type' => 'string', 'description' => "Extra HTML CSS class(es) for: {$param_name}." ];

		case 'css':
			return [ 'type' => 'string', 'description' => "WPBakery design options CSS string for: {$param_name}. Serialised by WPBakery's CSS builder." ];

		case 'animation_style':
			return [ 'type' => 'string', 'description' => "CSS entrance animation for: {$param_name}, e.g. \"fadeIn\", \"none\"." ];

		case 'font_container':
			return [ 'type' => 'string', 'description' => "WPBakery font container string for: {$param_name}, e.g. \"tag:h2|font_size:36|color:%23333333|line_height:1.4\"." ];

		case 'google_fonts':
			return [ 'type' => 'string', 'description' => "Google Fonts selection string for: {$param_name}, e.g. \"font_family:Roboto%3A100%2C300|font_style:300 light regular\"." ];

		case 'autocomplete':
			return [ 'type' => 'string', 'description' => "Autocomplete field: {$param_name}." ];

		case 'number':
			return [ 'type' => [ 'integer', 'number', 'string' ], 'description' => "Numeric value for: {$param_name}." ];

		default:
			return [ 'type' => 'string', 'description' => "Parameter ({$type}): {$param_name}." ];
	}
}
