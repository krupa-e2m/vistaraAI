<?php
/**
 * E2M Connect MCP - Elementor Set Template Conditions (Pro)
 *
 * Writes display conditions for a theme-builder template so it renders on
 * the matching parts of the site. Conditions are stored as a serialised
 * array in the template's _elementor_conditions meta, which Elementor
 * Pro's ConditionsManager reads on boot.
 *
 * Each condition is a string like:
 *   "include/general"       - everywhere
 *   "include/singular/post" - all single blog posts
 *   "include/singular/post/in_id/42" - one specific post
 *   "exclude/archive/taxonomy/category/in_id/5" - exclude a taxonomy archive
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-set-template-conditions', [
	'label'       => __( '[Elementor] Set Template Conditions', 'e2mconnect' ),
	'description' => 'Pro-only. Replaces the display conditions on a Theme Builder template.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'conditions'  => [
				'type'     => 'array',
				'minItems' => 1,
				'items'    => [ 'type' => 'string', 'minLength' => 1 ],
			],
		],
		'required'             => [ 'template_id', 'conditions' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'template_id' => [ 'type' => 'integer' ],
			'count'       => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_set_template_conditions_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Set Template Conditions (Pro)',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-set-template-conditions ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_set_template_conditions_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to update template conditions.', 'e2mconnect' ) );
	}

	$template_id = isset( $input['template_id'] ) ? (int) $input['template_id'] : 0;
	$conditions  = isset( $input['conditions'] ) && is_array( $input['conditions'] )
		? array_values( array_filter( array_map( 'strval', $input['conditions'] ) ) )
		: [];

	if ( $template_id <= 0 || ! get_post( $template_id ) ) {
		return new WP_Error( 'invalid_template_id', __( 'A valid template_id is required.', 'e2mconnect' ) );
	}
	if ( $conditions === [] ) {
		return new WP_Error( 'empty_conditions', __( 'At least one condition string is required.', 'e2mconnect' ) );
	}

	update_post_meta( $template_id, '_elementor_conditions', $conditions );

	// Nudge Elementor Pro to refresh its condition cache when it exposes
	// the helper. We call it defensively because the exact namespace can
	// change across Pro versions.
	if ( class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Classes\\Conditions_Cache' ) ) {
		try {
			$cache = new \ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache();
			if ( method_exists( $cache, 'regenerate' ) ) {
				$cache->regenerate();
			}
		} catch ( \Throwable $e ) {
			// Non-fatal: the meta write is authoritative.
		}
	}

	return [
		'template_id' => $template_id,
		'count'       => count( $conditions ),
	];
}
