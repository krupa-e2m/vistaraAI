<?php
/**
 * E2M Connect MCP – Oxygen Patterns
 *
 * Ready-to-use Oxygen Builder layout patterns + component builder helpers.
 *
 * Actions:
 *   list       — list all available patterns (with optional category filter)
 *   get        — get a single pattern's full component structure
 *   categories — list pattern categories
 *   create_component — helper to create a single Oxygen component object
 *
 * Built-in patterns (mirrors respira oxygen-patterns.php):
 *   hero-section, three-column-features, call-to-action, testimonial,
 *   pricing-table, image-with-text, faq-accordion, team-grid,
 *   footer, header-bar
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/oxygen-patterns', [
	'label'       => __( '[Oxygen] Patterns', 'e2mconnect' ),
	'description' => 'Ready-to-use Oxygen Builder layout patterns: hero, features, CTA, testimonial, pricing, image+text, FAQ, team, footer, header. List, get, or create individual component objects.',
	'category'    => 'e2m-oxygen',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories', 'create_component' ],
				'description' => 'list — all patterns; get — single pattern; categories — pattern categories; create_component — build a component object.',
			],
			// list
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by pattern category.',
			],
			// get
			'pattern_id' => [
				'type'        => 'string',
				'description' => 'Pattern slug for get, e.g. "hero-section", "footer".',
			],
			// create_component
			'name' => [
				'type'        => 'string',
				'description' => 'Component type slug for create_component, e.g. "ct_headline".',
			],
			'options' => [
				'type'                 => 'object',
				'description'          => 'Component options/settings for create_component.',
				'additionalProperties' => true,
			],
			'children' => [
				'type'        => 'array',
				'description' => 'Child component objects for create_component.',
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
			'component'  => [ 'type' => 'object' ],
			'total'      => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_oxygen_patterns',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Oxygen: Patterns',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute oxygen-patterns ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_oxygen_patterns( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_oxygen() ) {
		return new WP_Error( 'oxygen_missing', __( 'Oxygen Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	// Get patterns from Respira if available, else use built-in.
	$all_patterns = function_exists( 'respira_get_oxygen_patterns' )
		? respira_get_oxygen_patterns()
		: e2m_engine_oxygen_get_builtin_patterns();

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
				$cat = $pattern['category'] ?? 'general';
				$cats[ $cat ] = ( $cats[ $cat ] ?? 0 ) + 1;
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

		// ── Create component ──────────────────────────────────────────────────
		case 'create_component':
			if ( empty( $input['name'] ) ) {
				return new WP_Error( 'missing_name', __( '"name" (component type) is required for create_component.', 'e2mconnect' ) );
			}
			$component = e2m_engine_oxygen_make_component(
				sanitize_text_field( $input['name'] ),
				is_array( $input['options'] ?? null ) ? $input['options'] : [],
				is_array( $input['children'] ?? null ) ? $input['children'] : []
			);
			return [
				'action'    => 'create_component',
				'component' => $component,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, categories, create_component.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Component factory helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Create an Oxygen component structure (e2mconnect-namespaced).
 *
 * @param string $name     Component type slug.
 * @param array  $options  Options/settings.
 * @param array  $children Child components.
 * @return array<string,mixed>
 */
function e2m_engine_oxygen_make_component( string $name, array $options = [], array $children = [] ): array {
	$base = str_replace( [ 'ct_', 'oxy_' ], '', $name );
	$component = [
		'id'      => $base . '_' . uniqid(),
		'name'    => $name,
		'options' => $options,
	];
	if ( ! empty( $children ) ) {
		$component['children'] = $children;
	}
	return $component;
}

// ──────────────────────────────────────────────────────────────────────────────
// Built-in pattern catalogue (mirrors respira oxygen-patterns.php)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get the built-in Oxygen layout pattern catalogue.
 *
 * @return array<string,array>
 */
function e2m_engine_oxygen_get_builtin_patterns(): array {
	// Helper alias for cleaner pattern definitions.
	$mc = 'e2m_engine_oxygen_make_component';

	return [

		'hero-section' => [
			'title'       => 'Hero Section',
			'description' => 'Full-width hero section with heading and CTA button.',
			'category'    => 'headers',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'width' => '100%', 'background-color' => '#0066cc', 'padding' => '80px 20px' ], [
						$mc( 'ct_headline',   [ 'ct_content' => 'Welcome to Our Amazing Service', 'tag' => 'h1', 'font-size' => '48px', 'color' => '#ffffff', 'text-align' => 'center' ] ),
						$mc( 'ct_text_block', [ 'ct_content' => '<p>Your compelling tagline goes here</p>', 'font-size' => '18px', 'color' => '#ffffff', 'text-align' => 'center', 'margin' => '20px 0 30px 0' ] ),
						$mc( 'ct_link_button', [ 'ct_content' => 'Get Started', 'url' => '#', 'background-color' => '#ffffff', 'color' => '#0066cc', 'padding' => '15px 30px' ] ),
					] ),
				] ),
			],
		],

		'three-column-features' => [
			'title'       => 'Three Column Features',
			'description' => 'Three-column layout with icon and text features.',
			'category'    => 'content',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'padding' => '60px 20px', 'background-color' => '#ffffff' ], [
						$mc( 'ct_headline', [ 'ct_content' => 'Our Features', 'tag' => 'h2', 'font-size' => '36px', 'text-align' => 'center', 'margin' => '0 0 40px 0' ] ),
						$mc( 'ct_new_columns', [ 'column-count' => 3, 'column-gap' => '30px' ], [
							$mc( 'ct_column', [ 'text-align' => 'center', 'padding' => '20px' ], [
								$mc( 'ct_fancy_icon', [ 'icon-id' => 'FontAwesomeicon-star', 'icon-size' => '48px', 'icon-color' => '#0066cc' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => 'Feature One', 'tag' => 'h3', 'font-size' => '24px', 'margin' => '15px 0 10px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Brief description of this feature.</p>', 'color' => '#666666' ] ),
							] ),
							$mc( 'ct_column', [ 'text-align' => 'center', 'padding' => '20px' ], [
								$mc( 'ct_fancy_icon', [ 'icon-id' => 'FontAwesomeicon-bolt', 'icon-size' => '48px', 'icon-color' => '#0066cc' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => 'Feature Two', 'tag' => 'h3', 'font-size' => '24px', 'margin' => '15px 0 10px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Brief description of this feature.</p>', 'color' => '#666666' ] ),
							] ),
							$mc( 'ct_column', [ 'text-align' => 'center', 'padding' => '20px' ], [
								$mc( 'ct_fancy_icon', [ 'icon-id' => 'FontAwesomeicon-heart', 'icon-size' => '48px', 'icon-color' => '#0066cc' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => 'Feature Three', 'tag' => 'h3', 'font-size' => '24px', 'margin' => '15px 0 10px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Brief description of this feature.</p>', 'color' => '#666666' ] ),
							] ),
						] ),
					] ),
				] ),
			],
		],

		'call-to-action' => [
			'title'       => 'Call to Action',
			'description' => 'Centered CTA section with heading and button.',
			'category'    => 'cta',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'background-color' => '#1a1a2e', 'padding' => '80px 20px', 'text-align' => 'center' ], [
						$mc( 'ct_headline',   [ 'ct_content' => 'Ready to Get Started?', 'tag' => 'h2', 'font-size' => '36px', 'color' => '#ffffff' ] ),
						$mc( 'ct_text_block', [ 'ct_content' => '<p>Join thousands of satisfied customers today.</p>', 'font-size' => '18px', 'color' => '#cccccc', 'margin' => '15px 0 30px 0' ] ),
						$mc( 'ct_link_button', [ 'ct_content' => 'Start Free Trial', 'url' => '#', 'background-color' => '#e94560', 'color' => '#ffffff', 'padding' => '15px 40px', 'border-radius' => '5px', 'font-size' => '18px' ] ),
					] ),
				] ),
			],
		],

		'testimonial' => [
			'title'       => 'Testimonial',
			'description' => 'Testimonial with quote and author.',
			'category'    => 'testimonials',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'background-color' => '#f8f9fa', 'padding' => '60px 20px', 'text-align' => 'center' ], [
						$mc( 'ct_div_block', [ 'width' => '700px', 'margin' => '0 auto', 'padding' => '40px' ], [
							$mc( 'ct_fancy_icon', [ 'icon-id' => 'FontAwesomeicon-quote-left', 'icon-size' => '36px', 'icon-color' => '#0066cc' ] ),
							$mc( 'ct_text_block', [ 'ct_content' => '<p>"This product completely transformed how we work. The results exceeded all our expectations."</p>', 'font-size' => '20px', 'color' => '#333333', 'line-height' => '1.6', 'margin' => '20px 0' ] ),
							$mc( 'ct_headline',   [ 'ct_content' => 'Jane Smith', 'tag' => 'h4', 'font-size' => '18px', 'color' => '#333333' ] ),
							$mc( 'ct_text_block', [ 'ct_content' => '<p>CEO, Acme Corp</p>', 'font-size' => '14px', 'color' => '#999999' ] ),
						] ),
					] ),
				] ),
			],
		],

		'pricing-table' => [
			'title'       => 'Pricing Table',
			'description' => 'Three-column pricing comparison.',
			'category'    => 'pricing',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'padding' => '60px 20px', 'background-color' => '#ffffff' ], [
						$mc( 'ct_headline', [ 'ct_content' => 'Choose Your Plan', 'tag' => 'h2', 'font-size' => '36px', 'text-align' => 'center', 'margin' => '0 0 40px 0' ] ),
						$mc( 'ct_new_columns', [ 'column-count' => 3, 'column-gap' => '20px' ], [
							$mc( 'ct_column', [ 'padding' => '30px', 'border-width' => '1px', 'border-style' => 'solid', 'border-color' => '#eeeeee', 'border-radius' => '8px', 'text-align' => 'center' ], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Basic', 'tag' => 'h3', 'font-size' => '24px' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => '$9/mo', 'tag' => 'p', 'font-size' => '36px', 'color' => '#0066cc', 'margin' => '10px 0 20px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<ul><li>5 Projects</li><li>10GB Storage</li><li>Email Support</li></ul>', 'text-align' => 'left' ] ),
								$mc( 'ct_link_button', [ 'ct_content' => 'Get Started', 'url' => '#', 'background-color' => '#0066cc', 'color' => '#ffffff', 'padding' => '12px 30px', 'margin' => '20px 0 0 0' ] ),
							] ),
							$mc( 'ct_column', [ 'padding' => '30px', 'border-width' => '2px', 'border-style' => 'solid', 'border-color' => '#0066cc', 'border-radius' => '8px', 'text-align' => 'center', 'background-color' => '#f0f7ff' ], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Professional', 'tag' => 'h3', 'font-size' => '24px' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => '$29/mo', 'tag' => 'p', 'font-size' => '36px', 'color' => '#0066cc', 'margin' => '10px 0 20px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<ul><li>25 Projects</li><li>100GB Storage</li><li>Priority Support</li></ul>', 'text-align' => 'left' ] ),
								$mc( 'ct_link_button', [ 'ct_content' => 'Get Started', 'url' => '#', 'background-color' => '#0066cc', 'color' => '#ffffff', 'padding' => '12px 30px', 'margin' => '20px 0 0 0' ] ),
							] ),
							$mc( 'ct_column', [ 'padding' => '30px', 'border-width' => '1px', 'border-style' => 'solid', 'border-color' => '#eeeeee', 'border-radius' => '8px', 'text-align' => 'center' ], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Enterprise', 'tag' => 'h3', 'font-size' => '24px' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => '$99/mo', 'tag' => 'p', 'font-size' => '36px', 'color' => '#0066cc', 'margin' => '10px 0 20px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<ul><li>Unlimited Projects</li><li>1TB Storage</li><li>24/7 Support</li></ul>', 'text-align' => 'left' ] ),
								$mc( 'ct_link_button', [ 'ct_content' => 'Contact Sales', 'url' => '#', 'background-color' => '#0066cc', 'color' => '#ffffff', 'padding' => '12px 30px', 'margin' => '20px 0 0 0' ] ),
							] ),
						] ),
					] ),
				] ),
			],
		],

		'image-with-text' => [
			'title'       => 'Image with Text',
			'description' => 'Two-column layout with image and text.',
			'category'    => 'content',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'padding' => '60px 20px' ], [
						$mc( 'ct_new_columns', [ 'column-count' => 2, 'column-gap' => '40px' ], [
							$mc( 'ct_column', [], [
								$mc( 'ct_image', [ 'src' => 'https://via.placeholder.com/600x400', 'alt' => 'Feature image', 'width' => '100%', 'object-fit' => 'cover', 'border-radius' => '8px' ] ),
							] ),
							$mc( 'ct_column', [ 'display' => 'flex', 'flex-direction' => 'column', 'justify-content' => 'center' ], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Why Choose Us', 'tag' => 'h2', 'font-size' => '32px', 'margin' => '0 0 15px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Detailed description of why your product stands out.</p>', 'font-size' => '16px', 'color' => '#555555', 'line-height' => '1.7' ] ),
								$mc( 'ct_link_button', [ 'ct_content' => 'Learn More', 'url' => '#', 'background-color' => '#0066cc', 'color' => '#ffffff', 'padding' => '12px 24px', 'margin' => '20px 0 0 0' ] ),
							] ),
						] ),
					] ),
				] ),
			],
		],

		'faq-accordion' => [
			'title'       => 'FAQ Accordion',
			'description' => 'Frequently asked questions with accordion.',
			'category'    => 'content',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'padding' => '60px 20px', 'background-color' => '#ffffff' ], [
						$mc( 'ct_headline', [ 'ct_content' => 'Frequently Asked Questions', 'tag' => 'h2', 'font-size' => '36px', 'text-align' => 'center', 'margin' => '0 0 40px 0' ] ),
						$mc( 'ct_div_block', [ 'width' => '800px', 'margin' => '0 auto' ], [
							$mc( 'oxy_toggle', [ 'heading' => 'What is your return policy?', 'content' => 'We offer a 30-day money-back guarantee.' ] ),
							$mc( 'oxy_toggle', [ 'heading' => 'How long does shipping take?', 'content' => 'Standard 5-7 days. Express 2-3 days.' ] ),
							$mc( 'oxy_toggle', [ 'heading' => 'Do you offer support?', 'content' => 'Yes, 24/7 email support and live chat during business hours.' ] ),
						] ),
					] ),
				] ),
			],
		],

		'team-grid' => [
			'title'       => 'Team Grid',
			'description' => 'Team members grid with photos and roles.',
			'category'    => 'content',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'padding' => '60px 20px', 'background-color' => '#f8f9fa' ], [
						$mc( 'ct_headline', [ 'ct_content' => 'Meet Our Team', 'tag' => 'h2', 'font-size' => '36px', 'text-align' => 'center', 'margin' => '0 0 40px 0' ] ),
						$mc( 'ct_new_columns', [ 'column-count' => 3, 'column-gap' => '30px' ], [
							$mc( 'ct_column', [ 'text-align' => 'center', 'padding' => '20px' ], [
								$mc( 'ct_image',      [ 'src' => 'https://via.placeholder.com/200x200', 'alt' => 'Team member', 'width' => '150px', 'height' => '150px', 'border-radius' => '50%', 'object-fit' => 'cover' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => 'Alex Johnson', 'tag' => 'h4', 'font-size' => '20px', 'margin' => '15px 0 5px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>CEO & Founder</p>', 'color' => '#999999' ] ),
							] ),
							$mc( 'ct_column', [ 'text-align' => 'center', 'padding' => '20px' ], [
								$mc( 'ct_image',      [ 'src' => 'https://via.placeholder.com/200x200', 'alt' => 'Team member', 'width' => '150px', 'height' => '150px', 'border-radius' => '50%', 'object-fit' => 'cover' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => 'Sarah Williams', 'tag' => 'h4', 'font-size' => '20px', 'margin' => '15px 0 5px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Lead Developer</p>', 'color' => '#999999' ] ),
							] ),
							$mc( 'ct_column', [ 'text-align' => 'center', 'padding' => '20px' ], [
								$mc( 'ct_image',      [ 'src' => 'https://via.placeholder.com/200x200', 'alt' => 'Team member', 'width' => '150px', 'height' => '150px', 'border-radius' => '50%', 'object-fit' => 'cover' ] ),
								$mc( 'ct_headline',   [ 'ct_content' => 'Mike Chen', 'tag' => 'h4', 'font-size' => '20px', 'margin' => '15px 0 5px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Designer</p>', 'color' => '#999999' ] ),
							] ),
						] ),
					] ),
				] ),
			],
		],

		'footer' => [
			'title'       => 'Footer',
			'description' => 'Site footer with 4 columns and copyright.',
			'category'    => 'footers',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_section', [ 'background-color' => '#1a1a2e', 'padding' => '60px 20px 30px 20px', 'color' => '#cccccc' ], [
						$mc( 'ct_new_columns', [ 'column-count' => 4, 'column-gap' => '30px' ], [
							$mc( 'ct_column', [], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Company', 'tag' => 'h4', 'font-size' => '18px', 'color' => '#ffffff', 'margin' => '0 0 15px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p>Building amazing products since 2020.</p>', 'color' => '#aaaaaa' ] ),
							] ),
							$mc( 'ct_column', [], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Links', 'tag' => 'h4', 'font-size' => '18px', 'color' => '#ffffff', 'margin' => '0 0 15px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p><a href="#">About</a></p><p><a href="#">Blog</a></p><p><a href="#">Careers</a></p>', 'color' => '#aaaaaa' ] ),
							] ),
							$mc( 'ct_column', [], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Support', 'tag' => 'h4', 'font-size' => '18px', 'color' => '#ffffff', 'margin' => '0 0 15px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p><a href="#">Help Center</a></p><p><a href="#">Contact</a></p>', 'color' => '#aaaaaa' ] ),
							] ),
							$mc( 'ct_column', [], [
								$mc( 'ct_headline',   [ 'ct_content' => 'Legal', 'tag' => 'h4', 'font-size' => '18px', 'color' => '#ffffff', 'margin' => '0 0 15px 0' ] ),
								$mc( 'ct_text_block', [ 'ct_content' => '<p><a href="#">Privacy</a></p><p><a href="#">Terms</a></p>', 'color' => '#aaaaaa' ] ),
							] ),
						] ),
						$mc( 'ct_div_block', [ 'border-top-width' => '1px', 'border-style' => 'solid', 'border-color' => '#333333', 'margin' => '40px 0 0 0', 'padding' => '20px 0 0 0', 'text-align' => 'center' ], [
							$mc( 'ct_text_block', [ 'ct_content' => '<p>&copy; 2026 Your Company. All rights reserved.</p>', 'color' => '#888888', 'font-size' => '14px' ] ),
						] ),
					] ),
				] ),
			],
		],

		'header-bar' => [
			'title'       => 'Header Bar',
			'description' => 'Sticky header with logo, menu, and CTA.',
			'category'    => 'headers',
			'structure'   => [
				'code' => wp_json_encode( [
					$mc( 'ct_header_row', [ 'background-color' => '#ffffff', 'padding' => '15px 20px', 'position' => 'sticky', 'z-index' => 100, 'box-shadow' => '0 2px 10px rgba(0,0,0,0.05)' ], [
						$mc( 'oxy_header_left',   [ 'width' => '200px' ], [
							$mc( 'ct_image', [ 'src' => 'https://via.placeholder.com/150x40', 'alt' => 'Logo', 'height' => '40px' ] ),
						] ),
						$mc( 'oxy_header_center', [], [
							$mc( 'oxy_pro_menu', [ 'menu' => 'primary', 'orientation' => 'horizontal', 'mobile_breakpoint' => 768 ] ),
						] ),
						$mc( 'oxy_header_right',  [ 'width' => '200px', 'text-align' => 'right' ], [
							$mc( 'ct_link_button', [ 'ct_content' => 'Get Started', 'url' => '#', 'background-color' => '#0066cc', 'color' => '#ffffff', 'padding' => '10px 20px', 'border-radius' => '5px', 'font-size' => '14px' ] ),
						] ),
					] ),
				] ),
			],
		],

	];
}
