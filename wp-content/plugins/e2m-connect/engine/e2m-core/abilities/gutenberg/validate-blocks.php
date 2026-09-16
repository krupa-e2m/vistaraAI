<?php
/**
 * E2M Connect MCP - Gutenberg Validate Blocks
 *
 * Standalone pre-publish validation gate for Gutenberg block markup. Extracts
 * the round-trip mechanism that already lives inside
 * E2M_Builder_Gutenberg_Adapter::extract()/inject() (parse_blocks +
 * serialize_blocks) into a named, directly-callable ability, plus adds the
 * two checks the adapter itself does not perform: block-name registry
 * resolution and attribute-schema conformance per block.
 *
 * This is the highest-priority ability in the Gutenberg family (per the
 * improvement plan: "nothing else writes safely without it") because every
 * other Gutenberg-writing ability - build-page, add-container, add-widget,
 * update-element, batch-update, all via the generic builder abstraction and
 * this same adapter - can silently produce malformed post_content today if
 * a caller passes a bad block name or a mistyped attribute. The adapter's
 * canonical_to_block()/serialize_blocks() call does not check either of
 * those; it will happily serialize a block name that resolves to nothing
 * and attributes no registered attribute schema recognises. This ability
 * gives callers a way to check BEFORE writing, not after.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-validate-blocks', [
	'label'       => __( '[Gutenberg] Validate Blocks', 'e2mconnect' ),
	'description' => 'Validates Gutenberg block markup as a pre-publish gate: parse_blocks/serialize_blocks round-trip stability, block-name registry resolution, and attribute-schema conformance per block. Read-only - never writes. Pass either post_id (validate what is currently stored) or block_markup (validate markup before it is written anywhere).',
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Validate the block markup currently stored in this post\'s post_content. Mutually exclusive with block_markup.',
			],
			'block_markup' => [
				'type'        => 'string',
				'description' => 'Raw Gutenberg block markup (HTML comment delimiters + content) to validate directly, without it being written to any post first. Mutually exclusive with post_id.',
			],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'valid'  => [ 'type' => 'boolean', 'description' => 'True only if every check below passed for every block, including nested innerBlocks.' ],
			'errors' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'path'       => [ 'type' => 'string', 'description' => 'Position of the offending block, e.g. "blocks[2].innerBlocks[0]".' ],
						'block_name' => [ 'type' => 'string', 'description' => 'The block name found at this path (may be empty/null for a whitespace or malformed entry).' ],
						'code'       => [
							'type'        => 'string',
							'description' => 'One of: round_trip_unstable, block_not_registered, unknown_attribute, attribute_type_mismatch.',
						],
						'message' => [ 'type' => 'string' ],
					],
				],
			],
			'block_count' => [ 'type' => 'integer', 'description' => 'Total number of real blocks checked (whitespace/null entries excluded), including nested innerBlocks.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_validate_blocks_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Validate Blocks',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the gutenberg-validate-blocks ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_validate_blocks_ability( array $input ) {
	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return new WP_Error( 'blocks_unavailable', __( 'This WordPress core does not support the block editor (parse_blocks/serialize_blocks missing).', 'e2mconnect' ) );
	}
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return new WP_Error( 'block_registry_missing', __( 'The WordPress block type registry is unavailable.', 'e2mconnect' ) );
	}

	$has_post_id = isset( $input['post_id'] ) && (int) $input['post_id'] > 0;
	$has_markup  = isset( $input['block_markup'] ) && trim( (string) $input['block_markup'] ) !== '';

	if ( $has_post_id === $has_markup ) {
		return new WP_Error(
			'invalid_input',
			__( 'Provide exactly one of post_id or block_markup, not both and not neither.', 'e2mconnect' )
		);
	}

	if ( $has_post_id ) {
		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
		}
		$markup = (string) $post->post_content;
	} else {
		$markup = (string) $input['block_markup'];
	}

	// Check 1 — round-trip stability. A second parse+serialize pass on
	// already-serialized content must be byte-identical to the first; a
	// divergence means the original markup was malformed in a way WordPress
	// will silently "fix" (or corrupt) on save, which is exactly the failure
	// mode this ability exists to catch before it happens.
	$errors = [];
	$parsed_once  = e2m_engine_gutenberg_drop_whitespace_blocks( parse_blocks( $markup ) );
	$reserialized = serialize_blocks( $parsed_once );
	$parsed_twice = e2m_engine_gutenberg_drop_whitespace_blocks( parse_blocks( $reserialized ) );
	if ( serialize_blocks( $parsed_twice ) !== $reserialized ) {
		$errors[] = [
			'path'       => '(root)',
			'block_name' => '',
			'code'       => 'round_trip_unstable',
			'message'    => __( 'A second parse+serialize pass produced different markup than the first — the input is not stable block markup.', 'e2mconnect' ),
		];
	}

	// Checks 2 & 3 — per block, recursively: name resolves in the registry,
	// and every attribute key the block carries is one its registered
	// schema actually declares (catches typos and made-up attribute names
	// that would silently no-op rather than error).
	$registry    = WP_Block_Type_Registry::get_instance();
	$block_count = 0;
	e2m_engine_gutenberg_validate_block_list( $parsed_once, 'blocks', $registry, $errors, $block_count );

	return [
		'valid'       => empty( $errors ),
		'errors'      => $errors,
		'block_count' => $block_count,
	];
}

/**
 * Recursively validate a list of parsed blocks (as returned by parse_blocks(),
 * already stripped of whitespace placeholders), appending to $errors and
 * $block_count by reference so nested innerBlocks share one running tally
 * and one flat error list with full paths.
 *
 * @param array<int, array<string, mixed>> $blocks
 * @param string                           $path_prefix
 * @param WP_Block_Type_Registry           $registry
 * @param array<int, array<string, mixed>> $errors
 * @param int                              $block_count
 */
function e2m_engine_gutenberg_validate_block_list( array $blocks, string $path_prefix, WP_Block_Type_Registry $registry, array &$errors, int &$block_count ): void {
	foreach ( $blocks as $index => $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$path = $path_prefix . '[' . $index . ']';
		$name = (string) ( $block['blockName'] ?? '' );
		$block_count++;

		if ( $name === '' ) {
			// parse_blocks() can legitimately return a "freeform" block with
			// no name for raw HTML between block comments. Not an error on
			// its own — only a genuinely dangling name check applies below.
		} else {
			$block_type = $registry->get_registered( $name );
			if ( ! $block_type ) {
				$errors[] = [
					'path'       => $path,
					'block_name' => $name,
					'code'       => 'block_not_registered',
					'message'    => sprintf(
						/* translators: %s: block name */
						__( 'Block "%s" is not registered on this site — it will render as an "unrecognized block" error in the editor and may not render at all on the frontend.', 'e2mconnect' ),
						$name
					),
				];
			} else {
				e2m_engine_gutenberg_validate_block_attributes( $block, $block_type, $path, $errors );
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$inner = e2m_engine_gutenberg_drop_whitespace_blocks( $block['innerBlocks'] );
			e2m_engine_gutenberg_validate_block_list( $inner, $path . '.innerBlocks', $registry, $errors, $block_count );
		}
	}
}

/**
 * Check one block's attrs against its registered attribute schema. Flags an
 * attribute key the schema does not declare at all (typo / made-up name —
 * WordPress will silently drop it, not error, so this is the only way to
 * catch it) and a scalar type mismatch against the schema's declared type
 * (best-effort — only for the JSON-Schema-primitive types WP's attribute
 * system actually uses: string, boolean, integer, number, array, object).
 *
 * @param array<string, mixed> $block
 * @param WP_Block_Type        $block_type
 * @param string               $path
 * @param array<int, array<string, mixed>> $errors
 */
function e2m_engine_gutenberg_validate_block_attributes( array $block, WP_Block_Type $block_type, string $path, array &$errors ): void {
	try {
		$schema = (array) ( $block_type->attributes ?? [] );
	} catch ( \Throwable $e ) {
		// A third-party block can throw while reading its own attribute
		// schema. Report it as a finding rather than letting the whole
		// validation call fail on one bad block registration.
		$errors[] = [
			'path'       => $path,
			'block_name' => (string) $block_type->name,
			'code'       => 'attribute_type_mismatch',
			'message'    => sprintf(
				/* translators: 1: block name, 2: exception message */
				__( 'Block "%1$s" threw while reading its attribute schema: %2$s', 'e2mconnect' ),
				(string) $block_type->name,
				$e->getMessage()
			),
		];
		return;
	}

	$attrs = (array) ( $block['attrs'] ?? [] );

	foreach ( $attrs as $key => $value ) {
		if ( ! array_key_exists( $key, $schema ) ) {
			$errors[] = [
				'path'       => $path,
				'block_name' => (string) $block_type->name,
				'code'       => 'unknown_attribute',
				'message'    => sprintf(
					/* translators: 1: attribute key, 2: block name */
					__( 'Attribute "%1$s" is not declared in "%2$s"\'s registered schema — WordPress will silently drop it rather than error, so this attribute has no effect.', 'e2mconnect' ),
					(string) $key,
					(string) $block_type->name
				),
			];
			continue;
		}

		$declared_type = is_array( $schema[ $key ] ) ? ( $schema[ $key ]['type'] ?? null ) : null;
		if ( $declared_type === null || $value === null ) {
			continue; // No declared type to check against, or a legitimate null value — skip.
		}

		if ( ! e2m_engine_gutenberg_value_matches_type( $value, (string) $declared_type ) ) {
			$errors[] = [
				'path'       => $path,
				'block_name' => (string) $block_type->name,
				'code'       => 'attribute_type_mismatch',
				'message'    => sprintf(
					/* translators: 1: attribute key, 2: block name, 3: expected type, 4: actual PHP type */
					__( 'Attribute "%1$s" on "%2$s" is declared as "%3$s" but the value is %4$s.', 'e2mconnect' ),
					(string) $key,
					(string) $block_type->name,
					(string) $declared_type,
					gettype( $value )
				),
			];
		}
	}
}

/**
 * Loose (not strict-PHP-type) match against a JSON-Schema primitive type
 * name, since attrs arriving from JSON-decoded MCP input are already PHP
 * scalars/arrays and WordPress's own attribute type strings are the
 * JSON-Schema vocabulary ("string", "boolean", "integer", "number", "array",
 * "object"), not PHP type names.
 */
function e2m_engine_gutenberg_value_matches_type( $value, string $type ): bool {
	switch ( $type ) {
		case 'string':
			return is_string( $value );
		case 'boolean':
			return is_bool( $value );
		case 'integer':
			return is_int( $value );
		case 'number':
			return is_int( $value ) || is_float( $value );
		case 'array':
			return is_array( $value ) && array_is_list( $value );
		case 'object':
			return is_array( $value ) && ! array_is_list( $value );
		default:
			// An attribute type this validator does not model (e.g. "null",
			// a custom type some third-party block invented) — do not flag
			// what we cannot confidently check.
			return true;
	}
}

/**
 * Same whitespace-placeholder filter as
 * E2M_Builder_Gutenberg_Adapter::drop_whitespace_blocks() (kept as a
 * separate copy here rather than a cross-file dependency, since this
 * ability must remain independently loadable/testable without pulling in
 * the full builder-adapter class).
 *
 * @param array<int, mixed> $blocks
 * @return array<int, array<string, mixed>>
 */
function e2m_engine_gutenberg_drop_whitespace_blocks( array $blocks ): array {
	$out = [];
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		if ( ( $block['blockName'] ?? null ) === null ) {
			continue;
		}
		$out[] = $block;
	}
	return $out;
}
