<?php
/**
 * E2M Connect MCP – Beaver Builder Patterns
 *
 * Ready-to-use Beaver Builder layout patterns + row/module builder helpers.
 *
 * Actions:
 *   list          — list all available patterns (with optional category filter)
 *   get           — get a single pattern's full node structure
 *   categories    — list pattern categories
 *   create_row    — helper to build a complete BB row → column-group → column → module structure
 *   create_module — helper to create a single BB module node
 *
 * Built-in patterns:
 *   hero-section, three-column-features, call-to-action, testimonial,
 *   pricing-table, image-with-text
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/beaver-patterns', [
	'label'       => __( '[Beaver] Patterns', 'e2mconnect' ),
	'description' => 'Ready-to-use Beaver Builder layout patterns: hero, features, CTA, testimonial, pricing, image+text. List, get, or create individual row/module node structures.',
	'category'    => 'e2m-beaver',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories', 'create_row', 'create_module' ],
				'description' => 'list — all patterns; get — single pattern; categories — pattern categories; create_row — build a row structure; create_module — build a module node.',
			],
			// list
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by pattern category.',
			],
			// get
			'pattern_id' => [
				'type'        => 'string',
				'description' => 'Pattern slug for get, e.g. "hero-section".',
			],
			// create_row
			'settings' => [
				'type'                 => 'object',
				'description'          => 'Row settings for create_row.',
				'additionalProperties' => true,
			],
			'columns' => [
				'type'        => 'array',
				'description' => 'Column definitions for create_row. Each column may have optional "settings" and "modules" arrays.',
				'items'       => [ 'type' => 'object' ],
			],
			// create_module
			'type' => [
				'type'        => 'string',
				'description' => 'Module type slug for create_module, e.g. "heading", "html".',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'patterns'   => [ 'type' => 'array' ],
			'pattern'    => [ 'type' => 'object' ],
			'categories' => [ 'type' => 'array' ],
			'nodes'      => [ 'type' => 'object', 'description' => 'BB flat node map returned by create_row.' ],
			'node'       => [ 'type' => 'object', 'description' => 'Single module node returned by create_module.' ],
			'total'      => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_beaver_patterns',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Beaver: Patterns',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute beaver-patterns ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_beaver_patterns( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_beaver() ) {
		return new WP_Error( 'beaver_missing', __( 'Beaver Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	// Get patterns from Respira if available, else use built-in.
	$all_patterns = function_exists( 'respira_get_beaver_patterns' )
		? respira_get_beaver_patterns()
		: e2m_engine_beaver_builtin_patterns();

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$cat_filter = sanitize_key( $input['category'] ?? '' );
			$list       = [];
			foreach ( $all_patterns as $id => $pattern ) {
				if ( $cat_filter && ( $pattern['category'] ?? '' ) !== $cat_filter ) {
					continue;
				}
				$list[] = [
					'id'          => $id,
					'title'       => $pattern['title'] ?? $id,
					'description' => $pattern['description'] ?? '',
					'category'    => $pattern['category'] ?? 'general',
				];
			}
			return [
				'action'   => 'list',
				'patterns' => $list,
				'total'    => count( $list ),
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['pattern_id'] ) ) {
				return new WP_Error( 'missing_pattern_id', __( '"pattern_id" is required for get.', 'e2mconnect' ) );
			}
			$pid = sanitize_key( $input['pattern_id'] );
			if ( ! isset( $all_patterns[ $pid ] ) ) {
				return new WP_Error(
					'pattern_not_found',
					sprintf( __( 'Pattern "%s" not found.', 'e2mconnect' ), $pid )
				);
			}
			return [
				'action'  => 'get',
				'pattern' => array_merge( [ 'id' => $pid ], $all_patterns[ $pid ] ),
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_patterns as $pattern ) {
				$cat          = $pattern['category'] ?? 'general';
				$cats[ $cat ] = ( $cats[ $cat ] ?? 0 ) + 1;
			}
			$result = [];
			foreach ( $cats as $name => $count ) {
				$result[] = [ 'category' => $name, 'pattern_count' => $count ];
			}
			return [
				'action'     => 'categories',
				'categories' => $result,
				'total'      => count( $result ),
			];

		// ── Create row ────────────────────────────────────────────────────────
		case 'create_row':
			$row_settings = is_array( $input['settings'] ?? null ) ? $input['settings'] : [];
			$columns      = is_array( $input['columns'] ?? null ) ? $input['columns'] : [ [] ];
			$nodes        = e2m_engine_beaver_make_row( $row_settings, $columns );
			return [
				'action' => 'create_row',
				'nodes'  => $nodes,
			];

		// ── Create module ─────────────────────────────────────────────────────
		case 'create_module':
			if ( empty( $input['type'] ) ) {
				return new WP_Error( 'missing_type', __( '"type" (module type slug) is required for create_module.', 'e2mconnect' ) );
			}
			$mod_type     = sanitize_text_field( $input['type'] );
			$mod_settings = is_array( $input['settings'] ?? null ) ? $input['settings'] : [];
			$node         = e2m_engine_beaver_make_module_node( $mod_type, $mod_settings );
			return [
				'action' => 'create_module',
				'node'   => $node,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, categories, create_row, create_module.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Row / module factory helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Generate a proper BB flat node map for a complete row → column-group → column → module hierarchy.
 *
 * @param array<string,mixed>   $settings  Row-level settings.
 * @param array<int,array>      $columns   Column definitions. Each may have 'settings' and 'modules' arrays.
 * @return array<string,array>             Flat node map (arrays, not stdClass — call e2m_engine_beaver_arrays_to_nodes() before saving).
 */
function e2m_engine_beaver_make_row( array $settings, array $columns ): array {
	$nodes = [];

	$row_id    = 'r' . substr( str_replace( '.', '', uniqid( '', true ) ), -12 );
	$colgrp_id = 'g' . substr( str_replace( '.', '', uniqid( '', true ) ), -12 );

	// Row node.
	$nodes[ $row_id ] = [
		'node'     => $row_id,
		'type'     => 'row',
		'parent'   => null,
		'position' => 0,
		'settings' => $settings,
	];

	// Column-group node.
	$nodes[ $colgrp_id ] = [
		'node'     => $colgrp_id,
		'type'     => 'column-group',
		'parent'   => $row_id,
		'position' => 0,
		'settings' => [],
	];

	// Column nodes + their modules.
	foreach ( $columns as $col_index => $col_def ) {
		$col_id       = 'c' . substr( str_replace( '.', '', uniqid( '', true ) ), -12 );
		$col_settings = is_array( $col_def['settings'] ?? null ) ? $col_def['settings'] : [ 'size' => '100' ];

		$nodes[ $col_id ] = [
			'node'     => $col_id,
			'type'     => 'column',
			'parent'   => $colgrp_id,
			'position' => $col_index,
			'settings' => $col_settings,
		];

		// Module nodes for this column.
		$col_modules = is_array( $col_def['modules'] ?? null ) ? $col_def['modules'] : [];
		foreach ( $col_modules as $mod_index => $mod_def ) {
			$mod_type     = sanitize_text_field( $mod_def['type'] ?? 'html' );
			$mod_settings = is_array( $mod_def['settings'] ?? null ) ? $mod_def['settings'] : [];
			$mod_settings['type'] = $mod_type;

			$mod_id           = 'm' . substr( str_replace( '.', '', uniqid( '', true ) ), -12 );
			$nodes[ $mod_id ] = [
				'node'     => $mod_id,
				'type'     => 'module',
				'parent'   => $col_id,
				'position' => $mod_index,
				'settings' => $mod_settings,
			];
		}
	}

	return $nodes;
}

/**
 * Create a single BB module node structure.
 *
 * @param string              $type      Module type slug (e.g. 'heading', 'html').
 * @param array<string,mixed> $settings  Module settings. 'type' is injected automatically.
 * @return array<string,mixed>           Single module node array.
 */
function e2m_engine_beaver_make_module_node( string $type, array $settings ): array {
	$settings['type'] = $type;
	$mod_id           = 'm' . substr( str_replace( '.', '', uniqid( '', true ) ), -12 );

	return [
		'node'     => $mod_id,
		'type'     => 'module',
		'parent'   => null,
		'position' => 0,
		'settings' => $settings,
	];
}

// ──────────────────────────────────────────────────────────────────────────────
// Built-in pattern catalogue
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get the built-in Beaver Builder layout pattern catalogue.
 *
 * Uses static node IDs in structure arrays so patterns are deterministic
 * and can be diffed/compared reliably.
 *
 * @return array<string,array<string,mixed>>
 */
function e2m_engine_beaver_builtin_patterns(): array {
	return [

		'hero-section' => [
			'title'       => 'Hero Section',
			'description' => 'Full-width hero row with heading, subtitle rich-text, and CTA button.',
			'category'    => 'headers',
			'structure'   => [
				// BB flat node map — Row → Column-Group → Column → Modules.
				'row001hero' => [
					'node'     => 'row001hero',
					'type'     => 'row',
					'parent'   => null,
					'position' => 0,
					'settings' => [
						'full_width'       => 'full',
						'bg_type'          => 'color',
						'bg_color'         => '0066cc',
						'padding_top'      => 80,
						'padding_bottom'   => 80,
					],
				],
				'grp001hero' => [
					'node'     => 'grp001hero',
					'type'     => 'column-group',
					'parent'   => 'row001hero',
					'position' => 0,
					'settings' => [],
				],
				'col001hero' => [
					'node'     => 'col001hero',
					'type'     => 'column',
					'parent'   => 'grp001hero',
					'position' => 0,
					'settings' => [ 'size' => '100' ],
				],
				'mod001hero' => [
					'node'     => 'mod001hero',
					'type'     => 'module',
					'parent'   => 'col001hero',
					'position' => 0,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Welcome to Our Amazing Service',
						'tag'     => 'h1',
						'align'   => 'center',
						'color'   => 'ffffff',
					],
				],
				'mod002hero' => [
					'node'     => 'mod002hero',
					'type'     => 'module',
					'parent'   => 'col001hero',
					'position' => 1,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p>Your compelling tagline goes here</p>',
						'align'      => 'center',
						'text_color' => 'ffffff',
					],
				],
				'mod003hero' => [
					'node'     => 'mod003hero',
					'type'     => 'module',
					'parent'   => 'col001hero',
					'position' => 2,
					'settings' => [
						'type'       => 'button',
						'text'       => 'Get Started',
						'button_url' => '#',
						'align'      => 'center',
						'bg_color'   => 'ffffff',
						'text_color' => '0066cc',
					],
				],
			],
		],

		'three-column-features' => [
			'title'       => 'Three Column Features',
			'description' => 'Three-column row with icon, heading, and text in each column.',
			'category'    => 'content',
			'structure'   => [
				'row001feat' => [
					'node'     => 'row001feat',
					'type'     => 'row',
					'parent'   => null,
					'position' => 0,
					'settings' => [
						'padding_top'    => 60,
						'padding_bottom' => 60,
					],
				],
				'grp001feat' => [
					'node'     => 'grp001feat',
					'type'     => 'column-group',
					'parent'   => 'row001feat',
					'position' => 0,
					'settings' => [],
				],
				'col001feat' => [
					'node'     => 'col001feat',
					'type'     => 'column',
					'parent'   => 'grp001feat',
					'position' => 0,
					'settings' => [ 'size' => '33.33' ],
				],
				'mod001feat' => [
					'node'     => 'mod001feat',
					'type'     => 'module',
					'parent'   => 'col001feat',
					'position' => 0,
					'settings' => [
						'type'  => 'icon',
						'icon'  => 'fas fa-star',
						'color' => '0066cc',
						'size'  => 48,
						'align' => 'center',
					],
				],
				'mod002feat' => [
					'node'     => 'mod002feat',
					'type'     => 'module',
					'parent'   => 'col001feat',
					'position' => 1,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Feature One',
						'tag'     => 'h3',
						'align'   => 'center',
					],
				],
				'mod003feat' => [
					'node'     => 'mod003feat',
					'type'     => 'module',
					'parent'   => 'col001feat',
					'position' => 2,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p>Brief description of this feature.</p>',
						'align'      => 'center',
						'text_color' => '666666',
					],
				],
				'col002feat' => [
					'node'     => 'col002feat',
					'type'     => 'column',
					'parent'   => 'grp001feat',
					'position' => 1,
					'settings' => [ 'size' => '33.33' ],
				],
				'mod004feat' => [
					'node'     => 'mod004feat',
					'type'     => 'module',
					'parent'   => 'col002feat',
					'position' => 0,
					'settings' => [
						'type'  => 'icon',
						'icon'  => 'fas fa-bolt',
						'color' => '0066cc',
						'size'  => 48,
						'align' => 'center',
					],
				],
				'mod005feat' => [
					'node'     => 'mod005feat',
					'type'     => 'module',
					'parent'   => 'col002feat',
					'position' => 1,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Feature Two',
						'tag'     => 'h3',
						'align'   => 'center',
					],
				],
				'mod006feat' => [
					'node'     => 'mod006feat',
					'type'     => 'module',
					'parent'   => 'col002feat',
					'position' => 2,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p>Brief description of this feature.</p>',
						'align'      => 'center',
						'text_color' => '666666',
					],
				],
				'col003feat' => [
					'node'     => 'col003feat',
					'type'     => 'column',
					'parent'   => 'grp001feat',
					'position' => 2,
					'settings' => [ 'size' => '33.34' ],
				],
				'mod007feat' => [
					'node'     => 'mod007feat',
					'type'     => 'module',
					'parent'   => 'col003feat',
					'position' => 0,
					'settings' => [
						'type'  => 'icon',
						'icon'  => 'fas fa-heart',
						'color' => '0066cc',
						'size'  => 48,
						'align' => 'center',
					],
				],
				'mod008feat' => [
					'node'     => 'mod008feat',
					'type'     => 'module',
					'parent'   => 'col003feat',
					'position' => 1,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Feature Three',
						'tag'     => 'h3',
						'align'   => 'center',
					],
				],
				'mod009feat' => [
					'node'     => 'mod009feat',
					'type'     => 'module',
					'parent'   => 'col003feat',
					'position' => 2,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p>Brief description of this feature.</p>',
						'align'      => 'center',
						'text_color' => '666666',
					],
				],
			],
		],

		'call-to-action' => [
			'title'       => 'Call to Action',
			'description' => 'Centered CTA row with heading, text, and button.',
			'category'    => 'cta',
			'structure'   => [
				'row001cta' => [
					'node'     => 'row001cta',
					'type'     => 'row',
					'parent'   => null,
					'position' => 0,
					'settings' => [
						'bg_type'        => 'color',
						'bg_color'       => '1a1a2e',
						'padding_top'    => 80,
						'padding_bottom' => 80,
					],
				],
				'grp001cta' => [
					'node'     => 'grp001cta',
					'type'     => 'column-group',
					'parent'   => 'row001cta',
					'position' => 0,
					'settings' => [],
				],
				'col001cta' => [
					'node'     => 'col001cta',
					'type'     => 'column',
					'parent'   => 'grp001cta',
					'position' => 0,
					'settings' => [ 'size' => '100' ],
				],
				'mod001cta' => [
					'node'     => 'mod001cta',
					'type'     => 'module',
					'parent'   => 'col001cta',
					'position' => 0,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Ready to Get Started?',
						'tag'     => 'h2',
						'align'   => 'center',
						'color'   => 'ffffff',
					],
				],
				'mod002cta' => [
					'node'     => 'mod002cta',
					'type'     => 'module',
					'parent'   => 'col001cta',
					'position' => 1,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p>Join thousands of satisfied customers today.</p>',
						'align'      => 'center',
						'text_color' => 'cccccc',
					],
				],
				'mod003cta' => [
					'node'     => 'mod003cta',
					'type'     => 'module',
					'parent'   => 'col001cta',
					'position' => 2,
					'settings' => [
						'type'       => 'button',
						'text'       => 'Start Free Trial',
						'button_url' => '#',
						'align'      => 'center',
						'bg_color'   => 'e94560',
						'text_color' => 'ffffff',
					],
				],
			],
		],

		'testimonial' => [
			'title'       => 'Testimonial',
			'description' => 'Single testimonial with quote, author name, and company.',
			'category'    => 'testimonials',
			'structure'   => [
				'row001test' => [
					'node'     => 'row001test',
					'type'     => 'row',
					'parent'   => null,
					'position' => 0,
					'settings' => [
						'bg_type'        => 'color',
						'bg_color'       => 'f8f9fa',
						'padding_top'    => 60,
						'padding_bottom' => 60,
					],
				],
				'grp001test' => [
					'node'     => 'grp001test',
					'type'     => 'column-group',
					'parent'   => 'row001test',
					'position' => 0,
					'settings' => [],
				],
				'col001test' => [
					'node'     => 'col001test',
					'type'     => 'column',
					'parent'   => 'grp001test',
					'position' => 0,
					'settings' => [ 'size' => '100' ],
				],
				'mod001test' => [
					'node'     => 'mod001test',
					'type'     => 'module',
					'parent'   => 'col001test',
					'position' => 0,
					'settings' => [
						'type'  => 'icon',
						'icon'  => 'fas fa-quote-left',
						'color' => '0066cc',
						'size'  => 36,
						'align' => 'center',
					],
				],
				'mod002test' => [
					'node'     => 'mod002test',
					'type'     => 'module',
					'parent'   => 'col001test',
					'position' => 1,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p style="text-align:center;font-size:20px;">"This product completely transformed how we work. The results exceeded all our expectations."</p>',
						'text_color' => '333333',
					],
				],
				'mod003test' => [
					'node'     => 'mod003test',
					'type'     => 'module',
					'parent'   => 'col001test',
					'position' => 2,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Jane Smith',
						'tag'     => 'h4',
						'align'   => 'center',
						'color'   => '333333',
					],
				],
				'mod004test' => [
					'node'     => 'mod004test',
					'type'     => 'module',
					'parent'   => 'col001test',
					'position' => 3,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p style="text-align:center;">CEO, Acme Corp</p>',
						'text_color' => '999999',
					],
				],
			],
		],

		'pricing-table' => [
			'title'       => 'Pricing Table',
			'description' => 'Three-column pricing comparison built with native BB Pricing Table module.',
			'category'    => 'pricing',
			'note'        => 'Uses the BB Pricing Table module (type: pricing-table). Set the columns setting with plan definitions including title, price, and features.',
			'structure'   => [
				'row001price' => [
					'node'     => 'row001price',
					'type'     => 'row',
					'parent'   => null,
					'position' => 0,
					'settings' => [
						'padding_top'    => 60,
						'padding_bottom' => 60,
					],
				],
				'grp001price' => [
					'node'     => 'grp001price',
					'type'     => 'column-group',
					'parent'   => 'row001price',
					'position' => 0,
					'settings' => [],
				],
				'col001price' => [
					'node'     => 'col001price',
					'type'     => 'column',
					'parent'   => 'grp001price',
					'position' => 0,
					'settings' => [ 'size' => '100' ],
				],
				'mod001price' => [
					'node'     => 'mod001price',
					'type'     => 'module',
					'parent'   => 'col001price',
					'position' => 0,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Choose Your Plan',
						'tag'     => 'h2',
						'align'   => 'center',
					],
				],
				'mod002price' => [
					'node'     => 'mod002price',
					'type'     => 'module',
					'parent'   => 'col001price',
					'position' => 1,
					'settings' => [
						'type'    => 'pricing-table',
						'layout'  => 'column',
						'columns' => [
							[
								'title'        => 'Basic',
								'price'        => '$9',
								'duration'     => '/mo',
								'features'     => "5 Projects\n10GB Storage\nEmail Support",
								'cta_text'     => 'Get Started',
								'cta_url'      => '#',
							],
							[
								'title'        => 'Professional',
								'price'        => '$29',
								'duration'     => '/mo',
								'features'     => "25 Projects\n100GB Storage\nPriority Support",
								'cta_text'     => 'Get Started',
								'cta_url'      => '#',
								'featured'     => 'yes',
							],
							[
								'title'        => 'Enterprise',
								'price'        => '$99',
								'duration'     => '/mo',
								'features'     => "Unlimited Projects\n1TB Storage\n24/7 Support",
								'cta_text'     => 'Contact Sales',
								'cta_url'      => '#',
							],
						],
					],
				],
			],
		],

		'image-with-text' => [
			'title'       => 'Image with Text',
			'description' => 'Two-column row with photo on the left and heading, text, button on the right.',
			'category'    => 'content',
			'structure'   => [
				'row001img' => [
					'node'     => 'row001img',
					'type'     => 'row',
					'parent'   => null,
					'position' => 0,
					'settings' => [
						'padding_top'    => 60,
						'padding_bottom' => 60,
					],
				],
				'grp001img' => [
					'node'     => 'grp001img',
					'type'     => 'column-group',
					'parent'   => 'row001img',
					'position' => 0,
					'settings' => [],
				],
				'col001img' => [
					'node'     => 'col001img',
					'type'     => 'column',
					'parent'   => 'grp001img',
					'position' => 0,
					'settings' => [ 'size' => '50' ],
				],
				'mod001img' => [
					'node'     => 'mod001img',
					'type'     => 'module',
					'parent'   => 'col001img',
					'position' => 0,
					'settings' => [
						'type'      => 'photo',
						'photo_src' => 'https://via.placeholder.com/600x400',
						'align'     => 'center',
					],
				],
				'col002img' => [
					'node'     => 'col002img',
					'type'     => 'column',
					'parent'   => 'grp001img',
					'position' => 1,
					'settings' => [ 'size' => '50' ],
				],
				'mod002img' => [
					'node'     => 'mod002img',
					'type'     => 'module',
					'parent'   => 'col002img',
					'position' => 0,
					'settings' => [
						'type'    => 'heading',
						'heading' => 'Why Choose Us',
						'tag'     => 'h2',
					],
				],
				'mod003img' => [
					'node'     => 'mod003img',
					'type'     => 'module',
					'parent'   => 'col002img',
					'position' => 1,
					'settings' => [
						'type'       => 'rich-text',
						'text'       => '<p>Detailed description of why your product stands out.</p>',
						'text_color' => '555555',
					],
				],
				'mod004img' => [
					'node'     => 'mod004img',
					'type'     => 'module',
					'parent'   => 'col002img',
					'position' => 2,
					'settings' => [
						'type'       => 'button',
						'text'       => 'Learn More',
						'button_url' => '#',
						'bg_color'   => '0066cc',
						'text_color' => 'ffffff',
					],
				],
			],
		],

	];
}
