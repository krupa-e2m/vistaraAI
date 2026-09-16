<?php
/**
 * E2M Connect MCP – WPBakery Shortcode Registry
 *
 * Read WPBakery Page Builder's full shortcode library — all registered
 * shortcodes with their parameters, categories, and metadata.
 *
 * Delegates to WPBMap::getAllShortCodes() at runtime when available, then
 * to Respira_WPBakery_Shortcode_Registry, then falls back to the full
 * built-in shortcode catalogue.
 *
 * Actions:
 *   list       — list all known shortcodes (with optional category filter)
 *   get        — get full details for a single shortcode by tag name
 *   categories — list all shortcode categories
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/wpbakery-shortcode-registry', [
	'label'       => __( '[WPBakery] Shortcode Registry', 'e2mconnect' ),
	'description' => 'Read WPBakery Page Builder\'s shortcode library. List all registered shortcodes, filter by category, or get full details (params, types, descriptions) for a single shortcode tag.',
	'category'    => 'e2m-wpbakery',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories' ],
				'description' => 'list — all shortcodes; get — single shortcode by tag; categories — list all categories.',
			],
			'shortcode' => [
				'type'        => 'string',
				'description' => 'Shortcode tag for the get action, e.g. "vc_row", "vc_column_text", "vc_btn".',
			],
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by category: layout, content, media, interactive, buttons, wordpress, social.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'shortcodes' => [ 'type' => 'array' ],
			'shortcode'  => [ 'type' => 'object' ],
			'categories' => [ 'type' => 'array' ],
			'total'      => [ 'type' => 'integer' ],
			'source'     => [ 'type' => 'string', 'description' => '"live_api", "respira", or "built_in" — how shortcodes were detected.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_wpbakery_shortcode_registry',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'WPBakery: Shortcode Registry',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute wpbakery-shortcode-registry ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_wpbakery_shortcode_registry( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_wpbakery() ) {
		return new WP_Error( 'wpbakery_missing', __( 'WPBakery Page Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	[ $all_shortcodes, $source ] = e2m_engine_wpbakery_resolve_shortcodes();

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$category_filter = isset( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
			$filtered = $category_filter
				? array_values( array_filter( $all_shortcodes, fn( $s ) => ( $s['category'] ?? '' ) === $category_filter ) )
				: array_values( $all_shortcodes );

			return [
				'action'     => 'list',
				'shortcodes' => $filtered,
				'total'      => count( $filtered ),
				'source'     => $source,
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['shortcode'] ) ) {
				return new WP_Error( 'missing_shortcode', __( '"shortcode" is required for get action.', 'e2mconnect' ) );
			}
			$tag = sanitize_text_field( $input['shortcode'] );

			if ( class_exists( 'Respira_WPBakery_Shortcode_Registry' ) ) {
				$shortcode = Respira_WPBakery_Shortcode_Registry::get_shortcode( $tag );
			} else {
				$shortcode = e2m_engine_wpbakery_find_shortcode( $tag );
			}

			if ( ! $shortcode ) {
				return new WP_Error( 'shortcode_not_found', sprintf( __( 'No shortcode found with tag "%s".', 'e2mconnect' ), $tag ) );
			}

			return [
				'action'    => 'get',
				'shortcode' => $shortcode,
				'source'    => $source,
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_shortcodes as $s ) {
				// WPBakery v6+ can store multiple categories as an array.
				$raw_cat  = $s['category'] ?? 'general';
				$cat_list = is_array( $raw_cat ) ? $raw_cat : [ (string) $raw_cat ];
				foreach ( $cat_list as $cat ) {
					$cat = (string) $cat;
					if ( $cat === '' ) {
						$cat = 'general';
					}
					if ( ! isset( $cats[ $cat ] ) ) {
						$cats[ $cat ] = 0;
					}
					$cats[ $cat ]++;
				}
			}
			$result = [];
			foreach ( $cats as $name => $count ) {
				$result[] = [ 'category' => $name, 'shortcode_count' => $count ];
			}
			usort( $result, fn( $a, $b ) => $b['shortcode_count'] - $a['shortcode_count'] );

			return [
				'action'     => 'categories',
				'categories' => $result,
				'total'      => count( $result ),
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, categories.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Shared helpers (used by other wpbakery ability files too)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the full shortcode list and its source label.
 *
 * Priority: WPBMap live API → Respira class → built-in catalogue.
 *
 * @return array{0: array, 1: string}  [shortcodes, source]
 */
function e2m_engine_wpbakery_resolve_shortcodes(): array {
	// 1. Live WPBakery API (only available inside a WP request with WPBakery loaded).
	if ( class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'getAllShortCodes' ) ) {
		$raw = WPBMap::getAllShortCodes();
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			$normalised = [];
			foreach ( $raw as $tag => $data ) {
				// WPBMap uses 'name' for the display label and 'base' for the tag.
				// Our schema uses 'name' for the tag (for find_shortcode() lookup)
				// and 'title' for the display label. Merge data first so our
				// 'name => $tag' override wins (not the display label from $data).
				$entry          = (array) $data;
				$entry['title'] = $entry['title'] ?? ( $entry['name'] ?? $tag );
				$entry['name']  = $tag;
				$normalised[] = $entry;
			}
			return [ $normalised, 'live_api' ];
		}
	}

	// 2. Respira intelligence class.
	if ( class_exists( 'Respira_WPBakery_Shortcode_Registry' ) ) {
		$list = (array) Respira_WPBakery_Shortcode_Registry::get_all_shortcodes();
		return [ $list, 'respira' ];
	}

	// 3. Built-in catalogue.
	return [ e2m_engine_wpbakery_builtin_shortcodes(), 'built_in' ];
}

/**
 * Get all WPBakery shortcodes — via Respira class, live API, or built-in catalogue.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_wpbakery_get_all_shortcodes(): array {
	[ $list ] = e2m_engine_wpbakery_resolve_shortcodes();
	return $list;
}

/**
 * Find a shortcode by tag name in the resolved list.
 *
 * @param string $name Shortcode tag, e.g. "vc_row".
 * @return array<string,mixed>|null
 */
function e2m_engine_wpbakery_find_shortcode( string $name ): ?array {
	foreach ( e2m_engine_wpbakery_get_all_shortcodes() as $s ) {
		if ( ( $s['name'] ?? '' ) === $name ) {
			return $s;
		}
	}
	return null;
}

/**
 * Comprehensive built-in WPBakery shortcode catalogue.
 * Mirrors WPBakery's default registration, used as fallback when the live
 * WPBMap API and Respira classes are unavailable.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_wpbakery_builtin_shortcodes(): array {
	return [

		// ── Layout ────────────────────────────────────────────────────────────
		[
			'name'        => 'vc_row',
			'title'       => 'Row',
			'category'    => 'layout',
			'description' => 'Top-level row container. Wraps columns. Maps to a full-width or boxed row.',
			'params'      => [
				[ 'param_name' => 'full_width',         'type' => 'dropdown',  'description' => 'Row stretch mode: "" (boxed), "stretch_row", "stretch_row_content", "stretch_row_content_no_spaces".' ],
				[ 'param_name' => 'gap',                'type' => 'dropdown',  'description' => 'Gap size between columns: "", "0px", "10px", "20px", "30px", "35px".' ],
				[ 'param_name' => 'equal_height',       'type' => 'checkbox',  'description' => 'Equalize column heights.' ],
				[ 'param_name' => 'content_placement',  'type' => 'dropdown',  'description' => 'Vertical content placement: "", "top", "middle", "bottom".' ],
				[ 'param_name' => 'css_animation',      'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'css',                'type' => 'css',       'description' => 'Custom CSS (WPBakery design options).' ],
			],
		],
		[
			'name'        => 'vc_row_inner',
			'title'       => 'Inner Row',
			'category'    => 'layout',
			'description' => 'Nested row inside a column. Children should be vc_column_inner.',
			'params'      => [
				[ 'param_name' => 'gap',           'type' => 'dropdown',  'description' => 'Gap between inner columns.' ],
				[ 'param_name' => 'equal_height',  'type' => 'checkbox',  'description' => 'Equalize inner column heights.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'css',           'type' => 'css',       'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_column',
			'title'       => 'Column',
			'category'    => 'layout',
			'description' => 'Column inside a vc_row. Width expressed as a fraction: 1/1, 1/2, 1/3, 1/4, 2/3, 3/4.',
			'params'      => [
				[ 'param_name' => 'width',         'type' => 'dropdown',  'description' => 'Column width fraction: "1/1" (full), "1/2", "1/3", "1/4", "2/3", "3/4".' ],
				[ 'param_name' => 'offset',        'type' => 'autocomplete', 'description' => 'Responsive offset CSS classes (vc_hidden-* etc).' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'css',           'type' => 'css',       'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_column_inner',
			'title'       => 'Inner Column',
			'category'    => 'layout',
			'description' => 'Column inside a vc_row_inner. Same width fractions as vc_column.',
			'params'      => [
				[ 'param_name' => 'width',  'type' => 'dropdown',  'description' => 'Column width fraction.' ],
				[ 'param_name' => 'offset', 'type' => 'autocomplete', 'description' => 'Responsive offset CSS classes.' ],
				[ 'param_name' => 'css',    'type' => 'css',       'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_section',
			'title'       => 'Section',
			'category'    => 'layout',
			'description' => 'Top-level section wrapper. Can contain vc_row elements.',
			'params'      => [
				[ 'param_name' => 'full_width',      'type' => 'dropdown',  'description' => 'Section stretch mode.' ],
				[ 'param_name' => 'parallax',        'type' => 'dropdown',  'description' => 'Parallax type: "", "content", "content-moving".' ],
				[ 'param_name' => 'parallax_image',  'type' => 'attach_image', 'description' => 'Background image for parallax.' ],
				[ 'param_name' => 'css',             'type' => 'css',       'description' => 'Custom CSS.' ],
			],
		],

		// ── Content ───────────────────────────────────────────────────────────
		[
			'name'        => 'vc_column_text',
			'title'       => 'Text Block',
			'category'    => 'content',
			'description' => 'Rich text / HTML content block. Content goes between the opening and closing tags.',
			'params'      => [
				[ 'param_name' => 'content', 'type' => 'textarea_html', 'description' => 'HTML content.' ],
				[ 'param_name' => 'css',     'type' => 'css',           'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_raw_html',
			'title'       => 'Raw HTML',
			'category'    => 'content',
			'description' => 'Renders raw HTML. Content is base64-encoded inside the shortcode tags to avoid conflicts with WPBakery parsing.',
			'params'      => [
				[ 'param_name' => 'content', 'type' => 'textarea_html', 'description' => 'Raw HTML (stored base64-encoded in shortcode content).' ],
			],
		],
		[
			'name'        => 'vc_raw_js',
			'title'       => 'Raw JS',
			'category'    => 'content',
			'description' => 'Renders a raw JavaScript block. Content is base64-encoded.',
			'params'      => [
				[ 'param_name' => 'content', 'type' => 'textarea', 'description' => 'JavaScript code (stored base64-encoded).' ],
			],
		],
		[
			'name'        => 'vc_custom_heading',
			'title'       => 'Custom Heading',
			'category'    => 'content',
			'description' => 'Heading element with Google Fonts support and advanced typography options.',
			'params'      => [
				[ 'param_name' => 'text',             'type' => 'textfield',     'description' => 'Heading text.' ],
				[ 'param_name' => 'font_container',   'type' => 'font_container','description' => 'Font container: tag, font-size, line-height, color, etc.' ],
				[ 'param_name' => 'use_theme_fonts',  'type' => 'checkbox',      'description' => 'Use theme fonts instead of custom.' ],
				[ 'param_name' => 'google_fonts',     'type' => 'google_fonts',  'description' => 'Google Font selection.' ],
				[ 'param_name' => 'link',             'type' => 'link',          'description' => 'Optional link on heading.' ],
				[ 'param_name' => 'css_animation',    'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'css',              'type' => 'css',           'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_separator',
			'title'       => 'Separator',
			'category'    => 'content',
			'description' => 'Horizontal separator / divider line.',
			'params'      => [
				[ 'param_name' => 'type',         'type' => 'dropdown',  'description' => 'Separator style: "normal", "small", "shadow", "double", "dotted", "dashed".' ],
				[ 'param_name' => 'position',     'type' => 'dropdown',  'description' => 'Alignment: "center", "left", "right".' ],
				[ 'param_name' => 'color',        'type' => 'colorpicker','description' => 'Separator color.' ],
				[ 'param_name' => 'border_width', 'type' => 'number',    'description' => 'Border width in pixels.' ],
				[ 'param_name' => 'el_width',     'type' => 'number',    'description' => 'Width as percentage.' ],
				[ 'param_name' => 'css_animation','type' => 'animation_style','description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'css',          'type' => 'css',       'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_spacer',
			'title'       => 'Spacer',
			'category'    => 'content',
			'description' => 'Adds empty vertical space.',
			'params'      => [
				[ 'param_name' => 'height', 'type' => 'textfield', 'description' => 'Height of spacer, e.g. "32px".' ],
			],
		],

		// ── Media ─────────────────────────────────────────────────────────────
		[
			'name'        => 'vc_single_image',
			'title'       => 'Single Image',
			'category'    => 'media',
			'description' => 'Single image from the media library with optional link and style.',
			'params'      => [
				[ 'param_name' => 'image',           'type' => 'attach_image', 'description' => 'Image attachment ID.' ],
				[ 'param_name' => 'img_size',        'type' => 'textfield',    'description' => 'Image size: "large", "medium", "thumbnail", or WxH.' ],
				[ 'param_name' => 'alignment',       'type' => 'dropdown',     'description' => 'Alignment: "", "left", "center", "right".' ],
				[ 'param_name' => 'style',           'type' => 'dropdown',     'description' => 'Image style: "", "vc_box_border", "vc_box_border_circle", "vc_box_shadow", etc.' ],
				[ 'param_name' => 'border_color',    'type' => 'dropdown',     'description' => 'Border color name from WPBakery palette.' ],
				[ 'param_name' => 'img_link_large',  'type' => 'checkbox',     'description' => 'Link to full-size image.' ],
				[ 'param_name' => 'img_link_target', 'type' => 'dropdown',     'description' => 'Link target: "_self", "_blank".' ],
				[ 'param_name' => 'css_animation',   'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'link',            'type' => 'link',         'description' => 'Custom link.' ],
				[ 'param_name' => 'css',             'type' => 'css',          'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_gallery',
			'title'       => 'Image Gallery',
			'category'    => 'media',
			'description' => 'Image gallery with multiple display modes.',
			'params'      => [
				[ 'param_name' => 'images',        'type' => 'attach_images', 'description' => 'Comma-separated attachment IDs.' ],
				[ 'param_name' => 'type',          'type' => 'dropdown',      'description' => 'Gallery type: "flexslider_slide", "flexslider_fade", "nivo", "image_grid".' ],
				[ 'param_name' => 'interval',      'type' => 'number',        'description' => 'Slideshow interval in seconds.' ],
				[ 'param_name' => 'onclick',       'type' => 'dropdown',      'description' => 'Click action: "link_image", "link_no", "custom_link", "img_link_large".' ],
				[ 'param_name' => 'custom_links',  'type' => 'textfield',     'description' => 'Comma-separated custom links (when onclick=custom_link).' ],
				[ 'param_name' => 'img_size',      'type' => 'textfield',     'description' => 'Image size.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'css',           'type' => 'css',           'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_video',
			'title'       => 'Video Player',
			'category'    => 'media',
			'description' => 'Embed a video from YouTube, Vimeo, or a self-hosted URL.',
			'params'      => [
				[ 'param_name' => 'link',     'type' => 'textfield', 'description' => 'Video URL (YouTube, Vimeo, or direct).' ],
				[ 'param_name' => 'align',    'type' => 'dropdown',  'description' => 'Alignment: "", "left", "center", "right".' ],
				[ 'param_name' => 'width',    'type' => 'number',    'description' => 'Video width in pixels.' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
				[ 'param_name' => 'css',      'type' => 'css',       'description' => 'Custom CSS.' ],
			],
		],
		[
			'name'        => 'vc_gmaps',
			'title'       => 'Google Maps',
			'category'    => 'media',
			'description' => 'Embedded Google Map.',
			'params'      => [
				[ 'param_name' => 'link',        'type' => 'textfield', 'description' => 'Google Maps embed URL.' ],
				[ 'param_name' => 'type',        'type' => 'dropdown',  'description' => 'Map type: "roadmap", "satellite", "hybrid", "terrain".' ],
				[ 'param_name' => 'zoom',        'type' => 'number',    'description' => 'Map zoom level (1-20).' ],
				[ 'param_name' => 'scrollwheel', 'type' => 'checkbox',  'description' => 'Enable scroll-wheel zoom.' ],
				[ 'param_name' => 'draggable',   'type' => 'checkbox',  'description' => 'Enable drag.' ],
				[ 'param_name' => 'size',        'type' => 'textfield', 'description' => 'Map size, e.g. "300".' ],
				[ 'param_name' => 'map_style',   'type' => 'textfield', 'description' => 'Custom map style JSON string.' ],
				[ 'param_name' => 'css_animation','type'=> 'animation_style','description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',    'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],

		// ── Interactive ───────────────────────────────────────────────────────
		[
			'name'        => 'vc_accordion',
			'title'       => 'Accordion',
			'category'    => 'interactive',
			'description' => 'Accordion container. Children must be vc_accordion_tab.',
			'params'      => [
				[ 'param_name' => 'active_tab',    'type' => 'textfield', 'description' => 'Index (1-based) of the initially open tab.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',      'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_accordion_tab',
			'title'       => 'Accordion Tab',
			'category'    => 'interactive',
			'description' => 'Single accordion tab inside vc_accordion.',
			'params'      => [
				[ 'param_name' => 'title', 'type' => 'textfield', 'description' => 'Tab heading text.' ],
			],
		],
		[
			'name'        => 'vc_tabs',
			'title'       => 'Tabs',
			'category'    => 'interactive',
			'description' => 'Tabbed interface container. Children must be vc_tab.',
			'params'      => [
				[ 'param_name' => 'active_tab',    'type' => 'textfield', 'description' => 'Index (1-based) of the initially active tab.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',      'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_tab',
			'title'       => 'Tab',
			'category'    => 'interactive',
			'description' => 'Single tab inside vc_tabs.',
			'params'      => [
				[ 'param_name' => 'title',  'type' => 'textfield', 'description' => 'Tab label text.' ],
				[ 'param_name' => 'tab_id', 'type' => 'textfield', 'description' => 'Unique tab ID (auto-generated if empty).' ],
			],
		],
		[
			'name'        => 'vc_toggle',
			'title'       => 'Toggle',
			'category'    => 'interactive',
			'description' => 'Single expandable/collapsible toggle.',
			'params'      => [
				[ 'param_name' => 'title',    'type' => 'textfield', 'description' => 'Toggle heading text.' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
				[ 'param_name' => 'open',     'type' => 'checkbox',  'description' => 'Start in open state.' ],
			],
		],

		// ── Buttons / CTA ──────────────────────────────────────────────────────
		[
			'name'        => 'vc_btn',
			'title'       => 'Button',
			'category'    => 'buttons',
			'description' => 'Styled call-to-action button with icon support.',
			'params'      => [
				[ 'param_name' => 'title',              'type' => 'textfield', 'description' => 'Button text.' ],
				[ 'param_name' => 'color',              'type' => 'dropdown',  'description' => 'Button color: "btn-default", "btn-primary", "btn-info", "btn-success", "btn-warning", "btn-danger", "btn-inverse", "btn-link", "grey", "orange", "sky", "green", "juicy_pink", "sandy_brown", "purple", "black".' ],
				[ 'param_name' => 'size',               'type' => 'dropdown',  'description' => 'Button size: "sm", "md", "lg", "xs".' ],
				[ 'param_name' => 'align',              'type' => 'dropdown',  'description' => 'Alignment: "left", "right", "center", "inline".' ],
				[ 'param_name' => 'i_align',            'type' => 'dropdown',  'description' => 'Icon alignment: "left", "right".' ],
				[ 'param_name' => 'i_type',             'type' => 'dropdown',  'description' => 'Icon library: "fontawesome", "openiconic", "typicons", "entypo", "linecons".' ],
				[ 'param_name' => 'i_icon_fontawesome', 'type' => 'textfield', 'description' => 'FontAwesome icon class, e.g. "fa fa-arrow-right".' ],
				[ 'param_name' => 'link',               'type' => 'link',      'description' => 'Button link URL and target.' ],
				[ 'param_name' => 'button_block',       'type' => 'checkbox',  'description' => 'Full-width block button.' ],
				[ 'param_name' => 'add_icon',           'type' => 'checkbox',  'description' => 'Add an icon to the button.' ],
				[ 'param_name' => 'css_animation',      'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',           'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_cta',
			'title'       => 'Call to Action',
			'category'    => 'buttons',
			'description' => 'Call-to-action block with heading, description, and optional button.',
			'params'      => [
				[ 'param_name' => 'h2',               'type' => 'textfield', 'description' => 'Main heading text.' ],
				[ 'param_name' => 'h4',               'type' => 'textfield', 'description' => 'Sub-heading text.' ],
				[ 'param_name' => 'shape',            'type' => 'dropdown',  'description' => 'Block shape: "rounded", "square", "round".' ],
				[ 'param_name' => 'style',            'type' => 'dropdown',  'description' => 'Block style: "flat", "outline", "3d", "classic", "custom".' ],
				[ 'param_name' => 'color',            'type' => 'dropdown',  'description' => 'Block color.' ],
				[ 'param_name' => 'size',             'type' => 'dropdown',  'description' => 'Block size: "lg", "md", "sm", "xs".' ],
				[ 'param_name' => 'add_button',       'type' => 'dropdown',  'description' => 'Button position: "", "left", "right", "bottom", "top".' ],
				[ 'param_name' => 'btn_title',        'type' => 'textfield', 'description' => 'Button label text.' ],
				[ 'param_name' => 'btn_color',        'type' => 'dropdown',  'description' => 'Button color.' ],
				[ 'param_name' => 'btn_link',         'type' => 'link',      'description' => 'Button URL and target.' ],
				[ 'param_name' => 'btn_align',        'type' => 'dropdown',  'description' => 'Button alignment.' ],
				[ 'param_name' => 'add_icon',         'type' => 'dropdown',  'description' => 'Icon position: "", "left", "right".' ],
				[ 'param_name' => 'type',             'type' => 'dropdown',  'description' => 'Icon library type.' ],
				[ 'param_name' => 'icon_fontawesome', 'type' => 'textfield', 'description' => 'FontAwesome icon class.' ],
				[ 'param_name' => 'css_animation',    'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',         'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],

		// ── WordPress ─────────────────────────────────────────────────────────
		[
			'name'        => 'vc_posts_grid',
			'title'       => 'Posts Grid',
			'category'    => 'wordpress',
			'description' => 'Display posts in a grid layout.',
			'params'      => [
				[ 'param_name' => 'loop',                    'type' => 'autocomplete', 'description' => 'Query string, e.g. "size:3|post_type:post".' ],
				[ 'param_name' => 'grid_columns_count',      'type' => 'number',   'description' => 'Number of columns.' ],
				[ 'param_name' => 'grid_layout',             'type' => 'textfield','description' => 'Grid item template string.' ],
				[ 'param_name' => 'grid_template_columns',   'type' => 'textfield','description' => 'Template CSS columns string.' ],
				[ 'param_name' => 'css_animation',           'type' => 'animation_style','description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',                'type' => 'el_class', 'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_widget_sidebar',
			'title'       => 'Sidebar',
			'category'    => 'wordpress',
			'description' => 'Render a registered WordPress sidebar widget area.',
			'params'      => [
				[ 'param_name' => 'sidebar_id', 'type' => 'dropdown', 'description' => 'Registered sidebar ID.' ],
				[ 'param_name' => 'el_class',   'type' => 'el_class', 'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_posts',
			'title'       => 'WP Posts Widget',
			'category'    => 'wordpress',
			'description' => 'Recent posts widget.',
			'params'      => [
				[ 'param_name' => 'title',     'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'number',    'type' => 'number',    'description' => 'Number of posts to show.' ],
				[ 'param_name' => 'show_date', 'type' => 'checkbox',  'description' => 'Show post date.' ],
				[ 'param_name' => 'el_class',  'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_search',
			'title'       => 'WP Search Widget',
			'category'    => 'wordpress',
			'description' => 'WordPress search form widget.',
			'params'      => [
				[ 'param_name' => 'title',    'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_meta',
			'title'       => 'WP Meta Widget',
			'category'    => 'wordpress',
			'description' => 'WordPress meta links (login, RSS, etc).',
			'params'      => [
				[ 'param_name' => 'title',    'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_recentcomments',
			'title'       => 'WP Recent Comments',
			'category'    => 'wordpress',
			'description' => 'Recent comments widget.',
			'params'      => [
				[ 'param_name' => 'title',    'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'number',   'type' => 'number',    'description' => 'Number of comments.' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_categories',
			'title'       => 'WP Categories Widget',
			'category'    => 'wordpress',
			'description' => 'Categories list widget.',
			'params'      => [
				[ 'param_name' => 'title',        'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'display',      'type' => 'dropdown',  'description' => 'Display as: "list", "dropdown".' ],
				[ 'param_name' => 'count',        'type' => 'checkbox',  'description' => 'Show post count.' ],
				[ 'param_name' => 'hierarchical', 'type' => 'checkbox',  'description' => 'Show hierarchy.' ],
				[ 'param_name' => 'el_class',     'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_archives',
			'title'       => 'WP Archives Widget',
			'category'    => 'wordpress',
			'description' => 'Monthly archives widget.',
			'params'      => [
				[ 'param_name' => 'title',           'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'type',            'type' => 'dropdown',  'description' => 'Archive type: "monthly", "yearly", "daily", "weekly".' ],
				[ 'param_name' => 'limit',           'type' => 'number',    'description' => 'Number of archive items.' ],
				[ 'param_name' => 'show_post_count', 'type' => 'checkbox',  'description' => 'Show post count.' ],
				[ 'param_name' => 'el_class',        'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_tagcloud',
			'title'       => 'WP Tag Cloud',
			'category'    => 'wordpress',
			'description' => 'Tag cloud widget.',
			'params'      => [
				[ 'param_name' => 'title',    'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'taxonomy', 'type' => 'dropdown',  'description' => 'Taxonomy slug (default: post_tag).' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_custommenu',
			'title'       => 'WP Custom Menu',
			'category'    => 'wordpress',
			'description' => 'WordPress custom navigation menu widget.',
			'params'      => [
				[ 'param_name' => 'title',    'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'nav_menu', 'type' => 'dropdown',  'description' => 'Navigation menu ID or slug.' ],
				[ 'param_name' => 'el_class', 'type' => 'el_class',  'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_wp_text_slidebar',
			'title'       => 'WP Text Slidebar',
			'category'    => 'wordpress',
			'description' => 'Text widget with slideout panel.',
			'params'      => [
				[ 'param_name' => 'title',     'type' => 'textfield', 'description' => 'Widget title.' ],
				[ 'param_name' => 'el_class',  'type' => 'el_class',  'description' => 'Extra CSS class.' ],
				[ 'param_name' => 'label_on',  'type' => 'textfield', 'description' => 'Label when open.' ],
				[ 'param_name' => 'label_off', 'type' => 'textfield', 'description' => 'Label when closed.' ],
			],
		],

		// ── Social ────────────────────────────────────────────────────────────
		[
			'name'        => 'vc_facebook',
			'title'       => 'Facebook Like Button',
			'category'    => 'social',
			'description' => 'Facebook Like / Share widget.',
			'params'      => [
				[ 'param_name' => 'type',          'type' => 'dropdown', 'description' => 'Button type: "like", "recommend".' ],
				[ 'param_name' => 'width',         'type' => 'number',   'description' => 'Widget width in pixels.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',      'type' => 'el_class', 'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_tweetmeme',
			'title'       => 'Tweet Button',
			'category'    => 'social',
			'description' => 'Twitter Tweet / share button.',
			'params'      => [
				[ 'param_name' => 'type',          'type' => 'dropdown', 'description' => 'Button type: "horizontal", "vertical", "none".' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',      'type' => 'el_class', 'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_googleplus',
			'title'       => 'Google+ Button',
			'category'    => 'social',
			'description' => 'Google+ share button (legacy).',
			'params'      => [
				[ 'param_name' => 'annotation',    'type' => 'dropdown', 'description' => 'Annotation: "none", "bubble", "inline".' ],
				[ 'param_name' => 'width',         'type' => 'number',   'description' => 'Widget width.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',      'type' => 'el_class', 'description' => 'Extra CSS class.' ],
			],
		],
		[
			'name'        => 'vc_pinterest',
			'title'       => 'Pinterest Button',
			'category'    => 'social',
			'description' => 'Pinterest Pin It button.',
			'params'      => [
				[ 'param_name' => 'type',          'type' => 'dropdown',     'description' => 'Button type: "horizontal", "vertical", "none".' ],
				[ 'param_name' => 'image',         'type' => 'attach_image', 'description' => 'Image to pin.' ],
				[ 'param_name' => 'description',   'type' => 'textfield',    'description' => 'Pin description text.' ],
				[ 'param_name' => 'css_animation', 'type' => 'animation_style', 'description' => 'CSS animation on scroll.' ],
				[ 'param_name' => 'el_class',      'type' => 'el_class',     'description' => 'Extra CSS class.' ],
			],
		],

	];
}
