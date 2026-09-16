<?php
/**
 * E2M Connect MCP – Oxygen Settings Validator
 *
 * Validate component settings before writing them to an Oxygen page.
 * Returns errors (blocking) and warnings (non-blocking) so the AI can
 * self-correct before a bad build.
 *
 * Actions:
 *   validate       — validate settings for a single component type
 *   validate_tree  — validate all components in a full component tree
 *
 * Validation checks:
 *   - Required properties present
 *   - Unknown property names (warns, does not block)
 *   - Enum value violations (warns for known enums)
 *   - Internal-only properties passed as user input
 *   - Responsive suffix correctness
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/oxygen-settings-validator', [
	'label'       => __( '[Oxygen] Settings Validator', 'e2mconnect' ),
	'description' => 'Validate Oxygen Builder component settings before writing. Returns errors (blocking) and warnings (non-blocking). Use to self-correct settings before building pages.',
	'category'    => 'e2m-oxygen',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'validate', 'validate_tree' ],
				'description' => 'validate — single component; validate_tree — full component tree.',
			],
			'type' => [
				'type'        => 'string',
				'description' => 'Component type slug for validate, e.g. "ct_headline".',
			],
			'settings' => [
				'type'                 => 'object',
				'description'          => 'Settings key→value map to validate for a single component.',
				'additionalProperties' => true,
			],
			'components' => [
				'type'        => 'array',
				'description' => 'Array of {type, settings} objects for validate_tree.',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'type'     => [ 'type' => 'string' ],
						'settings' => [ 'type' => 'object', 'additionalProperties' => true ],
					],
				],
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
			'errors'   => [ 'type' => 'array' ],
			'warnings' => [ 'type' => 'array' ],
			'results'  => [ 'type' => 'array', 'description' => 'Per-component results for validate_tree.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_oxygen_settings_validator',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Oxygen: Settings Validator',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute oxygen-settings-validator ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_oxygen_settings_validator( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_oxygen() ) {
		return new WP_Error( 'oxygen_missing', __( 'Oxygen Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── Validate single component ──────────────────────────────────────────
		case 'validate':
			if ( empty( $input['type'] ) ) {
				return new WP_Error( 'missing_type', __( '"type" is required for validate.', 'e2mconnect' ) );
			}
			if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) ) {
				return new WP_Error( 'missing_settings', __( '"settings" object is required for validate.', 'e2mconnect' ) );
			}

			$type     = sanitize_text_field( $input['type'] );
			$settings = $input['settings'];

			$result = e2m_engine_oxygen_validate_component( $type, $settings );

			return array_merge( [ 'action' => 'validate', 'type' => $type ], $result );

		// ── Validate full component tree ──────────────────────────────────────
		case 'validate_tree':
			$components = is_array( $input['components'] ?? null ) ? $input['components'] : [];
			if ( empty( $components ) ) {
				return new WP_Error( 'missing_components', __( '"components" array is required for validate_tree.', 'e2mconnect' ) );
			}

			$results       = [];
			$total_errors   = 0;
			$total_warnings = 0;

			foreach ( $components as $idx => $comp ) {
				if ( ! is_array( $comp ) || empty( $comp['type'] ) ) {
					$results[] = [ 'index' => $idx, 'type' => '(unknown)', 'valid' => false, 'errors' => [ 'Missing "type" field.' ], 'warnings' => [] ];
					$total_errors++;
					continue;
				}
				$type     = sanitize_text_field( $comp['type'] );
				$settings = is_array( $comp['settings'] ?? null ) ? $comp['settings'] : [];
				$r        = e2m_engine_oxygen_validate_component( $type, $settings );
				$results[] = array_merge( [ 'index' => $idx, 'type' => $type ], $r );
				$total_errors   += count( $r['errors'] );
				$total_warnings += count( $r['warnings'] );
			}

			return [
				'action'         => 'validate_tree',
				'valid'          => $total_errors === 0,
				'total_errors'   => $total_errors,
				'total_warnings' => $total_warnings,
				'results'        => $results,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: validate, validate_tree.', 'e2mconnect' ) );
	}
}

/**
 * Validate a single component's settings.
 *
 * Delegates to Respira_Oxygen_Settings_Validator if available, otherwise
 * runs the native built-in validation.
 *
 * @param string              $type
 * @param array<string,mixed> $settings
 * @return array{valid:bool, errors:string[], warnings:string[]}
 */
function e2m_engine_oxygen_validate_component( string $type, array $settings ): array {
	if ( class_exists( 'Respira_Oxygen_Settings_Validator' ) ) {
		return (array) Respira_Oxygen_Settings_Validator::validate( $type, $settings );
	}
	return e2m_engine_oxygen_validate_native( $type, $settings );
}

/**
 * Native validation — checks required props, known props, and enum values.
 *
 * @param string              $type
 * @param array<string,mixed> $settings
 * @return array{valid:bool, errors:string[], warnings:string[]}
 */
function e2m_engine_oxygen_validate_native( string $type, array $settings ): array {
	$errors   = [];
	$warnings = [];

	$internal = [ 'ct_id', 'ct_parent', 'ct_depth', 'selector', 'original', 'activeselector', 'nicename', 'ct_content', 'ct_css', 'ct_js', 'classes' ];
	$required = [
		'ct_headline'    => [ 'ct_content' ],
		'ct_text_block'  => [ 'ct_content' ],
		'ct_image'       => [ 'src' ],
		'ct_link_button' => [ 'ct_content', 'url' ],
		'ct_link'        => [ 'ct_content', 'url' ],
		'oxy_video'      => [ 'url' ],
		'ct_code_block'  => [ 'ct_content' ],
	];
	$enums = [
		'tag'              => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'label', 'li' ],
		'target'           => [ '_self', '_blank', '_parent', '_top' ],
		'object-fit'       => [ 'cover', 'contain', 'fill', 'none', 'scale-down' ],
		'display'          => [ 'block', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'none' ],
		'position'         => [ 'static', 'relative', 'absolute', 'fixed', 'sticky' ],
		'flex-direction'   => [ 'row', 'row-reverse', 'column', 'column-reverse' ],
		'justify-content'  => [ 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly' ],
		'align-items'      => [ 'flex-start', 'flex-end', 'center', 'baseline', 'stretch' ],
		'text-align'       => [ 'left', 'center', 'right', 'justify' ],
		'text-transform'   => [ 'none', 'uppercase', 'lowercase', 'capitalize' ],
		'background-size'  => [ 'auto', 'cover', 'contain' ],
		'overflow'         => [ 'visible', 'hidden', 'scroll', 'auto' ],
		'order'            => [ 'ASC', 'DESC' ],
		'order_by'         => [ 'date', 'title', 'name', 'modified', 'rand', 'comment_count', 'menu_order', 'ID' ],
	];
	$responsive_suffixes = [ '_tablet', '_phone_landscape', '_phone_portrait' ];

	// Get known props for this component.
	$component   = e2m_engine_oxygen_find_component_builtin( $type, e2m_engine_oxygen_builtin_components() );
	$known_props = $component ? array_merge( (array) ( $component['properties'] ?? [] ), array_keys( e2m_engine_oxygen_common_props() ) ) : [];

	// Check required.
	foreach ( $required[ $type ] ?? [] as $req ) {
		if ( ! array_key_exists( $req, $settings ) || $settings[ $req ] === '' ) {
			$errors[] = "Required property \"{$req}\" is missing for component type \"{$type}\".";
		}
	}

	// Per-setting checks.
	foreach ( $settings as $key => $value ) {
		// Strip responsive suffix for base-name lookup.
		$base = $key;
		foreach ( $responsive_suffixes as $suffix ) {
			if ( str_ends_with( $key, $suffix ) ) {
				$base = substr( $key, 0, -strlen( $suffix ) );
				break;
			}
		}

		// Warn on unknown internal properties passed explicitly.
		if ( in_array( $base, $internal, true ) && ! in_array( $base, [ 'ct_content', 'ct_css', 'ct_js', 'classes' ], true ) ) {
			$warnings[] = "Property \"{$key}\" is an internal Oxygen property and should not be set directly.";
		}

		// Warn on unknown property if we know this component's props.
		if ( ! empty( $known_props ) && ! in_array( $base, $known_props, true ) && ! in_array( $base, $internal, true ) ) {
			$warnings[] = "Unknown property \"{$key}\" for component \"{$type}\". It may still work if Oxygen supports it as a CSS property.";
		}

		// Enum validation.
		if ( isset( $enums[ $base ] ) && ! in_array( $value, $enums[ $base ], true ) ) {
			$warnings[] = "Property \"{$key}\" value \"{$value}\" is not in the expected enum: " . implode( ', ', $enums[ $base ] ) . '.';
		}
	}

	return [
		'valid'    => empty( $errors ),
		'errors'   => $errors,
		'warnings' => $warnings,
	];
}
