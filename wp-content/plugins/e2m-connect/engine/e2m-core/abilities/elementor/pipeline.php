<?php
/**
 * E2M Connect MCP - Elementor Pipeline
 *
 * Execute multiple Elementor builder operations atomically in a single
 * MCP call. Each step calls an internal ability executor; if any step
 * fails the pipeline halts and reports which step failed. All writes are
 * applied in order — no partial rollback is attempted (Elementor has no
 * transaction model), but the caller receives a full step-by-step report.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-pipeline', [
	'label'       => __( '[Elementor] Pipeline', 'e2mconnect' ),
	'description' => 'Execute multiple Elementor builder operations atomically in one call — stops at the first failure and reports each step.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'steps' => [
				'type'        => 'array',
				'description' => 'Ordered list of pipeline steps to execute.',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'ability' => [
							'type'        => 'string',
							'description' => 'Ability slug to call (e.g. "e2m/elementor-validate-widget", "e2m/elementor-create-atomic-widget").',
						],
						'input' => [
							'type'                 => 'object',
							'description'          => 'Input payload for this ability step.',
							'additionalProperties' => true,
						],
						'label' => [
							'type'        => 'string',
							'description' => 'Human-readable step description for the report.',
						],
					],
					'required' => [ 'ability', 'input' ],
				],
				'minItems' => 1,
				'maxItems' => 20,
			],
			'stop_on_error' => [
				'type'        => 'boolean',
				'description' => 'When true (default), the pipeline halts at the first failed step.',
				'default'     => true,
			],
		],
		'required'             => [ 'steps' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'success'       => [ 'type' => 'boolean' ],
			'steps_total'   => [ 'type' => 'integer' ],
			'steps_run'     => [ 'type' => 'integer' ],
			'steps_passed'  => [ 'type' => 'integer' ],
			'steps_failed'  => [ 'type' => 'integer' ],
			'results'       => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'step'    => [ 'type' => 'integer' ],
						'ability' => [ 'type' => 'string' ],
						'label'   => [ 'type' => 'string' ],
						'success' => [ 'type' => 'boolean' ],
						'output'  => [ 'type' => 'object', 'additionalProperties' => true ],
						'error'   => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_pipeline_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Pipeline',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-pipeline ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_pipeline_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$steps         = $input['steps'] ?? [];
	$stop_on_error = (bool) ( $input['stop_on_error'] ?? true );

	if ( empty( $steps ) || ! is_array( $steps ) ) {
		return new WP_Error( 'missing_steps', __( 'At least one pipeline step is required.', 'e2mconnect' ) );
	}

	// Build a map of ability slug → execute callback.
	$ability_map = [
		'e2m/elementor-manage-global-classes'   => 'e2m_engine_elementor_manage_global_classes_ability',
		'e2m/elementor-manage-variables'        => 'e2m_engine_elementor_manage_variables_ability',
		'e2m/elementor-manage-global-styles-v3' => 'e2m_engine_elementor_manage_global_styles_v3_ability',
		'e2m/elementor-get-style-schema'        => 'e2m_engine_elementor_get_style_schema_ability',
		'e2m/elementor-manage-interactions'     => 'e2m_engine_elementor_manage_interactions_ability',
		'e2m/elementor-create-atomic-widget'    => 'e2m_engine_elementor_create_atomic_widget_ability',
		'e2m/elementor-validate-widget'         => 'e2m_engine_elementor_validate_widget_ability',
		// Core Elementor abilities.
		'e2m/elementor-get-global-settings'     => 'e2m_engine_elementor_get_global_settings_ability',
		'e2m/elementor-update-global-colors'    => 'e2m_engine_elementor_update_global_colors_ability',
		'e2m/elementor-update-global-typography' => 'e2m_engine_elementor_update_global_typography_ability',
		'e2m/elementor-update-page-settings'    => 'e2m_engine_elementor_update_page_settings_ability',
	];

	$results      = [];
	$steps_run    = 0;
	$steps_passed = 0;
	$steps_failed = 0;

	foreach ( $steps as $idx => $step ) {
		$step_num = $idx + 1;
		$ability  = sanitize_text_field( $step['ability'] ?? '' );
		$step_input = is_array( $step['input'] ?? null ) ? $step['input'] : [];
		$label    = sanitize_text_field( $step['label'] ?? $ability );

		if ( $ability === '' ) {
			$results[] = [
				'step'    => $step_num,
				'ability' => $ability,
				'label'   => $label,
				'success' => false,
				'error'   => __( 'Missing ability slug.', 'e2mconnect' ),
			];
			$steps_run++;
			$steps_failed++;
			if ( $stop_on_error ) {
				break;
			}
			continue;
		}

		// Look up executor.
		$callback = $ability_map[ $ability ] ?? null;
		if ( $callback === null || ! function_exists( $callback ) ) {
			$results[] = [
				'step'    => $step_num,
				'ability' => $ability,
				'label'   => $label,
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: ability slug */
					__( 'Ability "%s" is not available in this pipeline.', 'e2mconnect' ),
					$ability
				),
			];
			$steps_run++;
			$steps_failed++;
			if ( $stop_on_error ) {
				break;
			}
			continue;
		}

		$output = call_user_func( $callback, $step_input );
		$steps_run++;

		if ( is_wp_error( $output ) ) {
			$results[] = [
				'step'    => $step_num,
				'ability' => $ability,
				'label'   => $label,
				'success' => false,
				'error'   => $output->get_error_message(),
			];
			$steps_failed++;
			if ( $stop_on_error ) {
				break;
			}
		} else {
			$results[] = [
				'step'    => $step_num,
				'ability' => $ability,
				'label'   => $label,
				'success' => true,
				'output'  => is_array( $output ) ? $output : [],
			];
			$steps_passed++;
		}
	}

	return [
		'success'      => $steps_failed === 0,
		'steps_total'  => count( $steps ),
		'steps_run'    => $steps_run,
		'steps_passed' => $steps_passed,
		'steps_failed' => $steps_failed,
		'results'      => $results,
	];
}
