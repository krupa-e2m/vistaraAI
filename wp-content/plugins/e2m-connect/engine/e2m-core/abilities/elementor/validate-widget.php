<?php
/**
 * E2M Connect MCP - Elementor Validate Widget
 *
 * Validates widget settings before they are applied to a page, preventing
 * broken layouts. Checks that all required controls are present, values are
 * within accepted ranges/options, and no unknown keys are passed.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-validate-widget', [
	'label'       => __( '[Elementor] Validate Widget', 'e2mconnect' ),
	'description' => 'Validates Elementor widget settings before applying them — prevents broken layouts by checking required fields, types, and allowed values.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'widget_type' => [
				'type'        => 'string',
				'description' => 'Elementor widget type slug to validate against (e.g. "heading", "button").',
			],
			'settings' => [
				'type'                 => 'object',
				'description'          => 'The widget settings payload to validate.',
				'additionalProperties' => true,
			],
			'strict' => [
				'type'        => 'boolean',
				'description' => 'When true, unknown settings keys are treated as errors. Default false.',
				'default'     => false,
			],
		],
		'required'             => [ 'widget_type', 'settings' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'widget_type' => [ 'type' => 'string' ],
			'valid'       => [ 'type' => 'boolean' ],
			'errors'      => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'warnings'    => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'passed'      => [ 'type' => 'integer' ],
			'checked'     => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_validate_widget_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Validate Widget',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-validate-widget ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_validate_widget_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$widget_type = sanitize_text_field( $input['widget_type'] ?? '' );
	$settings    = is_array( $input['settings'] ?? null ) ? $input['settings'] : [];
	$strict      = (bool) ( $input['strict'] ?? false );

	if ( $widget_type === '' ) {
		return new WP_Error( 'missing_widget_type', __( 'widget_type is required.', 'e2mconnect' ) );
	}

	$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
	if ( ! $widgets_manager ) {
		return new WP_Error( 'elementor_not_ready', __( 'Elementor widgets manager is not available.', 'e2mconnect' ) );
	}

	$widget = $widgets_manager->get_widget_types( $widget_type );
	if ( ! $widget ) {
		return new WP_Error( 'widget_not_found', sprintf(
			/* translators: %s: widget type slug */
			__( 'Widget type "%s" is not registered.', 'e2mconnect' ),
			$widget_type
		) );
	}

	$controls = $widget->get_controls();
	$errors   = [];
	$warnings = [];
	$passed   = 0;
	$checked  = 0;

	foreach ( $controls as $key => $control ) {
		$ctrl_type = $control['type'] ?? '';

		// Skip section/tabs — not real settings keys.
		if ( in_array( $ctrl_type, [ 'section', 'tabs', 'tab', 'divider', 'heading', 'raw_html' ], true ) ) {
			continue;
		}

		// M3 fix: Skip controls whose 'condition' is not satisfied by current settings.
		// Conditional controls only appear in the editor when the condition matches —
		// they should never be flagged as required when their condition is not met.
		if ( ! empty( $control['condition'] ) && is_array( $control['condition'] ) ) {
			$condition_met = true;
			foreach ( $control['condition'] as $cond_key => $cond_value ) {
				// Strip trailing '!' for negation conditions (e.g. 'icon!' => '').
				$negated  = str_ends_with( $cond_key, '!' );
				$base_key = $negated ? rtrim( $cond_key, '!' ) : $cond_key;
				$setting_val = $settings[ $base_key ] ?? null;
				if ( is_array( $cond_value ) ) {
					$match = in_array( $setting_val, $cond_value, true );
				} else {
					$match = ( $setting_val === $cond_value ) || ( (string) $setting_val === (string) $cond_value );
				}
				if ( $negated ? $match : ! $match ) {
					$condition_met = false;
					break;
				}
			}
			if ( ! $condition_met ) {
				// Condition not met — skip this control entirely, don't count it.
				continue;
			}
		}

		$checked++;

		$has_value = array_key_exists( $key, $settings );

		// Required check.
		$is_required = ! empty( $control['required'] );
		if ( $is_required && ! $has_value ) {
			$errors[] = [
				'field'   => $key,
				'type'    => 'required',
				'message' => sprintf( __( 'Required field "%s" is missing.', 'e2mconnect' ), $key ),
			];
			continue;
		}

		if ( ! $has_value ) {
			$passed++;
			continue;
		}

		$value = $settings[ $key ];

		// Select control — validate options.
		if ( $ctrl_type === 'select' || $ctrl_type === 'choose' ) {
			$options       = array_keys( $control['options'] ?? [] );
			$default_value = $control['default'] ?? null;
			if ( ! empty( $options ) && ! in_array( $value, $options, true ) ) {
				$errors[] = [
					'field'    => $key,
					'type'     => 'invalid_option',
					'value'    => $value,
					'allowed'  => $options,
					'message'  => sprintf( __( 'Value "%s" is not a valid option for field "%s".', 'e2mconnect' ), $value, $key ),
				];
				continue;
			}
		}

		// Number control — validate min/max.
		if ( $ctrl_type === 'number' || $ctrl_type === 'slider' ) {
			$num = is_array( $value ) ? ( $value['size'] ?? null ) : $value;
			if ( $num !== null ) {
				$min = $control['min'] ?? null;
				$max = $control['max'] ?? null;
				if ( $min !== null && $num < $min ) {
					$warnings[] = [
						'field'   => $key,
						'type'    => 'below_min',
						'value'   => $num,
						'min'     => $min,
						'message' => sprintf( __( 'Value %s for field "%s" is below minimum %s.', 'e2mconnect' ), $num, $key, $min ),
					];
				}
				if ( $max !== null && $num > $max ) {
					$warnings[] = [
						'field'   => $key,
						'type'    => 'above_max',
						'value'   => $num,
						'max'     => $max,
						'message' => sprintf( __( 'Value %s for field "%s" exceeds maximum %s.', 'e2mconnect' ), $num, $key, $max ),
					];
				}
			}
		}

		$passed++;
	}

	// Strict mode: flag unknown keys.
	if ( $strict ) {
		$known_keys = array_keys( $controls );
		foreach ( array_keys( $settings ) as $setting_key ) {
			if ( ! in_array( $setting_key, $known_keys, true ) ) {
				$errors[] = [
					'field'   => $setting_key,
					'type'    => 'unknown_key',
					'message' => sprintf( __( 'Unknown setting key "%s" (strict mode).', 'e2mconnect' ), $setting_key ),
				];
			}
		}
	}

	// If no rules were checked, we cannot claim the widget is valid.
	if ( $checked === 0 ) {
		$warnings[] = [
			'key'     => '_schema',
			'message' => __( 'No validation rules found for this widget type — schema may be missing or widget type is unregistered.', 'e2mconnect' ),
		];
	}

	return [
		'widget_type' => $widget_type,
		'valid'       => $checked > 0 && empty( $errors ),
		'errors'      => $errors,
		'warnings'    => $warnings,
		'passed'      => $passed,
		'checked'     => $checked,
	];
}
