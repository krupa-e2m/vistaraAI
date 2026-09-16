<?php
/**
 * E2M Connect MCP - Detect Page Builder
 *
 * Inspects a single page or post and reports which page builder, if any, is
 * responsible for its rendered content. Shares detection heuristics with the
 * list-pages ability via the e2m_engine_detect_builder_for_post() helper.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/detect-page-builder', [
	'label'       => __( '[Page] Detect Builder', 'e2mconnect' ),
	'description' => 'Detects which page builder (Elementor, Gutenberg, Bricks, Divi, Beaver, or classic) is responsible for a given post/page, plus the active theme\'s FSE capability (block theme, hybrid, or classic).',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Post or page ID to inspect.',
			],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'post_type'  => [ 'type' => 'string' ],
			'builder'    => [
				'type'        => 'string',
				'description' => 'One of: elementor, gutenberg, bricks, divi, beaver, classic. Per-post detection — unchanged by theme_mode below.',
			],
			'signals'    => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Markers found during detection (meta keys and content signatures).',
			],
			'theme_mode' => [
				'type'        => 'string',
				'description' => 'Site-level FSE capability of the ACTIVE theme (not this specific post): "block" (full block/FSE theme — wp_is_block_theme() true), "hybrid" (classic theme that opts into block-template support), or "classic". Independent of the per-post builder field above — a classic theme can still have Gutenberg-block content on one post (builder=gutenberg, theme_mode=classic).',
			],
			'theme_mode_signals' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Markers found while determining theme_mode.',
			],
		],
	],

	'execute_callback'    => 'e2m_engine_detect_page_builder_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Detect Page Builder',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the detect-page-builder ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_detect_page_builder_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
	}

	$signals = [];
	$builder = 'classic';

	if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
		$signals[] = '_elementor_edit_mode=builder';
		$builder   = 'elementor';
	} elseif ( get_post_meta( $post_id, '_bricks_editor_mode', true ) ) {
		$signals[] = '_bricks_editor_mode';
		$builder   = 'bricks';
	} elseif ( get_post_meta( $post_id, '_et_pb_use_builder', true ) === 'on' ) {
		$signals[] = '_et_pb_use_builder=on';
		$builder   = 'divi';
	} elseif ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
		$signals[] = '_fl_builder_enabled';
		$builder   = 'beaver';
	} elseif ( str_contains( (string) $post->post_content, '<!-- wp:' ) ) {
		$signals[] = 'gutenberg-block-delimiter';
		$builder   = 'gutenberg';
	}

	[ $theme_mode, $theme_mode_signals ] = e2m_engine_detect_theme_mode();

	return [
		'post_id'            => $post_id,
		'post_type'          => (string) $post->post_type,
		'builder'            => $builder,
		'signals'            => $signals,
		'theme_mode'         => $theme_mode,
		'theme_mode_signals' => $theme_mode_signals,
	];
}

/**
 * Determine the ACTIVE theme's site-level FSE capability — independent of any
 * specific post's builder. Safe default is "classic" (unchanged from before
 * this was added): only upgrade to "block" or "hybrid" on a positive signal,
 * never guess.
 *
 * - "block"   — a full block/FSE theme (wp_is_block_theme() true: has
 *               theme.json AND a templates/ directory using block templates).
 * - "hybrid"  — a classic (PHP-template) theme that opts into partial block
 *               features, e.g. declares `add_theme_support('block-templates')`
 *               or ships a theme.json without qualifying as a full block theme.
 * - "classic" — neither. The pre-existing, safe fallback.
 *
 * @return array{0: string, 1: string[]}
 */
function e2m_engine_detect_theme_mode(): array {
	$signals = [];

	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		$signals[] = 'wp_is_block_theme()=true';
		return [ 'block', $signals ];
	}

	if ( current_theme_supports( 'block-templates' ) ) {
		$signals[] = 'theme_support:block-templates';
		return [ 'hybrid', $signals ];
	}

	$theme_json_path = trailingslashit( get_stylesheet_directory() ) . 'theme.json';
	if ( file_exists( $theme_json_path ) ) {
		$signals[] = 'theme.json present (non-block theme)';
		return [ 'hybrid', $signals ];
	}

	return [ 'classic', $signals ];
}
