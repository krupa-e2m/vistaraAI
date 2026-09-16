<?php
/**
 * E2M Connect MCP - Convert HTML
 *
 * Parses a chunk of raw HTML and produces a canonical element tree that can
 * be injected into any builder. The converter focuses on the common blog /
 * landing-page subset: headings, paragraphs, images, lists, buttons, and
 * generic HTML fallbacks for anything it does not recognise.
 *
 * The output is intentionally small and deterministic; it is not a full
 * visual parser. Use build-page to write the result into a post.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/convert-html', [
	'label'       => __( '[Builder] Convert HTML', 'e2mconnect' ),
	'description' => 'Converts a raw HTML string into a canonical builder tree suitable for build-page.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'html'    => [ 'type' => 'string', 'minLength' => 1 ],
			'builder' => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg', 'bricks' ], 'default' => 'gutenberg' ],
		],
		'required'             => [ 'html' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'builder'  => [ 'type' => 'string' ],
			'elements' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_convert_html_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Convert HTML to Builder',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the convert-html ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_convert_html_ability( array $input ) {
	$html    = isset( $input['html'] ) ? (string) $input['html'] : '';
	$builder = isset( $input['builder'] ) ? sanitize_key( (string) $input['builder'] ) : 'gutenberg';

	if ( trim( $html ) === '' ) {
		return new WP_Error( 'invalid_html', __( 'A non-empty HTML payload is required.', 'e2mconnect' ) );
	}

	$adapter = E2M_Builder_Registry::instance()->get( $builder );
	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'Unknown builder.', 'e2mconnect' ) );
	}

	$elements = e2m_engine_html_to_canonical( $html, $adapter );

	return [
		'builder'  => $builder,
		'elements' => $elements,
	];
}

/**
 * Very small HTML -> canonical converter. Walks the document body and
 * produces one canonical widget per top-level element it recognises.
 * Anything unknown becomes an "html" widget with the original markup.
 *
 * @return array<int, array<string, mixed>>
 */
function e2m_engine_html_to_canonical( string $html, E2M_Builder_Adapter_Interface $adapter ): array {
	$doc                     = new DOMDocument();
	$doc->preserveWhiteSpace = false;
	$doc->substituteEntities = false;

	// Wrap input so DOMDocument can parse partial documents without warnings.
	$wrapper = '<?xml encoding="utf-8" ?><div id="e2m-wrap">' . $html . '</div>';
	libxml_use_internal_errors( true );
	$doc->loadHTML( $wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$wrap = $doc->getElementById( 'e2m-wrap' );
	$out  = [];
	if ( ! $wrap ) {
		return $out;
	}

	foreach ( $wrap->childNodes as $child ) {
		$canonical = e2m_engine_dom_to_canonical( $child, $adapter );
		if ( $canonical !== null ) {
			$out[] = $canonical;
		}
	}

	return $out;
}

/**
 * Map a single DOM node to a canonical element via the adapter's widget map.
 * Returns null for empty text nodes so the caller skips them.
 *
 * @return array<string, mixed>|null
 */
function e2m_engine_dom_to_canonical( DOMNode $node, E2M_Builder_Adapter_Interface $adapter ): ?array {
	if ( $node->nodeType === XML_TEXT_NODE ) {
		$text = trim( (string) $node->nodeValue );
		if ( $text === '' ) {
			return null;
		}
		return e2m_engine_widget_or_html( $adapter, 'text', [ 'text' => $text, '__inner_html' => wp_kses_post( $text ) ] );
	}

	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return null;
	}

	/** @var DOMElement $node */
	$tag  = strtolower( $node->nodeName );
	$html = $node->ownerDocument ? $node->ownerDocument->saveHTML( $node ) : '';

	switch ( $tag ) {
		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			return e2m_engine_widget_or_html(
				$adapter,
				'heading',
				[
					'title'        => wp_strip_all_tags( (string) $node->textContent ),
					'header_size'  => $tag,
					'level'        => (int) substr( $tag, 1 ),
					'__inner_html' => $html,
				]
			);

		case 'p':
			return e2m_engine_widget_or_html(
				$adapter,
				'text',
				[
					'editor'       => wp_kses_post( (string) $node->ownerDocument->saveHTML( $node ) ),
					'__inner_html' => $html,
				]
			);

		case 'img':
			/** @var DOMElement $node */
			return e2m_engine_widget_or_html(
				$adapter,
				'image',
				[
					'url' => (string) $node->getAttribute( 'src' ),
					'alt' => (string) $node->getAttribute( 'alt' ),
					'image' => [ 'url' => (string) $node->getAttribute( 'src' ) ],
					'__inner_html' => $html,
				]
			);

		case 'a':
			/** @var DOMElement $node */
			return e2m_engine_widget_or_html(
				$adapter,
				'button',
				[
					'text' => wp_strip_all_tags( (string) $node->textContent ),
					'link' => [ 'url' => (string) $node->getAttribute( 'href' ) ],
					'__inner_html' => $html,
				]
			);

		case 'ul':
		case 'ol':
			return e2m_engine_widget_or_html(
				$adapter,
				'icon-list',
				[ '__inner_html' => $html ]
			);

		case 'hr':
			return e2m_engine_widget_or_html( $adapter, 'divider', [ '__inner_html' => $html ] );

		default:
			return e2m_engine_widget_or_html( $adapter, 'html', [ 'html' => $html, '__inner_html' => $html ] );
	}
}

/**
 * Ask the adapter for a widget of the given friendly type, falling back to
 * the adapter's "html" widget when the type is not in its map.
 *
 * @return array<string, mixed>
 */
function e2m_engine_widget_or_html( E2M_Builder_Adapter_Interface $adapter, string $friendly, array $settings ): array {
	$widget = $adapter->make_widget( $friendly, $settings );
	if ( is_wp_error( $widget ) ) {
		$fallback = $adapter->make_widget( 'html', $settings );
		return is_wp_error( $fallback ) ? [] : $fallback;
	}
	return $widget;
}
