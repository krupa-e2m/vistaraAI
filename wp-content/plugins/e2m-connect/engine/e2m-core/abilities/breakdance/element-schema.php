<?php
/**
 * E2M Connect MCP – Breakdance Element Schema
 *
 * Return full JSON-schema definitions for Breakdance element types so AI
 * agents know exactly which properties each element supports.
 *
 * Delegates to Respira_Breakdance_Element_Schema when available, and builds
 * the schema from the built-in element registry otherwise.
 *
 * Actions:
 *   get_schema      — schema for a single element type
 *   multi_schema    — schemas for multiple element types in one call
 *   structure_notes — Breakdance structure / storage documentation
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/breakdance-element-schema', [
	'label'       => __( '[Breakdance] Element Schema', 'e2mconnect' ),
	'description' => 'Return full property schemas for Breakdance element types: content fields, design fields, allowed values. Supports single, multi, and structure_notes.',
	'category'    => 'e2m-breakdance',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'get_schema', 'multi_schema', 'structure_notes' ],
				'description' => 'get_schema — schema for one element; multi_schema — schemas for multiple; structure_notes — Breakdance tree documentation.',
			],
			'element_type' => [
				'type'        => 'string',
				'description' => 'Namespaced element type for get_schema, e.g. "EssentialElements\\Heading".',
			],
			'element_types' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Array of namespaced element types for multi_schema.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'          => [ 'type' => 'string' ],
			'schema'          => [ 'type' => 'object', 'description' => 'Schema for a single element type.' ],
			'schemas'         => [ 'type' => 'object', 'description' => 'Map of type => schema for multi_schema.' ],
			'structure_notes' => [ 'type' => 'object', 'description' => 'Breakdance tree structure documentation.' ],
			'source'          => [ 'type' => 'string', 'description' => '"live_api" or "built_in".' ],
		],
	],

	'execute_callback'    => 'e2m_engine_breakdance_element_schema',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Breakdance: Element Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute breakdance-element-schema ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_breakdance_element_schema( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_breakdance() ) {
		return new WP_Error( 'breakdance_missing', __( 'Breakdance Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );
	$source = class_exists( 'Respira_Breakdance_Element_Schema' ) ? 'live_api' : 'built_in';

	switch ( $action ) {

		// ── Get Schema ────────────────────────────────────────────────────────
		case 'get_schema':
			if ( empty( $input['element_type'] ) ) {
				return new WP_Error( 'missing_element_type', __( '"element_type" is required for get_schema.', 'e2mconnect' ) );
			}
			$type = sanitize_text_field( $input['element_type'] );

			if ( class_exists( 'Respira_Breakdance_Element_Schema' ) ) {
				$schema_gen = new Respira_Breakdance_Element_Schema();
				$result     = $schema_gen->get_builder_schema( [ $type ] );
				$schema     = $result[ $type ] ?? $result;
			} else {
				$schema = e2m_engine_breakdance_build_element_schema( $type );
			}

			return [
				'action' => 'get_schema',
				'schema' => $schema,
				'source' => $source,
			];

		// ── Multi Schema ──────────────────────────────────────────────────────
		case 'multi_schema':
			$types = isset( $input['element_types'] ) && is_array( $input['element_types'] )
				? $input['element_types']
				: [];

			if ( empty( $types ) ) {
				return new WP_Error( 'missing_element_types', __( '"element_types" array is required for multi_schema.', 'e2mconnect' ) );
			}

			$schemas = [];
			foreach ( $types as $type ) {
				$type = sanitize_text_field( (string) $type );
				if ( class_exists( 'Respira_Breakdance_Element_Schema' ) ) {
					$schema_gen        = new Respira_Breakdance_Element_Schema();
					$result            = $schema_gen->get_builder_schema( [ $type ] );
					$schemas[ $type ]  = $result[ $type ] ?? $result;
				} else {
					$schemas[ $type ] = e2m_engine_breakdance_build_element_schema( $type );
				}
			}

			return [
				'action'  => 'multi_schema',
				'schemas' => $schemas,
				'total'   => count( $schemas ),
				'source'  => $source,
			];

		// ── Structure Notes ───────────────────────────────────────────────────
		case 'structure_notes':
			return [
				'action'          => 'structure_notes',
				'structure_notes' => [
					'hierarchy'      => 'Section → Columns → Column → Element',
					'data_format'    => 'JSON-encoded tree stored in _breakdance_data postmeta',
					'element_data'   => 'Each element has data.content (user content) and data.design (styling)',
					'nesting_rules'  => 'Columns must contain Column children; AdvancedAccordion must contain AdvancedAccordionContent; AdvancedSlider must contain AdvancedSlide; ContentToggle must contain ContentToggleContent',
					'element_types'  => 'Element types use namespaced strings: EssentialElements\\Section, EssentialElements\\Heading, etc.',
					'storage_key'    => '_breakdance_data',
					'storage_format' => 'Nested array of {id, type, data:{content:{...}, design:{...}}, children:[...]}',
					'top_level'      => 'Top-level structure can be: array of elements, OR {tree_json_string:"..."} wrapper (v2.6+), OR {root:{children:[...]}}, OR {type:"root",children:[...]}',
					'id_format'      => 'Element IDs are sequential integers (1, 2, 3, …)',
					'version'        => 'BREAKDANCE_PLUGIN_VERSION constant',
					'cache_clear'    => 'do_action("breakdance_invalidate_caches", $post_id) or delete_transient("breakdance_page_{$post_id}")',
				],
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: get_schema, multi_schema, structure_notes.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Native schema builder
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Build a property schema for a single Breakdance element type.
 *
 * Uses the element registry to find the element's property list, then maps
 * each property to a full JSON-schema definition via e2m_engine_breakdance_property_schema().
 *
 * @param string $type  Namespaced element type.
 * @return array<string,mixed>
 */
function e2m_engine_breakdance_build_element_schema( string $type ): array {
	$element = e2m_engine_breakdance_find_element( $type );

	if ( ! $element ) {
		return [
			'type'        => $type,
			'description' => 'Unknown element type.',
			'properties'  => [],
			'content'     => [],
			'design'      => [],
		];
	}

	$props   = $element['properties'] ?? [];
	$content = [];
	$design  = [];

	// Classify properties into content (user-facing) vs design (styling).
	$design_props = [
		'backgroundColor', 'color', 'fontSize', 'fontWeight', 'fontFamily',
		'textAlign', 'borderRadius', 'padding', 'margin', 'gap', 'display',
		'flexDirection', 'justifyContent', 'alignItems', 'gridTemplateColumns',
		'width', 'height', 'objectFit', 'iconColor', 'iconSize',
	];

	foreach ( $props as $prop ) {
		$schema = e2m_engine_breakdance_property_schema( $prop );
		if ( in_array( $prop, $design_props, true ) ) {
			$design[ $prop ] = $schema;
		} else {
			$content[ $prop ] = $schema;
		}
	}

	return [
		'type'        => $type,
		'name'        => $element['name'] ?? $type,
		'title'       => $element['title'] ?? $element['name'] ?? $type,
		'description' => $element['description'] ?? '',
		'category'    => $element['category'] ?? 'general',
		'content'     => $content,
		'design'      => $design,
		'properties'  => $props,
	];
}

/**
 * Return a JSON-schema fragment for a single Breakdance property.
 *
 * @param string $prop  Property name.
 * @return array<string,mixed>
 */
function e2m_engine_breakdance_property_schema( string $prop ): array {
	$map = [
		'text'               => [ 'type' => 'string', 'description' => 'Main text content.' ],
		'tag'                => [ 'type' => 'string', 'enum' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'label', 'li' ], 'description' => 'HTML tag for the element.' ],
		'url'                => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Link URL.' ],
		'linkTarget'         => [ 'type' => 'string', 'enum' => [ '_self', '_blank', '_parent', '_top' ], 'description' => 'Link target.' ],
		'image'              => [ 'type' => [ 'integer', 'string' ], 'description' => 'Image attachment ID or URL.' ],
		'alt'                => [ 'type' => 'string', 'description' => 'Image alt text.' ],
		'width'              => [ 'type' => 'string', 'description' => 'Element width, e.g. "100%", "500px".' ],
		'height'             => [ 'type' => 'string', 'description' => 'Element height, e.g. "300px", "auto".' ],
		'objectFit'          => [ 'type' => 'string', 'enum' => [ 'cover', 'contain', 'fill', 'none', 'scale-down' ], 'description' => 'CSS object-fit for images/video.' ],
		'backgroundColor'    => [ 'type' => 'string', 'description' => 'Background color: hex, rgb(), rgba(), or CSS name.' ],
		'color'              => [ 'type' => 'string', 'description' => 'Text/foreground color: hex, rgb(), rgba(), or CSS name.' ],
		'fontSize'           => [ 'type' => 'string', 'description' => 'Font size, e.g. "16px", "1.5rem".' ],
		'fontWeight'         => [ 'type' => [ 'string', 'integer' ], 'description' => 'Font weight, e.g. 400, 700, "bold".' ],
		'fontFamily'         => [ 'type' => 'string', 'description' => 'Font family name or stack.' ],
		'textAlign'          => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right', 'justify' ], 'description' => 'Text alignment.' ],
		'borderRadius'       => [ 'type' => 'string', 'description' => 'Border radius, e.g. "4px", "50%".' ],
		'padding'            => [ 'type' => 'string', 'description' => 'CSS padding shorthand, e.g. "20px 40px".' ],
		'margin'             => [ 'type' => 'string', 'description' => 'CSS margin shorthand, e.g. "0 auto".' ],
		'gap'                => [ 'type' => 'string', 'description' => 'Flex/grid gap, e.g. "16px".' ],
		'display'            => [ 'type' => 'string', 'enum' => [ 'block', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'none' ], 'description' => 'CSS display value.' ],
		'flexDirection'      => [ 'type' => 'string', 'enum' => [ 'row', 'row-reverse', 'column', 'column-reverse' ], 'description' => 'Flex direction.' ],
		'justifyContent'     => [ 'type' => 'string', 'enum' => [ 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly' ], 'description' => 'Flex justify-content.' ],
		'alignItems'         => [ 'type' => 'string', 'enum' => [ 'flex-start', 'flex-end', 'center', 'stretch', 'baseline' ], 'description' => 'Flex align-items.' ],
		'gridTemplateColumns'=> [ 'type' => 'string', 'description' => 'CSS grid-template-columns, e.g. "repeat(3, 1fr)".' ],
		'autoplay'           => [ 'type' => 'boolean', 'description' => 'Auto-play media or slider.' ],
		'loop'               => [ 'type' => 'boolean', 'description' => 'Loop media or slider.' ],
		'controls'           => [ 'type' => 'boolean', 'description' => 'Show media player controls.' ],
		'muted'              => [ 'type' => 'boolean', 'description' => 'Mute video audio.' ],
		'videoType'          => [ 'type' => 'string', 'enum' => [ 'youtube', 'vimeo', 'self-hosted' ], 'description' => 'Video source type.' ],
		'videoUrl'           => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Video URL or embed URL.' ],
		'icon'               => [ 'type' => 'string', 'description' => 'Icon class or SVG string.' ],
		'iconColor'          => [ 'type' => 'string', 'description' => 'Icon color.' ],
		'iconSize'           => [ 'type' => 'string', 'description' => 'Icon size, e.g. "24px".' ],
		'title'              => [ 'type' => 'string', 'description' => 'Title text.' ],
		'description'        => [ 'type' => 'string', 'description' => 'Description text.' ],
		'items'              => [ 'type' => 'array', 'description' => 'Array of list/accordion/tab items.' ],
		'slides'             => [ 'type' => 'array', 'description' => 'Array of slide items.' ],
		'tabs'               => [ 'type' => 'array', 'description' => 'Array of tab items.' ],
		'images'             => [ 'type' => 'array', 'description' => 'Array of image attachment IDs or URLs.' ],
		'columns'            => [ 'type' => 'integer', 'description' => 'Number of columns.' ],
		'layout'             => [ 'type' => 'string', 'description' => 'Layout style identifier.' ],
		'speed'              => [ 'type' => 'integer', 'description' => 'Animation or transition speed in ms.' ],
		'navigation'         => [ 'type' => 'boolean', 'description' => 'Show slider navigation arrows.' ],
		'pagination'         => [ 'type' => 'boolean', 'description' => 'Show slider pagination dots.' ],
		'zoom'               => [ 'type' => 'integer', 'description' => 'Map zoom level (1-20) or image zoom factor.' ],
		'address'            => [ 'type' => 'string', 'description' => 'Physical address for Google Maps.' ],
		'value'              => [ 'type' => 'number', 'description' => 'Counter or progress current value.' ],
		'maxValue'           => [ 'type' => 'number', 'description' => 'Counter or progress maximum value.' ],
		'label'              => [ 'type' => 'string', 'description' => 'Label text.' ],
		'suffix'             => [ 'type' => 'string', 'description' => 'Counter suffix, e.g. "+".' ],
		'prefix'             => [ 'type' => 'string', 'description' => 'Counter prefix, e.g. "$".' ],
		'start'              => [ 'type' => 'number', 'description' => 'Counter start value.' ],
		'end'                => [ 'type' => [ 'number', 'string' ], 'description' => 'Counter end value or countdown end datetime.' ],
		'duration'           => [ 'type' => 'integer', 'description' => 'Animation duration in ms.' ],
		'highlight'          => [ 'type' => 'string', 'description' => 'Highlighted text portion for dual heading.' ],
		'price'              => [ 'type' => 'string', 'description' => 'Price string, e.g. "$29".' ],
		'period'             => [ 'type' => 'string', 'description' => 'Billing period, e.g. "/mo".' ],
		'buttonText'         => [ 'type' => 'string', 'description' => 'CTA button label text.' ],
		'buttonUrl'          => [ 'type' => 'string', 'format' => 'uri', 'description' => 'CTA button link URL.' ],
		'features'           => [ 'type' => 'array', 'description' => 'Array of pricing feature strings.' ],
		'src'                => [ 'type' => 'string', 'description' => 'Source URL for Lottie JSON or file.' ],
		'postType'           => [ 'type' => 'string', 'description' => 'WordPress post type slug.' ],
		'postsPerPage'       => [ 'type' => 'integer', 'description' => 'Number of posts to show per page.' ],
		'orderBy'            => [ 'type' => 'string', 'enum' => [ 'date', 'title', 'menu_order', 'rand', 'comment_count', 'modified' ], 'description' => 'Post query order-by field.' ],
		'order'              => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'description' => 'Post query sort direction.' ],
		'globalBlockId'      => [ 'type' => 'integer', 'description' => 'Post ID of the global block to reference.' ],
		'fields'             => [ 'type' => 'array', 'description' => 'Array of form field definitions.' ],
		'submitText'         => [ 'type' => 'string', 'description' => 'Form submit button text.' ],
		'actions'            => [ 'type' => 'array', 'description' => 'Form submission action definitions.' ],
		'successMessage'     => [ 'type' => 'string', 'description' => 'Message shown after successful form submission.' ],
		'placeholder'        => [ 'type' => 'string', 'description' => 'Input placeholder text.' ],
		'menu'               => [ 'type' => 'integer', 'description' => 'WordPress nav menu term ID.' ],
		'trigger'            => [ 'type' => 'string', 'description' => 'Popup trigger method.' ],
		'animation'          => [ 'type' => 'string', 'description' => 'Entry animation name.' ],
		'firstOpen'          => [ 'type' => 'boolean', 'description' => 'Whether the first item is open by default.' ],
		'multipleOpen'       => [ 'type' => 'boolean', 'description' => 'Whether multiple items can be open simultaneously.' ],
		'html'               => [ 'type' => 'string', 'description' => 'Raw HTML content.' ],
		'customCss'          => [ 'type' => 'string', 'description' => 'Custom CSS to inject.' ],
		'js'                 => [ 'type' => 'string', 'description' => 'Custom JavaScript to inject.' ],
		'separator'          => [ 'type' => 'string', 'description' => 'Breadcrumb separator character.' ],
		'position'           => [ 'type' => 'string', 'enum' => [ 'static', 'relative', 'absolute', 'fixed', 'sticky' ], 'description' => 'CSS position.' ],
	];

	return $map[ $prop ] ?? [ 'type' => 'string', 'description' => "Property: {$prop}." ];
}
