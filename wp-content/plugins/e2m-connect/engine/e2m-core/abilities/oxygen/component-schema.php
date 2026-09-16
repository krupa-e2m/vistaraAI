<?php
/**
 * E2M Connect MCP – Oxygen Component Schema
 *
 * Get the full settings schema for one or more Oxygen Builder components —
 * what properties they accept, valid values, responsive suffixes, and
 * which properties are required.
 *
 * This is the "AI intelligence" layer: before building a page the AI can
 * call get_schema to know exactly what settings are valid for each component
 * type, preventing trial-and-error against Oxygen's builder.
 *
 * Actions:
 *   get_schema  — detailed schema for one component type
 *   multi_schema — schemas for multiple component types at once
 *   common_props — list of CSS properties valid for all components
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/oxygen-component-schema', [
	'label'       => __( '[Oxygen] Component Schema', 'e2mconnect' ),
	'description' => 'Get the settings schema for Oxygen Builder components. Returns accepted properties, enum values, required fields, and responsive suffix rules. Use before building pages to avoid invalid settings.',
	'category'    => 'e2m-oxygen',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'get_schema', 'multi_schema', 'common_props' ],
				'description' => 'get_schema — single component; multi_schema — several at once; common_props — universal CSS properties.',
			],
			'type' => [
				'type'        => 'string',
				'description' => 'Component type slug for get_schema, e.g. "ct_headline".',
			],
			'types' => [
				'type'        => 'array',
				'description' => 'Array of component type slugs for multi_schema.',
				'items'       => [ 'type' => 'string' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'              => [ 'type' => 'string' ],
			'type'                => [ 'type' => 'string' ],
			'schema'              => [ 'type' => 'object' ],
			'schemas'             => [ 'type' => 'object' ],
			'common_properties'   => [ 'type' => 'object' ],
			'responsive_suffixes' => [ 'type' => 'array' ],
			'internal_properties' => [ 'type' => 'array' ],
		],
	],

	'execute_callback'    => 'e2m_engine_oxygen_component_schema',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Oxygen: Component Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute oxygen-component-schema ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_oxygen_component_schema( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_oxygen() ) {
		return new WP_Error( 'oxygen_missing', __( 'Oxygen Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── Get schema for one component ──────────────────────────────────────
		case 'get_schema':
			if ( empty( $input['type'] ) ) {
				return new WP_Error( 'missing_type', __( '"type" is required for get_schema.', 'e2mconnect' ) );
			}
			$type = sanitize_text_field( $input['type'] );

			if ( class_exists( 'Respira_Oxygen_Component_Schema' ) ) {
				$gen    = new Respira_Oxygen_Component_Schema();
				$schema = $gen->get_builder_schema( [ $type ] );
				return [
					'action' => 'get_schema',
					'type'   => $type,
					'schema' => $schema['available_components'][ $type ] ?? [],
					'responsive_suffixes' => [ '_tablet', '_phone_landscape', '_phone_portrait' ],
					'internal_properties' => [ 'ct_id', 'ct_parent', 'ct_depth', 'selector', 'original', 'activeselector', 'nicename', 'ct_content', 'ct_css', 'ct_js', 'classes' ],
				];
			}

			// Native fallback — build schema from built-in component data.
			$component = e2m_engine_oxygen_find_component_builtin( $type, e2m_engine_oxygen_builtin_components() );
			if ( ! $component ) {
				return new WP_Error( 'component_not_found', sprintf( __( 'No component found with type "%s".', 'e2mconnect' ), $type ) );
			}
			return [
				'action'              => 'get_schema',
				'type'                => $type,
				'schema'              => e2m_engine_oxygen_build_basic_schema( $component ),
				'responsive_suffixes' => [ '_tablet', '_phone_landscape', '_phone_portrait' ],
				'internal_properties' => [ 'ct_id', 'ct_parent', 'ct_depth', 'selector', 'original', 'activeselector', 'nicename', 'ct_content', 'ct_css', 'ct_js', 'classes' ],
				'note'                => 'Schema built from built-in catalogue. Install Respira for richer schema data.',
			];

		// ── Get schemas for multiple components ───────────────────────────────
		case 'multi_schema':
			$types = is_array( $input['types'] ?? null ) ? $input['types'] : [];
			if ( empty( $types ) ) {
				return new WP_Error( 'missing_types', __( '"types" array is required for multi_schema.', 'e2mconnect' ) );
			}

			$schemas = [];

			if ( class_exists( 'Respira_Oxygen_Component_Schema' ) ) {
				$gen    = new Respira_Oxygen_Component_Schema();
				$result = $gen->get_builder_schema( $types );
				$schemas = $result['available_components'] ?? [];
			} else {
				$builtins = e2m_engine_oxygen_builtin_components();
				foreach ( $types as $t ) {
					$t = sanitize_text_field( $t );
					$comp = e2m_engine_oxygen_find_component_builtin( $t, $builtins );
					$schemas[ $t ] = $comp ? e2m_engine_oxygen_build_basic_schema( $comp ) : [];
				}
			}

			return [
				'action'  => 'multi_schema',
				'schemas' => $schemas,
				'count'   => count( $schemas ),
			];

		// ── Common CSS properties ─────────────────────────────────────────────
		case 'common_props':
			return [
				'action'            => 'common_props',
				'common_properties' => e2m_engine_oxygen_common_props(),
				'responsive_suffixes' => [ '_tablet', '_phone_landscape', '_phone_portrait' ],
				'note'              => 'Append a responsive suffix to any property name to set it for that breakpoint, e.g. "font-size_tablet".',
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: get_schema, multi_schema, common_props.', 'e2mconnect' ) );
	}
}

/**
 * Build a basic schema array from built-in component data.
 *
 * @param array<string,mixed> $component
 * @return array<string,mixed>
 */
function e2m_engine_oxygen_build_basic_schema( array $component ): array {
	$props = [];
	foreach ( $component['properties'] ?? [] as $prop ) {
		$props[ $prop ] = [ 'type' => 'string', 'description' => $prop ];
	}
	return [
		'name'        => $component['name'] ?? '',
		'type'        => $component['type'] ?? '',
		'category'    => $component['category'] ?? '',
		'description' => $component['description'] ?? '',
		'properties'  => $props,
		'required'    => $component['required'] ?? [],
	];
}

/**
 * Common CSS properties valid for all Oxygen components.
 *
 * @return array<string,array>
 */
function e2m_engine_oxygen_common_props(): array {
	return [
		'width'             => [ 'type' => 'string', 'example' => '100%', 'description' => 'Element width.' ],
		'height'            => [ 'type' => 'string', 'example' => '400px', 'description' => 'Element height.' ],
		'max-width'         => [ 'type' => 'string', 'example' => '1200px' ],
		'min-height'        => [ 'type' => 'string', 'example' => '200px' ],
		'padding'           => [ 'type' => 'string', 'example' => '20px 30px' ],
		'margin'            => [ 'type' => 'string', 'example' => '0 auto' ],
		'background-color'  => [ 'type' => 'string', 'example' => '#ffffff' ],
		'background-image'  => [ 'type' => 'string', 'example' => 'url(...)' ],
		'background-size'   => [ 'type' => 'string', 'enum' => [ 'auto', 'cover', 'contain' ] ],
		'background-repeat' => [ 'type' => 'string', 'enum' => [ 'repeat', 'no-repeat', 'repeat-x', 'repeat-y' ] ],
		'color'             => [ 'type' => 'string', 'example' => '#333333' ],
		'font-size'         => [ 'type' => 'string', 'example' => '16px' ],
		'font-family'       => [ 'type' => 'string', 'example' => 'Inter, sans-serif' ],
		'font-weight'       => [ 'type' => 'string', 'example' => '600' ],
		'line-height'       => [ 'type' => 'string', 'example' => '1.6' ],
		'text-align'        => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right', 'justify' ] ],
		'text-transform'    => [ 'type' => 'string', 'enum' => [ 'none', 'uppercase', 'lowercase', 'capitalize' ] ],
		'display'           => [ 'type' => 'string', 'enum' => [ 'block', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'none' ] ],
		'flex-direction'    => [ 'type' => 'string', 'enum' => [ 'row', 'row-reverse', 'column', 'column-reverse' ] ],
		'justify-content'   => [ 'type' => 'string', 'enum' => [ 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly' ] ],
		'align-items'       => [ 'type' => 'string', 'enum' => [ 'flex-start', 'flex-end', 'center', 'baseline', 'stretch' ] ],
		'position'          => [ 'type' => 'string', 'enum' => [ 'static', 'relative', 'absolute', 'fixed', 'sticky' ] ],
		'border-radius'     => [ 'type' => 'string', 'example' => '8px' ],
		'border-style'      => [ 'type' => 'string', 'enum' => [ 'none', 'solid', 'dashed', 'dotted', 'double' ] ],
		'border-color'      => [ 'type' => 'string', 'example' => '#cccccc' ],
		'border-width'      => [ 'type' => 'string', 'example' => '1px' ],
		'box-shadow'        => [ 'type' => 'string', 'example' => '0 2px 8px rgba(0,0,0,0.15)' ],
		'opacity'           => [ 'type' => 'string', 'example' => '0.8' ],
		'overflow'          => [ 'type' => 'string', 'enum' => [ 'visible', 'hidden', 'scroll', 'auto' ] ],
		'z-index'           => [ 'type' => 'string', 'example' => '10' ],
		'classes'           => [ 'type' => 'string', 'description' => 'Space-separated Oxygen CSS class names to apply.' ],
		'ct_css'            => [ 'type' => 'string', 'description' => 'Custom CSS injected for this component (scoped).' ],
	];
}
