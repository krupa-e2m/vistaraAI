<?php
/**
 * E2M Connect MCP – Breakdance Patterns
 *
 * Ready-to-use Breakdance layout patterns and element builder helpers.
 *
 * Actions:
 *   list           — list all available patterns (with optional category filter)
 *   get            — get a single pattern's full element tree
 *   categories     — list pattern categories
 *   create_element — helper to build a properly structured Breakdance element node
 *
 * Built-in patterns:
 *   hero-section, three-column-features, call-to-action, testimonial-grid,
 *   faq-accordion, pricing-table, image-text-split, post-loop
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/breakdance-patterns', [
	'label'       => __( '[Breakdance] Patterns', 'e2mconnect' ),
	'description' => 'Ready-to-use Breakdance layout patterns: hero, features, CTA, testimonial grid, FAQ, pricing, image+text, post loop. List, get, or create individual element nodes.',
	'category'    => 'e2m-breakdance',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'categories', 'create_element' ],
				'description' => 'list — all patterns; get — single pattern; categories — pattern categories; create_element — build an element node.',
			],
			'category' => [
				'type'        => 'string',
				'description' => 'Filter list by pattern category.',
			],
			'pattern_id' => [
				'type'        => 'string',
				'description' => 'Pattern slug for get, e.g. "hero-section".',
			],
			// create_element
			'type' => [
				'type'        => 'string',
				'description' => 'Namespaced element type for create_element, e.g. "EssentialElements\\Heading".',
			],
			'content' => [
				'type'                 => 'object',
				'description'          => 'data.content fields for create_element.',
				'additionalProperties' => true,
			],
			'design' => [
				'type'                 => 'object',
				'description'          => 'data.design fields for create_element.',
				'additionalProperties' => true,
			],
			'children' => [
				'type'        => 'array',
				'description' => 'Child element nodes for create_element.',
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
			'element'    => [ 'type' => 'object', 'description' => 'Single element node returned by create_element.' ],
			'total'      => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_breakdance_patterns',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Breakdance: Patterns',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute breakdance-patterns ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_breakdance_patterns( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_breakdance() ) {
		return new WP_Error( 'breakdance_missing', __( 'Breakdance Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	// Get patterns from Respira if available, else use built-in.
	$all_patterns = function_exists( 'respira_get_breakdance_patterns' )
		? respira_get_breakdance_patterns()
		: e2m_engine_breakdance_builtin_patterns();

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
				return new WP_Error(
					'pattern_not_found',
					sprintf( __( 'Pattern "%s" not found.', 'e2mconnect' ), $pid )
				);
			}
			return [
				'action'  => 'get',
				'pattern' => array_merge( [ 'id' => $pid ], $all_patterns[ $pid ] ),
			];

		// ── Categories ────────────────────────────────────────────────────────
		case 'categories':
			$cats = [];
			foreach ( $all_patterns as $pattern ) {
				$cat          = $pattern['category'] ?? 'general';
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

		// ── Create element ────────────────────────────────────────────────────
		case 'create_element':
			if ( empty( $input['type'] ) ) {
				return new WP_Error( 'missing_type', __( '"type" (element type string) is required for create_element.', 'e2mconnect' ) );
			}
			$el_type  = sanitize_text_field( $input['type'] );
			$content  = is_array( $input['content'] ?? null )  ? $input['content']  : [];
			$design   = is_array( $input['design'] ?? null )   ? $input['design']   : [];
			$children = is_array( $input['children'] ?? null ) ? $input['children'] : [];

			$element = e2m_engine_breakdance_make_element( $el_type, $content, $design, $children );
			return [
				'action'  => 'create_element',
				'element' => $element,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, categories, create_element.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Element factory helper
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Create a single properly-structured Breakdance element node.
 *
 * @param string              $type      Namespaced element type string.
 * @param array<string,mixed> $content   data.content fields.
 * @param array<string,mixed> $design    data.design fields.
 * @param array<int,array>    $children  Child element nodes.
 * @param int                 $id        Integer ID; uses rand(1000,9999) if 0.
 * @return array<string,mixed>
 */
function e2m_engine_breakdance_make_element(
	string $type,
	array $content,
	array $design,
	array $children,
	int $id = 0
): array {
	return [
		'id'       => $id > 0 ? $id : rand( 1000, 9999 ),
		'type'     => $type,
		'data'     => [
			'content' => $content,
			'design'  => $design,
		],
		'children' => $children,
	];
}

// ──────────────────────────────────────────────────────────────────────────────
// Built-in pattern catalogue
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get the built-in Breakdance layout pattern catalogue.
 *
 * Patterns use static integer IDs so they are deterministic and diff-safe.
 * The tree structure follows Breakdance's nested format:
 * {id, type, data:{content:{}, design:{}}, children:[...]}.
 *
 * @return array<string,array<string,mixed>>
 */
function e2m_engine_breakdance_builtin_patterns(): array {
	return [

		// ── Hero Section ──────────────────────────────────────────────────────
		'hero-section' => [
			'title'       => 'Hero Section',
			'description' => 'Full-width hero section with heading, subtitle, and CTA button.',
			'category'    => 'headers',
			'structure'   => [
				[
					'id'   => 1001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#0066cc',
							'padding'         => '80px 40px',
							'display'         => 'flex',
							'flexDirection'   => 'column',
							'alignItems'      => 'center',
							'justifyContent'  => 'center',
						],
					],
					'children' => [
						[
							'id'   => 1002,
							'type' => 'EssentialElements\\Heading',
							'data' => [
								'content' => [
									'text' => 'Welcome to Our Amazing Service',
									'tag'  => 'h1',
								],
								'design'  => [
									'color'      => '#ffffff',
									'fontSize'   => '48px',
									'fontWeight' => '700',
									'textAlign'  => 'center',
								],
							],
							'children' => [],
						],
						[
							'id'   => 1003,
							'type' => 'EssentialElements\\Text',
							'data' => [
								'content' => [
									'text' => 'Your compelling tagline goes here. Explain what makes you different.',
								],
								'design'  => [
									'color'     => '#e0eeff',
									'fontSize'  => '20px',
									'textAlign' => 'center',
									'margin'    => '16px 0 32px',
								],
							],
							'children' => [],
						],
						[
							'id'   => 1004,
							'type' => 'EssentialElements\\Button',
							'data' => [
								'content' => [
									'text'       => 'Get Started',
									'url'        => '#',
									'linkTarget' => '_self',
								],
								'design'  => [
									'backgroundColor' => '#ffffff',
									'color'           => '#0066cc',
									'borderRadius'    => '4px',
									'padding'         => '14px 32px',
									'fontSize'        => '16px',
									'fontWeight'      => '600',
								],
							],
							'children' => [],
						],
					],
				],
			],
		],

		// ── Three Column Features ─────────────────────────────────────────────
		'three-column-features' => [
			'title'       => 'Three Column Features',
			'description' => 'Three-column layout with icon, heading, and description in each column.',
			'category'    => 'content',
			'structure'   => [
				[
					'id'   => 2001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#ffffff',
							'padding'         => '60px 40px',
						],
					],
					'children' => [
						[
							'id'   => 2002,
							'type' => 'EssentialElements\\Columns',
							'data' => [
								'content' => [],
								'design'  => [
									'gridTemplateColumns' => 'repeat(3, 1fr)',
									'gap'                 => '32px',
								],
							],
							'children' => [
								[
									'id'   => 2003,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'display'       => 'flex',
											'flexDirection' => 'column',
											'alignItems'    => 'center',
											'padding'       => '24px',
											'textAlign'     => 'center',
										],
									],
									'children' => [
										[
											'id'   => 2004,
											'type' => 'EssentialElements\\Icon',
											'data' => [
												'content' => [ 'icon' => 'fas fa-star' ],
												'design'  => [ 'iconColor' => '#0066cc', 'iconSize' => '48px' ],
											],
											'children' => [],
										],
										[
											'id'   => 2005,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Feature One', 'tag' => 'h3' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '22px', 'fontWeight' => '600', 'margin' => '16px 0 8px' ],
											],
											'children' => [],
										],
										[
											'id'   => 2006,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'Brief description of this feature and how it helps your users.' ],
												'design'  => [ 'color' => '#666666', 'fontSize' => '16px' ],
											],
											'children' => [],
										],
									],
								],
								[
									'id'   => 2007,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'display'       => 'flex',
											'flexDirection' => 'column',
											'alignItems'    => 'center',
											'padding'       => '24px',
											'textAlign'     => 'center',
										],
									],
									'children' => [
										[
											'id'   => 2008,
											'type' => 'EssentialElements\\Icon',
											'data' => [
												'content' => [ 'icon' => 'fas fa-bolt' ],
												'design'  => [ 'iconColor' => '#0066cc', 'iconSize' => '48px' ],
											],
											'children' => [],
										],
										[
											'id'   => 2009,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Feature Two', 'tag' => 'h3' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '22px', 'fontWeight' => '600', 'margin' => '16px 0 8px' ],
											],
											'children' => [],
										],
										[
											'id'   => 2010,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'Brief description of this feature and how it helps your users.' ],
												'design'  => [ 'color' => '#666666', 'fontSize' => '16px' ],
											],
											'children' => [],
										],
									],
								],
								[
									'id'   => 2011,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'display'       => 'flex',
											'flexDirection' => 'column',
											'alignItems'    => 'center',
											'padding'       => '24px',
											'textAlign'     => 'center',
										],
									],
									'children' => [
										[
											'id'   => 2012,
											'type' => 'EssentialElements\\Icon',
											'data' => [
												'content' => [ 'icon' => 'fas fa-heart' ],
												'design'  => [ 'iconColor' => '#0066cc', 'iconSize' => '48px' ],
											],
											'children' => [],
										],
										[
											'id'   => 2013,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Feature Three', 'tag' => 'h3' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '22px', 'fontWeight' => '600', 'margin' => '16px 0 8px' ],
											],
											'children' => [],
										],
										[
											'id'   => 2014,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'Brief description of this feature and how it helps your users.' ],
												'design'  => [ 'color' => '#666666', 'fontSize' => '16px' ],
											],
											'children' => [],
										],
									],
								],
							],
						],
					],
				],
			],
		],

		// ── Call to Action ────────────────────────────────────────────────────
		'call-to-action' => [
			'title'       => 'Call to Action',
			'description' => 'Centered CTA section with heading, text, and button on a dark background.',
			'category'    => 'cta',
			'structure'   => [
				[
					'id'   => 3001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#1a1a2e',
							'padding'         => '80px 40px',
							'display'         => 'flex',
							'flexDirection'   => 'column',
							'alignItems'      => 'center',
						],
					],
					'children' => [
						[
							'id'   => 3002,
							'type' => 'EssentialElements\\Heading',
							'data' => [
								'content' => [ 'text' => 'Ready to Get Started?', 'tag' => 'h2' ],
								'design'  => [
									'color'      => '#ffffff',
									'fontSize'   => '40px',
									'fontWeight' => '700',
									'textAlign'  => 'center',
								],
							],
							'children' => [],
						],
						[
							'id'   => 3003,
							'type' => 'EssentialElements\\Text',
							'data' => [
								'content' => [ 'text' => 'Join thousands of satisfied customers today. No credit card required.' ],
								'design'  => [
									'color'     => '#cccccc',
									'fontSize'  => '18px',
									'textAlign' => 'center',
									'margin'    => '16px 0 32px',
								],
							],
							'children' => [],
						],
						[
							'id'   => 3004,
							'type' => 'EssentialElements\\Button',
							'data' => [
								'content' => [ 'text' => 'Start Free Trial', 'url' => '#', 'linkTarget' => '_self' ],
								'design'  => [
									'backgroundColor' => '#e94560',
									'color'           => '#ffffff',
									'borderRadius'    => '4px',
									'padding'         => '14px 32px',
									'fontSize'        => '16px',
									'fontWeight'      => '600',
								],
							],
							'children' => [],
						],
					],
				],
			],
		],

		// ── Testimonial Grid ──────────────────────────────────────────────────
		'testimonial-grid' => [
			'title'       => 'Testimonial Grid',
			'description' => 'Two-column grid of testimonial cards with quote, author, and company.',
			'category'    => 'content',
			'structure'   => [
				[
					'id'   => 4001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#f8f9fa',
							'padding'         => '60px 40px',
						],
					],
					'children' => [
						[
							'id'   => 4002,
							'type' => 'EssentialElements\\Heading',
							'data' => [
								'content' => [ 'text' => 'What Our Customers Say', 'tag' => 'h2' ],
								'design'  => [
									'color'      => '#222222',
									'fontSize'   => '36px',
									'fontWeight' => '700',
									'textAlign'  => 'center',
									'margin'     => '0 0 40px',
								],
							],
							'children' => [],
						],
						[
							'id'   => 4003,
							'type' => 'EssentialElements\\Columns',
							'data' => [
								'content' => [],
								'design'  => [
									'gridTemplateColumns' => 'repeat(2, 1fr)',
									'gap'                 => '24px',
								],
							],
							'children' => [
								[
									'id'   => 4004,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'backgroundColor' => '#ffffff',
											'borderRadius'    => '8px',
											'padding'         => '32px',
										],
									],
									'children' => [
										[
											'id'   => 4005,
											'type' => 'EssentialElements\\RichText',
											'data' => [
												'content' => [ 'text' => '"This product completely transformed how we work. The results exceeded all our expectations."' ],
												'design'  => [ 'color' => '#333333', 'fontSize' => '16px' ],
											],
											'children' => [],
										],
										[
											'id'   => 4006,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Jane Smith', 'tag' => 'h4' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '16px', 'fontWeight' => '600', 'margin' => '16px 0 4px' ],
											],
											'children' => [],
										],
										[
											'id'   => 4007,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'CEO, Acme Corp' ],
												'design'  => [ 'color' => '#999999', 'fontSize' => '14px' ],
											],
											'children' => [],
										],
									],
								],
								[
									'id'   => 4008,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'backgroundColor' => '#ffffff',
											'borderRadius'    => '8px',
											'padding'         => '32px',
										],
									],
									'children' => [
										[
											'id'   => 4009,
											'type' => 'EssentialElements\\RichText',
											'data' => [
												'content' => [ 'text' => '"Incredible service and outstanding support team. We saw a 40% increase in conversions within the first month."' ],
												'design'  => [ 'color' => '#333333', 'fontSize' => '16px' ],
											],
											'children' => [],
										],
										[
											'id'   => 4010,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'John Davis', 'tag' => 'h4' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '16px', 'fontWeight' => '600', 'margin' => '16px 0 4px' ],
											],
											'children' => [],
										],
										[
											'id'   => 4011,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'Marketing Director, TechCo' ],
												'design'  => [ 'color' => '#999999', 'fontSize' => '14px' ],
											],
											'children' => [],
										],
									],
								],
							],
						],
					],
				],
			],
		],

		// ── FAQ Accordion ─────────────────────────────────────────────────────
		'faq-accordion' => [
			'title'       => 'FAQ Accordion',
			'description' => 'Frequently asked questions in an accordion layout with schema markup support.',
			'category'    => 'content',
			'structure'   => [
				[
					'id'   => 5001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#ffffff',
							'padding'         => '60px 40px',
						],
					],
					'children' => [
						[
							'id'   => 5002,
							'type' => 'EssentialElements\\Heading',
							'data' => [
								'content' => [ 'text' => 'Frequently Asked Questions', 'tag' => 'h2' ],
								'design'  => [
									'color'      => '#222222',
									'fontSize'   => '36px',
									'fontWeight' => '700',
									'textAlign'  => 'center',
									'margin'     => '0 0 40px',
								],
							],
							'children' => [],
						],
						[
							'id'   => 5003,
							'type' => 'EssentialElements\\FAQ',
							'data' => [
								'content' => [
									'items' => [
										[
											'question' => 'How do I get started?',
											'answer'   => 'Getting started is easy. Simply sign up for a free account and follow the onboarding wizard to set up your workspace.',
										],
										[
											'question' => 'What payment methods do you accept?',
											'answer'   => 'We accept all major credit cards (Visa, Mastercard, AmEx), PayPal, and bank transfers for annual plans.',
										],
										[
											'question' => 'Can I cancel at any time?',
											'answer'   => 'Yes, you can cancel your subscription at any time from your account dashboard. No cancellation fees apply.',
										],
										[
											'question' => 'Do you offer customer support?',
											'answer'   => 'We offer 24/7 email support for all plans, plus live chat support for Professional and Enterprise plans.',
										],
									],
									'firstOpen'    => true,
									'multipleOpen' => false,
								],
								'design'  => [
									'color'           => '#222222',
									'backgroundColor' => '#f8f9fa',
									'borderRadius'    => '8px',
								],
							],
							'children' => [],
						],
					],
				],
			],
		],

		// ── Pricing Table ─────────────────────────────────────────────────────
		'pricing-table' => [
			'title'       => 'Pricing Table',
			'description' => 'Three-column pricing comparison with feature lists and CTA buttons.',
			'category'    => 'content',
			'structure'   => [
				[
					'id'   => 6001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#f8f9fa',
							'padding'         => '60px 40px',
						],
					],
					'children' => [
						[
							'id'   => 6002,
							'type' => 'EssentialElements\\Heading',
							'data' => [
								'content' => [ 'text' => 'Choose Your Plan', 'tag' => 'h2' ],
								'design'  => [
									'color'      => '#222222',
									'fontSize'   => '36px',
									'fontWeight' => '700',
									'textAlign'  => 'center',
									'margin'     => '0 0 40px',
								],
							],
							'children' => [],
						],
						[
							'id'   => 6003,
							'type' => 'EssentialElements\\Columns',
							'data' => [
								'content' => [],
								'design'  => [
									'gridTemplateColumns' => 'repeat(3, 1fr)',
									'gap'                 => '24px',
									'alignItems'          => 'stretch',
								],
							],
							'children' => [
								// Basic Plan.
								[
									'id'   => 6004,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'backgroundColor' => '#ffffff',
											'borderRadius'    => '8px',
											'padding'         => '40px 32px',
											'display'         => 'flex',
											'flexDirection'   => 'column',
											'alignItems'      => 'center',
										],
									],
									'children' => [
										[
											'id'   => 6005,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Basic', 'tag' => 'h3' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '22px', 'fontWeight' => '600', 'textAlign' => 'center' ],
											],
											'children' => [],
										],
										[
											'id'   => 6006,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => '$9', 'tag' => 'h2' ],
												'design'  => [ 'color' => '#0066cc', 'fontSize' => '48px', 'fontWeight' => '700', 'textAlign' => 'center' ],
											],
											'children' => [],
										],
										[
											'id'   => 6007,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'per month' ],
												'design'  => [ 'color' => '#999999', 'fontSize' => '14px', 'textAlign' => 'center', 'margin' => '0 0 24px' ],
											],
											'children' => [],
										],
										[
											'id'   => 6008,
											'type' => 'EssentialElements\\CheckmarkList',
											'data' => [
												'content' => [
													'items' => [ '5 Projects', '10GB Storage', 'Email Support', 'Basic Analytics' ],
												],
												'design'  => [ 'color' => '#333333', 'fontSize' => '15px', 'iconColor' => '#0066cc' ],
											],
											'children' => [],
										],
										[
											'id'   => 6009,
											'type' => 'EssentialElements\\Button',
											'data' => [
												'content' => [ 'text' => 'Get Started', 'url' => '#', 'linkTarget' => '_self' ],
												'design'  => [
													'backgroundColor' => '#0066cc',
													'color'           => '#ffffff',
													'borderRadius'    => '4px',
													'padding'         => '12px 28px',
													'fontSize'        => '15px',
													'margin'          => '24px 0 0',
												],
											],
											'children' => [],
										],
									],
								],
								// Professional Plan.
								[
									'id'   => 6010,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'backgroundColor' => '#0066cc',
											'borderRadius'    => '8px',
											'padding'         => '40px 32px',
											'display'         => 'flex',
											'flexDirection'   => 'column',
											'alignItems'      => 'center',
										],
									],
									'children' => [
										[
											'id'   => 6011,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Professional', 'tag' => 'h3' ],
												'design'  => [ 'color' => '#ffffff', 'fontSize' => '22px', 'fontWeight' => '600', 'textAlign' => 'center' ],
											],
											'children' => [],
										],
										[
											'id'   => 6012,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => '$29', 'tag' => 'h2' ],
												'design'  => [ 'color' => '#ffffff', 'fontSize' => '48px', 'fontWeight' => '700', 'textAlign' => 'center' ],
											],
											'children' => [],
										],
										[
											'id'   => 6013,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'per month' ],
												'design'  => [ 'color' => '#cce0ff', 'fontSize' => '14px', 'textAlign' => 'center', 'margin' => '0 0 24px' ],
											],
											'children' => [],
										],
										[
											'id'   => 6014,
											'type' => 'EssentialElements\\CheckmarkList',
											'data' => [
												'content' => [
													'items' => [ '25 Projects', '100GB Storage', 'Priority Support', 'Advanced Analytics', 'Custom Domain' ],
												],
												'design'  => [ 'color' => '#ffffff', 'fontSize' => '15px', 'iconColor' => '#ffffff' ],
											],
											'children' => [],
										],
										[
											'id'   => 6015,
											'type' => 'EssentialElements\\Button',
											'data' => [
												'content' => [ 'text' => 'Get Started', 'url' => '#', 'linkTarget' => '_self' ],
												'design'  => [
													'backgroundColor' => '#ffffff',
													'color'           => '#0066cc',
													'borderRadius'    => '4px',
													'padding'         => '12px 28px',
													'fontSize'        => '15px',
													'fontWeight'      => '600',
													'margin'          => '24px 0 0',
												],
											],
											'children' => [],
										],
									],
								],
								// Enterprise Plan.
								[
									'id'   => 6016,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'backgroundColor' => '#ffffff',
											'borderRadius'    => '8px',
											'padding'         => '40px 32px',
											'display'         => 'flex',
											'flexDirection'   => 'column',
											'alignItems'      => 'center',
										],
									],
									'children' => [
										[
											'id'   => 6017,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Enterprise', 'tag' => 'h3' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '22px', 'fontWeight' => '600', 'textAlign' => 'center' ],
											],
											'children' => [],
										],
										[
											'id'   => 6018,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => '$99', 'tag' => 'h2' ],
												'design'  => [ 'color' => '#0066cc', 'fontSize' => '48px', 'fontWeight' => '700', 'textAlign' => 'center' ],
											],
											'children' => [],
										],
										[
											'id'   => 6019,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'per month' ],
												'design'  => [ 'color' => '#999999', 'fontSize' => '14px', 'textAlign' => 'center', 'margin' => '0 0 24px' ],
											],
											'children' => [],
										],
										[
											'id'   => 6020,
											'type' => 'EssentialElements\\CheckmarkList',
											'data' => [
												'content' => [
													'items' => [ 'Unlimited Projects', '1TB Storage', '24/7 Support', 'Custom Analytics', 'Custom Domain', 'SLA Guarantee' ],
												],
												'design'  => [ 'color' => '#333333', 'fontSize' => '15px', 'iconColor' => '#0066cc' ],
											],
											'children' => [],
										],
										[
											'id'   => 6021,
											'type' => 'EssentialElements\\Button',
											'data' => [
												'content' => [ 'text' => 'Contact Sales', 'url' => '#', 'linkTarget' => '_self' ],
												'design'  => [
													'backgroundColor' => '#0066cc',
													'color'           => '#ffffff',
													'borderRadius'    => '4px',
													'padding'         => '12px 28px',
													'fontSize'        => '15px',
													'margin'          => '24px 0 0',
												],
											],
											'children' => [],
										],
									],
								],
							],
						],
					],
				],
			],
		],

		// ── Image Text Split ──────────────────────────────────────────────────
		'image-text-split' => [
			'title'       => 'Image Text Split',
			'description' => 'Two-column layout with image on left and heading, text, button on right.',
			'category'    => 'content',
			'structure'   => [
				[
					'id'   => 7001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#ffffff',
							'padding'         => '60px 40px',
						],
					],
					'children' => [
						[
							'id'   => 7002,
							'type' => 'EssentialElements\\Columns',
							'data' => [
								'content' => [],
								'design'  => [
									'gridTemplateColumns' => '1fr 1fr',
									'gap'                 => '48px',
									'alignItems'          => 'center',
								],
							],
							'children' => [
								[
									'id'   => 7003,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [],
									],
									'children' => [
										[
											'id'   => 7004,
											'type' => 'EssentialElements\\Image',
											'data' => [
												'content' => [
													'image' => 'https://via.placeholder.com/600x450',
													'alt'   => 'Feature image',
												],
												'design'  => [
													'width'        => '100%',
													'height'       => 'auto',
													'borderRadius' => '8px',
													'objectFit'    => 'cover',
												],
											],
											'children' => [],
										],
									],
								],
								[
									'id'   => 7005,
									'type' => 'EssentialElements\\Column',
									'data' => [
										'content' => [],
										'design'  => [
											'display'       => 'flex',
											'flexDirection' => 'column',
											'justifyContent'=> 'center',
										],
									],
									'children' => [
										[
											'id'   => 7006,
											'type' => 'EssentialElements\\Heading',
											'data' => [
												'content' => [ 'text' => 'Why Choose Us', 'tag' => 'h2' ],
												'design'  => [ 'color' => '#222222', 'fontSize' => '36px', 'fontWeight' => '700', 'margin' => '0 0 16px' ],
											],
											'children' => [],
										],
										[
											'id'   => 7007,
											'type' => 'EssentialElements\\Text',
											'data' => [
												'content' => [ 'text' => 'Detailed description of why your product or service stands out from the competition. Share your unique value proposition here.' ],
												'design'  => [ 'color' => '#555555', 'fontSize' => '16px', 'margin' => '0 0 24px' ],
											],
											'children' => [],
										],
										[
											'id'   => 7008,
											'type' => 'EssentialElements\\Button',
											'data' => [
												'content' => [ 'text' => 'Learn More', 'url' => '#', 'linkTarget' => '_self' ],
												'design'  => [
													'backgroundColor' => '#0066cc',
													'color'           => '#ffffff',
													'borderRadius'    => '4px',
													'padding'         => '12px 28px',
													'fontSize'        => '15px',
												],
											],
											'children' => [],
										],
									],
								],
							],
						],
					],
				],
			],
		],

		// ── Post Loop ─────────────────────────────────────────────────────────
		'post-loop' => [
			'title'       => 'Post Loop',
			'description' => 'Dynamic post loop builder displaying recent posts in a three-column grid.',
			'category'    => 'dynamic',
			'structure'   => [
				[
					'id'   => 8001,
					'type' => 'EssentialElements\\Section',
					'data' => [
						'content' => [],
						'design'  => [
							'backgroundColor' => '#ffffff',
							'padding'         => '60px 40px',
						],
					],
					'children' => [
						[
							'id'   => 8002,
							'type' => 'EssentialElements\\Heading',
							'data' => [
								'content' => [ 'text' => 'Latest Posts', 'tag' => 'h2' ],
								'design'  => [
									'color'      => '#222222',
									'fontSize'   => '36px',
									'fontWeight' => '700',
									'textAlign'  => 'center',
									'margin'     => '0 0 40px',
								],
							],
							'children' => [],
						],
						[
							'id'   => 8003,
							'type' => 'EssentialElements\\PostLoopBuilder',
							'data' => [
								'content' => [
									'postType'    => 'post',
									'postsPerPage'=> 6,
									'orderBy'     => 'date',
									'order'       => 'DESC',
									'columns'     => 3,
									'layout'      => 'grid',
								],
								'design'  => [
									'gap'             => '24px',
									'backgroundColor' => '#ffffff',
								],
							],
							'children' => [
								[
									'id'   => 8004,
									'type' => 'EssentialElements\\PostFeaturedImage',
									'data' => [
										'content' => [],
										'design'  => [
											'width'        => '100%',
											'height'       => '220px',
											'objectFit'    => 'cover',
											'borderRadius' => '8px 8px 0 0',
										],
									],
									'children' => [],
								],
								[
									'id'   => 8005,
									'type' => 'EssentialElements\\PostTitle',
									'data' => [
										'content' => [ 'tag' => 'h3' ],
										'design'  => [
											'color'      => '#222222',
											'fontSize'   => '18px',
											'fontWeight' => '600',
											'margin'     => '16px 0 8px',
										],
									],
									'children' => [],
								],
								[
									'id'   => 8006,
									'type' => 'EssentialElements\\PostExcerpt',
									'data' => [
										'content' => [],
										'design'  => [
											'color'    => '#666666',
											'fontSize' => '14px',
											'margin'   => '0 0 16px',
										],
									],
									'children' => [],
								],
							],
						],
					],
				],
			],
		],

	];
}
