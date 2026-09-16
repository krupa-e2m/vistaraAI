<?php
/**
 * E2M Connect MCP – WPBakery Patterns
 *
 * Ready-to-use WPBakery Page Builder layout patterns and a shortcode node
 * factory helper.
 *
 * Each pattern ships both as a E2M Connect tree array (for programmatic
 * manipulation) and as a raw WPBakery shortcode string (for direct
 * insertion into post_content).
 *
 * Delegates to respira_get_wpbakery_patterns() when available; otherwise
 * uses the full built-in catalogue.
 *
 * Actions:
 *   list              — list all patterns (with optional category filter)
 *   get               — get a single pattern's full structure
 *   categories        — list pattern categories
 *   create_shortcode  — build a {type, attributes, content, children} node
 *                       and the equivalent shortcode string
 *
 * Built-in patterns:
 *   hero-section, three-column-features, call-to-action,
 *   image-with-text, faq-accordion, pricing-table
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/wpbakery-patterns', [
	'label'       => __( '[WPBakery] Patterns', 'e2mconnect' ),
	'description' => 'Ready-to-use WPBakery layout patterns: hero, features, CTA, image+text, FAQ accordion, pricing. List, get, create individual shortcode nodes with both tree and shortcode_string representations.',
	'category'    => 'e2m-wpbakery',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories', 'create_shortcode' ],
				'description' => 'list — all patterns; get — single pattern by ID; categories — pattern categories; create_shortcode — build a shortcode node.',
			],
			// list
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by pattern category.',
			],
			// get
			'pattern_id' => [
				'type'        => 'string',
				'description' => 'Pattern slug for get, e.g. "hero-section", "pricing-table".',
			],
			// create_shortcode
			'type' => [
				'type'        => 'string',
				'description' => 'Shortcode tag for create_shortcode, e.g. "vc_row", "vc_column_text".',
			],
			'attributes' => [
				'type'                 => 'object',
				'description'          => 'Shortcode attributes (key/value pairs).',
				'additionalProperties' => true,
			],
			'content' => [
				'type'        => 'string',
				'description' => 'Inner text content for create_shortcode (used for vc_column_text etc).',
			],
			'children' => [
				'type'        => 'array',
				'description' => 'Child shortcode nodes for create_shortcode.',
				'items'       => [ 'type' => 'object' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'patterns'   => [ 'type' => 'array' ],
			'pattern'    => [ 'type' => 'object' ],
			'categories' => [ 'type' => 'array' ],
			'node'       => [ 'type' => 'object' ],
			'total'      => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_wpbakery_patterns',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'WPBakery: Patterns',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute wpbakery-patterns ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_wpbakery_patterns( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_wpbakery() ) {
		return new WP_Error( 'wpbakery_missing', __( 'WPBakery Page Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	// Resolve pattern catalogue.
	$all_patterns = function_exists( 'respira_get_wpbakery_patterns' )
		? respira_get_wpbakery_patterns()
		: e2m_engine_wpbakery_builtin_patterns();

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$cat_filter = sanitize_key( $input['category'] ?? '' );
			$list       = [];
			foreach ( $all_patterns as $id => $pattern ) {
				if ( $cat_filter && ( $pattern['category'] ?? '' ) !== $cat_filter ) {
					continue;
				}
				$list[] = [
					'id'          => $id,
					'title'       => $pattern['title'] ?? $id,
					'description' => $pattern['description'] ?? '',
					'category'    => $pattern['category'] ?? 'general',
				];
			}
			return [
				'action'   => 'list',
				'patterns' => $list,
				'total'    => count( $list ),
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['pattern_id'] ) ) {
				return new WP_Error( 'missing_pattern_id', __( '"pattern_id" is required for get.', 'e2mconnect' ) );
			}
			$pid = sanitize_key( $input['pattern_id'] );
			if ( ! isset( $all_patterns[ $pid ] ) ) {
				return new WP_Error( 'pattern_not_found', sprintf( __( 'Pattern "%s" not found.', 'e2mconnect' ), $pid ) );
			}
			return [
				'action'  => 'get',
				'pattern' => array_merge( [ 'id' => $pid ], $all_patterns[ $pid ] ),
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_patterns as $pattern ) {
				$cat        = $pattern['category'] ?? 'general';
				$cats[$cat] = ( $cats[$cat] ?? 0 ) + 1;
			}
			$result = [];
			foreach ( $cats as $name => $count ) {
				$result[] = [ 'category' => $name, 'pattern_count' => $count ];
			}
			return [
				'action'     => 'categories',
				'categories' => $result,
				'total'      => count( $result ),
			];

		// ── Create shortcode ──────────────────────────────────────────────────
		case 'create_shortcode':
			if ( empty( $input['type'] ) ) {
				return new WP_Error( 'missing_type', __( '"type" (shortcode tag) is required for create_shortcode.', 'e2mconnect' ) );
			}

			$type       = sanitize_text_field( $input['type'] );
			$attributes = is_array( $input['attributes'] ?? null ) ? $input['attributes'] : [];
			$content    = (string) ( $input['content'] ?? '' );
			$children   = is_array( $input['children'] ?? null ) ? $input['children'] : [];

			$node = [
				'type'       => $type,
				'attributes' => $attributes,
				'content'    => $content,
				'children'   => $children,
			];

			$shortcode_string = e2m_engine_wpbakery_tree_to_shortcodes( [ $node ] );

			return [
				'action'           => 'create_shortcode',
				'node'             => $node,
				'shortcode_string' => $shortcode_string,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, categories, create_shortcode.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Built-in pattern catalogue
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get the full built-in WPBakery pattern catalogue.
 *
 * Each pattern has:
 *   title, description, category, structure (tree array), shortcode_string.
 *
 * @return array<string,array<string,mixed>>
 */
function e2m_engine_wpbakery_builtin_patterns(): array {

	// ── Pattern helper: build a tree node inline ────────────────────────────
	$n = static function ( string $type, array $attrs = [], string $content = '', array $children = [] ): array {
		return [
			'type'       => $type,
			'attributes' => $attrs,
			'content'    => $content,
			'children'   => $children,
		];
	};

	// ── hero-section ─────────────────────────────────────────────────────────
	$hero_tree = [
		$n( 'vc_row', [ 'full_width' => 'stretch_row_content_no_spaces' ], '', [
			$n( 'vc_column', [ 'width' => '1/1' ], '', [
				$n( 'vc_custom_heading', [
					'text'           => 'Welcome to Our Amazing Service',
					'font_container' => 'tag:h1|font_size:48|color:%23ffffff|line_height:1.2',
					'css'            => '.vc_custom_heading{text-align:center;}',
				] ),
				$n( 'vc_column_text', [], '<p style="text-align:center;color:#ffffff;font-size:18px;">Your compelling tagline goes here. Convert visitors into customers.</p>' ),
				$n( 'vc_btn', [
					'title' => 'Get Started',
					'color' => 'white',
					'size'  => 'lg',
					'align' => 'center',
					'link'  => 'url:%23||',
				] ),
			] ),
		] ),
	];

	// ── three-column-features ─────────────────────────────────────────────────
	$features_tree = [
		$n( 'vc_row', [], '', [
			$n( 'vc_column', [ 'width' => '1/3' ], '', [
				$n( 'vc_custom_heading', [
					'text'           => 'Feature One',
					'font_container' => 'tag:h3|font_size:24|color:%23333333',
				] ),
				$n( 'vc_column_text', [], '<p>Brief description of this feature and why it matters to your audience.</p>' ),
			] ),
			$n( 'vc_column', [ 'width' => '1/3' ], '', [
				$n( 'vc_custom_heading', [
					'text'           => 'Feature Two',
					'font_container' => 'tag:h3|font_size:24|color:%23333333',
				] ),
				$n( 'vc_column_text', [], '<p>Brief description of this feature and why it matters to your audience.</p>' ),
			] ),
			$n( 'vc_column', [ 'width' => '1/3' ], '', [
				$n( 'vc_custom_heading', [
					'text'           => 'Feature Three',
					'font_container' => 'tag:h3|font_size:24|color:%23333333',
				] ),
				$n( 'vc_column_text', [], '<p>Brief description of this feature and why it matters to your audience.</p>' ),
			] ),
		] ),
	];

	// ── call-to-action ────────────────────────────────────────────────────────
	$cta_tree = [
		$n( 'vc_row', [], '', [
			$n( 'vc_column', [ 'width' => '1/1' ], '', [
				$n( 'vc_cta', [
					'h2'        => 'Ready to Get Started?',
					'h4'        => 'Join thousands of satisfied customers today.',
					'shape'     => 'rounded',
					'style'     => 'flat',
					'color'     => 'blue',
					'add_button'=> 'bottom',
					'btn_title' => 'Start Free Trial',
					'btn_color' => 'white',
					'btn_link'  => 'url:%23||',
					'btn_align' => 'center',
				] ),
			] ),
		] ),
	];

	// ── image-with-text ───────────────────────────────────────────────────────
	$image_text_tree = [
		$n( 'vc_row', [], '', [
			$n( 'vc_column', [ 'width' => '1/2' ], '', [
				$n( 'vc_single_image', [
					'image'    => '',
					'img_size' => 'large',
					'alignment'=> 'center',
				] ),
			] ),
			$n( 'vc_column', [ 'width' => '1/2' ], '', [
				$n( 'vc_custom_heading', [
					'text'           => 'Why Choose Us',
					'font_container' => 'tag:h2|font_size:32|color:%23333333',
				] ),
				$n( 'vc_column_text', [], '<p>Detailed description of why your product or service stands out from the competition. Highlight key benefits and unique value propositions.</p>' ),
				$n( 'vc_btn', [
					'title' => 'Learn More',
					'color' => 'btn-primary',
					'size'  => 'md',
					'align' => 'left',
					'link'  => 'url:%23||',
				] ),
			] ),
		] ),
	];

	// ── faq-accordion ─────────────────────────────────────────────────────────
	$faq_tree = [
		$n( 'vc_row', [], '', [
			$n( 'vc_column', [ 'width' => '1/1' ], '', [
				$n( 'vc_custom_heading', [
					'text'           => 'Frequently Asked Questions',
					'font_container' => 'tag:h2|font_size:36|color:%23333333',
					'css'            => '.vc_custom_heading{text-align:center;margin-bottom:30px;}',
				] ),
				$n( 'vc_accordion', [ 'active_tab' => '1' ], '', [
					$n( 'vc_accordion_tab', [ 'title' => 'What is your return policy?' ], '', [
						$n( 'vc_column_text', [], '<p>We offer a full 30-day money-back guarantee on all purchases. If you are not completely satisfied, contact our support team for a prompt refund.</p>' ),
					] ),
					$n( 'vc_accordion_tab', [ 'title' => 'How long does shipping take?' ], '', [
						$n( 'vc_column_text', [], '<p>Standard shipping takes 5-7 business days. Express shipping (2-3 days) is available at checkout for an additional fee.</p>' ),
					] ),
					$n( 'vc_accordion_tab', [ 'title' => 'Do you offer customer support?' ], '', [
						$n( 'vc_column_text', [], '<p>Yes! We provide 24/7 email support and live chat during business hours (9am-6pm EST, Mon-Fri).</p>' ),
					] ),
				] ),
			] ),
		] ),
	];

	// ── pricing-table ─────────────────────────────────────────────────────────
	$pricing_tree = [
		$n( 'vc_row', [], '', [
			$n( 'vc_column', [ 'width' => '1/3' ], '', [
				$n( 'vc_column_text', [], '
<div style="text-align:center;padding:30px;border:1px solid #eeeeee;border-radius:8px;">
  <h3 style="font-size:24px;">Basic</h3>
  <p style="font-size:36px;color:#0066cc;font-weight:700;">$9<span style="font-size:16px;">/mo</span></p>
  <ul style="list-style:none;padding:0;margin:20px 0;">
    <li>5 Projects</li>
    <li>10GB Storage</li>
    <li>Email Support</li>
  </ul>
</div>' ),
				$n( 'vc_btn', [
					'title' => 'Get Started',
					'color' => 'btn-primary',
					'size'  => 'md',
					'align' => 'center',
					'link'  => 'url:%23||',
				] ),
			] ),
			$n( 'vc_column', [ 'width' => '1/3' ], '', [
				$n( 'vc_column_text', [], '
<div style="text-align:center;padding:30px;border:2px solid #0066cc;border-radius:8px;background:#f0f7ff;">
  <h3 style="font-size:24px;">Professional</h3>
  <p style="font-size:36px;color:#0066cc;font-weight:700;">$29<span style="font-size:16px;">/mo</span></p>
  <ul style="list-style:none;padding:0;margin:20px 0;">
    <li>25 Projects</li>
    <li>100GB Storage</li>
    <li>Priority Support</li>
  </ul>
</div>' ),
				$n( 'vc_btn', [
					'title' => 'Get Started',
					'color' => 'btn-primary',
					'size'  => 'md',
					'align' => 'center',
					'link'  => 'url:%23||',
				] ),
			] ),
			$n( 'vc_column', [ 'width' => '1/3' ], '', [
				$n( 'vc_column_text', [], '
<div style="text-align:center;padding:30px;border:1px solid #eeeeee;border-radius:8px;">
  <h3 style="font-size:24px;">Enterprise</h3>
  <p style="font-size:36px;color:#0066cc;font-weight:700;">$99<span style="font-size:16px;">/mo</span></p>
  <ul style="list-style:none;padding:0;margin:20px 0;">
    <li>Unlimited Projects</li>
    <li>1TB Storage</li>
    <li>24/7 Support</li>
  </ul>
</div>' ),
				$n( 'vc_btn', [
					'title' => 'Contact Sales',
					'color' => 'btn-primary',
					'size'  => 'md',
					'align' => 'center',
					'link'  => 'url:%23||',
				] ),
			] ),
		] ),
	];

	return [

		'hero-section' => [
			'title'            => 'Hero Section',
			'description'      => 'Full-width hero section with custom heading, tagline, and CTA button.',
			'category'         => 'headers',
			'structure'        => $hero_tree,
			'shortcode_string' => e2m_engine_wpbakery_tree_to_shortcodes( $hero_tree ),
		],

		'three-column-features' => [
			'title'            => 'Three Column Features',
			'description'      => 'Three equal columns each with a heading and descriptive text.',
			'category'         => 'content',
			'structure'        => $features_tree,
			'shortcode_string' => e2m_engine_wpbakery_tree_to_shortcodes( $features_tree ),
		],

		'call-to-action' => [
			'title'            => 'Call to Action',
			'description'      => 'Full-width CTA block using vc_cta with heading, description, and button.',
			'category'         => 'cta',
			'structure'        => $cta_tree,
			'shortcode_string' => e2m_engine_wpbakery_tree_to_shortcodes( $cta_tree ),
		],

		'image-with-text' => [
			'title'            => 'Image with Text',
			'description'      => 'Two-column layout with image on the left and heading, text, button on the right.',
			'category'         => 'content',
			'structure'        => $image_text_tree,
			'shortcode_string' => e2m_engine_wpbakery_tree_to_shortcodes( $image_text_tree ),
		],

		'faq-accordion' => [
			'title'            => 'FAQ Accordion',
			'description'      => 'Frequently asked questions displayed in a vc_accordion with three tabs.',
			'category'         => 'content',
			'structure'        => $faq_tree,
			'shortcode_string' => e2m_engine_wpbakery_tree_to_shortcodes( $faq_tree ),
		],

		'pricing-table' => [
			'title'            => 'Pricing Table',
			'description'      => 'Three-column pricing table with Basic, Professional, and Enterprise plans.',
			'category'         => 'content',
			'structure'        => $pricing_tree,
			'shortcode_string' => e2m_engine_wpbakery_tree_to_shortcodes( $pricing_tree ),
		],

	];
}
