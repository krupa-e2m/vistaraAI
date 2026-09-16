<?php
/**
 * E2M Connect MCP – Beaver Builder Module Registry
 *
 * Read Beaver Builder's full module library — all available module types
 * with their properties, categories, and metadata.
 *
 * Delegates to Respira_Beaver_Module_Registry when available, and falls
 * back to the full built-in module catalogue otherwise.
 *
 * Actions:
 *   list       — list all known modules (with optional category filter)
 *   get        — get full details for a single module by type slug
 *   categories — list all module categories
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/beaver-module-registry', [
	'label'       => __( '[Beaver] Module Registry', 'e2mconnect' ),
	'description' => 'Read Beaver Builder\'s module library. List all modules, filter by category, or get full details for a single module type (type slug, properties, description, category).',
	'category'    => 'e2m-beaver',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories' ],
				'description' => 'list — all modules; get — single module by module_type; categories — list all categories.',
			],
			'module_type' => [
				'type'        => 'string',
				'description' => 'Module type slug for the get action, e.g. "heading", "html", "button".',
			],
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by category: basic, media, advanced, posts, wordpress, social.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'modules'  => [ 'type' => 'array' ],
			'module'   => [ 'type' => 'object' ],
			'categories' => [ 'type' => 'array' ],
			'total'    => [ 'type' => 'integer' ],
			'source'   => [ 'type' => 'string', 'description' => '"live_api" or "built_in" — how modules were detected.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_beaver_module_registry',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Beaver: Module Registry',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute beaver-module-registry ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_beaver_module_registry( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_beaver() ) {
		return new WP_Error( 'beaver_missing', __( 'Beaver Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	// Resolve module list — prefer Respira_Beaver_Module_Registry if available.
	$source      = class_exists( 'Respira_Beaver_Module_Registry' ) ? 'live_api' : 'built_in';
	$all_modules = e2m_engine_beaver_get_all_modules();

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$category_filter = isset( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
			$filtered        = $category_filter
				? array_values( array_filter( $all_modules, fn( $m ) => ( $m['category'] ?? '' ) === $category_filter ) )
				: array_values( $all_modules );

			return [
				'action'  => 'list',
				'modules' => $filtered,
				'total'   => count( $filtered ),
				'source'  => $source,
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['module_type'] ) ) {
				return new WP_Error( 'missing_module_type', __( '"module_type" is required for the get action.', 'e2mconnect' ) );
			}
			$type = sanitize_text_field( $input['module_type'] );

			if ( class_exists( 'Respira_Beaver_Module_Registry' ) ) {
				$module = Respira_Beaver_Module_Registry::get_module( $type );
			} else {
				$module = e2m_engine_beaver_find_module_builtin( $type );
			}

			if ( ! $module ) {
				return new WP_Error(
					'module_not_found',
					sprintf( __( 'No module found with type "%s".', 'e2mconnect' ), $type )
				);
			}

			return [
				'action' => 'get',
				'module' => $module,
				'source' => $source,
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_modules as $m ) {
				$cat = $m['category'] ?? 'general';
				if ( ! isset( $cats[ $cat ] ) ) {
					$cats[ $cat ] = 0;
				}
				$cats[ $cat ]++;
			}
			$result = [];
			foreach ( $cats as $name => $count ) {
				$result[] = [ 'category' => $name, 'module_count' => $count ];
			}
			usort( $result, fn( $a, $b ) => $b['module_count'] - $a['module_count'] );

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
// Shared helpers (used by other beaver ability files too)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get all Beaver Builder modules — via Respira class or built-in catalogue.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_beaver_get_all_modules(): array {
	if ( class_exists( 'Respira_Beaver_Module_Registry' ) ) {
		return (array) Respira_Beaver_Module_Registry::get_all_modules();
	}
	return e2m_engine_beaver_builtin_modules();
}

/**
 * Find a module in the built-in list by type slug.
 *
 * @param string $type  Module type slug.
 * @return array<string,mixed>|null
 */
function e2m_engine_beaver_find_module_builtin( string $type ): ?array {
	foreach ( e2m_engine_beaver_builtin_modules() as $m ) {
		if ( ( $m['type'] ?? '' ) === $type ) {
			return $m;
		}
	}
	return null;
}

/**
 * Full built-in Beaver Builder module catalogue.
 * Used as fallback when Respira_Beaver_Module_Registry is not loaded.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_beaver_builtin_modules(): array {
	return [
		// ── Basic ─────────────────────────────────────────────────────────────
		[
			'name'        => 'Heading',
			'title'       => 'Heading',
			'type'        => 'heading',
			'category'    => 'basic',
			'description' => 'Heading element (H1-H6) with typography controls.',
			'properties'  => [ 'heading', 'tag', 'link', 'link_target', 'link_nofollow', 'align', 'color', 'font_size', 'line_height', 'letter_spacing', 'text_transform' ],
		],
		[
			'name'        => 'Photo',
			'title'       => 'Photo',
			'type'        => 'photo',
			'category'    => 'basic',
			'description' => 'Single image/photo element with alignment and link support.',
			'properties'  => [ 'photo', 'photo_src', 'align', 'caption', 'link_type', 'link', 'link_target', 'width', 'height', 'photo_crop' ],
		],
		[
			'name'        => 'Rich Text',
			'title'       => 'Rich Text',
			'type'        => 'rich-text',
			'category'    => 'basic',
			'description' => 'WYSIWYG rich text / HTML editor block.',
			'properties'  => [ 'text', 'align', 'font_size', 'line_height', 'text_color' ],
		],
		[
			'name'        => 'HTML',
			'title'       => 'HTML',
			'type'        => 'html',
			'category'    => 'basic',
			'description' => 'Raw HTML / shortcode block.',
			'properties'  => [ 'html' ],
		],
		[
			'name'        => 'Button',
			'title'       => 'Button',
			'type'        => 'button',
			'category'    => 'basic',
			'description' => 'Clickable button with style and link options.',
			'properties'  => [ 'text', 'button_url', 'link_target', 'link_nofollow', 'align', 'width', 'bg_color', 'text_color', 'border_radius', 'padding_top', 'padding_bottom', 'padding_left', 'padding_right' ],
		],
		[
			'name'        => 'Icon',
			'title'       => 'Icon',
			'type'        => 'icon',
			'category'    => 'basic',
			'description' => 'Icon element from a font icon library with optional link.',
			'properties'  => [ 'icon', 'link', 'link_target', 'link_nofollow', 'color', 'size', 'align', 'bg_color', 'bg_size', 'border_radius' ],
		],
		[
			'name'        => 'Separator',
			'title'       => 'Separator',
			'type'        => 'separator',
			'category'    => 'basic',
			'description' => 'Horizontal rule / divider element.',
			'properties'  => [ 'color', 'height', 'width', 'align' ],
		],
		[
			'name'        => 'Spacer',
			'title'       => 'Spacer',
			'type'        => 'spacer',
			'category'    => 'basic',
			'description' => 'Vertical spacer / whitespace block.',
			'properties'  => [ 'height', 'responsive_height', 'mobile_height' ],
		],

		// ── Media ─────────────────────────────────────────────────────────────
		[
			'name'        => 'Video',
			'title'       => 'Video',
			'type'        => 'video',
			'category'    => 'media',
			'description' => 'Embeds a video (YouTube, Vimeo, or self-hosted) with autoplay and controls.',
			'properties'  => [ 'video_type', 'video_url', 'embed_code', 'auto_play', 'controls', 'loop', 'mute', 'width', 'align' ],
		],
		[
			'name'        => 'Audio',
			'title'       => 'Audio',
			'type'        => 'audio',
			'category'    => 'media',
			'description' => 'HTML5 audio player.',
			'properties'  => [ 'audio', 'audio_url', 'auto_play', 'loop', 'align' ],
		],
		[
			'name'        => 'Gallery',
			'title'       => 'Gallery',
			'type'        => 'gallery',
			'category'    => 'media',
			'description' => 'Image gallery with multiple layout styles.',
			'properties'  => [ 'photos', 'layout', 'columns', 'photo_size', 'photo_crop', 'click_action', 'caption_type' ],
		],
		[
			'name'        => 'Slideshow',
			'title'       => 'Slideshow',
			'type'        => 'slideshow',
			'category'    => 'media',
			'description' => 'Auto-advancing image slideshow with configurable speed and transitions.',
			'properties'  => [ 'photos', 'auto_play', 'speed', 'pause', 'transitionspeed', 'transition', 'arrows', 'dots', 'height' ],
		],
		[
			'name'        => 'Map',
			'title'       => 'Map',
			'type'        => 'map',
			'category'    => 'media',
			'description' => 'Embeds a Google Map with address and zoom controls.',
			'properties'  => [ 'address', 'zoom', 'height', 'map_type', 'show_controls', 'show_info_window' ],
		],

		// ── Advanced ──────────────────────────────────────────────────────────
		[
			'name'        => 'Accordion',
			'title'       => 'Accordion',
			'type'        => 'accordion',
			'category'    => 'advanced',
			'description' => 'Expandable accordion panels.',
			'properties'  => [ 'items', 'click_to_close', 'first_open', 'animation', 'label_color', 'content_text_color', 'border_color' ],
		],
		[
			'name'        => 'Tabs',
			'title'       => 'Tabs',
			'type'        => 'tabs',
			'category'    => 'advanced',
			'description' => 'Tabbed content panels.',
			'properties'  => [ 'tabs', 'layout', 'active_tab', 'label_active_color', 'label_inactive_color', 'content_text_color', 'border_color' ],
		],
		[
			'name'        => 'Contact Form',
			'title'       => 'Contact Form',
			'type'        => 'contact-form',
			'category'    => 'advanced',
			'description' => 'Simple AJAX contact form with field builder.',
			'properties'  => [ 'fields', 'submit_button_text', 'success_message', 'to', 'from', 'reply_to', 'subject' ],
		],
		[
			'name'        => 'Testimonials',
			'title'       => 'Testimonials',
			'type'        => 'testimonials',
			'category'    => 'advanced',
			'description' => 'Testimonial display with optional slider.',
			'properties'  => [ 'items', 'layout', 'auto_play', 'speed', 'align', 'name_color', 'text_color', 'company_color' ],
		],
		[
			'name'        => 'Pricing Table',
			'title'       => 'Pricing Table',
			'type'        => 'pricing-table',
			'category'    => 'advanced',
			'description' => 'Pricing table columns with feature lists.',
			'properties'  => [ 'columns', 'layout', 'align', 'title_color', 'amount_color', 'duration_color', 'features_text_color', 'button_text_color', 'button_bg_color' ],
		],
		[
			'name'        => 'Subscribe Form',
			'title'       => 'Subscribe Form',
			'type'        => 'subscribe-form',
			'category'    => 'advanced',
			'description' => 'Email list subscribe / opt-in form.',
			'properties'  => [ 'layout', 'show_name', 'submit_button_text', 'success_message', 'provider', 'show_labels', 'btn_bg_color', 'btn_text_color' ],
		],
		[
			'name'        => 'Icon Group',
			'title'       => 'Icon Group',
			'type'        => 'icon-group',
			'category'    => 'advanced',
			'description' => 'Row of icons displayed together.',
			'properties'  => [ 'icons', 'align', 'gap', 'size', 'color', 'hover_color', 'bg_color', 'hover_bg_color' ],
		],
		[
			'name'        => 'Callout',
			'title'       => 'Callout',
			'type'        => 'callout',
			'category'    => 'advanced',
			'description' => 'Callout box with heading, text, icon, and CTA button.',
			'properties'  => [ 'title', 'text', 'cta_type', 'link', 'link_target', 'icon', 'icon_color', 'icon_size', 'title_color', 'text_color' ],
		],
		[
			'name'        => 'Number Counter',
			'title'       => 'Number Counter',
			'type'        => 'number-counter',
			'category'    => 'advanced',
			'description' => 'Animated number counter with prefix and suffix.',
			'properties'  => [ 'number', 'max_number', 'prefix', 'suffix', 'speed', 'label', 'number_color', 'label_color', 'align' ],
		],

		// ── Posts ─────────────────────────────────────────────────────────────
		[
			'name'        => 'Post Grid',
			'title'       => 'Post Grid',
			'type'        => 'post-grid',
			'category'    => 'posts',
			'description' => 'Displays posts in a configurable grid layout.',
			'properties'  => [ 'layout', 'posts_per_page', 'post_type', 'order', 'order_by', 'categories', 'authors', 'show_image', 'show_title', 'show_excerpt', 'show_date', 'show_author', 'columns' ],
		],
		[
			'name'        => 'Post Slider',
			'title'       => 'Post Slider',
			'type'        => 'post-slider',
			'category'    => 'posts',
			'description' => 'Posts displayed in a sliding carousel.',
			'properties'  => [ 'layout', 'posts_per_page', 'post_type', 'order', 'order_by', 'categories', 'auto_play', 'speed', 'pause', 'arrows', 'dots', 'show_title', 'show_excerpt' ],
		],

		// ── WordPress ─────────────────────────────────────────────────────────
		[
			'name'        => 'Menu',
			'title'       => 'Menu',
			'type'        => 'menu',
			'category'    => 'wordpress',
			'description' => 'Renders a WordPress navigation menu.',
			'properties'  => [ 'menu', 'menu_layout', 'link_color', 'link_hover_color', 'mobile_breakpoint' ],
		],
		[
			'name'        => 'Sidebar',
			'title'       => 'Sidebar',
			'type'        => 'sidebar',
			'category'    => 'wordpress',
			'description' => 'Outputs a registered WordPress widget area sidebar.',
			'properties'  => [ 'sidebar' ],
		],

		// ── Social ────────────────────────────────────────────────────────────
		[
			'name'        => 'Social Buttons',
			'title'       => 'Social Buttons',
			'type'        => 'social-buttons',
			'category'    => 'social',
			'description' => 'Social sharing buttons for major networks.',
			'properties'  => [ 'networks', 'layout', 'size', 'align', 'show_count' ],
		],
	];
}
