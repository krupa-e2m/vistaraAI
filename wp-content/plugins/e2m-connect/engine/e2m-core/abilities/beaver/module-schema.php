<?php
/**
 * E2M Connect MCP – Beaver Builder Module Schema
 *
 * Get the full property schema for one or more Beaver Builder module types —
 * what settings they accept, valid values, and structure documentation.
 *
 * This is the "AI intelligence" layer: before building a page the AI can
 * call get_schema to know exactly what settings are valid for each module
 * type, preventing trial-and-error against Beaver Builder.
 *
 * Actions:
 *   get_schema      — detailed schema for one module type
 *   multi_schema    — schemas for multiple module types at once
 *   structure_notes — BB layout structure documentation
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/beaver-module-schema', [
	'label'       => __( '[Beaver] Module Schema', 'e2mconnect' ),
	'description' => 'Get the settings schema for Beaver Builder modules. Returns accepted properties, enum values, and structure documentation. Use before building pages to avoid invalid settings.',
	'category'    => 'e2m-beaver',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'get_schema', 'multi_schema', 'structure_notes' ],
				'description' => 'get_schema — single module schema; multi_schema — several at once; structure_notes — BB hierarchy and meta key docs.',
			],
			'module_type' => [
				'type'        => 'string',
				'description' => 'Module type slug for get_schema, e.g. "heading", "html", "button".',
			],
			'module_types' => [
				'type'        => 'array',
				'description' => 'Array of module type slugs for multi_schema.',
				'items'       => [ 'type' => 'string' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'          => [ 'type' => 'string' ],
			'module_type'     => [ 'type' => 'string' ],
			'schema'          => [ 'type' => 'object' ],
			'schemas'         => [ 'type' => 'object' ],
			'structure_notes' => [ 'type' => 'object' ],
		],
	],

	'execute_callback'    => 'e2m_engine_beaver_module_schema',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Beaver: Module Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute beaver-module-schema ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_beaver_module_schema( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_beaver() ) {
		return new WP_Error( 'beaver_missing', __( 'Beaver Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── Get schema ────────────────────────────────────────────────────────
		case 'get_schema':
			if ( empty( $input['module_type'] ) ) {
				return new WP_Error( 'missing_module_type', __( '"module_type" is required for get_schema.', 'e2mconnect' ) );
			}
			$type = sanitize_text_field( $input['module_type'] );

			if ( class_exists( 'Respira_Beaver_Module_Schema' ) ) {
				$schema_gen = new Respira_Beaver_Module_Schema();
				$result     = $schema_gen->get_builder_schema( [ $type ] );
				return [
					'action'      => 'get_schema',
					'module_type' => $type,
					'schema'      => $result['available_modules'][ $type ] ?? $result,
				];
			}

			$schema = e2m_engine_beaver_build_module_schema( $type );
			if ( empty( $schema ) ) {
				return new WP_Error(
					'module_not_found',
					sprintf( __( 'No schema found for module type "%s".', 'e2mconnect' ), $type )
				);
			}

			return [
				'action'      => 'get_schema',
				'module_type' => $type,
				'schema'      => $schema,
			];

		// ── Multi schema ──────────────────────────────────────────────────────
		case 'multi_schema':
			if ( empty( $input['module_types'] ) || ! is_array( $input['module_types'] ) ) {
				return new WP_Error( 'missing_module_types', __( '"module_types" array is required for multi_schema.', 'e2mconnect' ) );
			}
			$types   = array_map( 'sanitize_text_field', $input['module_types'] );
			$schemas = [];

			if ( class_exists( 'Respira_Beaver_Module_Schema' ) ) {
				$schema_gen = new Respira_Beaver_Module_Schema();
				$result     = $schema_gen->get_builder_schema( $types );
				return [
					'action'  => 'multi_schema',
					'schemas' => $result['available_modules'] ?? $result,
				];
			}

			foreach ( $types as $type ) {
				$schemas[ $type ] = e2m_engine_beaver_build_module_schema( $type );
			}

			return [
				'action'  => 'multi_schema',
				'schemas' => $schemas,
			];

		// ── Structure notes ───────────────────────────────────────────────────
		case 'structure_notes':
			return [
				'action'          => 'structure_notes',
				'structure_notes' => e2m_engine_beaver_structure_notes(),
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: get_schema, multi_schema, structure_notes.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Native schema builder helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Build a module schema from the registry properties + property schema map.
 *
 * @param string $type  Module type slug.
 * @return array<string,mixed>
 */
function e2m_engine_beaver_build_module_schema( string $type ): array {
	$module = e2m_engine_beaver_find_module_builtin( $type );
	if ( ! $module ) {
		return [];
	}

	$props        = $module['properties'] ?? [];
	$prop_schemas = [];
	foreach ( $props as $prop ) {
		$prop_schemas[ $prop ] = e2m_engine_beaver_property_schema( $prop );
	}

	return [
		'type'        => $type,
		'title'       => $module['title'] ?? $module['name'] ?? $type,
		'description' => $module['description'] ?? '',
		'category'    => $module['category'] ?? 'basic',
		'properties'  => $prop_schemas,
	];
}

/**
 * Return the schema definition for a single Beaver Builder module property.
 *
 * @param string $prop  Property name.
 * @return array<string,mixed>
 */
function e2m_engine_beaver_property_schema( string $prop ): array {
	$map = [
		// ── Text / content ────────────────────────────────────────────────────
		'heading'          => [ 'type' => 'string', 'description' => 'Heading text content.' ],
		'text'             => [ 'type' => 'string', 'description' => 'Plain text content.' ],
		'html'             => [ 'type' => 'string', 'description' => 'Raw HTML content.' ],
		'label'            => [ 'type' => 'string', 'description' => 'Label text.' ],
		'title'            => [ 'type' => 'string', 'description' => 'Title text.' ],
		'caption'          => [ 'type' => 'string', 'description' => 'Image caption text.' ],
		'prefix'           => [ 'type' => 'string', 'description' => 'Text shown before the number.' ],
		'suffix'           => [ 'type' => 'string', 'description' => 'Text shown after the number.' ],
		'submit_button_text' => [ 'type' => 'string', 'description' => 'Submit button label.' ],
		'success_message'  => [ 'type' => 'string', 'description' => 'Message shown after successful form submit.' ],

		// ── Links ─────────────────────────────────────────────────────────────
		'link'             => [ 'type' => 'string', 'format' => 'uri', 'description' => 'URL for the link.' ],
		'link_target'      => [ 'type' => 'string', 'enum' => [ '_self', '_blank', '_parent', '_top' ], 'description' => 'Link target attribute.' ],
		'link_nofollow'    => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Add rel="nofollow" to the link.' ],
		'button_url'       => [ 'type' => 'string', 'format' => 'uri', 'description' => 'URL for the button.' ],

		// ── Photo / image ─────────────────────────────────────────────────────
		'photo'            => [ 'type' => 'integer', 'description' => 'WordPress media attachment ID.' ],
		'photo_src'        => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Photo URL (resolved from attachment ID).' ],
		'photo_crop'       => [ 'type' => 'string', 'description' => 'Image crop/size key, e.g. "thumbnail", "medium", "large", "full".' ],
		'photo_size'       => [ 'type' => 'string', 'description' => 'Image size key.' ],

		// ── Heading tag ───────────────────────────────────────────────────────
		'tag'              => [ 'type' => 'string', 'enum' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span' ], 'description' => 'HTML heading/element tag.' ],

		// ── Colors ────────────────────────────────────────────────────────────
		'color'            => [ 'type' => 'string', 'description' => 'Foreground/text color. Hex without # prefix (BB convention), e.g. "333333".' ],
		'bg_color'         => [ 'type' => 'string', 'description' => 'Background color. Hex without # prefix.' ],
		'text_color'       => [ 'type' => 'string', 'description' => 'Text color. Hex without # prefix.' ],
		'name_color'       => [ 'type' => 'string', 'description' => 'Name/author color. Hex without # prefix.' ],
		'label_color'      => [ 'type' => 'string', 'description' => 'Label color. Hex without # prefix.' ],
		'label_active_color'   => [ 'type' => 'string', 'description' => 'Active tab label color.' ],
		'label_inactive_color' => [ 'type' => 'string', 'description' => 'Inactive tab label color.' ],
		'content_text_color'   => [ 'type' => 'string', 'description' => 'Content area text color.' ],
		'border_color'     => [ 'type' => 'string', 'description' => 'Border color. Hex without # prefix.' ],
		'icon_color'       => [ 'type' => 'string', 'description' => 'Icon color.' ],
		'hover_color'      => [ 'type' => 'string', 'description' => 'Hover state color.' ],
		'hover_bg_color'   => [ 'type' => 'string', 'description' => 'Hover state background color.' ],
		'btn_bg_color'     => [ 'type' => 'string', 'description' => 'Button background color.' ],
		'btn_text_color'   => [ 'type' => 'string', 'description' => 'Button text color.' ],
		'button_bg_color'  => [ 'type' => 'string', 'description' => 'Button background color.' ],
		'button_text_color' => [ 'type' => 'string', 'description' => 'Button text color.' ],
		'link_color'       => [ 'type' => 'string', 'description' => 'Link text color.' ],
		'link_hover_color' => [ 'type' => 'string', 'description' => 'Link hover color.' ],
		'title_color'      => [ 'type' => 'string', 'description' => 'Title element color.' ],
		'amount_color'     => [ 'type' => 'string', 'description' => 'Price amount color.' ],
		'duration_color'   => [ 'type' => 'string', 'description' => 'Price duration color.' ],
		'features_text_color' => [ 'type' => 'string', 'description' => 'Feature list text color.' ],
		'number_color'     => [ 'type' => 'string', 'description' => 'Counter number color.' ],
		'company_color'    => [ 'type' => 'string', 'description' => 'Company/role name color.' ],

		// ── Alignment ─────────────────────────────────────────────────────────
		'align'            => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right' ], 'description' => 'Horizontal alignment.' ],

		// ── Dimensions ────────────────────────────────────────────────────────
		'width'            => [ 'type' => 'string', 'description' => 'Element width (px, %, or "auto").' ],
		'size'             => [ 'type' => 'integer', 'description' => 'Size value (pixels).' ],
		'height'           => [ 'type' => 'integer', 'description' => 'Element height in pixels.' ],
		'icon_size'        => [ 'type' => 'integer', 'description' => 'Icon size in pixels.' ],
		'font_size'        => [ 'type' => 'object', 'description' => 'Font size settings (responsive object with length and unit).' ],
		'line_height'      => [ 'type' => 'object', 'description' => 'Line height settings (responsive object).' ],
		'letter_spacing'   => [ 'type' => 'object', 'description' => 'Letter spacing settings (responsive object).' ],
		'border_radius'    => [ 'type' => 'integer', 'description' => 'Border radius in pixels.' ],
		'gap'              => [ 'type' => 'integer', 'description' => 'Gap between items in pixels.' ],
		'bg_size'          => [ 'type' => 'integer', 'description' => 'Background/container size in pixels.' ],
		'padding_top'      => [ 'type' => 'integer', 'description' => 'Top padding in pixels.' ],
		'padding_bottom'   => [ 'type' => 'integer', 'description' => 'Bottom padding in pixels.' ],
		'padding_left'     => [ 'type' => 'integer', 'description' => 'Left padding in pixels.' ],
		'padding_right'    => [ 'type' => 'integer', 'description' => 'Right padding in pixels.' ],

		// ── Typography extras ─────────────────────────────────────────────────
		'text_transform'   => [ 'type' => 'string', 'enum' => [ 'none', 'uppercase', 'lowercase', 'capitalize' ], 'description' => 'CSS text-transform value.' ],
		'text_shadow'      => [ 'type' => 'object', 'description' => 'Text shadow settings.' ],

		// ── Slider / carousel ─────────────────────────────────────────────────
		'speed'            => [ 'type' => 'integer', 'description' => 'Transition speed in milliseconds.' ],
		'pause'            => [ 'type' => 'integer', 'description' => 'Pause duration between slides in milliseconds.' ],
		'auto_play'        => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Enable auto-play.' ],
		'arrows'           => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show prev/next arrows.' ],
		'dots'             => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show navigation dots.' ],
		'loop'             => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Loop the slideshow.' ],
		'transitionspeed'  => [ 'type' => 'integer', 'description' => 'Slide transition animation speed (ms).' ],
		'transition'       => [ 'type' => 'string', 'enum' => [ 'fade', 'slide' ], 'description' => 'Slide transition effect.' ],

		// ── Repeatable items ──────────────────────────────────────────────────
		'items'            => [ 'type' => 'array', 'description' => 'Repeatable content items (accordion panels, icon group items, etc.).' ],
		'tabs'             => [ 'type' => 'array', 'description' => 'Tab panel definitions.' ],
		'photos'           => [ 'type' => 'array', 'description' => 'Array of image attachment IDs.' ],
		'icons'            => [ 'type' => 'array', 'description' => 'Array of icon group items.' ],
		'columns'          => [ 'type' => 'array', 'description' => 'Pricing table columns or grid column count.' ],
		'fields'           => [ 'type' => 'array', 'description' => 'Form field definitions.' ],
		'networks'         => [ 'type' => 'array', 'description' => 'Social network list (facebook, twitter, etc.).' ],

		// ── Layout ────────────────────────────────────────────────────────────
		'layout'           => [ 'type' => 'string', 'description' => 'Layout style variant.' ],
		'active_tab'       => [ 'type' => 'integer', 'description' => 'Index (0-based) of the initially active tab.' ],
		'click_to_close'   => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Allow clicking an open accordion panel to close it.' ],
		'first_open'       => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Open the first accordion panel by default.' ],
		'animation'        => [ 'type' => 'string', 'description' => 'Animation type for accordion.' ],
		'caption_type'     => [ 'type' => 'string', 'enum' => [ 'none', 'below', 'hover' ], 'description' => 'How captions are displayed on gallery images.' ],
		'click_action'     => [ 'type' => 'string', 'enum' => [ 'none', 'lightbox', 'url' ], 'description' => 'Action when a gallery image is clicked.' ],
		'menu_layout'      => [ 'type' => 'string', 'enum' => [ 'horizontal', 'vertical', 'accordion' ], 'description' => 'Menu display layout.' ],
		'show_labels'      => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show field labels in the form.' ],
		'show_count'       => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show share count on social buttons.' ],
		'show_name'        => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show name field in subscribe form.' ],

		// ── Map ───────────────────────────────────────────────────────────────
		'address'          => [ 'type' => 'string', 'description' => 'Postal address or lat,lng for the map center.' ],
		'zoom'             => [ 'type' => 'integer', 'description' => 'Map zoom level (1-20).' ],
		'map_type'         => [ 'type' => 'string', 'enum' => [ 'roadmap', 'satellite', 'hybrid', 'terrain' ], 'description' => 'Google Maps map type.' ],
		'show_controls'    => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show map controls.' ],
		'show_info_window' => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show info window on map marker.' ],

		// ── Video / audio ─────────────────────────────────────────────────────
		'video_type'       => [ 'type' => 'string', 'enum' => [ 'wordpress', 'embed', 'url' ], 'description' => 'Video source type.' ],
		'video_url'        => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Video URL (YouTube, Vimeo, or direct).' ],
		'embed_code'       => [ 'type' => 'string', 'description' => 'Raw embed code for the video.' ],
		'controls'         => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show video controls.' ],
		'mute'             => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Mute video audio.' ],
		'audio'            => [ 'type' => 'integer', 'description' => 'Audio attachment ID.' ],
		'audio_url'        => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Audio file URL.' ],

		// ── Posts query ───────────────────────────────────────────────────────
		'posts_per_page'   => [ 'type' => 'integer', 'description' => 'Number of posts to display.' ],
		'post_type'        => [ 'type' => 'string', 'description' => 'WordPress post type slug.' ],
		'order'            => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'description' => 'Sort order direction.' ],
		'order_by'         => [ 'type' => 'string', 'enum' => [ 'date', 'title', 'menu_order', 'rand', 'comment_count' ], 'description' => 'Sort order field.' ],
		'categories'       => [ 'type' => 'string', 'description' => 'Comma-separated category IDs to filter.' ],
		'authors'          => [ 'type' => 'string', 'description' => 'Comma-separated author user IDs to filter.' ],
		'show_image'       => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show featured image.' ],
		'show_title'       => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show post title.' ],
		'show_excerpt'     => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show post excerpt.' ],
		'show_date'        => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show post date.' ],
		'show_author'      => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ], 'description' => 'Show post author.' ],

		// ── WP widgets / menu ─────────────────────────────────────────────────
		'sidebar'          => [ 'type' => 'string', 'description' => 'Registered sidebar/widget-area ID.' ],
		'menu'             => [ 'type' => 'string', 'description' => 'WordPress navigation menu slug or ID.' ],
		'mobile_breakpoint' => [ 'type' => 'integer', 'description' => 'Pixel width below which the mobile menu activates.' ],

		// ── Callout ───────────────────────────────────────────────────────────
		'cta_type'         => [ 'type' => 'string', 'enum' => [ 'link', 'button', 'none' ], 'description' => 'CTA type for the callout.' ],
		'icon'             => [ 'type' => 'string', 'description' => 'Icon class string, e.g. "fas fa-star".' ],

		// ── Number counter ────────────────────────────────────────────────────
		'number'           => [ 'type' => 'integer', 'description' => 'Final number for the counter animation.' ],
		'max_number'       => [ 'type' => 'integer', 'description' => 'Maximum denominator number.' ],

		// ── Email / form config ───────────────────────────────────────────────
		'to'               => [ 'type' => 'string', 'format' => 'email', 'description' => 'Recipient email address.' ],
		'from'             => [ 'type' => 'string', 'format' => 'email', 'description' => 'Sender email address.' ],
		'reply_to'         => [ 'type' => 'string', 'format' => 'email', 'description' => 'Reply-to email address.' ],
		'subject'          => [ 'type' => 'string', 'description' => 'Email subject line.' ],
		'provider'         => [ 'type' => 'string', 'description' => 'Email marketing provider integration.' ],

		// ── Responsive heights (spacer) ───────────────────────────────────────
		'responsive_height' => [ 'type' => 'integer', 'description' => 'Height in pixels for tablet breakpoint.' ],
		'mobile_height'    => [ 'type' => 'integer', 'description' => 'Height in pixels for mobile breakpoint.' ],

		// ── Link type (photo) ─────────────────────────────────────────────────
		'link_type'        => [ 'type' => 'string', 'enum' => [ 'none', 'url', 'lightbox', 'file' ], 'description' => 'Photo click action type.' ],
	];

	return $map[ $prop ] ?? [ 'type' => 'string', 'description' => "Beaver Builder '{$prop}' setting." ];
}

/**
 * Returns Beaver Builder structure documentation.
 *
 * @return array<string,mixed>
 */
function e2m_engine_beaver_structure_notes(): array {
	return [
		'hierarchy'      => 'Row → Column-Group → Column → Module',
		'data_format'    => 'PHP serialized stdClass flat node map stored in postmeta',
		'meta_key'       => '_fl_builder_data',
		'draft_key'      => '_fl_builder_draft',
		'enabled_key'    => '_fl_builder_enabled',
		'enabled_value'  => '1',
		'note'           => 'Each node has: node (13-char alphanumeric ID), type (row|column-group|column|module), parent (node ID or null), position (integer), settings (stdClass). For module nodes, settings->type holds the widget type (e.g. "heading", "html").',
		'node_id_format' => '13-character alphanumeric string generated via substr(str_replace(".", "", uniqid("", true)), -13)',
		'detection'      => 'class_exists("FLBuilder") || class_exists("FLBuilderModel") || defined("FL_BUILDER_VERSION")',
		'cache_clear'    => 'Call FLBuilder::delete_all_asset_cache($post_id) after writing data to flush BB\'s CSS/JS cache.',
	];
}
