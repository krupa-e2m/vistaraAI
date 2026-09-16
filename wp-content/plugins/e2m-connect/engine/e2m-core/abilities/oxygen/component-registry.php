<?php
/**
 * E2M Connect MCP – Oxygen Component Registry
 *
 * Read Oxygen Builder's full component library — all available component
 * types with their properties, categories, and metadata.
 *
 * Works with both Oxygen Builder (CT_VERSION) and Oxygen 6 (Breakdance
 * rebranded as "Oxygen 6" uses BREAKDANCE_VERSION). Falls back to the
 * full built-in component catalogue when the live Oxygen API is not
 * available at request time (e.g. outside the builder context).
 *
 * Actions:
 *   list       — list all known components (with optional category filter)
 *   get        — get full details for a single component by type slug
 *   categories — list all component categories
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/oxygen-component-registry', [
	'label'       => __( '[Oxygen] Component Registry', 'e2mconnect' ),
	'description' => 'Read Oxygen Builder\'s component library. List all components, filter by category, or get full details for a single component type (type slug, properties, required fields, responsive support).',
	'category'    => 'e2m-oxygen',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories' ],
				'description' => 'list — all components; get — single component by type; categories — list all categories.',
			],
			'type' => [
				'type'        => 'string',
				'description' => 'Component type slug for the get action, e.g. "ct_headline", "ct_link_button", "oxy_video".',
			],
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by category: structure, basic, media, interactive, header, wordpress, forms, advanced.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'components' => [ 'type' => 'array' ],
			'component'  => [ 'type' => 'object' ],
			'categories' => [ 'type' => 'array' ],
			'total'      => [ 'type' => 'integer' ],
			'source'     => [ 'type' => 'string', 'description' => '"live_api" or "built_in" — how components were detected.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_oxygen_component_registry',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Oxygen: Component Registry',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute oxygen-component-registry ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_oxygen_component_registry( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_oxygen() ) {
		return new WP_Error( 'oxygen_missing', __( 'Oxygen Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	// Resolve component list — prefer Respira_Oxygen_Component_Registry if available.
	$all_components = e2m_engine_oxygen_get_all_components();
	$source         = e2m_engine_oxygen_has_registry() ? 'live_api' : 'built_in';

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$category_filter = isset( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
			$filtered = $category_filter
				? array_values( array_filter( $all_components, fn( $c ) => ( $c['category'] ?? '' ) === $category_filter ) )
				: array_values( $all_components );

			return [
				'action'     => 'list',
				'components' => $filtered,
				'total'      => count( $filtered ),
				'source'     => $source,
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['type'] ) ) {
				return new WP_Error( 'missing_type', __( '"type" is required for get action.', 'e2mconnect' ) );
			}
			$type = sanitize_text_field( $input['type'] );

			if ( e2m_engine_oxygen_has_registry() ) {
				$component = Respira_Oxygen_Component_Registry::get_component( $type );
			} else {
				$component = e2m_engine_oxygen_find_component_builtin( $type, $all_components );
			}

			if ( ! $component ) {
				return new WP_Error( 'component_not_found', sprintf( __( 'No component found with type "%s".', 'e2mconnect' ), $type ) );
			}

			// Enrich with schema if available.
			if ( class_exists( 'Respira_Oxygen_Component_Schema' ) ) {
				$schema_gen = new Respira_Oxygen_Component_Schema();
				$schema     = $schema_gen->get_builder_schema( [ $type ] );
				$component['schema'] = $schema['available_components'][ $type ] ?? [];
			}

			return [
				'action'    => 'get',
				'component' => $component,
				'source'    => $source,
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_components as $c ) {
				$cat = $c['category'] ?? 'general';
				if ( ! isset( $cats[ $cat ] ) ) {
					$cats[ $cat ] = 0;
				}
				$cats[ $cat ]++;
			}
			$result = [];
			foreach ( $cats as $name => $count ) {
				$result[] = [ 'category' => $name, 'component_count' => $count ];
			}
			usort( $result, fn( $a, $b ) => $b['component_count'] - $a['component_count'] );

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
// Shared helpers (used by other oxygen ability files too)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Check if Respira_Oxygen_Component_Registry is available.
 */
function e2m_engine_oxygen_has_registry(): bool {
	return class_exists( 'Respira_Oxygen_Component_Registry' );
}

/**
 * Get all Oxygen components — via Respira class or built-in catalogue.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_oxygen_get_all_components(): array {
	if ( e2m_engine_oxygen_has_registry() ) {
		return (array) Respira_Oxygen_Component_Registry::get_all_components();
	}
	return e2m_engine_oxygen_builtin_components();
}

/**
 * Find a component in the built-in list by type or name.
 *
 * @param string             $type
 * @param array<int,array>   $all
 * @return array<string,mixed>|null
 */
function e2m_engine_oxygen_find_component_builtin( string $type, array $all ): ?array {
	foreach ( $all as $c ) {
		if ( ( $c['type'] ?? '' ) === $type || strtolower( $c['name'] ?? '' ) === strtolower( $type ) ) {
			return $c;
		}
	}
	return null;
}

/**
 * Full built-in Oxygen component catalogue (mirrors Respira_Oxygen_Component_Registry).
 * Used as fallback when the Respira intelligence classes are not loaded.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_oxygen_builtin_components(): array {
	return [
		// ── Structure ─────────────────────────────────────────────────────────
		[ 'name' => 'Section',         'type' => 'ct_section',           'category' => 'structure',   'description' => 'Section container.',                                    'properties' => [ 'width', 'height', 'background-color', 'padding' ] ],
		[ 'name' => 'Div',             'type' => 'ct_div_block',         'category' => 'structure',   'description' => 'Div block container.',                                  'properties' => [ 'width', 'height', 'background-color', 'padding', 'margin' ] ],
		[ 'name' => 'Div Block 2',     'type' => 'ct_div_block_2',       'category' => 'structure',   'description' => 'Alternate div block container.',                        'properties' => [ 'width', 'height', 'background-color', 'padding', 'margin' ] ],
		[ 'name' => 'Columns',         'type' => 'ct_new_columns',       'category' => 'structure',   'description' => 'Column layout container.',                              'properties' => [ 'column-count', 'column-gap', 'column-width' ] ],
		[ 'name' => 'Column',          'type' => 'ct_column',            'category' => 'structure',   'description' => 'Individual column.',                                    'properties' => [ 'width', 'padding', 'background-color' ] ],
		[ 'name' => 'Link Wrapper',    'type' => 'ct_link_wrapper',      'category' => 'structure',   'description' => 'Link container that wraps children in an anchor tag.',  'properties' => [ 'url', 'target', 'color' ] ],
		[ 'name' => 'Span',            'type' => 'ct_span',              'category' => 'structure',   'description' => 'Inline span element for text styling.',                 'properties' => [ 'ct_content', 'color', 'font-size', 'font-weight' ] ],
		[ 'name' => 'Inner Content',   'type' => 'ct_inner_content',     'category' => 'structure',   'description' => 'Inner content area for templates.',                     'properties' => [] ],

		// ── Basic ─────────────────────────────────────────────────────────────
		[ 'name' => 'Heading',         'type' => 'ct_headline',          'category' => 'basic',       'description' => 'Heading element (H1-H6).',                              'properties' => [ 'ct_content', 'tag', 'font-size', 'color', 'font-family' ], 'required' => [ 'ct_content' ] ],
		[ 'name' => 'Text',            'type' => 'ct_text_block',        'category' => 'basic',       'description' => 'Rich text block.',                                      'properties' => [ 'ct_content', 'font-size', 'color', 'line-height' ],       'required' => [ 'ct_content' ] ],
		[ 'name' => 'Link',            'type' => 'ct_link',              'category' => 'basic',       'description' => 'Link element.',                                         'properties' => [ 'ct_content', 'url', 'target', 'color' ],                  'required' => [ 'ct_content', 'url' ] ],
		[ 'name' => 'Button',          'type' => 'ct_link_button',       'category' => 'basic',       'description' => 'Button element.',                                       'properties' => [ 'ct_content', 'url', 'target', 'background-color', 'color', 'padding' ], 'required' => [ 'ct_content', 'url' ] ],
		[ 'name' => 'Icon',            'type' => 'ct_fancy_icon',        'category' => 'basic',       'description' => 'Icon element.',                                         'properties' => [ 'icon-id', 'icon-size', 'icon-color', 'url' ] ],
		[ 'name' => 'Text Link',       'type' => 'ct_link_text',         'category' => 'basic',       'description' => 'Inline text link.',                                     'properties' => [ 'ct_content', 'url', 'target', 'color', 'font-size' ] ],
		[ 'name' => 'Fancy Image',     'type' => 'ct_fancy_image',       'category' => 'basic',       'description' => 'Advanced image with hover effects and lightbox.',        'properties' => [ 'src', 'alt', 'width', 'height', 'object-fit', 'url', 'target' ] ],

		// ── Media ─────────────────────────────────────────────────────────────
		[ 'name' => 'Image',           'type' => 'ct_image',             'category' => 'media',       'description' => 'Image element.',                                        'properties' => [ 'src', 'alt', 'width', 'height', 'object-fit' ],           'required' => [ 'src' ] ],
		[ 'name' => 'Video',           'type' => 'oxy_video',            'category' => 'media',       'description' => 'Video element.',                                        'properties' => [ 'url', 'embed_code', 'autoplay', 'controls' ],              'required' => [ 'url' ] ],
		[ 'name' => 'Audio',           'type' => 'oxy_audio',            'category' => 'media',       'description' => 'Audio player.',                                         'properties' => [ 'url', 'autoplay', 'loop' ] ],
		[ 'name' => 'Gallery',         'type' => 'oxy_gallery',          'category' => 'media',       'description' => 'Image gallery.',                                        'properties' => [ 'images', 'columns', 'spacing' ] ],

		// ── Interactive ───────────────────────────────────────────────────────
		[ 'name' => 'Tabs',            'type' => 'oxy_tabs',             'category' => 'interactive', 'description' => 'Tabbed content.',                                       'properties' => [ 'tabs', 'active_tab', 'orientation' ] ],
		[ 'name' => 'Accordion',       'type' => 'oxy_accordion',        'category' => 'interactive', 'description' => 'Accordion content.',                                    'properties' => [ 'items', 'active_item', 'multiple_open' ] ],
		[ 'name' => 'Toggle',          'type' => 'oxy_toggle',           'category' => 'interactive', 'description' => 'Toggle content.',                                       'properties' => [ 'heading', 'content', 'open' ] ],
		[ 'name' => 'Modal',           'type' => 'oxy_modal',            'category' => 'interactive', 'description' => 'Modal popup.',                                          'properties' => [ 'trigger', 'content', 'width', 'overlay' ] ],
		[ 'name' => 'Slider',          'type' => 'ct_slider',            'category' => 'interactive', 'description' => 'Slider container for slides.',                          'properties' => [ 'autoplay', 'speed', 'arrows', 'dots', 'loop' ] ],
		[ 'name' => 'Slide',           'type' => 'ct_slide',             'category' => 'interactive', 'description' => 'Individual slide.',                                     'properties' => [ 'background-color', 'background-image', 'padding' ] ],
		[ 'name' => 'Easy Posts',      'type' => 'oxy_dynamic_list',     'category' => 'interactive', 'description' => 'Query loop with PHP template for post listings.',       'properties' => [ 'query_type', 'post_type', 'posts_per_page', 'order_by', 'order', 'columns' ] ],

		// ── Header ────────────────────────────────────────────────────────────
		[ 'name' => 'Header Row',      'type' => 'ct_header_row',        'category' => 'header',      'description' => 'Header builder row container.',                         'properties' => [ 'width', 'height', 'background-color', 'padding', 'position' ] ],
		[ 'name' => 'Header Left',     'type' => 'oxy_header_left',      'category' => 'header',      'description' => 'Left zone of header row.',                              'properties' => [ 'width', 'padding' ] ],
		[ 'name' => 'Header Center',   'type' => 'oxy_header_center',    'category' => 'header',      'description' => 'Center zone of header row.',                            'properties' => [ 'width', 'padding' ] ],
		[ 'name' => 'Header Right',    'type' => 'oxy_header_right',     'category' => 'header',      'description' => 'Right zone of header row.',                             'properties' => [ 'width', 'padding' ] ],

		// ── WordPress ─────────────────────────────────────────────────────────
		[ 'name' => 'Repeater',        'type' => 'oxy_repeater',         'category' => 'wordpress',   'description' => 'Query loop / repeater.',                                'properties' => [ 'query_type', 'post_type', 'posts_per_page', 'order_by', 'order' ] ],
		[ 'name' => 'Post Title',      'type' => 'oxy_post_title',       'category' => 'wordpress',   'description' => 'Dynamic post title.',                                   'properties' => [ 'tag', 'font-size', 'color' ] ],
		[ 'name' => 'Post Content',    'type' => 'oxy_post_content',     'category' => 'wordpress',   'description' => 'Dynamic post content.',                                 'properties' => [ 'font-size', 'color', 'line-height' ] ],
		[ 'name' => 'Featured Image',  'type' => 'oxy_featured_image',   'category' => 'wordpress',   'description' => 'Dynamic featured image.',                               'properties' => [ 'size', 'width', 'height' ] ],
		[ 'name' => 'Menu',            'type' => 'oxy_pro_menu',         'category' => 'wordpress',   'description' => 'WordPress menu.',                                       'properties' => [ 'menu', 'orientation', 'mobile_breakpoint' ] ],
		[ 'name' => 'Post Excerpt',    'type' => 'oxy_post_excerpt',     'category' => 'wordpress',   'description' => 'Dynamic post excerpt.',                                 'properties' => [ 'font-size', 'color', 'line-height' ] ],
		[ 'name' => 'Post Date',       'type' => 'oxy_post_date',        'category' => 'wordpress',   'description' => 'Dynamic post date.',                                    'properties' => [ 'font-size', 'color' ] ],
		[ 'name' => 'Post Author',     'type' => 'oxy_post_author',      'category' => 'wordpress',   'description' => 'Dynamic post author name.',                             'properties' => [ 'font-size', 'color' ] ],
		[ 'name' => 'Post Terms',      'type' => 'oxy_post_terms',       'category' => 'wordpress',   'description' => 'Dynamic post taxonomy terms.',                          'properties' => [ 'font-size', 'color' ] ],
		[ 'name' => 'Post Meta',       'type' => 'oxy_post_meta',        'category' => 'wordpress',   'description' => 'Dynamic post custom field value.',                      'properties' => [ 'font-size', 'color' ] ],
		[ 'name' => 'Comments',        'type' => 'oxy_post_comments',    'category' => 'wordpress',   'description' => 'Post comments section.',                                'properties' => [ 'font-size', 'color' ] ],
		[ 'name' => 'Breadcrumb',      'type' => 'oxy_breadcrumb',       'category' => 'wordpress',   'description' => 'Breadcrumb navigation.',                                'properties' => [ 'font-size', 'color' ] ],
		[ 'name' => 'Widget',          'type' => 'ct_widget',            'category' => 'wordpress',   'description' => 'WordPress widget.',                                     'properties' => [] ],

		// ── Forms ─────────────────────────────────────────────────────────────
		[ 'name' => 'Form',            'type' => 'oxy_contact_form',     'category' => 'forms',       'description' => 'Contact form.',                                         'properties' => [ 'fields', 'submit_button_text', 'email_to' ] ],
		[ 'name' => 'Input',           'type' => 'ct_input',             'category' => 'forms',       'description' => 'Form input field.',                                     'properties' => [ 'type', 'name', 'placeholder', 'required' ] ],
		[ 'name' => 'Textarea',        'type' => 'ct_textarea',          'category' => 'forms',       'description' => 'Form textarea.',                                        'properties' => [ 'name', 'placeholder', 'rows', 'required' ] ],

		// ── Advanced ──────────────────────────────────────────────────────────
		[ 'name' => 'Progress Bar',    'type' => 'oxy_progress_bar',     'category' => 'advanced',    'description' => 'Animated progress bar.',                                'properties' => [ 'percent', 'height', 'color', 'background-color' ] ],
		[ 'name' => 'Counter',         'type' => 'oxy_counter',          'category' => 'advanced',    'description' => 'Animated counter.',                                     'properties' => [ 'start', 'end', 'duration', 'prefix', 'suffix' ] ],
		[ 'name' => 'Pricing Box',     'type' => 'oxy_pricing_box',      'category' => 'advanced',    'description' => 'Pricing table.',                                        'properties' => [ 'title', 'price', 'features', 'button_text', 'button_url' ] ],
		[ 'name' => 'Testimonial',     'type' => 'oxy_testimonial',      'category' => 'advanced',    'description' => 'Testimonial component.',                                'properties' => [ 'content', 'author', 'photo', 'rating' ] ],
		[ 'name' => 'Icon Box',        'type' => 'oxy_icon_box',         'category' => 'advanced',    'description' => 'Icon with title and description.',                      'properties' => [ 'icon', 'title', 'description', 'url' ] ],
		[ 'name' => 'Google Map',      'type' => 'oxy_map',              'category' => 'advanced',    'description' => 'Google Maps embed.',                                    'properties' => [ 'address', 'zoom', 'height', 'marker' ] ],
		[ 'name' => 'Code Block',      'type' => 'ct_code_block',        'category' => 'advanced',    'description' => 'Custom code (HTML/CSS/JS).',                            'properties' => [ 'ct_content', 'language' ],                                'required' => [ 'ct_content' ] ],
		[ 'name' => 'Shortcode',       'type' => 'ct_shortcode',         'category' => 'advanced',    'description' => 'WordPress shortcode.',                                  'properties' => [ 'shortcode' ] ],
		[ 'name' => 'Nestable Shortcode', 'type' => 'ct_nestable_shortcode', 'category' => 'advanced', 'description' => 'Nestable shortcode wrapper.',                         'properties' => [ 'shortcode' ] ],
		[ 'name' => 'Superbox',        'type' => 'oxy_superbox',         'category' => 'advanced',    'description' => 'Container with hover overlay effect.',                  'properties' => [ 'width', 'height', 'background-color', 'overlay' ] ],
		[ 'name' => 'Flip Box',        'type' => 'oxy_flip_box',         'category' => 'advanced',    'description' => 'Front/back flip animation container.',                  'properties' => [ 'width', 'height', 'speed', 'orientation' ] ],
		[ 'name' => 'Table of Contents', 'type' => 'oxy_table_of_contents', 'category' => 'advanced', 'description' => 'Auto-generated table of contents.',                   'properties' => [ 'font-size', 'color', 'background-color' ] ],
		[ 'name' => 'Mega Menu',       'type' => 'oxy_mega_menu',        'category' => 'advanced',    'description' => 'Mega menu with column layouts.',                        'properties' => [ 'menu', 'orientation', 'mobile_breakpoint', 'columns' ] ],
		[ 'name' => 'Logo Slider',     'type' => 'oxy_logo_slider',      'category' => 'advanced',    'description' => 'Logo carousel / slider.',                               'properties' => [ 'images', 'autoplay', 'speed', 'columns', 'spacing' ] ],
		[ 'name' => 'Number Counter',  'type' => 'oxy_number_counter',   'category' => 'advanced',    'description' => 'Animated number counter.',                              'properties' => [ 'start', 'end', 'duration', 'prefix', 'suffix' ] ],

		// ── Slider (posts) ────────────────────────────────────────────────────
		[ 'name' => 'Posts Slider',    'type' => 'oxy_easy_posts_slider','category' => 'interactive', 'description' => 'Content slider with posts query.',                     'properties' => [ 'slides', 'autoplay', 'speed', 'arrows', 'dots' ] ],
	];
}
