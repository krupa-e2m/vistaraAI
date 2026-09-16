<?php
/**
 * E2M Connect MCP - Widget Shortcut Helper
 *
 * Shared plumbing for every "e2m/add-*" widget shortcut. Each shortcut
 * collapses down to: validate the post, resolve the adapter, ask it to
 * produce a widget node, insert the node at the requested location, and
 * write the tree back with inject.
 *
 * Individual shortcut files keep their own schemas (so MCP clients still
 * see a meaningful, per-widget input contract), but all of them route
 * through e2m_engine_widget_shortcut_run() to avoid drift.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Insert a widget node into a post's builder tree.
 *
 * @param array<string, mixed>       $input           The raw ability input; must carry post_id (+ optional parent_id/position).
 * @param string                     $friendly_type   E2M Connect-friendly widget slug (e.g. "heading").
 * @param array<string, mixed>|null  $settings        Pre-shaped settings map. When null, $input['settings'] is used.
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_widget_shortcut_run( array $input, string $friendly_type, ?array $settings = null ) {
	$post_id   = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$parent_id = isset( $input['parent_id'] ) && $input['parent_id'] !== ''
		? (string) $input['parent_id']
		: null;
	$position  = isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX;

	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$adapter = E2M_Builder_Registry::instance()->for_post( $post_id )
		?? E2M_Builder_Registry::instance()->get( 'gutenberg' );

	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for this post.', 'e2mconnect' ) );
	}

	if ( $settings === null ) {
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
	}

	$widget = $adapter->make_widget( $friendly_type, $settings );
	if ( is_wp_error( $widget ) ) {
		return $widget;
	}

	$tree     = $adapter->extract( $post_id );
	$elements = $tree['elements'] ?? [];

	$ok = E2M_Builder_Canonical::insert( $elements, $widget, $parent_id, $position );
	if ( ! $ok ) {
		return new WP_Error( 'parent_not_found', __( 'Target parent element not found.', 'e2mconnect' ) );
	}

	$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'    => $post_id,
		'element_id' => (string) ( $widget['id'] ?? '' ),
		'builder'    => $adapter->slug(),
		'type'       => (string) ( $widget['type'] ?? '' ),
	];
}

/**
 * Return the schema fragment shared by every widget shortcut: post_id (req),
 * optional parent_id + position. Individual shortcuts merge their own
 * widget-specific properties on top of this.
 *
 * @return array<string, array<string, mixed>>
 */
function e2m_engine_widget_shortcut_common_properties(): array {
	return [
		'post_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
		'parent_id' => [ 'type' => 'string',  'description' => 'Parent element ID. Empty or omitted = insert at root.' ],
		'position'  => [ 'type' => 'integer', 'minimum' => 0, 'description' => '0-based position inside the parent (or root). Defaults to append.' ],
	];
}

/**
 * Standard output schema for widget shortcuts.
 *
 * @return array<string, mixed>
 */
function e2m_engine_widget_shortcut_output_schema(): array {
	return [
		'type'       => 'object',
		'properties' => [
			'post_id'    => [ 'type' => 'integer' ],
			'element_id' => [ 'type' => 'string' ],
			'builder'    => [ 'type' => 'string' ],
			'type'       => [ 'type' => 'string' ],
		],
	];
}

/**
 * Boilerplate "annotations" block for widget shortcut abilities.
 *
 * @return array<string, mixed>
 */
function e2m_engine_widget_shortcut_annotations( string $title ): array {
	return [
		'title'       => $title,
		'readonly'    => false,
		'destructive' => false,
		'idempotent'  => false,
	];
}
