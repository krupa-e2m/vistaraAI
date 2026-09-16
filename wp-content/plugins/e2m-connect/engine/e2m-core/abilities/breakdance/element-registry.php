<?php
/**
 * E2M Connect MCP – Breakdance Element Registry
 *
 * Read Breakdance Builder's full element library — all available element types
 * with their properties, categories, and metadata.
 *
 * Delegates to Respira_Breakdance_Element_Registry when available, and falls
 * back to the full built-in element catalogue otherwise.
 *
 * Actions:
 *   list       — list all known elements (with optional category filter)
 *   get        — get full details for a single element by type string
 *   categories — list all element categories
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/breakdance-element-registry', [
	'label'       => __( '[Breakdance] Element Registry', 'e2mconnect' ),
	'description' => 'Read Breakdance Builder\'s element library. List all elements, filter by category, or get full details for a single element type (namespaced type string, properties, description, category).',
	'category'    => 'e2m-breakdance',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories' ],
				'description' => 'list — all elements; get — single element by element_type; categories — list all categories.',
			],
			'element_type' => [
				'type'        => 'string',
				'description' => 'Namespaced element type for the get action, e.g. "EssentialElements\\Section", "EssentialElements\\Heading".',
			],
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by category: basics, blocks, site, advanced, dynamic, forms, woocommerce.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'elements'   => [ 'type' => 'array' ],
			'element'    => [ 'type' => 'object' ],
			'categories' => [ 'type' => 'array' ],
			'total'      => [ 'type' => 'integer' ],
			'source'     => [ 'type' => 'string', 'description' => '"live_api" or "built_in" — how elements were detected.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_breakdance_element_registry',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Breakdance: Element Registry',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute breakdance-element-registry ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_breakdance_element_registry( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_breakdance() ) {
		return new WP_Error( 'breakdance_missing', __( 'Breakdance Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	$source       = class_exists( 'Respira_Breakdance_Element_Registry' ) ? 'live_api' : 'built_in';
	$all_elements = e2m_engine_breakdance_get_all_elements();

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$category_filter = isset( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
			$filtered        = $category_filter
				? array_values( array_filter( $all_elements, fn( $e ) => ( $e['category'] ?? '' ) === $category_filter ) )
				: array_values( $all_elements );

			return [
				'action'   => 'list',
				'elements' => $filtered,
				'total'    => count( $filtered ),
				'source'   => $source,
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['element_type'] ) ) {
				return new WP_Error( 'missing_element_type', __( '"element_type" is required for get action.', 'e2mconnect' ) );
			}
			$type = sanitize_text_field( $input['element_type'] );

			if ( class_exists( 'Respira_Breakdance_Element_Registry' ) ) {
				$element = Respira_Breakdance_Element_Registry::get_element( $type );
			} else {
				$element = e2m_engine_breakdance_find_element( $type );
			}

			if ( ! $element ) {
				return new WP_Error( 'element_not_found', sprintf( __( 'No element found with type "%s".', 'e2mconnect' ), $type ) );
			}

			// Enrich with schema if available.
			if ( class_exists( 'Respira_Breakdance_Element_Schema' ) ) {
				$schema_gen        = new Respira_Breakdance_Element_Schema();
				$schema            = $schema_gen->get_builder_schema( [ $type ] );
				$element['schema'] = $schema[ $type ] ?? [];
			}

			return [
				'action'  => 'get',
				'element' => $element,
				'source'  => $source,
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_elements as $e ) {
				$cat = $e['category'] ?? 'general';
				if ( ! isset( $cats[ $cat ] ) ) {
					$cats[ $cat ] = 0;
				}
				$cats[ $cat ]++;
			}
			$result = [];
			foreach ( $cats as $name => $count ) {
				$result[] = [ 'category' => $name, 'element_count' => $count ];
			}
			usort( $result, fn( $a, $b ) => $b['element_count'] - $a['element_count'] );

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
// Shared helpers (used by other breakdance ability files too)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get all Breakdance elements — via Respira class or built-in catalogue.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_breakdance_get_all_elements(): array {
	if ( class_exists( 'Respira_Breakdance_Element_Registry' ) ) {
		return (array) Respira_Breakdance_Element_Registry::get_all_elements();
	}
	return e2m_engine_breakdance_builtin_elements();
}

/**
 * Find a single element in the built-in catalogue by type or name.
 *
 * @param string $type  Namespaced type string or short name.
 * @return array<string,mixed>|null
 */
function e2m_engine_breakdance_find_element( string $type ): ?array {
	// Normalize: accept both "EssentialElements/Heading" and "EssentialElements\\Heading".
	$normalised = str_replace( '/', '\\', $type );
	foreach ( e2m_engine_breakdance_builtin_elements() as $e ) {
		$element_type = str_replace( '/', '\\', (string) ( $e['type'] ?? '' ) );
		if ( $element_type === $normalised || strtolower( $e['name'] ?? '' ) === strtolower( $type ) ) {
			return $e;
		}
	}
	return null;
}

/**
 * Full built-in Breakdance element catalogue (mirrors Respira_Breakdance_Element_Registry).
 * Used as fallback when the Respira intelligence classes are not loaded.
 * WooCommerce elements are appended only when WooCommerce is active.
 *
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_breakdance_builtin_elements(): array {
	$elements = [

		// ── Basics ────────────────────────────────────────────────────────────
		[
			'name'        => 'Section',
			'title'       => 'Section',
			'type'        => 'EssentialElements\\Section',
			'category'    => 'basics',
			'description' => 'Top-level section container. Accepts Columns as direct children.',
			'properties'  => [ 'backgroundColor', 'padding', 'margin', 'width', 'height', 'display', 'flexDirection', 'justifyContent', 'alignItems' ],
		],
		[
			'name'        => 'Columns',
			'title'       => 'Columns',
			'type'        => 'EssentialElements\\Columns',
			'category'    => 'basics',
			'description' => 'Columns layout container. Must contain Column children.',
			'properties'  => [ 'gap', 'backgroundColor', 'padding', 'gridTemplateColumns', 'alignItems' ],
		],
		[
			'name'        => 'Column',
			'title'       => 'Column',
			'type'        => 'EssentialElements\\Column',
			'category'    => 'basics',
			'description' => 'Individual column inside a Columns element.',
			'properties'  => [ 'width', 'padding', 'backgroundColor', 'flexDirection', 'gap' ],
		],
		[
			'name'        => 'Div',
			'title'       => 'Div',
			'type'        => 'EssentialElements\\Div',
			'category'    => 'basics',
			'description' => 'Generic div container for grouping elements.',
			'properties'  => [ 'backgroundColor', 'padding', 'margin', 'display', 'flexDirection', 'justifyContent', 'alignItems', 'gap' ],
		],
		[
			'name'        => 'Grid',
			'title'       => 'Grid',
			'type'        => 'EssentialElements\\Grid',
			'category'    => 'basics',
			'description' => 'CSS grid container.',
			'properties'  => [ 'gridTemplateColumns', 'gap', 'backgroundColor', 'padding' ],
		],
		[
			'name'        => 'Heading',
			'title'       => 'Heading',
			'type'        => 'EssentialElements\\Heading',
			'category'    => 'basics',
			'description' => 'Heading element (H1-H6) with typography controls.',
			'properties'  => [ 'text', 'tag', 'color', 'fontSize', 'fontWeight', 'fontFamily', 'textAlign' ],
		],
		[
			'name'        => 'Text',
			'title'       => 'Text',
			'type'        => 'EssentialElements\\Text',
			'category'    => 'basics',
			'description' => 'Plain text block.',
			'properties'  => [ 'text', 'color', 'fontSize', 'fontWeight', 'textAlign' ],
		],
		[
			'name'        => 'RichText',
			'title'       => 'Rich Text',
			'type'        => 'EssentialElements\\RichText',
			'category'    => 'basics',
			'description' => 'WYSIWYG rich text block with full HTML support.',
			'properties'  => [ 'text', 'color', 'fontSize', 'textAlign' ],
		],
		[
			'name'        => 'TextLink',
			'title'       => 'Text Link',
			'type'        => 'EssentialElements\\TextLink',
			'category'    => 'basics',
			'description' => 'Inline text link element.',
			'properties'  => [ 'text', 'url', 'linkTarget', 'color', 'fontSize' ],
		],
		[
			'name'        => 'Button',
			'title'       => 'Button',
			'type'        => 'EssentialElements\\Button',
			'category'    => 'basics',
			'description' => 'Clickable button with style and link options.',
			'properties'  => [ 'text', 'url', 'linkTarget', 'backgroundColor', 'color', 'borderRadius', 'padding', 'fontSize' ],
		],
		[
			'name'        => 'Image',
			'title'       => 'Image',
			'type'        => 'EssentialElements\\Image',
			'category'    => 'basics',
			'description' => 'Image element with responsive sizing.',
			'properties'  => [ 'image', 'alt', 'width', 'height', 'objectFit', 'url', 'linkTarget' ],
		],
		[
			'name'        => 'Video',
			'title'       => 'Video',
			'type'        => 'EssentialElements\\Video',
			'category'    => 'basics',
			'description' => 'Video element supporting YouTube, Vimeo, and self-hosted.',
			'properties'  => [ 'videoType', 'videoUrl', 'autoplay', 'loop', 'controls', 'muted', 'width', 'height' ],
		],
		[
			'name'        => 'Icon',
			'title'       => 'Icon',
			'type'        => 'EssentialElements\\Icon',
			'category'    => 'basics',
			'description' => 'Icon element from icon library with optional link.',
			'properties'  => [ 'icon', 'iconColor', 'iconSize', 'url', 'linkTarget' ],
		],

		// ── Blocks ────────────────────────────────────────────────────────────
		[
			'name'        => 'AnimatedHeading',
			'title'       => 'Animated Heading',
			'type'        => 'EssentialElements\\AnimatedHeading',
			'category'    => 'blocks',
			'description' => 'Heading with text animation effects.',
			'properties'  => [ 'text', 'highlight', 'animation', 'color', 'fontSize', 'fontWeight' ],
		],
		[
			'name'        => 'Badge',
			'title'       => 'Badge',
			'type'        => 'EssentialElements\\Badge',
			'category'    => 'blocks',
			'description' => 'Small badge / label element.',
			'properties'  => [ 'text', 'backgroundColor', 'color', 'borderRadius', 'padding' ],
		],
		[
			'name'        => 'BasicList',
			'title'       => 'Basic List',
			'type'        => 'EssentialElements\\BasicList',
			'category'    => 'blocks',
			'description' => 'Simple bulleted or numbered list.',
			'properties'  => [ 'items', 'color', 'fontSize', 'icon', 'iconColor' ],
		],
		[
			'name'        => 'BasicSlider',
			'title'       => 'Basic Slider',
			'type'        => 'EssentialElements\\BasicSlider',
			'category'    => 'blocks',
			'description' => 'Simple image slider / carousel.',
			'properties'  => [ 'slides', 'autoplay', 'loop', 'speed', 'navigation', 'pagination' ],
		],
		[
			'name'        => 'Blockquote',
			'title'       => 'Blockquote',
			'type'        => 'EssentialElements\\Blockquote',
			'category'    => 'blocks',
			'description' => 'Styled blockquote / pull quote.',
			'properties'  => [ 'text', 'author', 'color', 'fontSize', 'backgroundColor' ],
		],
		[
			'name'        => 'BusinessHours',
			'title'       => 'Business Hours',
			'type'        => 'EssentialElements\\BusinessHours',
			'category'    => 'blocks',
			'description' => 'Display business operating hours.',
			'properties'  => [ 'items', 'color', 'fontSize', 'backgroundColor' ],
		],
		[
			'name'        => 'CheckmarkList',
			'title'       => 'Checkmark List',
			'type'        => 'EssentialElements\\CheckmarkList',
			'category'    => 'blocks',
			'description' => 'List with checkmark icons for feature sets.',
			'properties'  => [ 'items', 'color', 'iconColor', 'fontSize' ],
		],
		[
			'name'        => 'CircleCounter',
			'title'       => 'Circle Counter',
			'type'        => 'EssentialElements\\CircleCounter',
			'category'    => 'blocks',
			'description' => 'Animated circular progress / counter.',
			'properties'  => [ 'value', 'maxValue', 'label', 'suffix', 'color', 'fontSize' ],
		],
		[
			'name'        => 'CountdownTimer',
			'title'       => 'Countdown Timer',
			'type'        => 'EssentialElements\\CountdownTimer',
			'category'    => 'blocks',
			'description' => 'Countdown timer to a specific date/time.',
			'properties'  => [ 'end', 'color', 'fontSize', 'backgroundColor', 'label' ],
		],
		[
			'name'        => 'Divider',
			'title'       => 'Divider',
			'type'        => 'EssentialElements\\Divider',
			'category'    => 'blocks',
			'description' => 'Horizontal rule / decorative divider.',
			'properties'  => [ 'color', 'width', 'height', 'margin' ],
		],
		[
			'name'        => 'DualHeading',
			'title'       => 'Dual Heading',
			'type'        => 'EssentialElements\\DualHeading',
			'category'    => 'blocks',
			'description' => 'Two-part heading with separate styling per part.',
			'properties'  => [ 'text', 'highlight', 'tag', 'color', 'fontSize', 'fontWeight' ],
		],
		[
			'name'        => 'FancyDivider',
			'title'       => 'Fancy Divider',
			'type'        => 'EssentialElements\\FancyDivider',
			'category'    => 'blocks',
			'description' => 'Decorative section divider with SVG shapes.',
			'properties'  => [ 'color', 'height', 'layout', 'backgroundColor' ],
		],
		[
			'name'        => 'FancyTestimonial',
			'title'       => 'Fancy Testimonial',
			'type'        => 'EssentialElements\\FancyTestimonial',
			'category'    => 'blocks',
			'description' => 'Rich testimonial with photo, rating, and quote.',
			'properties'  => [ 'text', 'author', 'image', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'FAQ',
			'title'       => 'FAQ',
			'type'        => 'EssentialElements\\FAQ',
			'category'    => 'blocks',
			'description' => 'Frequently asked questions with schema markup.',
			'properties'  => [ 'items', 'firstOpen', 'multipleOpen', 'color', 'backgroundColor' ],
		],
		[
			'name'        => 'Gallery',
			'title'       => 'Gallery',
			'type'        => 'EssentialElements\\Gallery',
			'category'    => 'blocks',
			'description' => 'Image gallery with multiple layout styles.',
			'properties'  => [ 'images', 'columns', 'layout', 'zoom', 'gap' ],
		],
		[
			'name'        => 'GoogleMap',
			'title'       => 'Google Map',
			'type'        => 'EssentialElements\\GoogleMap',
			'category'    => 'blocks',
			'description' => 'Google Maps embed.',
			'properties'  => [ 'address', 'zoom', 'height', 'width' ],
		],
		[
			'name'        => 'HoverSwapper',
			'title'       => 'Hover Swapper',
			'type'        => 'EssentialElements\\HoverSwapper',
			'category'    => 'blocks',
			'description' => 'Element that swaps content on hover.',
			'properties'  => [ 'image', 'backgroundColor', 'animation', 'padding' ],
		],
		[
			'name'        => 'IconBox',
			'title'       => 'Icon Box',
			'type'        => 'EssentialElements\\IconBox',
			'category'    => 'blocks',
			'description' => 'Icon with title and description.',
			'properties'  => [ 'icon', 'iconColor', 'iconSize', 'title', 'description', 'url', 'color', 'backgroundColor' ],
		],
		[
			'name'        => 'ImageBox',
			'title'       => 'Image Box',
			'type'        => 'EssentialElements\\ImageBox',
			'category'    => 'blocks',
			'description' => 'Image with title and description overlay.',
			'properties'  => [ 'image', 'title', 'description', 'url', 'color', 'backgroundColor', 'objectFit' ],
		],
		[
			'name'        => 'LogoList',
			'title'       => 'Logo List',
			'type'        => 'EssentialElements\\LogoList',
			'category'    => 'blocks',
			'description' => 'Row of logos / brand marks.',
			'properties'  => [ 'images', 'columns', 'gap', 'backgroundColor' ],
		],
		[
			'name'        => 'LottieAnimation',
			'title'       => 'Lottie Animation',
			'type'        => 'EssentialElements\\LottieAnimation',
			'category'    => 'blocks',
			'description' => 'Lottie JSON animation player.',
			'properties'  => [ 'src', 'autoplay', 'loop', 'speed', 'width', 'height' ],
		],
		[
			'name'        => 'PricingTable',
			'title'       => 'Pricing Table',
			'type'        => 'EssentialElements\\PricingTable',
			'category'    => 'blocks',
			'description' => 'Pricing plans display with feature lists.',
			'properties'  => [ 'title', 'price', 'period', 'features', 'buttonText', 'buttonUrl', 'backgroundColor', 'color' ],
		],
		[
			'name'        => 'ProgressBar',
			'title'       => 'Progress Bar',
			'type'        => 'EssentialElements\\ProgressBar',
			'category'    => 'blocks',
			'description' => 'Animated horizontal progress bar.',
			'properties'  => [ 'value', 'maxValue', 'label', 'color', 'backgroundColor', 'height' ],
		],
		[
			'name'        => 'SimpleCounter',
			'title'       => 'Simple Counter',
			'type'        => 'EssentialElements\\SimpleCounter',
			'category'    => 'blocks',
			'description' => 'Animated number counter.',
			'properties'  => [ 'start', 'end', 'duration', 'prefix', 'suffix', 'label', 'color', 'fontSize' ],
		],
		[
			'name'        => 'SimpleTestimonial',
			'title'       => 'Simple Testimonial',
			'type'        => 'EssentialElements\\SimpleTestimonial',
			'category'    => 'blocks',
			'description' => 'Basic testimonial with quote and author.',
			'properties'  => [ 'text', 'author', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'SocialIcons',
			'title'       => 'Social Icons',
			'type'        => 'EssentialElements\\SocialIcons',
			'category'    => 'blocks',
			'description' => 'Row of social media icon links.',
			'properties'  => [ 'items', 'iconColor', 'iconSize', 'gap', 'backgroundColor' ],
		],
		[
			'name'        => 'Spacer',
			'title'       => 'Spacer',
			'type'        => 'EssentialElements\\Spacer',
			'category'    => 'blocks',
			'description' => 'Vertical whitespace spacer.',
			'properties'  => [ 'height' ],
		],
		[
			'name'        => 'StatsGrid',
			'title'       => 'Stats Grid',
			'type'        => 'EssentialElements\\StatsGrid',
			'category'    => 'blocks',
			'description' => 'Grid of stat numbers with labels.',
			'properties'  => [ 'items', 'columns', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'TableOfContents',
			'title'       => 'Table of Contents',
			'type'        => 'EssentialElements\\TableOfContents',
			'category'    => 'blocks',
			'description' => 'Auto-generated table of contents from headings.',
			'properties'  => [ 'title', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'Tabs',
			'title'       => 'Tabs',
			'type'        => 'EssentialElements\\Tabs',
			'category'    => 'blocks',
			'description' => 'Tabbed content panels.',
			'properties'  => [ 'tabs', 'color', 'backgroundColor', 'fontSize' ],
		],

		// ── Site ──────────────────────────────────────────────────────────────
		[
			'name'        => 'HeaderBuilder',
			'title'       => 'Header Builder',
			'type'        => 'EssentialElements\\HeaderBuilder',
			'category'    => 'site',
			'description' => 'Global header builder container.',
			'properties'  => [ 'backgroundColor', 'padding', 'height', 'position' ],
		],
		[
			'name'        => 'MenuBuilder',
			'title'       => 'Menu Builder',
			'type'        => 'EssentialElements\\MenuBuilder',
			'category'    => 'site',
			'description' => 'Custom navigation menu builder.',
			'properties'  => [ 'menu', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'MenuButton',
			'title'       => 'Menu Button',
			'type'        => 'EssentialElements\\MenuButton',
			'category'    => 'site',
			'description' => 'Mobile hamburger menu toggle button.',
			'properties'  => [ 'color', 'backgroundColor', 'iconSize' ],
		],
		[
			'name'        => 'MenuLink',
			'title'       => 'Menu Link',
			'type'        => 'EssentialElements\\MenuLink',
			'category'    => 'site',
			'description' => 'Individual navigation menu link.',
			'properties'  => [ 'text', 'url', 'linkTarget', 'color', 'fontSize' ],
		],
		[
			'name'        => 'Popup',
			'title'       => 'Popup',
			'type'        => 'EssentialElements\\Popup',
			'category'    => 'site',
			'description' => 'Modal popup container.',
			'properties'  => [ 'trigger', 'animation', 'backgroundColor', 'padding', 'width' ],
		],
		[
			'name'        => 'SearchForm',
			'title'       => 'Search Form',
			'type'        => 'EssentialElements\\SearchForm',
			'category'    => 'site',
			'description' => 'WordPress search form.',
			'properties'  => [ 'placeholder', 'backgroundColor', 'color', 'borderRadius' ],
		],
		[
			'name'        => 'WPMenu',
			'title'       => 'WP Menu',
			'type'        => 'EssentialElements\\WPMenu',
			'category'    => 'site',
			'description' => 'Renders a registered WordPress navigation menu.',
			'properties'  => [ 'menu', 'color', 'backgroundColor', 'fontSize' ],
		],

		// ── Advanced ──────────────────────────────────────────────────────────
		[
			'name'        => 'AdvancedAccordion',
			'title'       => 'Advanced Accordion',
			'type'        => 'EssentialElements\\AdvancedAccordion',
			'category'    => 'advanced',
			'description' => 'Accordion container. Must contain AdvancedAccordionContent children.',
			'properties'  => [ 'firstOpen', 'multipleOpen', 'animation', 'color', 'backgroundColor' ],
		],
		[
			'name'        => 'AdvancedAccordionContent',
			'title'       => 'Advanced Accordion Content',
			'type'        => 'EssentialElements\\AdvancedAccordionContent',
			'category'    => 'advanced',
			'description' => 'Individual accordion panel. Must be inside AdvancedAccordion.',
			'properties'  => [ 'title', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'AdvancedSlider',
			'title'       => 'Advanced Slider',
			'type'        => 'EssentialElements\\AdvancedSlider',
			'category'    => 'advanced',
			'description' => 'Advanced slider container. Must contain AdvancedSlide children.',
			'properties'  => [ 'autoplay', 'loop', 'speed', 'navigation', 'pagination' ],
		],
		[
			'name'        => 'AdvancedSlide',
			'title'       => 'Advanced Slide',
			'type'        => 'EssentialElements\\AdvancedSlide',
			'category'    => 'advanced',
			'description' => 'Individual slide inside AdvancedSlider.',
			'properties'  => [ 'backgroundColor', 'image', 'padding' ],
		],
		[
			'name'        => 'AdvancedTabs',
			'title'       => 'Advanced Tabs',
			'type'        => 'EssentialElements\\AdvancedTabs',
			'category'    => 'advanced',
			'description' => 'Advanced tabs with rich tab content.',
			'properties'  => [ 'tabs', 'color', 'backgroundColor', 'fontSize' ],
		],
		[
			'name'        => 'CodeBlock',
			'title'       => 'Code Block',
			'type'        => 'EssentialElements\\CodeBlock',
			'category'    => 'advanced',
			'description' => 'Custom code block (HTML/CSS/JS).',
			'properties'  => [ 'html', 'customCss', 'js' ],
		],
		[
			'name'        => 'ContentToggle',
			'title'       => 'Content Toggle',
			'type'        => 'EssentialElements\\ContentToggle',
			'category'    => 'advanced',
			'description' => 'Content toggle container. Must contain ContentToggleContent children.',
			'properties'  => [ 'color', 'backgroundColor', 'animation' ],
		],
		[
			'name'        => 'ContentToggleContent',
			'title'       => 'Content Toggle Content',
			'type'        => 'EssentialElements\\ContentToggleContent',
			'category'    => 'advanced',
			'description' => 'Individual toggle panel inside ContentToggle.',
			'properties'  => [ 'title', 'color', 'backgroundColor' ],
		],
		[
			'name'        => 'GlobalBlock',
			'title'       => 'Global Block',
			'type'        => 'EssentialElements\\GlobalBlock',
			'category'    => 'advanced',
			'description' => 'Reusable global block reference.',
			'properties'  => [ 'globalBlockId' ],
		],
		[
			'name'        => 'HTML',
			'title'       => 'HTML',
			'type'        => 'EssentialElements\\HTML',
			'category'    => 'advanced',
			'description' => 'Raw HTML / shortcode block.',
			'properties'  => [ 'html' ],
		],

		// ── Dynamic ───────────────────────────────────────────────────────────
		[
			'name'        => 'Breadcrumbs',
			'title'       => 'Breadcrumbs',
			'type'        => 'EssentialElements\\Breadcrumbs',
			'category'    => 'dynamic',
			'description' => 'Breadcrumb navigation trail.',
			'properties'  => [ 'color', 'fontSize', 'separator' ],
		],
		[
			'name'        => 'PostContent',
			'title'       => 'Post Content',
			'type'        => 'EssentialElements\\PostContent',
			'category'    => 'dynamic',
			'description' => 'Dynamic post content body.',
			'properties'  => [ 'color', 'fontSize', 'textAlign' ],
		],
		[
			'name'        => 'PostExcerpt',
			'title'       => 'Post Excerpt',
			'type'        => 'EssentialElements\\PostExcerpt',
			'category'    => 'dynamic',
			'description' => 'Dynamic post excerpt.',
			'properties'  => [ 'color', 'fontSize', 'textAlign' ],
		],
		[
			'name'        => 'PostList',
			'title'       => 'Post List',
			'type'        => 'EssentialElements\\PostList',
			'category'    => 'dynamic',
			'description' => 'Displays a list of posts from a query.',
			'properties'  => [ 'postType', 'postsPerPage', 'orderBy', 'order', 'columns', 'layout' ],
		],
		[
			'name'        => 'PostLoopBuilder',
			'title'       => 'Post Loop Builder',
			'type'        => 'EssentialElements\\PostLoopBuilder',
			'category'    => 'dynamic',
			'description' => 'Custom post loop with template builder.',
			'properties'  => [ 'postType', 'postsPerPage', 'orderBy', 'order', 'columns' ],
		],
		[
			'name'        => 'PostMeta',
			'title'       => 'Post Meta',
			'type'        => 'EssentialElements\\PostMeta',
			'category'    => 'dynamic',
			'description' => 'Dynamic post custom field value.',
			'properties'  => [ 'color', 'fontSize' ],
		],
		[
			'name'        => 'PostTitle',
			'title'       => 'Post Title',
			'type'        => 'EssentialElements\\PostTitle',
			'category'    => 'dynamic',
			'description' => 'Dynamic post title.',
			'properties'  => [ 'tag', 'color', 'fontSize', 'fontWeight', 'textAlign' ],
		],
		[
			'name'        => 'PostFeaturedImage',
			'title'       => 'Post Featured Image',
			'type'        => 'EssentialElements\\PostFeaturedImage',
			'category'    => 'dynamic',
			'description' => 'Dynamic post featured image.',
			'properties'  => [ 'width', 'height', 'objectFit', 'borderRadius' ],
		],
		[
			'name'        => 'RepeaterField',
			'title'       => 'Repeater Field',
			'type'        => 'EssentialElements\\RepeaterField',
			'category'    => 'dynamic',
			'description' => 'Display ACF or custom repeater field values.',
			'properties'  => [ 'fields', 'columns', 'layout' ],
		],
		[
			'name'        => 'TermLoopBuilder',
			'title'       => 'Term Loop Builder',
			'type'        => 'EssentialElements\\TermLoopBuilder',
			'category'    => 'dynamic',
			'description' => 'Loop through taxonomy terms.',
			'properties'  => [ 'postType', 'orderBy', 'order', 'columns' ],
		],
		[
			'name'        => 'ArchiveTitle',
			'title'       => 'Archive Title',
			'type'        => 'EssentialElements\\ArchiveTitle',
			'category'    => 'dynamic',
			'description' => 'Dynamic archive page title.',
			'properties'  => [ 'tag', 'color', 'fontSize', 'fontWeight' ],
		],
		[
			'name'        => 'CommentForm',
			'title'       => 'Comment Form',
			'type'        => 'EssentialElements\\CommentForm',
			'category'    => 'dynamic',
			'description' => 'WordPress comment form.',
			'properties'  => [ 'color', 'backgroundColor', 'fontSize' ],
		],

		// ── Forms ─────────────────────────────────────────────────────────────
		[
			'name'        => 'FormBuilder',
			'title'       => 'Form Builder',
			'type'        => 'EssentialElements\\FormBuilder',
			'category'    => 'forms',
			'description' => 'Full-featured contact form builder.',
			'properties'  => [ 'fields', 'submitText', 'actions', 'successMessage', 'color', 'backgroundColor' ],
		],
		[
			'name'        => 'LoginForm',
			'title'       => 'Login Form',
			'type'        => 'EssentialElements\\LoginForm',
			'category'    => 'forms',
			'description' => 'WordPress user login form.',
			'properties'  => [ 'placeholder', 'submitText', 'color', 'backgroundColor', 'borderRadius' ],
		],
		[
			'name'        => 'RegisterForm',
			'title'       => 'Register Form',
			'type'        => 'EssentialElements\\RegisterForm',
			'category'    => 'forms',
			'description' => 'WordPress user registration form.',
			'properties'  => [ 'fields', 'submitText', 'successMessage', 'color', 'backgroundColor' ],
		],
		[
			'name'        => 'ForgotPasswordForm',
			'title'       => 'Forgot Password Form',
			'type'        => 'EssentialElements\\ForgotPasswordForm',
			'category'    => 'forms',
			'description' => 'WordPress password reset / forgot password form.',
			'properties'  => [ 'placeholder', 'submitText', 'successMessage', 'color', 'backgroundColor' ],
		],
	];

	// WooCommerce elements — only when WooCommerce is active.
	if ( class_exists( 'WooCommerce' ) ) {
		$woo_elements = [
			[
				'name'        => 'ProductBuilder',
				'title'       => 'Product Builder',
				'type'        => 'EssentialElements\\ProductBuilder',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce single product page builder.',
				'properties'  => [ 'backgroundColor', 'padding', 'layout' ],
			],
			[
				'name'        => 'ProductCartButton',
				'title'       => 'Product Cart Button',
				'type'        => 'EssentialElements\\ProductCartButton',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce add-to-cart button.',
				'properties'  => [ 'text', 'backgroundColor', 'color', 'borderRadius', 'padding' ],
			],
			[
				'name'        => 'ProductDescription',
				'title'       => 'Product Description',
				'type'        => 'EssentialElements\\ProductDescription',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product description.',
				'properties'  => [ 'color', 'fontSize', 'textAlign' ],
			],
			[
				'name'        => 'ProductImages',
				'title'       => 'Product Images',
				'type'        => 'EssentialElements\\ProductImages',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product image gallery.',
				'properties'  => [ 'width', 'height', 'objectFit', 'zoom' ],
			],
			[
				'name'        => 'ProductPrice',
				'title'       => 'Product Price',
				'type'        => 'EssentialElements\\ProductPrice',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product price display.',
				'properties'  => [ 'color', 'fontSize', 'fontWeight' ],
			],
			[
				'name'        => 'ProductRating',
				'title'       => 'Product Rating',
				'type'        => 'EssentialElements\\ProductRating',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product star rating.',
				'properties'  => [ 'color', 'iconColor', 'iconSize', 'fontSize' ],
			],
			[
				'name'        => 'ProductReviews',
				'title'       => 'Product Reviews',
				'type'        => 'EssentialElements\\ProductReviews',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product reviews section.',
				'properties'  => [ 'color', 'backgroundColor', 'fontSize' ],
			],
			[
				'name'        => 'ProductTabs',
				'title'       => 'Product Tabs',
				'type'        => 'EssentialElements\\ProductTabs',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product information tabs.',
				'properties'  => [ 'color', 'backgroundColor', 'fontSize' ],
			],
			[
				'name'        => 'ProductTitle',
				'title'       => 'Product Title',
				'type'        => 'EssentialElements\\ProductTitle',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce product title.',
				'properties'  => [ 'tag', 'color', 'fontSize', 'fontWeight', 'textAlign' ],
			],
			[
				'name'        => 'CartPage',
				'title'       => 'Cart Page',
				'type'        => 'EssentialElements\\CartPage',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce shopping cart page layout.',
				'properties'  => [ 'backgroundColor', 'color', 'fontSize', 'padding' ],
			],
			[
				'name'        => 'CheckoutBuilder',
				'title'       => 'Checkout Builder',
				'type'        => 'EssentialElements\\CheckoutBuilder',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce checkout page builder.',
				'properties'  => [ 'backgroundColor', 'color', 'fontSize', 'padding', 'layout' ],
			],
			[
				'name'        => 'MiniCart',
				'title'       => 'Mini Cart',
				'type'        => 'EssentialElements\\MiniCart',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce mini cart / cart icon with count.',
				'properties'  => [ 'iconColor', 'iconSize', 'color', 'backgroundColor' ],
			],
			[
				'name'        => 'ShopPage',
				'title'       => 'Shop Page',
				'type'        => 'EssentialElements\\ShopPage',
				'category'    => 'woocommerce',
				'description' => 'WooCommerce shop / product archive page layout.',
				'properties'  => [ 'columns', 'postsPerPage', 'layout', 'backgroundColor', 'color' ],
			],
		];
		$elements = array_merge( $elements, $woo_elements );
	}

	return $elements;
}
