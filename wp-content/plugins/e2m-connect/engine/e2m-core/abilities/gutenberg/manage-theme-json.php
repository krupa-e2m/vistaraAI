<?php
/**
 * E2M Connect MCP - Gutenberg Manage theme.json
 *
 * Read / deep-merge / validate / write the active theme's theme.json file.
 * This is the single design-token source for the Gutenberg pipeline (client
 * 12.2): color/typography/spacing palettes, appearanceTools, useRootPadding-
 * AwareAlignments, styles.elements, and - the two fields client agents
 * specifically depend on - styles.blocks.variations (Section Styles, client
 * 12.10) and templateParts area registration (header/footer landmarks,
 * client 12.6).
 *
 * Deliberately does NOT rely solely on WP_Theme_JSON::remove_insecure_properties()
 * (core's own static sanitizer, public since 5.9) to decide what is "valid" -
 * its internal allowlist (VALID_TOP_LEVEL_KEYS / VALID_SETTINGS / VALID_STYLES
 * constants) is a WordPress-core implementation detail that has changed shape
 * across major versions (LATEST_SCHEMA went from 2 to 3 in WP 6.6, changing
 * preset-override behavior and expanding fluid-typography/layout controls),
 * and different WP core versions bundled with different client sites could
 * sanitize the SAME input differently, silently stripping a field this
 * ability's own callers asked for. Instead: (1) this ability does its own
 * explicit, conservative structural checks for exactly the fields the
 * Gutenberg pipeline actually writes (documented in
 * e2m_engine_gutenberg_validate_theme_json_shape()) — this is the PRIMARY
 * gate; (2) WP_Theme_JSON::remove_insecure_properties() is run only as a
 * secondary, best-effort confirmation when available (guarded by
 * class_exists()+method_exists(), matching this codebase's established
 * convention) — its result is reported as a WARNING if it would have
 * stripped something, never used to silently overwrite what this ability's
 * own validation already approved for the actual write.
 *
 * Deep merge (the "merge" action) is deliberately real recursive merging,
 * not a template_reuse.php-style "replace nested arrays wholesale" -
 * theme.json's settings/styles trees are genuinely config-shaped (palettes,
 * scales), not ID-keyed element trees where a shallow-replace-only strategy
 * is correct (see class-e2m-builder-canonical.php's own comment on why it
 * avoids deep merge for THAT shape of data - a different case from this one).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-theme-json', [
	'label'       => __( '[Gutenberg] Manage theme.json', 'e2mconnect' ),
	'description' => __( 'Read, deep-merge, validate, and write the active theme\'s theme.json. Handles styles.blocks.variations (Section Styles) and templateParts area registration (header/footer). Targets theme.json version 3.', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'      => [
				'type' => 'string',
				'enum' => [ 'read', 'merge', 'write' ],
				'description' => '"read" returns the current theme.json (or a version-3 skeleton if none exists yet). "merge" deep-merges the given theme_json fragment into the existing file. "write" validates and writes the given theme_json as the complete document (full replace).',
			],
			'theme_scope' => [
				'type'    => 'string',
				'enum'    => [ 'child', 'parent' ],
				'default' => 'child',
			],
			'theme_json'  => [
				'type'        => 'object',
				'description' => 'Required for "merge"/"write". A theme.json-shaped object (or fragment, for "merge"). additionalProperties left open — theme.json legitimately has many optional keys this ability does not enumerate.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'theme_scope'   => [ 'type' => 'string' ],
			'theme_json'    => [ 'type' => 'object', 'description' => 'The resulting (read, merged, or written) theme.json document.' ],
			'validation'    => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
			'backup_id'     => [ 'type' => 'string', 'description' => 'Rollback id for the pre-write backup (empty for "read", or if backups are unavailable).' ],
			'bytes_written' => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_theme_json_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage theme.json',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the gutenberg-manage-theme-json ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_theme_json_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'read', 'merge', 'write' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: read, merge, write.', 'e2mconnect' ) );
	}

	$theme_scope = ( $input['theme_scope'] ?? 'child' ) === 'parent' ? 'parent' : 'child';
	$absolute    = e2m_engine_resolve_theme_file_path( $theme_scope, 'theme.json' );
	if ( is_wp_error( $absolute ) ) {
		return $absolute;
	}

	$existing = e2m_engine_gutenberg_read_theme_json_file( $absolute );
	if ( is_wp_error( $existing ) ) {
		return $existing;
	}

	if ( $action === 'read' ) {
		return [
			'theme_scope' => $theme_scope,
			'theme_json'  => $existing,
			'validation'  => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
			'backup_id'   => '',
			'bytes_written' => 0,
		];
	}

	// merge / write both require a theme_json payload.
	if ( ! isset( $input['theme_json'] ) || ! is_array( $input['theme_json'] ) ) {
		return new WP_Error( 'missing_theme_json', __( 'theme_json is required for "merge" and "write".', 'e2mconnect' ) );
	}
	$payload = $input['theme_json'];

	$target = $action === 'merge'
		? e2m_engine_gutenberg_deep_merge( $existing, $payload )
		: $payload;

	// Always ensure version 3 unless the caller explicitly set a different
	// (presumably intentional, e.g. testing) version — never silently
	// downgrade a caller's explicit choice, only fill in the default.
	if ( ! isset( $target['version'] ) ) {
		$target['version'] = 3;
	}

	$validation = e2m_engine_gutenberg_validate_theme_json_shape( $target );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'theme_json_invalid',
			sprintf(
				/* translators: %s: joined validation error messages */
				__( 'theme.json failed validation: %s', 'e2mconnect' ),
				implode( '; ', $validation['errors'] )
			),
			[ 'validation' => $validation ]
		);
	}

	// Secondary, best-effort confirmation pass — never the sole gate (see
	// file header). WP_Theme_JSON::remove_insecure_properties() is core's
	// own real sanitizer (verified: public static since 5.9, delegates to
	// remove_insecure_styles()/remove_insecure_settings()); run it and
	// report (as a WARNING, not by replacing $target) anything it would
	// strip that our own validation above already approved — that gap is
	// exactly the "different core version, different internal allowlist"
	// risk this ability exists to surface rather than silently absorb.
	// $target itself is never overwritten by this pass: what actually gets
	// written to disk is what OUR validation approved, not core's.
	if ( class_exists( 'WP_Theme_JSON' ) && method_exists( 'WP_Theme_JSON', 'remove_insecure_properties' ) ) {
		try {
			$core_sanitized = WP_Theme_JSON::remove_insecure_properties( $target, 'theme' );
			$dropped = e2m_engine_gutenberg_diff_dropped_keys( $target, $core_sanitized );
			if ( ! empty( $dropped ) ) {
				$validation['warnings'][] = sprintf(
					/* translators: %s: comma-joined list of dropped top-level-ish key paths */
					__( 'WordPress core\'s own theme.json sanitizer (WP_Theme_JSON::remove_insecure_properties) would strip: %s. Writing anyway since this ability\'s own validation approved them — review if the resulting page does not render as expected on this WP version.', 'e2mconnect' ),
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
		$validation['warnings'][] = __( 'WP_Theme_JSON::remove_insecure_properties() unavailable on this WordPress core — proceeded on this ability\'s own structural validation only.', 'e2mconnect' );
	}

	$encoded = wp_json_encode( $target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( $encoded === false ) {
		return new WP_Error( 'theme_json_encode_failed', __( 'Failed to encode the resulting theme.json as JSON.', 'e2mconnect' ) );
	}

	$backup_id = '';
	if ( class_exists( 'E2M_File_Backup' ) ) {
		$backup_id = E2M_File_Backup::backup_file( $absolute, 'pre:e2m/gutenberg-manage-theme-json:' . $action );
	}

	$dir = dirname( $absolute );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'theme_directory_create_failed', __( 'Failed to create the theme directory.', 'e2mconnect' ) );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write, same pattern as e2m/update-theme-file.
	$bytes = file_put_contents( $absolute, $encoded . "\n" );
	if ( $bytes === false ) {
		return new WP_Error( 'theme_file_write_failed', __( 'Failed to write theme.json.', 'e2mconnect' ) );
	}

	return [
		'theme_scope'   => $theme_scope,
		'theme_json'    => $target,
		'validation'    => $validation,
		'backup_id'     => $backup_id,
		'bytes_written' => (int) $bytes,
	];
}

/**
 * Read and JSON-decode the theme's theme.json, or return a minimal version-3
 * skeleton if the file does not exist yet — a brand-new Gutenberg build has
 * no theme.json to read, and that is not an error.
 *
 * @param string $absolute
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_theme_json_file( string $absolute ) {
	if ( ! is_file( $absolute ) ) {
		return [
			'$schema' => 'https://schemas.wp.org/trunk/theme.json',
			'version' => 3,
			'settings' => new stdClass(),
			'styles'   => new stdClass(),
		];
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read, same pattern as e2m/read-theme-file.
	$raw = file_get_contents( $absolute );
	if ( $raw === false ) {
		return new WP_Error( 'theme_file_read_failed', __( 'Failed to read the existing theme.json.', 'e2mconnect' ) );
	}

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'theme_json_parse_failed',
			sprintf(
				/* translators: %s: json_decode error message */
				__( 'The existing theme.json is not valid JSON: %s', 'e2mconnect' ),
				json_last_error_msg()
			)
		);
	}

	return $decoded;
}

/**
 * Real recursive deep-merge of two theme.json-shaped associative arrays.
 * Scalars and lists (arrays with sequential integer keys, e.g.
 * settings.color.palette, styles.blocks.variations.<name> entries where
 * applicable) from $override REPLACE the corresponding value in $base
 * wholesale — merging list entries by position would silently blend
 * unrelated palette entries together, which is never correct. Only
 * associative (keyed) sub-arrays are merged key-by-key, recursively.
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
 * keys are present in $before but missing from $after — used to surface
 * what WP_Theme_JSON::remove_insecure_properties() would have stripped,
 * without ever acting on that result (see file header). Intentionally
 * shallow (does not walk the full tree) — this is a human-readable warning,
 * not a second validation pass; a human reviewing the warning can always
 * inspect the full documents themselves via the "read" action.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return string[]
 */
function e2m_engine_gutenberg_diff_dropped_keys( array $before, array $after ): array {
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
 * This ability's own explicit, conservative structural validation — the
 * PRIMARY gate (see file header for why WP_Theme_JSON's internal
 * sanitization is not trusted as the sole check). Checks exactly the fields
 * the Gutenberg client pipeline is documented to depend on; does not attempt
 * to validate the entirety of theme.json's very large optional surface.
 *
 * @param array<string, mixed> $doc
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_theme_json_shape( array $doc ): array {
	$errors   = [];
	$warnings = [];

	// theme_json arrives as a raw pass-through object (not field-validated by
	// the input JSON Schema the way post_id etc. are) — a loosely-typed
	// caller could plausibly send "3" (string) instead of 3 (int), so accept
	// any value that is unambiguously an integer once cast, not only the
	// strict PHP int type.
	$version_is_numeric = isset( $doc['version'] ) && is_numeric( $doc['version'] ) && (string) (int) $doc['version'] === (string) $doc['version'];
	if ( ! $version_is_numeric ) {
		$errors[] = 'version must be an integer (e.g. 3).';
	} elseif ( (int) $doc['version'] < 2 ) {
		$warnings[] = sprintf( 'version is %d — the Gutenberg pipeline targets version 3 (WP 6.6+) for fluid typography and Section Styles support.', (int) $doc['version'] );
	}

	foreach ( [ 'settings', 'styles' ] as $top_key ) {
		if ( isset( $doc[ $top_key ] ) && ! is_array( $doc[ $top_key ] ) && ! ( $doc[ $top_key ] instanceof stdClass ) ) {
			$errors[] = sprintf( '"%s" must be an object.', $top_key );
		}
	}

	// styles.blocks.variations (Section Styles, client 12.10) — each
	// variation must be a keyed object (a variation name -> style
	// definition), never a list; a caller accidentally passing an array
	// list here would silently fail to apply as named variations.
	// $doc['styles'] can legitimately be absent, a stdClass (from an empty
	// JSON object literal decoded with assoc=false upstream), or an array —
	// only descend into ['blocks'] once we know it is a real array.
	$styles = is_array( $doc['styles'] ?? null ) ? $doc['styles'] : [];
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

	// templateParts area registration (client 12.6) — each entry needs
	// name + area, and area must be one of the values WordPress recognises
	// for the semantic landmark it wraps (header/footer are the ones the
	// client pipeline actually uses; "uncategorized" is core's own default
	// for a part that isn't header/footer).
	$template_parts = $doc['templateParts'] ?? null;
	if ( $template_parts !== null ) {
		if ( ! is_array( $template_parts ) || ! array_is_list( $template_parts ) ) {
			$errors[] = 'templateParts must be a list of {name, title, area} objects.';
		} else {
			foreach ( $template_parts as $i => $part ) {
				if ( ! is_array( $part ) ) {
					$errors[] = sprintf( 'templateParts[%d] must be an object.', (int) $i );
					continue;
				}
				if ( empty( $part['name'] ) || ! is_string( $part['name'] ) ) {
					$errors[] = sprintf( 'templateParts[%d].name is required and must be a string.', (int) $i );
				}
				$area = $part['area'] ?? null;
				if ( $area !== null && ! in_array( $area, [ 'header', 'footer', 'uncategorized' ], true ) ) {
					$warnings[] = sprintf(
						'templateParts[%d].area is "%s" — expected one of header, footer, uncategorized; WordPress will still accept other values but they will not get semantic landmark wrapping.',
						(int) $i,
						(string) $area
					);
				}
			}
		}
	}

	return [
		'valid'    => empty( $errors ),
		'warnings' => $warnings,
		'errors'   => $errors,
	];
}
