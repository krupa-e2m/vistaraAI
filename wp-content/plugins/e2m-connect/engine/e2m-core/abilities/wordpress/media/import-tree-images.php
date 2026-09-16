<?php
/**
 * E2M Connect MCP - Import Tree Images
 *
 * Recursively walks a post's entire builder tree (any depth - containers,
 * nested atomic widgets, gallery/carousel/slider items, background images)
 * and rewrites every remote image URL it finds into a local Media Library
 * attachment, deduplicating against existing uploads by content hash.
 *
 * Single-image `e2m/sideload-image` already exists for the "I have one URL"
 * case; this ability closes the gap for "I have a whole built page and want
 * every image reference resolved and deduplicated in one pass."
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/import-tree-images', [
	'label'       => __( '[Media] Import Tree Images', 'e2mconnect' ),
	'description' => 'Walks a post\'s entire builder tree, sideloads every remote image it finds (deduplicated by content hash against the Media Library), and rewrites the tree to point at local attachments.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'builder' => [
				'type'        => 'string',
				'enum'        => [ 'auto', 'elementor', 'gutenberg', 'bricks' ],
				'default'     => 'auto',
				'description' => 'Force a specific adapter. "auto" detects from the stored post.',
			],
			'dry_run' => [
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'When true, report what would be imported/deduped without writing anything back.',
			],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'builder'   => [ 'type' => 'string' ],
			'dry_run'   => [ 'type' => 'boolean' ],
			'imported'  => [ 'type' => 'integer', 'description' => 'Remote URLs newly sideloaded into the Media Library.' ],
			'deduped'   => [ 'type' => 'integer', 'description' => 'Remote URLs matched to an existing attachment by content hash.' ],
			'rewritten' => [ 'type' => 'integer', 'description' => 'Total URL references rewritten in the tree (imported + deduped).' ],
			'failed'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'url'   => [ 'type' => 'string' ],
						'error' => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_import_tree_images_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Import Tree Images',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the import-tree-images ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_import_tree_images_ability( array $input ) {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to upload files.', 'e2mconnect' ) );
	}

	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$dry_run = ! empty( $input['dry_run'] );
	$builder = isset( $input['builder'] ) ? sanitize_key( (string) $input['builder'] ) : 'auto';

	$adapter = ( $builder !== '' && $builder !== 'auto' )
		? E2M_Builder_Registry::instance()->get( $builder )
		: E2M_Builder_Registry::instance()->for_post( $post_id );

	if ( ! $adapter ) {
		return new WP_Error( 'adapter_missing', __( 'No builder adapter could be resolved for this post.', 'e2mconnect' ) );
	}

	$tree     = $adapter->extract( $post_id );
	$elements = (array) ( $tree['elements'] ?? [] );

	$stats = [
		'imported'  => 0,
		'deduped'   => 0,
		'rewritten' => 0,
		'failed'    => [],
	];

	// A single remote URL that appears more than once in the tree (e.g. the
	// same background image on three sliders) is only sideloaded once; every
	// occurrence after the first is rewritten from this in-request cache.
	$resolved = [];

	E2M_Builder_Canonical::walk(
		$elements,
		function ( &$node ) use ( &$resolved, &$stats, $dry_run ) {
			if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
				return;
			}
			e2m_engine_import_tree_images_walk_settings( $node['settings'], $resolved, $stats, $dry_run );
		}
	);

	if ( ! $dry_run && ( $stats['imported'] > 0 || $stats['deduped'] > 0 ) ) {
		$write = $adapter->inject( $post_id, [ 'elements' => $elements ] );
		if ( is_wp_error( $write ) ) {
			return $write;
		}
	}

	return [
		'post_id'   => $post_id,
		'builder'   => (string) ( $tree['builder'] ?? $builder ),
		'dry_run'   => $dry_run,
		'imported'  => $stats['imported'],
		'deduped'   => $stats['deduped'],
		'rewritten' => $stats['imported'] + $stats['deduped'],
		'failed'    => $stats['failed'],
	];
}

/**
 * Recursively walk a settings array (a single element's `settings` blob) and
 * resolve every remote image URL found under an `image`-like shape. Handles:
 *   - a single image object:      settings.image = ['id' => ..., 'url' => ...]
 *   - a bare url field:           settings.url = 'https://...'
 *   - a background image object:  settings.background_image = ['url' => ...]
 *   - a list of image objects:    settings.images = [['url' => ...], ...]
 *   - raw markup (Gutenberg):     settings.__inner_html / __inner_content,
 *                                  e.g. a core/image block's <img src="...">
 *                                  which never surfaces as a structured attr
 * at any nesting depth, since widget settings shapes vary by widget/adapter.
 *
 * @param array<string, mixed>                            $settings
 * @param array<string, int|null>                          $resolved Map of source URL -> attachment id (null = "seen in a dry run"), shared across the whole tree walk.
 * @param array{imported:int, deduped:int, failed:array}    $stats
 */
function e2m_engine_import_tree_images_walk_settings( array &$settings, array &$resolved, array &$stats, bool $dry_run ): void {
	foreach ( $settings as $key => &$value ) {
		if ( $key === '__inner_html' && is_string( $value ) ) {
			$value = e2m_engine_import_tree_images_rewrite_html( $value, $resolved, $stats, $dry_run );
			continue;
		}

		if ( $key === '__inner_content' && is_array( $value ) ) {
			foreach ( $value as &$fragment ) {
				if ( is_string( $fragment ) ) {
					$fragment = e2m_engine_import_tree_images_rewrite_html( $fragment, $resolved, $stats, $dry_run );
				}
			}
			unset( $fragment );
			continue;
		}

		if ( is_array( $value ) ) {
			// An image-shaped object: has a 'url' string sibling.
			if ( isset( $value['url'] ) && is_string( $value['url'] ) && e2m_engine_import_tree_images_is_remote_url( $value['url'] ) ) {
				$new_id = e2m_engine_import_tree_images_resolve( $value['url'], $resolved, $stats, $dry_run );
				if ( $new_id !== null && ! $dry_run ) {
					$value['id']  = $new_id;
					$value['url'] = (string) wp_get_attachment_url( $new_id );
				}
			}
			// Recurse into nested arrays (galleries, carousels, atomic nested
			// widget settings, arbitrary depth).
			e2m_engine_import_tree_images_walk_settings( $value, $resolved, $stats, $dry_run );
			continue;
		}

		// A bare url-like scalar field (classic widget "url" setting that
		// isn't wrapped in an { id, url } object).
		if ( is_string( $value ) && in_array( $key, [ 'url', 'src', 'image_url', 'background_image_url' ], true ) && e2m_engine_import_tree_images_is_remote_url( $value ) ) {
			$new_id = e2m_engine_import_tree_images_resolve( $value, $resolved, $stats, $dry_run );
			if ( $new_id !== null && ! $dry_run ) {
				$value = (string) wp_get_attachment_url( $new_id );
			}
		}
	}
	unset( $value );
}

/**
 * Find every `<img src="...">` (and `srcset`) reference inside a raw HTML
 * fragment - as stored on Gutenberg's `__inner_html`/`__inner_content` - and
 * rewrite any remote ones to point at the local attachment. Non-image markup
 * (most blocks) has no `<img>` tag and returns unchanged at negligible cost.
 *
 * @param array<string, int|null>                          $resolved
 * @param array{imported:int, deduped:int, failed:array}   $stats
 */
function e2m_engine_import_tree_images_rewrite_html( string $html, array &$resolved, array &$stats, bool $dry_run ): string {
	if ( ! str_contains( $html, '<img' ) && ! str_contains( $html, 'background-image' ) ) {
		return $html;
	}

	// <img ... src="URL" ...> - single-quoted attrs are rare in block markup
	// (core serializers always emit double quotes) but handled for safety.
	$html = (string) preg_replace_callback(
		'/(<img\b[^>]*\ssrc=)(["\'])(.*?)\2/i',
		function ( array $m ) use ( &$resolved, &$stats, $dry_run ) {
			return e2m_engine_import_tree_images_rewrite_match( $m, $resolved, $stats, $dry_run );
		},
		$html
	);

	// CSS `background-image: url(...)` inside inline style="" attributes
	// (e.g. a cover block's inner markup style fallback).
	$html = (string) preg_replace_callback(
		'/(background-image\s*:\s*url\()([\'"]?)(.*?)\2(\))/i',
		function ( array $m ) use ( &$resolved, &$stats, $dry_run ) {
			$url = $m[3];
			if ( ! e2m_engine_import_tree_images_is_remote_url( $url ) ) {
				return $m[0];
			}
			$new_id = e2m_engine_import_tree_images_resolve( $url, $resolved, $stats, $dry_run );
			if ( $new_id === null || $dry_run ) {
				return $m[0];
			}
			return $m[1] . $m[2] . (string) wp_get_attachment_url( $new_id ) . $m[2] . $m[4];
		},
		$html
	);

	return $html;
}

/**
 * preg_replace_callback handler for a single matched `<img src="URL">`.
 *
 * @param array<int, string>                               $m
 * @param array<string, int|null>                          $resolved
 * @param array{imported:int, deduped:int, failed:array}    $stats
 */
function e2m_engine_import_tree_images_rewrite_match( array $m, array &$resolved, array &$stats, bool $dry_run ): string {
	[ , $prefix, $quote, $url ] = $m;

	if ( ! e2m_engine_import_tree_images_is_remote_url( $url ) ) {
		return $m[0];
	}

	$new_id = e2m_engine_import_tree_images_resolve( $url, $resolved, $stats, $dry_run );
	if ( $new_id === null || $dry_run ) {
		return $m[0];
	}

	return $prefix . $quote . (string) wp_get_attachment_url( $new_id ) . $quote;
}

/**
 * True when the URL looks like a remote image reference worth importing -
 * not already hosted on this site, and not a data: URI.
 */
function e2m_engine_import_tree_images_is_remote_url( string $url ): bool {
	if ( $url === '' || str_starts_with( $url, 'data:' ) ) {
		return false;
	}
	if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$/i', $url ) ) {
		return false;
	}
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$url_host  = wp_parse_url( $url, PHP_URL_HOST );
	return $url_host !== null && $url_host !== $site_host;
}

/**
 * Resolve a single remote URL to a local attachment id: reuse from the
 * in-request cache, then the content-hash index, then sideload as a last
 * resort. Returns null (and records a failure) if the URL cannot be
 * downloaded or hashed - callers must leave the original URL untouched
 * in that case.
 *
 * @param array<string, int|null>                          $resolved
 * @param array{imported:int, deduped:int, failed:array}   $stats
 */
function e2m_engine_import_tree_images_resolve( string $url, array &$resolved, array &$stats, bool $dry_run ): ?int {
	if ( array_key_exists( $url, $resolved ) ) {
		return $resolved[ $url ];
	}

	if ( $dry_run ) {
		// Dry run never downloads; just count it as "would import" so the
		// caller gets an accurate preview without side effects. Cache a null
		// placeholder so the same URL appearing again in this tree (e.g. once
		// in __inner_html and again in __inner_content for the same node, or
		// a repeated background image) is not counted twice.
		$resolved[ $url ] = null;
		$stats['imported']++;
		return null;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp_file = download_url( $url, 30 );
	if ( is_wp_error( $tmp_file ) ) {
		// Cache the failure too (not just successes) - the same URL commonly
		// repeats within one node (e.g. a Gutenberg image block's
		// __inner_html and __inner_content carry identical markup), and
		// without this a single bad URL would retry the download and log a
		// duplicate failure entry for every repeat instead of once.
		$resolved[ $url ] = null;
		$stats['failed'][] = [ 'url' => $url, 'error' => $tmp_file->get_error_message() ];
		return null;
	}

	$sha1 = sha1_file( $tmp_file );

	if ( $sha1 !== false ) {
		$existing = e2m_engine_import_tree_images_find_by_hash( $sha1 );
		if ( $existing !== null ) {
			wp_delete_file( $tmp_file );
			$resolved[ $url ] = $existing;
			$stats['deduped']++;
			return $existing;
		}
	}

	$file_array = [
		'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'imported-image' ),
		'tmp_name' => $tmp_file,
	];

	$attachment_id = media_handle_sideload( $file_array, 0 );

	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}
		$resolved[ $url ] = null;
		$stats['failed'][] = [ 'url' => $url, 'error' => $attachment_id->get_error_message() ];
		return null;
	}

	// Stamp the same content-hash meta upload-media.php uses, so this newly
	// sideloaded image is itself deduplicable by any future import pass -
	// classic sideload-image never did this, which is exactly the gap S1
	// closes.
	if ( $sha1 !== false ) {
		update_post_meta( (int) $attachment_id, '_e2m_sha1', $sha1 );
	}

	$resolved[ $url ] = (int) $attachment_id;
	$stats['imported']++;

	return (int) $attachment_id;
}

/**
 * Look up an existing attachment by content hash. Mirrors the query in
 * e2m/find-media-by-hash without an extra ability dispatch round-trip.
 */
function e2m_engine_import_tree_images_find_by_hash( string $sha1 ): ?int {
	$query = new WP_Query( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'fields'         => 'ids',
		'meta_key'       => '_e2m_sha1',
		'meta_value'     => $sha1,
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	] );

	return ! empty( $query->posts ) ? (int) $query->posts[0] : null;
}
