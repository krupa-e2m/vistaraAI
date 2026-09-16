<?php
/**
 * E2M Connect MCP - Gutenberg Register Block Style
 *
 * Register/list/unregister block style variations via core's own
 * `register_block_style()` / `WP_Block_Styles_Registry`. This is the
 * SIMPLE, single-property alternative to a full `theme.json`
 * `styles.blocks.<name>.variations.<variation-name>` entry
 * (e2m/gutenberg-manage-theme-json, S5-G5, done) - confirmed directly
 * against core source that these are genuinely two different application/
 * generation mechanisms, not two ways to write the same output:
 *
 *  - A theme.json `styles.blocks.*.variations` entry is a rich, ref-aware
 *    style OBJECT (border/spacing/color/background/nested `elements`/
 *    `blocks` sub-trees) - rendered per block instance via
 *    wp_render_block_style_variation_support_styles() building a scoped
 *    WP_Theme_JSON instance and generating real CSS from the full
 *    stylesheet engine (class-wp-theme-json.php).
 *  - `register_block_style()`'s own `inline_style`/`style_handle`/
 *    `style_data` properties are comparatively "dumb": a literal CSS
 *    string, an already-registered stylesheet handle, or (since 6.6.0) a
 *    small theme.json-like partial run through the same generator but
 *    without per-instance ref-resolution or nested-block recursion.
 *
 * Both mechanisms converge on the IDENTICAL application contract though -
 * confirmed against real core block.json style declarations (core/table,
 * core/separator, core/button, core/image, core/quote) and their compiled
 * stylesheets: applying a style to a block instance is purely a className
 * on the block matching `is-style-{name}` (e.g. `.wp-block-table.is-style-
 * stripes`). A theme.json-declared variation ALSO ends up discoverable via
 * this same registry - wp_register_block_style_variations_from_theme_json_
 * partials() (wp-includes/block-supports/block-style-variations.php) calls
 * register_block_style() for each theme.json partial variation so it shows
 * up in the editor's style picker - but a plain register_block_style() call
 * (this ability's "register" action) works even without any matching
 * theme.json entry, and works on a classic/non-block theme too, since it
 * does not depend on WP_Theme_JSON_Resolver::get_merged_data() the way a
 * theme.json variation does.
 *
 * Confirmed IN-MEMORY-ONLY, per-request - WP_Block_Styles_Registry is a
 * plain singleton with zero DB/CPT/file backing, the identical shape
 * WP_Block_Patterns_Registry has (which e2m/gutenberg-manage-patterns'
 * "register_category" action already documents this same limitation for).
 * A registration made via this ability vanishes the instant the request
 * ends unless the caller ALSO persists the same register_block_style()
 * call from the theme's functions.php (or a plugin) on the `init` hook -
 * this ability cannot do that persistence itself; it can only confirm
 * whether core accepted the registration for the current request and
 * report the limitation honestly, the same posture "register_category"
 * already established rather than inventing new wording for an
 * equivalent gap.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-register-block-style', [
	'label'       => __( '[Gutenberg] Register Block Style', 'e2mconnect' ),
	'description' => __( 'Register/list/unregister a block style variation via register_block_style() - the simple, single-property alternative to a full theme.json styles.blocks.variations entry (e2m/gutenberg-manage-theme-json). Applies to a block instance via className "is-style-{name}", same contract core\'s own built-in style variations use. In-memory only for the current request - does not persist across requests on its own.', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'          => [
				'type'        => 'string',
				'enum'        => [ 'register', 'unregister', 'list', 'list_for_block' ],
				'description' => '"register" calls register_block_style() for the current request only. "unregister" calls unregister_block_style() for the current request only. "list" returns every registered style for every block type. "list_for_block" returns every registered style for one block type (block_name required).',
			],
			'block_name'      => [
				'oneOf' => [
					[ 'type' => 'string' ],
					[ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
				'description' => 'Required for "register"/"unregister"/"list_for_block". A single block name ("core/table") or (since WP 6.6.0) an array of block names to register the same style on all of them at once - matches register_block_style()\'s own signature. "unregister"/"list_for_block" only accept a single string, never an array (core\'s own unregister_block_style() takes one block name at a time).',
			],
			'style_name'      => [ 'type' => 'string', 'description' => 'Required for "register"/"unregister". The style identifier used to compute the "is-style-{name}" CSS class - must be a non-empty string with no spaces (matches core\'s own validation in WP_Block_Styles_Registry::register()).' ],
			'label'           => [ 'type' => 'string', 'description' => 'Optional, "register" only. Human-readable label shown in the editor\'s style picker. Defaults to style_name if omitted (matches core\'s own default).' ],
			'inline_style'    => [ 'type' => 'string', 'description' => 'Optional, "register" only. Literal CSS to enqueue, scoped by the caller to ".&lt;block-selector&gt;.is-style-{style_name}" (core does not auto-scope this string - the caller\'s CSS selector must include the is-style class itself).' ],
			'style_handle'    => [ 'type' => 'string', 'description' => 'Optional, "register" only. The handle of an ALREADY-REGISTERED stylesheet (via wp_register_style()) to enqueue wherever this style is used, instead of inline_style.' ],
			'is_default'      => [ 'type' => 'boolean', 'default' => false, 'description' => 'Optional, "register" only. Whether this is the block\'s default style (no is-style-* class needed to apply it).' ],
			'style_data'      => [ 'type' => 'object', 'description' => 'Optional, "register" only (WP 6.6.0+). A theme.json-like partial (e.g. {"color":{"background":"..."}}) run through the same style generator as a theme.json variation, without per-instance ref-resolution or nested-block recursion - simpler than a full theme.json styles.blocks.variations entry but richer than a plain inline_style CSS string.', 'additionalProperties' => true ],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'block_name' => [ 'oneOf' => [ [ 'type' => 'string' ], [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ],
			'style_name' => [ 'type' => 'string' ],
			'styles'     => [ 'type' => 'object', 'description' => 'Present on "list"/"list_for_block" - registered styles, keyed by block name (list) or by style name (list_for_block).' ],
			'validation' => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_register_block_style_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Register Block Style',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-register-block-style ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_register_block_style_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'register', 'unregister', 'list', 'list_for_block' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: register, unregister, list, list_for_block.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'register_block_style' ) || ! class_exists( 'WP_Block_Styles_Registry' ) ) {
		return new WP_Error( 'block_styles_unavailable', __( 'This WordPress core does not support block style registration.', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			return [ 'styles' => WP_Block_Styles_Registry::get_instance()->get_all_registered() ];
		case 'list_for_block':
			return e2m_engine_gutenberg_list_styles_for_block( $input );
		case 'register':
			return e2m_engine_gutenberg_register_block_style( $input );
		case 'unregister':
			return e2m_engine_gutenberg_unregister_block_style( $input );
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_list_styles_for_block( array $input ) {
	$block_name = isset( $input['block_name'] ) ? $input['block_name'] : null;
	if ( ! is_string( $block_name ) || $block_name === '' ) {
		return new WP_Error( 'missing_block_name', __( 'block_name (a single string) is required for "list_for_block".', 'e2mconnect' ) );
	}

	$styles = WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( $block_name );

	return [
		'block_name' => $block_name,
		'styles'     => $styles,
	];
}

/**
 * Register a block style for the CURRENT REQUEST ONLY - see this file's
 * own header for why no persistence mechanism exists here. Validates the
 * style_name shape itself (non-empty, no spaces) as a PRIMARY gate before
 * calling core, since register_block_style() itself only degrades to a
 * silent _doing_it_wrong() + `false` return on invalid input rather than a
 * catchable error - checking here first gives the caller an actionable
 * WP_Error instead of a bare "registration failed" with no reason.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_register_block_style( array $input ) {
	$block_name = isset( $input['block_name'] ) ? $input['block_name'] : null;
	if ( ! ( is_string( $block_name ) && $block_name !== '' ) && ! ( is_array( $block_name ) && ! empty( $block_name ) ) ) {
		return new WP_Error( 'missing_block_name', __( 'block_name (a non-empty string, or an array of strings) is required for "register".', 'e2mconnect' ) );
	}
	if ( is_array( $block_name ) ) {
		foreach ( $block_name as $one ) {
			if ( ! is_string( $one ) || $one === '' ) {
				return new WP_Error( 'invalid_block_name', __( 'Every entry in block_name must be a non-empty string.', 'e2mconnect' ) );
			}
		}
	}

	$style_name = isset( $input['style_name'] ) ? (string) $input['style_name'] : '';
	if ( $style_name === '' ) {
		return new WP_Error( 'missing_style_name', __( 'style_name is required for "register".', 'e2mconnect' ) );
	}
	if ( str_contains( $style_name, ' ' ) ) {
		return new WP_Error( 'invalid_style_name', __( 'style_name must not contain spaces - matches WP_Block_Styles_Registry\'s own validation.', 'e2mconnect' ) );
	}

	$style_properties = [ 'name' => $style_name ];
	if ( isset( $input['label'] ) ) {
		$style_properties['label'] = (string) $input['label'];
	}
	if ( isset( $input['inline_style'] ) ) {
		$style_properties['inline_style'] = (string) $input['inline_style'];
	}
	if ( isset( $input['style_handle'] ) ) {
		$style_properties['style_handle'] = (string) $input['style_handle'];
	}
	if ( isset( $input['is_default'] ) ) {
		$style_properties['is_default'] = (bool) $input['is_default'];
	}
	if ( isset( $input['style_data'] ) && is_array( $input['style_data'] ) ) {
		$style_properties['style_data'] = $input['style_data'];
	}

	$registered = register_block_style( $block_name, $style_properties );
	if ( $registered === false ) {
		return new WP_Error( 'block_style_registration_failed', __( 'WordPress core rejected the block style registration (see server error log for _doing_it_wrong details - usually an invalid block_name or a style_name that does not meet core\'s own validation).', 'e2mconnect' ) );
	}

	return [
		'block_name' => $block_name,
		'style_name' => $style_name,
		'validation' => [
			'valid'    => true,
			'warnings' => [ __( 'Block style registration is in-memory only for this request - it does not persist. Register it permanently from the theme\'s functions.php (or a plugin) on the init hook if it needs to survive future requests.', 'e2mconnect' ) ],
			'errors'   => [],
		],
	];
}

/**
 * Unregister a block style for the CURRENT REQUEST ONLY - see this file's
 * own header for why no persistence mechanism exists here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_unregister_block_style( array $input ) {
	$block_name = isset( $input['block_name'] ) ? $input['block_name'] : null;
	if ( ! is_string( $block_name ) || $block_name === '' ) {
		return new WP_Error( 'missing_block_name', __( 'block_name (a single string) is required for "unregister" - core\'s own unregister_block_style() takes one block name at a time, never an array.', 'e2mconnect' ) );
	}
	$style_name = isset( $input['style_name'] ) ? (string) $input['style_name'] : '';
	if ( $style_name === '' ) {
		return new WP_Error( 'missing_style_name', __( 'style_name is required for "unregister".', 'e2mconnect' ) );
	}

	if ( ! WP_Block_Styles_Registry::get_instance()->is_registered( $block_name, $style_name ) ) {
		return new WP_Error( 'block_style_not_found', __( 'No registered style matches this block_name + style_name.', 'e2mconnect' ) );
	}

	unregister_block_style( $block_name, $style_name );

	return [
		'block_name' => $block_name,
		'style_name' => $style_name,
		'validation' => [
			'valid'    => true,
			'warnings' => [ __( 'Unregistration is in-memory only for this request. If the style was persisted via a theme/plugin init hook, it will reappear on the next request unless that code is also removed.', 'e2mconnect' ) ],
			'errors'   => [],
		],
	];
}
