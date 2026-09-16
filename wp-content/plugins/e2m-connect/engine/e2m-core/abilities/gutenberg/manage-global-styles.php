<?php
/**
 * E2M Connect MCP - Gutenberg Manage Global Styles
 *
 * Read / deep-merge / write the active theme's `wp_global_styles` custom
 * post - the runtime USER customization layer WordPress's Site Editor
 * writes to, stored as JSON in that post's post_content. This is distinct
 * from both:
 *  - e2m/gutenberg-manage-theme-json (S5-G5) - the theme's on-disk
 *    theme.json file, which supplies the theme's DEFAULT design tokens;
 *    wp_global_styles layers user overrides on top of those defaults at
 *    render time, it does not replace the file.
 *  - e2m/elementor-manage-global-styles-v3 - Elementor's own Kit globals,
 *    a completely separate settings system with no relationship to core's
 *    Global Styles CPT.
 *
 * Resolution uses WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles()
 * (public, stable since 5.9) rather than a hand-rolled WP_Query - this is
 * the exact same lookup core's own Site Editor uses (tax_query on
 * `wp_theme` scoped to the active theme's stylesheet slug), so this
 * ability never drifts from how WordPress itself finds "the" global
 * styles post for the active theme. Read passes create_post=false (a
 * fresh theme with no user customizations yet is not an error - it simply
 * returns theme.json's defaults with no post to report). merge/write pass
 * create_post=true, since a caller intending to write customizations
 * needs a post to write them into.
 *
 * Exposes the resolved post's ID as `post_id` in the OUTPUT (every action)
 * and accepts it as an optional `post_id` INPUT (merge/write) for exactly
 * one reason: E2M_Safety_Gatekeeper::extract_target_post_id() and
 * E2M_Risk_Classifier::int_from() run at the REST layer, before this
 * ability's execute_callback, and both pattern-match on the literal INPUT
 * key `post_id` (see class-e2m-safety-gatekeeper.php /
 * class-e2m-risk-classifier.php) - they cannot see this ability's own
 * previous output, and they do not care that a `wp_global_styles` post is
 * not conventionally "a page". A caller that does a "read" first and then
 * passes the returned post_id into its "merge"/"write" call gets
 * E2M_Meta_Snapshot::capture_if_destructive() (which always snapshots
 * post_content, regardless of the builder-meta-key allowlist) and the
 * scoped DB backup firing automatically before the write even reaches this
 * file - zero bespoke backup code needed here. A caller that omits it
 * still gets wp_update_post()'s native post-revision safety net, just not
 * the gatekeeper's snapshot. This is deliberately different from
 * manage-theme-json.php's explicit E2M_File_Backup call, because that
 * ability's target is a file on disk with no post_id concept at all,
 * whereas this ability's target IS an ordinary (if unusually-named) post.
 *
 * Validation follows the same two-tier approach as manage-theme-json.php
 * and for the same reason: WP_Theme_JSON::remove_insecure_properties() is
 * core's own sanitizer, but its internal allowlists are a WordPress-core
 * implementation detail that shifts across major versions, so different
 * client sites on different cores could silently strip the same input
 * differently. This ability's own e2m_engine_gutenberg_validate_global_styles_shape()
 * is the PRIMARY gate; remove_insecure_properties() (called with
 * origin='custom', the origin core itself uses for user/global-styles data,
 * as opposed to 'theme' for theme.json) runs only as a secondary,
 * best-effort confirmation reported as a warning, never used to silently
 * overwrite what this ability's own validation already approved.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-global-styles', [
	'label'       => __( '[Gutenberg] Manage Global Styles', 'e2mconnect' ),
	'description' => __( 'Read, deep-merge, and write the active theme\'s wp_global_styles post (core\'s user-customization layer on top of theme.json defaults). Distinct from theme.json itself and from Elementor\'s Kit globals.', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'        => [
				'type'        => 'string',
				'enum'        => [ 'read', 'merge', 'write' ],
				'description' => '"read" returns the current wp_global_styles document for the active theme (empty styles/settings if no user customizations exist yet - not an error). "merge" deep-merges the given global_styles fragment into the existing document. "write" validates and writes the given global_styles as the complete document (full replace).',
			],
			'global_styles' => [
				'type'        => 'object',
				'description' => 'Required for "merge"/"write". A wp_global_styles-shaped object ({version, styles, settings}, or a fragment for "merge"). additionalProperties left open.',
			],
			'post_id'       => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Optional. The wp_global_styles post ID, as returned by a prior "read" call. Passing it back on "merge"/"write" lets the safety gatekeeper\'s automatic pre-write snapshot engage (it pattern-matches on this literal input key) - recommended for any caller that already has it. When omitted, this ability resolves the post itself; it is still written correctly, just without that particular automatic safety net.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'       => [ 'type' => 'integer', 'description' => 'The resolved wp_global_styles post ID. 0 on "read" when no user-customization post exists yet for this theme.' ],
			'global_styles' => [ 'type' => 'object', 'description' => 'The resulting (read, merged, or written) global styles document.' ],
			'validation'    => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_global_styles_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Global Styles',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the gutenberg-manage-global-styles ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_global_styles_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'read', 'merge', 'write' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: read, merge, write.', 'e2mconnect' ) );
	}

	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return new WP_Error( 'wp_theme_json_resolver_unavailable', __( 'WP_Theme_JSON_Resolver is unavailable on this WordPress core.', 'e2mconnect' ) );
	}

	// A caller that already knows the post ID (from a prior "read") can pass
	// it back so the safety gatekeeper's automatic snapshot engages (see
	// the post_id input field's description) and so this call skips
	// re-resolving via the tax_query lookup. It is validated against the
	// active theme's real global-styles post below, never trusted blindly -
	// a stale or unrelated post_id must not let a caller overwrite the
	// wrong post.
	$claimed_post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	// Read never creates a post: a fresh theme with no user customizations
	// yet is a legitimate, non-error state, and a plain "read" should not
	// have the side effect of inserting a new DB row.
	$create_post = $action !== 'read';
	$existing    = e2m_engine_gutenberg_read_global_styles_post( $create_post );
	if ( is_wp_error( $existing ) ) {
		return $existing;
	}

	if ( $claimed_post_id > 0 && $claimed_post_id !== $existing['post_id'] ) {
		return new WP_Error(
			'global_styles_post_id_mismatch',
			__( 'The supplied post_id does not match the active theme\'s current wp_global_styles post. Re-read to get the current post_id before merging/writing.', 'e2mconnect' )
		);
	}

	if ( $action === 'read' ) {
		return [
			'post_id'       => $existing['post_id'],
			'global_styles' => $existing['document'],
			'validation'    => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
		];
	}

	// merge / write both require a global_styles payload, and both require
	// that create_post actually resolved a post to write into.
	if ( ! isset( $input['global_styles'] ) || ! is_array( $input['global_styles'] ) ) {
		return new WP_Error( 'missing_global_styles', __( 'global_styles is required for "merge" and "write".', 'e2mconnect' ) );
	}
	if ( $existing['post_id'] <= 0 ) {
		return new WP_Error( 'global_styles_post_unresolvable', __( 'Could not resolve or create the wp_global_styles post for the active theme.', 'e2mconnect' ) );
	}
	$payload = $input['global_styles'];

	$target = $action === 'merge'
		? e2m_engine_gutenberg_deep_merge( $existing['document'], $payload )
		: $payload;

	// Always ensure version + the isGlobalStylesUserThemeJSON marker unless
	// the caller explicitly set them - never silently override a caller's
	// explicit choice, only fill in sensible defaults. WP_Theme_JSON::LATEST_SCHEMA
	// is core's own current schema version constant (mirrors what
	// get_user_data_from_wp_global_styles() itself writes for a brand-new post).
	if ( ! isset( $target['version'] ) ) {
		$target['version'] = class_exists( 'WP_Theme_JSON' ) ? WP_Theme_JSON::LATEST_SCHEMA : 3;
	}
	if ( ! isset( $target['isGlobalStylesUserThemeJSON'] ) ) {
		$target['isGlobalStylesUserThemeJSON'] = true;
	}

	$validation = e2m_engine_gutenberg_validate_global_styles_shape( $target );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'global_styles_invalid',
			sprintf(
				/* translators: %s: joined validation error messages */
				__( 'global styles failed validation: %s', 'e2mconnect' ),
				implode( '; ', $validation['errors'] )
			),
			[ 'validation' => $validation ]
		);
	}

	// Secondary, best-effort confirmation pass - never the sole gate (see
	// file header). origin='custom' matches how core itself sanitizes
	// user/global-styles data (as opposed to 'theme' for theme.json).
	if ( class_exists( 'WP_Theme_JSON' ) && method_exists( 'WP_Theme_JSON', 'remove_insecure_properties' ) ) {
		try {
			$core_sanitized = WP_Theme_JSON::remove_insecure_properties( $target, 'custom' );
			$dropped        = e2m_engine_gutenberg_diff_dropped_keys_shallow( $target, $core_sanitized );
			if ( ! empty( $dropped ) ) {
				$validation['warnings'][] = sprintf(
					/* translators: %s: comma-joined list of dropped top-level-ish key paths */
					__( 'WordPress core\'s own sanitizer (WP_Theme_JSON::remove_insecure_properties) would strip: %s. Writing anyway since this ability\'s own validation approved them - review if the resulting page does not render as expected on this WP version.', 'e2mconnect' ),
					implode( ', ', $dropped )
				);
			}
		} catch ( \Throwable $e ) {
			$validation['warnings'][] = sprintf(
				/* translators: %s: exception message */
				__( 'WP_Theme_JSON::remove_insecure_properties() raised a warning while confirming this document: %s (proceeding on this ability\'s own validation).', 'e2mconnect' ),
				$e->getMessage()
			);
		}
	} else {
		$validation['warnings'][] = __( 'WP_Theme_JSON::remove_insecure_properties() unavailable on this WordPress core - proceeded on this ability\'s own structural validation only.', 'e2mconnect' );
	}

	$encoded = wp_json_encode( $target, JSON_UNESCAPED_SLASHES );
	if ( $encoded === false ) {
		return new WP_Error( 'global_styles_encode_failed', __( 'Failed to encode the resulting global styles document as JSON.', 'e2mconnect' ) );
	}

	// No explicit backup call here by design (see file header): post_id is
	// an accepted input key (unlike e2m/elementor-manage-global-styles-v3's
	// internally-resolved kit_id), so when a caller passes back the post_id
	// a prior "read" gave them, E2M_Safety_Gatekeeper::extract_target_post_id()
	// and E2M_Risk_Classifier both pick it straight off $input and the
	// automatic pre-write snapshot (E2M_Meta_Snapshot::capture_if_destructive(),
	// which always snapshots post_content) plus the scoped DB backup engage
	// before this callback ever runs - no bespoke backup code needed here.
	// A caller that omits post_id still gets wp_update_post()'s native
	// WordPress post-revision safety net (the same implicit net
	// e2m/update-page.php relies on), just not the gatekeeper's snapshot.
	$updated = wp_update_post(
		[
			'ID'           => $existing['post_id'],
			'post_content' => wp_slash( $encoded ),
		],
		true
	);
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return [
		'post_id'       => $existing['post_id'],
		'global_styles' => $target,
		'validation'    => $validation,
	];
}

/**
 * Resolve the active theme's wp_global_styles post via
 * WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles() - the same
 * lookup core's own Site Editor uses - and JSON-decode its post_content.
 *
 * @param bool $create_post Whether to create the post if none exists yet.
 * @return array{post_id: int, document: array<string, mixed>}|WP_Error
 */
function e2m_engine_gutenberg_read_global_styles_post( bool $create_post ) {
	$user_cpt = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), $create_post );

	if ( empty( $user_cpt ) || ! isset( $user_cpt['ID'] ) ) {
		// No user-customization post exists yet and we were not asked to
		// create one (read path) - not an error, just an empty document.
		return [
			'post_id'  => 0,
			'document' => [
				'version'                     => class_exists( 'WP_Theme_JSON' ) ? WP_Theme_JSON::LATEST_SCHEMA : 3,
				'isGlobalStylesUserThemeJSON' => true,
				'styles'                      => new stdClass(),
				'settings'                    => new stdClass(),
			],
		];
	}

	$raw = isset( $user_cpt['post_content'] ) ? (string) $user_cpt['post_content'] : '';
	if ( $raw === '' ) {
		return [
			'post_id'  => (int) $user_cpt['ID'],
			'document' => [
				'version'                     => class_exists( 'WP_Theme_JSON' ) ? WP_Theme_JSON::LATEST_SCHEMA : 3,
				'isGlobalStylesUserThemeJSON' => true,
				'styles'                      => new stdClass(),
				'settings'                    => new stdClass(),
			],
		];
	}

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'global_styles_parse_failed',
			sprintf(
				/* translators: %s: json_decode error message */
				__( 'The existing wp_global_styles post_content is not valid JSON: %s', 'e2mconnect' ),
				json_last_error_msg()
			)
		);
	}

	return [
		'post_id'  => (int) $user_cpt['ID'],
		'document' => $decoded,
	];
}

/**
 * Real recursive deep-merge, identical semantics to
 * e2m_engine_gutenberg_deep_merge() in manage-theme-json.php: scalars and
 * lists from $override replace the corresponding value in $base wholesale
 * (merging list entries by position would silently blend unrelated palette
 * entries); only associative (keyed) sub-arrays merge key-by-key,
 * recursively. Duplicated rather than shared per this codebase's
 * established gutenberg/ convention (see validate-blocks.php's own header)
 * of each ability file staying independently loadable/testable without a
 * shared gutenberg-helpers.php.
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
if ( ! function_exists( 'e2m_engine_gutenberg_deep_merge' ) ) {
	function e2m_engine_gutenberg_deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if (
				is_array( $value )
				&& ! array_is_list( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& ! array_is_list( $base[ $key ] )
			) {
				$base[ $key ] = e2m_engine_gutenberg_deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}

/**
 * Report which top-level (and one level of nested, for settings/styles)
 * keys are present in $before but missing from $after - used to surface
 * what WP_Theme_JSON::remove_insecure_properties() would have stripped,
 * without ever acting on that result (see file header). Intentionally
 * shallow, matching manage-theme-json.php's equivalent helper - this is a
 * human-readable warning, not a second validation pass.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return string[]
 */
function e2m_engine_gutenberg_diff_dropped_keys_shallow( array $before, array $after ): array {
	$dropped = [];
	foreach ( $before as $key => $value ) {
		if ( ! array_key_exists( $key, $after ) ) {
			$dropped[] = (string) $key;
			continue;
		}
		if ( is_array( $value ) && ! array_is_list( $value ) && is_array( $after[ $key ] ) ) {
			foreach ( $value as $sub_key => $_sub_value ) {
				if ( ! array_key_exists( $sub_key, $after[ $key ] ) ) {
					$dropped[] = $key . '.' . $sub_key;
				}
			}
		}
	}
	return $dropped;
}

/**
 * This ability's own explicit, conservative structural validation - the
 * PRIMARY gate (see file header for why WP_Theme_JSON's internal
 * sanitization is not trusted as the sole check). global_styles documents
 * share theme.json's settings/styles shape (WP_Theme_JSON treats both as
 * the same underlying data structure, just different origins), so the same
 * checks manage-theme-json.php applies are relevant here too - version,
 * settings/styles must be objects, styles.blocks.*.variations must be keyed
 * not a list. templateParts is deliberately NOT checked here: that field is
 * theme.json-only (FSE template-part registration), never meaningful in a
 * user's global-styles override layer.
 *
 * @param array<string, mixed> $doc
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_global_styles_shape( array $doc ): array {
	$errors   = [];
	$warnings = [];

	$version_is_numeric = isset( $doc['version'] ) && is_numeric( $doc['version'] ) && (string) (int) $doc['version'] === (string) $doc['version'];
	if ( ! $version_is_numeric ) {
		$errors[] = 'version must be an integer (e.g. 3).';
	} elseif ( (int) $doc['version'] < 2 ) {
		$warnings[] = sprintf( 'version is %d - the Gutenberg pipeline targets the current schema version for fluid typography and Section Styles support.', (int) $doc['version'] );
	}

	foreach ( [ 'settings', 'styles' ] as $top_key ) {
		if ( isset( $doc[ $top_key ] ) && ! is_array( $doc[ $top_key ] ) && ! ( $doc[ $top_key ] instanceof stdClass ) ) {
			$errors[] = sprintf( '"%s" must be an object.', $top_key );
		}
	}

	// styles.blocks.<name>.variations (Section Styles, client 12.10) - each
	// variation must be a keyed object, never a list, same rule as
	// manage-theme-json.php - a user-level override of a section style
	// variation follows the identical shape.
	$styles     = is_array( $doc['styles'] ?? null ) ? $doc['styles'] : [];
	$variations = $styles['blocks'] ?? null;
	if ( is_array( $variations ) ) {
		foreach ( $variations as $block_name => $block_styles ) {
			if ( ! is_array( $block_styles ) ) {
				continue;
			}
			$block_variations = $block_styles['variations'] ?? null;
			if ( $block_variations === null ) {
				continue;
			}
			if ( ! is_array( $block_variations ) || array_is_list( $block_variations ) ) {
				$errors[] = sprintf(
					'styles.blocks.%s.variations must be a keyed object (variation-name => style definition), not a list.',
					(string) $block_name
				);
			}
		}
	}

	return [
		'valid'    => empty( $errors ),
		'warnings' => $warnings,
		'errors'   => $errors,
	];
}
