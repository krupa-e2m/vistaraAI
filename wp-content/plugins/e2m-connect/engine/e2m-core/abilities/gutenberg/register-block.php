<?php
/**
 * E2M Connect MCP - Gutenberg Register Block
 *
 * Scaffold a custom block's SOURCE FILES (block.json, render.php for a
 * dynamic block, an unbuilt src/index.js stub) to the modern standard, and
 * optionally register the block type for the CURRENT REQUEST only, to
 * confirm the scaffold parses correctly with core's own
 * register_block_type()/WP_Block_Type_Registry.
 *
 * This is rung (g) - the decision ladder's LAST RESORT (see
 * gutenberg-block-decision-map's own ordering) - and correspondingly this
 * ability's own scope is deliberately narrow: it writes correct SOURCE,
 * it does not attempt to be a build pipeline. Confirmed directly against
 * this codebase's own engine (E2M_Engine_Sandbox_Helper, the only
 * shell-out-to-a-binary precedent that exists here) that there is ZERO
 * Node/npm/wp-scripts capability anywhere in this PHP engine - running
 * `wp-scripts build` (a Node/webpack toolchain) from inside a WordPress
 * PHP request is out of scope for this ability, and always will be; the
 * scaffolded `src/index.js` is deliberately UNBUILT ESNext - a developer
 * (or the future gutenberg-block-author client agent, on the developer's
 * own machine) must run `npm install && npm run build` afterward before
 * the block is usable in the editor. This ability's own output says so.
 *
 * Confirmed core's register_block_type() accepts EITHER a block.json path/
 * folder (delegating internally to register_block_type_from_metadata(),
 * the modern/recommended form since WP 5.8) OR a bare name + args array -
 * this ability always writes a real block.json to disk and, when it also
 * registers for the current request, passes the FOLDER PATH form so the
 * exact same metadata-resolution machinery core's own runtime uses (script/
 * style handle registration via register_block_script_handle()/
 * register_block_style_handle(), apiVersion resolution, etc.) validates
 * the scaffold, rather than this ability re-deriving those mappings itself.
 *
 * Confirmed block TYPE registration is exactly as IN-MEMORY-ONLY-PER-
 * REQUEST as block STYLE registration (e2m/gutenberg-register-block-style)
 * - WP_Block_Type_Registry is the SAME kind of private-array-on-a-singleton
 * with zero DB/CPT/file backing (confirmed directly in
 * class-wp-block-type-registry.php). The scaffolded files on disk persist;
 * the REGISTRATION of those files as an active block type does not, unless
 * something (a plugin's own `init` hook, typically calling
 * register_block_type( __DIR__ . '/blocks/<slug>' )) runs on every future
 * request. This ability writes that loader snippet as one of its
 * scaffolded files (a small mu-plugin-shaped PHP file calling
 * register_block_type() on `init`) specifically so persistence is
 * achievable without asking the developer to hand-author it - but this
 * ability's own "register" action call, made once during this MCP
 * request, is still only a same-request validation pass, exactly the same
 * honest framing e2m/gutenberg-register-block-style already established
 * for the identical limitation on the sibling registry.
 *
 * Frontend-correctness rules enforced in the generated render.php
 * (confirmed directly against core's own dynamic blocks, e.g.
 * render_block_core_latest_posts() in wp-includes/blocks/latest-posts.php):
 * the wrapper element MUST print get_block_wrapper_attributes() or every
 * declared `supports` (color/spacing/typography/global styles) produces
 * NO frontend output at all - this is the single highest-value
 * correctness rule for a dynamic block, and this ability's generated
 * render.php always includes it. Dynamic blocks also get
 * `supports.html: false` in the generated block.json (a PHP render
 * overwrites manual HTML edits anyway - leaving html:true just invites
 * edits that vanish on save).
 *
 * This is the FIRST multi-file-write ability in this codebase (confirmed -
 * every prior file-writing ability, e.g. e2m/gutenberg-manage-patterns'
 * "register" action, writes exactly one file per call). Reuses the EXACT
 * same per-file pipeline those abilities already established
 * (e2m_engine_resolve_theme_file_path() -> syntax-check if PHP ->
 * E2M_File_Backup::backup_file() -> file_put_contents()), looped once per
 * scaffolded file, sharing one $task_id across the whole scaffold (that
 * parameter already exists on E2M_File_Backup::backup_file() specifically
 * for grouping one logical multi-file task - confirmed in its own
 * docblock - so the whole scaffold can be rolled back atomically via the
 * existing backup/rollback machinery; no new primitive was needed).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-register-block', [
	'label'       => __( '[Gutenberg] Register Block', 'e2mconnect' ),
	'description' => __( 'Scaffold a custom block\'s source files (block.json, render.php for a dynamic block, an UNBUILT src/index.js stub, and an init-hook loader) to the modern standard - apiVersion 3, get_block_wrapper_attributes() in render.php, supports.html:false for dynamic blocks. Does NOT run any build tool (no Node/wp-scripts capability exists in this engine) - the developer must build the scaffolded JS afterward. "register" additionally validates the scaffold via core\'s own register_block_type() for the CURRENT REQUEST only (in-memory, does not persist - same limitation as e2m/gutenberg-register-block-style).', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'         => [
				'type'        => 'string',
				'enum'        => [ 'scaffold', 'register', 'unregister', 'list' ],
				'description' => '"scaffold" writes block.json + (if dynamic) render.php + an unbuilt src/index.js stub + an init-hook loader PHP file to disk, but does NOT register the block type. "register" does everything "scaffold" does, AND additionally calls register_block_type() against the written block.json folder for the CURRENT REQUEST only (validates the scaffold parses; does not persist past this request). "unregister" calls unregister_block_type() for the current request only - does not delete any scaffolded files. "list" returns every currently-registered block type (delegates to the same WP_Block_Type_Registry e2m/gutenberg-list-blocks already reads from).',
			],
			'block_name'     => [ 'type' => 'string', 'description' => 'Required for scaffold/register/unregister. Must be "<namespace>/<name>" form, lowercase, matching core\'s own WP_Block_Type_Registry validation (/^[a-z0-9-]+\\/[a-z0-9-]+$/).' ],
			'title'          => [ 'type' => 'string', 'description' => 'Required for scaffold/register. Human-readable block title.' ],
			'description'    => [ 'type' => 'string', 'description' => 'Optional. block.json "description".' ],
			'category'       => [ 'type' => 'string', 'default' => 'widgets', 'description' => 'Optional. block.json "category" (e.g. "widgets", "text", "media", "design").' ],
			'icon'           => [ 'type' => 'string', 'default' => 'block-default', 'description' => 'Optional. A Dashicon slug for the editor icon.' ],
			'keywords'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Optional. block.json "keywords" for editor search.' ],
			'is_dynamic'     => [ 'type' => 'boolean', 'default' => true, 'description' => 'Whether this block needs server-side rendering (render.php + a "render" field in block.json, supports.html forced to false) or is purely static (a save() function inside index.js, no render.php scaffolded). Per the improvement plan\'s own rule: dynamic for editable/queryable content, static for presentational content.' ],
			'supports'       => [ 'type' => 'object', 'description' => 'Optional. block.json "supports" object (e.g. {"color":{"background":true,"text":true},"spacing":{"padding":true},"typography":{"fontSize":true}}). Passed through verbatim - this ability does not invent a default supports set, since that is a real per-block design decision.', 'additionalProperties' => true ],
			'attributes'     => [ 'type' => 'object', 'description' => 'Optional. block.json "attributes" object. Passed through verbatim.', 'additionalProperties' => true ],
			'uses_inner_blocks' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Whether the scaffolded block should compose core blocks via InnerBlocks (a shell hosting core blocks, per the improvement plan\'s own "not a re-implementation" rule) rather than being a leaf block with no children.' ],
			'theme_scope'    => [ 'type' => 'string', 'enum' => [ 'child', 'parent' ], 'default' => 'child' ],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'block_name'      => [ 'type' => 'string' ],
			'files_written'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Relative theme paths of every file written by "scaffold"/"register".' ],
			'backup_id'       => [ 'type' => 'string', 'description' => 'The E2M_File_Backup task id grouping every scaffolded file - rolls back the whole scaffold atomically.' ],
			'registered'      => [ 'type' => 'boolean', 'description' => 'true if "register" successfully validated the scaffold via core\'s register_block_type() this request.' ],
			'block_types'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list".' ],
			'validation'      => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_register_block_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Register Block',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-register-block ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_register_block_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'scaffold', 'register', 'unregister', 'list' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: scaffold, register, unregister, list.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'register_block_type' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return new WP_Error( 'block_registration_unavailable', __( 'This WordPress core does not support custom block registration.', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			return [ 'block_types' => array_values( WP_Block_Type_Registry::get_instance()->get_all_registered() ) ];
		case 'scaffold':
			return e2m_engine_gutenberg_scaffold_block( $input, false );
		case 'register':
			return e2m_engine_gutenberg_scaffold_block( $input, true );
		case 'unregister':
			return e2m_engine_gutenberg_unregister_block( $input );
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * Validate the block_name shape the same way WP_Block_Type_Registry::
 * register() itself does - checked here as this ability's own PRIMARY
 * gate (core only degrades to a silent _doing_it_wrong() + false return on
 * bad input, never a catchable error), same posture
 * e2m/gutenberg-register-block-style already established for style_name.
 *
 * @param string $block_name
 * @return true|WP_Error
 */
function e2m_engine_gutenberg_validate_block_name( string $block_name ) {
	if ( $block_name === '' ) {
		return new WP_Error( 'missing_block_name', __( 'block_name is required.', 'e2mconnect' ) );
	}
	if ( ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block_name ) ) {
		return new WP_Error(
			'invalid_block_name',
			__( 'block_name must be lowercase "<namespace>/<name>" form (letters, digits, hyphens only) - matches WP_Block_Type_Registry\'s own validation.', 'e2mconnect' )
		);
	}
	return true;
}

/**
 * Scaffold a custom block's source files, and optionally register it for
 * the current request via register_block_type() against the written
 * block.json folder path (the same modern form core's own runtime uses,
 * so core's own metadata-resolution machinery - not a re-derivation by
 * this ability - is what validates the scaffold).
 *
 * @param array<string, mixed> $input
 * @param bool                 $also_register
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_scaffold_block( array $input, bool $also_register ) {
	$block_name = isset( $input['block_name'] ) ? (string) $input['block_name'] : '';
	$name_check = e2m_engine_gutenberg_validate_block_name( $block_name );
	if ( is_wp_error( $name_check ) ) {
		return $name_check;
	}
	if ( empty( $input['title'] ) || ! is_string( $input['title'] ) ) {
		return new WP_Error( 'missing_title', __( 'title is required and must be a non-empty string.', 'e2mconnect' ) );
	}

	$theme_scope = ( $input['theme_scope'] ?? 'child' ) === 'parent' ? 'parent' : 'child';
	$is_dynamic  = ! isset( $input['is_dynamic'] ) || (bool) $input['is_dynamic'];

	$slug_parts   = explode( '/', $block_name );
	$block_slug   = sanitize_file_name( (string) end( $slug_parts ) );
	if ( $block_slug === '' ) {
		return new WP_Error( 'invalid_block_name', __( 'Could not derive a safe directory name from block_name.', 'e2mconnect' ) );
	}
	$block_dir_relative = 'blocks/' . $block_slug;

	$block_json = e2m_engine_gutenberg_build_block_json( $input, $block_name, $is_dynamic );

	$task_id = 'e2m-register-block:' . $block_slug . ':' . wp_generate_uuid4();
	$files   = [];
	$backup_id = '';

	// File 1 — block.json.
	$result = e2m_engine_gutenberg_write_theme_file(
		$theme_scope,
		$block_dir_relative . '/block.json',
		(string) wp_json_encode( $block_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
		false,
		$task_id
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$files[]   = $result['relative_path'];
	$backup_id = $result['backup_id'];

	// File 2 — render.php, dynamic blocks only.
	if ( $is_dynamic ) {
		$render_php = e2m_engine_gutenberg_build_render_php( $block_name );
		$result     = e2m_engine_gutenberg_write_theme_file(
			$theme_scope,
			$block_dir_relative . '/render.php',
			$render_php,
			true,
			$task_id
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$files[] = $result['relative_path'];
	}

	// File 3 — src/index.js, UNBUILT. This ability never runs a build
	// tool (see file header) - the developer must run their own
	// `npm install && npm run build` (wp-scripts) afterward.
	$index_js = e2m_engine_gutenberg_build_index_js_stub( $block_name, $is_dynamic, ! empty( $input['uses_inner_blocks'] ) );
	$result   = e2m_engine_gutenberg_write_theme_file(
		$theme_scope,
		$block_dir_relative . '/src/index.js',
		$index_js,
		false,
		$task_id
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$files[] = $result['relative_path'];

	// File 4 — the init-hook loader, so the scaffold CAN persist past
	// this single request once the developer's theme actually loads this
	// file (e.g. from functions.php) - see file header for why this
	// ability cannot make that happen automatically.
	$loader_php = e2m_engine_gutenberg_build_loader_php( $block_slug, $block_dir_relative );
	$result     = e2m_engine_gutenberg_write_theme_file(
		$theme_scope,
		$block_dir_relative . '/register.php',
		$loader_php,
		true,
		$task_id
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$files[] = $result['relative_path'];

	$validation = [
		'valid'    => true,
		'warnings' => [
			__( 'The scaffolded src/index.js is UNBUILT ESNext - this ability has no Node/build-tool capability. Run "npm install && npm run build" (wp-scripts) on the developer\'s own machine before the block is usable in the editor.', 'e2mconnect' ),
			__( 'A separate register.php loader file was scaffolded, but it only takes effect once your theme actually includes/requires it (e.g. from functions.php) on every request - this ability writing the file does not itself make WordPress load it.', 'e2mconnect' ),
		],
		'errors'   => [],
	];

	$registered = false;
	if ( $also_register ) {
		$absolute_dir = e2m_engine_resolve_theme_file_path( $theme_scope, $block_dir_relative );
		if ( is_wp_error( $absolute_dir ) ) {
			return $absolute_dir;
		}
		$register_result = register_block_type( $absolute_dir );
		if ( $register_result === false || is_wp_error( $register_result ) ) {
			$validation['warnings'][] = __( 'register_block_type() did not accept the scaffolded block.json this request - files were still written; review the block.json shape.', 'e2mconnect' );
		} else {
			$registered = true;
			$validation['warnings'][] = __( 'Block type registration is in-memory only for this request - it does not persist. It will only be registered on future requests once the scaffolded register.php loader is actually included from your theme (see the other warning above).', 'e2mconnect' );
		}
	}

	return [
		'block_name'    => $block_name,
		'files_written' => $files,
		'backup_id'     => $backup_id,
		'registered'    => $registered,
		'validation'    => $validation,
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_unregister_block( array $input ) {
	$block_name = isset( $input['block_name'] ) ? (string) $input['block_name'] : '';
	$name_check = e2m_engine_gutenberg_validate_block_name( $block_name );
	if ( is_wp_error( $name_check ) ) {
		return $name_check;
	}

	if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
		return new WP_Error( 'block_not_registered', __( 'No block type is currently registered with this block_name for the current request.', 'e2mconnect' ) );
	}

	unregister_block_type( $block_name );

	return [
		'block_name' => $block_name,
		'validation' => [
			'valid'    => true,
			'warnings' => [ __( 'Unregistration is in-memory only for this request. If the block was registered via a loader included from the theme/a plugin, it will register again on the next request unless that loader is also removed/disabled.', 'e2mconnect' ) ],
			'errors'   => [],
		],
	];
}

/**
 * Write one scaffolded file via the exact same pipeline every other
 * theme-file-writing ability already uses (resolve path -> syntax-check
 * if PHP -> E2M_File_Backup::backup_file() with the shared $task_id ->
 * file_put_contents()) - looped once per file by the caller, sharing one
 * $task_id across the whole scaffold so it can be rolled back atomically.
 *
 * @param string $theme_scope
 * @param string $relative_path
 * @param string $content
 * @param bool   $is_php
 * @param string $task_id
 * @return array{relative_path: string, backup_id: string}|WP_Error
 */
function e2m_engine_gutenberg_write_theme_file( string $theme_scope, string $relative_path, string $content, bool $is_php, string $task_id ) {
	$absolute = e2m_engine_resolve_theme_file_path( $theme_scope, $relative_path );
	if ( is_wp_error( $absolute ) ) {
		return $absolute;
	}

	if ( $is_php && function_exists( 'e2m_engine_validate_theme_php_syntax' ) ) {
		$lint = e2m_engine_validate_theme_php_syntax( $content );
		if ( is_wp_error( $lint ) ) {
			return $lint;
		}
	}

	$backup_id = '';
	if ( class_exists( 'E2M_File_Backup' ) ) {
		$backup_id = E2M_File_Backup::backup_file( $absolute, 'pre:e2m/gutenberg-register-block:scaffold', $task_id );
	}

	$dir = dirname( $absolute );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'block_directory_create_failed', __( 'Failed to create the block scaffold directory.', 'e2mconnect' ) );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write, same pattern as e2m/gutenberg-manage-patterns.
	$bytes = file_put_contents( $absolute, $content );
	if ( $bytes === false ) {
		return new WP_Error( 'block_file_write_failed', sprintf( 'Failed to write %s.', $relative_path ) );
	}

	return [
		'relative_path' => $relative_path,
		'backup_id'     => $backup_id,
	];
}

/**
 * Build the block.json payload - only the fields core's own
 * register_block_type_from_metadata() actually reads (confirmed against
 * its $property_mappings array in wp-includes/blocks.php) are emitted;
 * this ability never invents a field core wouldn't recognise.
 *
 * @param array<string, mixed> $input
 * @param string               $block_name
 * @param bool                 $is_dynamic
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_build_block_json( array $input, string $block_name, bool $is_dynamic ): array {
	$block_json = [
		'$schema'     => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion'  => 3,
		'name'        => $block_name,
		'title'       => (string) $input['title'],
		'category'    => isset( $input['category'] ) ? (string) $input['category'] : 'widgets',
		'icon'        => isset( $input['icon'] ) ? (string) $input['icon'] : 'block-default',
		'editorScript' => 'file:./index.js',
	];

	if ( ! empty( $input['description'] ) ) {
		$block_json['description'] = (string) $input['description'];
	}
	if ( ! empty( $input['keywords'] ) && is_array( $input['keywords'] ) ) {
		$block_json['keywords'] = array_values( array_map( 'strval', $input['keywords'] ) );
	}
	if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
		$block_json['attributes'] = $input['attributes'];
	}

	$supports = isset( $input['supports'] ) && is_array( $input['supports'] ) ? $input['supports'] : [];
	if ( $is_dynamic ) {
		// Frontend-correctness rule: a dynamic block's PHP render
		// overwrites manual HTML edits anyway - html:true just invites
		// edits that vanish on save.
		$supports['html'] = false;
		$block_json['render'] = 'file:./render.php';
	}
	if ( ! empty( $supports ) ) {
		$block_json['supports'] = $supports;
	}

	return $block_json;
}

/**
 * Build a minimal, correct render.php for a dynamic block. ALWAYS prints
 * get_block_wrapper_attributes() on the wrapper element - confirmed
 * against core's own dynamic blocks (e.g. render_block_core_latest_posts())
 * that omitting this call means every `supports`-declared feature
 * (color/spacing/typography/global styles) produces literally no frontend
 * output, the single highest-value correctness rule for a dynamic block.
 * Renders nested InnerBlocks content server-side via the $content
 * variable core passes into render.php (never re-implemented by hand).
 *
 * @param string $block_name
 * @return string
 */
function e2m_engine_gutenberg_build_render_php( string $block_name ): string {
	return <<<PHP
<?php
/**
 * Server-side render for the "{$block_name}" block.
 *
 * \$attributes - the block's attributes.
 * \$content    - the block's default content, if any (InnerBlocks content
 *                is already resolved into this by core - never re-render
 *                nested blocks by hand).
 * \$block      - the WP_Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\$wrapper_attributes = get_block_wrapper_attributes();
?>
<div <?php echo \$wrapper_attributes; ?>>
	<?php echo \$content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already-rendered InnerBlocks/attribute-derived markup from core, not raw user input. ?>
</div>
PHP;
}

/**
 * Build an unbuilt ESNext src/index.js stub. Deliberately minimal - this
 * ability scaffolds a correct STARTING POINT, not a finished block; the
 * real editing/save/edit logic is the developer's own work, same
 * "rung (g), last resort, invoked least" posture the decision ladder
 * documents for this whole path. Uses InnerBlocks composition when
 * requested, per the improvement plan's own "a shell hosting core blocks,
 * not a re-implementation" rule.
 *
 * @param string $block_name
 * @param bool   $is_dynamic
 * @param bool   $uses_inner_blocks
 * @return string
 */
function e2m_engine_gutenberg_build_index_js_stub( string $block_name, bool $is_dynamic, bool $uses_inner_blocks ): string {
	$inner_blocks_import = $uses_inner_blocks ? "import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';" : "import { useBlockProps } from '@wordpress/block-editor';";
	$edit_body = $uses_inner_blocks
		? "return <div { ...useBlockProps() }><InnerBlocks templateLock={ false } /></div>;"
		: "return <p { ...useBlockProps() }>{ 'Edit this block.' }</p>;";
	// Static blocks (is_dynamic=false) need a save() that mirrors the edit
	// markup - InnerBlocks.Content for composed blocks, per the
	// improvement plan's own static-vs-dynamic InnerBlocks rule; dynamic
	// blocks have no save() at all (render.php owns the frontend output).
	$save_export = $is_dynamic
		? ''
		: ( $uses_inner_blocks
			? "\nexport function save() {\n\treturn <div { ...useBlockProps.save() }><InnerBlocks.Content /></div>;\n}\n"
			: "\nexport function save() {\n\treturn <p { ...useBlockProps.save() }>{ 'Edit this block.' }</p>;\n}\n" );

	return <<<JS
/**
 * Editor script for the "{$block_name}" block.
 *
 * UNBUILT source - run `npm install && npm run build` (wp-scripts) before
 * this block is usable in the editor. This ability does not run a build
 * tool itself.
 */
import { registerBlockType } from '@wordpress/blocks';
{$inner_blocks_import}
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: function Edit() {
		{$edit_body}
	},
}{$save_export} );
JS;
}

/**
 * Build the init-hook loader PHP file - the piece that lets the scaffold
 * actually persist past a single request, once the developer's theme
 * includes/requires it (e.g. from functions.php). This ability cannot
 * make WordPress load it automatically (see file header) - it only
 * writes the correct loader code so the developer doesn't have to
 * hand-author it.
 *
 * @param string $block_slug
 * @param string $block_dir_relative
 * @return string
 */
function e2m_engine_gutenberg_build_loader_php( string $block_slug, string $block_dir_relative ): string {
	// This file itself lives INSIDE the block's own directory
	// (blocks/<slug>/register.php), so __DIR__ from here already points
	// at the folder register_block_type() needs - no path suffix. Passing
	// $block_dir_relative in only for the docblock comment below, so the
	// generated file documents which block folder it belongs to without
	// getting the actual registration path wrong.
	return <<<PHP
<?php
/**
 * Registers the "{$block_slug}" custom block on every request.
 *
 * Lives at {$block_dir_relative}/register.php - include/require THIS FILE
 * (not the folder) from your theme's functions.php (or a plugin) so this
 * block registers on init. Writing this file alone does not make
 * WordPress load it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_block_type( __DIR__ );
} );
PHP;
}
