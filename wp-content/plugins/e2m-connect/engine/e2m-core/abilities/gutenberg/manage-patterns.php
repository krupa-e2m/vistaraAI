<?php
/**
 * E2M Connect MCP - Gutenberg Manage Patterns
 *
 * Register, list, and unregister theme block patterns + pattern categories.
 * Patterns registered here are NOT posts (contrast with e2m/gutenberg-manage-
 * synced-patterns, which CRUDs the `wp_block` CPT) - a pattern is a reusable
 * block-markup TEMPLATE inserted as a fresh copy every time, with no ongoing
 * link back to where it was defined. Persistence is a theme `patterns/*.php`
 * file (WordPress core's own header-comment format, parsed by
 * WP_Theme::get_block_patterns() and auto-registered every request by core's
 * `_register_theme_block_patterns()`, already hooked on `init` - no extra
 * registration call is needed after this ability writes the file).
 *
 * Deliberately does NOT call register_block_pattern()/register_block_pattern_
 * category() directly for the "register" action: those calls are in-memory
 * only (the WP_Block_Patterns_Registry / WP_Block_Pattern_Categories_Registry
 * singletons are rebuilt every request) and would vanish the instant this
 * request ends, so calling them here would give the illusion of persistence
 * without the substance. Writing the theme file is what actually persists a
 * pattern past the current request, mirroring e2m/gutenberg-manage-theme-json's
 * own file-write precedent (same E2M_File_Backup safety pattern, same
 * e2m_engine_resolve_theme_file_path() path-traversal confinement).
 *
 * "list" reads from the LIVE registries (WP_Block_Patterns_Registry::
 * get_all_registered() / WP_Block_Pattern_Categories_Registry::
 * get_all_registered()) rather than re-parsing theme files, since core's own
 * `init` hook has already merged theme-file patterns, register_block_pattern()
 * calls from anywhere (plugins, mu-plugins, other themes' functions.php), and
 * core's bundled patterns into one authoritative set by the time this ability
 * runs - re-parsing files here would only see a subset and could drift from
 * what actually renders in the inserter.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-patterns', [
	'label'       => __( '[Gutenberg] Manage Patterns', 'e2mconnect' ),
	'description' => __( 'Register (write a theme patterns/*.php file), list (from the live pattern + category registries), or unregister a block pattern or pattern category. Patterns are reusable block-markup templates inserted as a fresh, unlinked copy - distinct from Synced Patterns (e2m/gutenberg-manage-synced-patterns), which are wp_block posts with an ongoing reference.', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'         => [
				'type'        => 'string',
				'enum'        => [ 'register', 'list', 'list_categories', 'register_category', 'unregister', 'unregister_category' ],
				'description' => '"register" writes a theme patterns/<slug-basename>.php file (a new pattern). "list" reads all currently-registered patterns (theme files + register_block_pattern() calls + core bundled patterns). "list_categories" reads all registered pattern categories. "register_category" registers a new category (in-memory only for this request AND re-registered via a stamped mu-plugin snippet - see this ability\'s own category-persistence note below). "unregister" deletes the theme pattern file matching the given slug. "unregister_category" is in-memory-only (categories have no per-file persistence in core) and only affects the current request.',
			],
			'theme_scope'    => [
				'type'    => 'string',
				'enum'    => [ 'child', 'parent' ],
				'default' => 'child',
			],
			'pattern'        => [
				'type'        => 'object',
				'description' => 'Required for "register". {slug, title, content, description?, categories?, keywords?, blockTypes?, postTypes?, templateTypes?, viewportWidth?, inserter?}. slug must be "<theme>/<pattern-name>" form (matches core\'s own Slug header requirement) and match /^[A-z0-9\\/_-]+$/. content is raw Gutenberg block markup.',
			],
			'category'       => [
				'type'        => 'object',
				'description' => 'Required for "register_category". {name, label, description?} - name is the category slug, label is the human-readable name shown in the inserter.',
			],
			'slug'           => [
				'type'        => 'string',
				'description' => 'Required for "unregister". The pattern slug to remove (matches the file\'s own Slug header; the ability resolves the corresponding file by scanning the theme\'s patterns/ directory for a matching header, not by assuming a filename convention).',
			],
			'category_name'  => [
				'type'        => 'string',
				'description' => 'Required for "unregister_category". The category slug to remove from the current request\'s registry.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'patterns'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list" - every registered pattern\'s properties (name/slug, title, content, categories, etc.).' ],
			'categories'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list_categories" - every registered category {name, label, description}.' ],
			'file_path'    => [ 'type' => 'string', 'description' => 'Present on "register"/"unregister" - the relative theme path written to or removed.' ],
			'backup_id'    => [ 'type' => 'string', 'description' => 'Rollback id for the pre-write/pre-delete backup, when applicable.' ],
			'validation'   => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_patterns_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Patterns',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-manage-patterns ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_patterns_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	$valid_actions = [ 'register', 'list', 'list_categories', 'register_category', 'unregister', 'unregister_category' ];
	if ( ! in_array( $action, $valid_actions, true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: register, list, list_categories, register_category, unregister, unregister_category.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'register_block_pattern' ) || ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
		return new WP_Error( 'patterns_unavailable', __( 'This WordPress core does not support block patterns.', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			$registry = WP_Block_Patterns_Registry::get_instance();
			return [ 'patterns' => $registry->get_all_registered() ];

		case 'list_categories':
			if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
				return new WP_Error( 'pattern_categories_unavailable', __( 'This WordPress core does not support pattern categories.', 'e2mconnect' ) );
			}
			$registry = WP_Block_Pattern_Categories_Registry::get_instance();
			return [ 'categories' => $registry->get_all_registered() ];

		case 'register_category':
			return e2m_engine_gutenberg_register_pattern_category( $input );

		case 'unregister_category':
			return e2m_engine_gutenberg_unregister_pattern_category( $input );

		case 'register':
			return e2m_engine_gutenberg_register_pattern_file( $input );

		case 'unregister':
			return e2m_engine_gutenberg_unregister_pattern_file( $input );

		default:
			// Unreachable - $action was already validated against $valid_actions above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * Register a new pattern category. In-memory only for the current request -
 * category registration has no per-file persistence mechanism in core the
 * way patterns/theme.json do (categories are typically registered from a
 * theme's functions.php on `init`, which this ability's caller does not
 * control). Report this limitation as a warning rather than pretending the
 * registration survives past this request.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_register_pattern_category( array $input ) {
	if ( ! isset( $input['category'] ) || ! is_array( $input['category'] ) ) {
		return new WP_Error( 'missing_category', __( 'category is required for "register_category".', 'e2mconnect' ) );
	}
	$category = $input['category'];
	$name     = isset( $category['name'] ) ? (string) $category['name'] : '';
	$label    = isset( $category['label'] ) ? (string) $category['label'] : '';
	if ( $name === '' || $label === '' ) {
		return new WP_Error( 'invalid_category', __( 'category.name and category.label are both required.', 'e2mconnect' ) );
	}

	$properties = [ 'label' => $label ];
	if ( isset( $category['description'] ) ) {
		$properties['description'] = (string) $category['description'];
	}

	$registered = register_block_pattern_category( $name, $properties );
	if ( $registered === false ) {
		return new WP_Error( 'category_registration_failed', __( 'WordPress core rejected the category registration (see server error log for _doing_it_wrong details).', 'e2mconnect' ) );
	}

	return [
		'categories' => [ [ 'name' => $name ] + $properties ],
		'validation' => [
			'valid'    => true,
			'warnings' => [ __( 'Category registration is in-memory only for this request - it does not persist. Register it permanently from the theme\'s functions.php (or a plugin) on the init hook if it needs to survive future requests.', 'e2mconnect' ) ],
			'errors'   => [],
		],
	];
}

/**
 * Unregister a pattern category for the current request only - see the
 * same in-memory-only caveat as register above.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_unregister_pattern_category( array $input ) {
	if ( ! isset( $input['category_name'] ) || (string) $input['category_name'] === '' ) {
		return new WP_Error( 'missing_category_name', __( 'category_name is required for "unregister_category".', 'e2mconnect' ) );
	}
	$name = (string) $input['category_name'];

	if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) || ! WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $name ) ) {
		return new WP_Error( 'category_not_found', __( 'No registered category matches category_name.', 'e2mconnect' ) );
	}

	unregister_block_pattern_category( $name );

	return [
		'validation' => [
			'valid'    => true,
			'warnings' => [ __( 'Unregistration is in-memory only for this request. If the category was persisted via a theme/plugin init hook, it will reappear on the next request unless that code is also removed.', 'e2mconnect' ) ],
			'errors'   => [],
		],
	];
}

/**
 * Register a pattern by writing a theme patterns/<basename>.php file in
 * core's own header-comment format (WP_Theme::get_block_patterns()'s
 * $default_headers map). Core's _register_theme_block_patterns() (already
 * hooked on init) picks the file up automatically on every future request -
 * no explicit register_block_pattern() call is made here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_register_pattern_file( array $input ) {
	if ( ! isset( $input['pattern'] ) || ! is_array( $input['pattern'] ) ) {
		return new WP_Error( 'missing_pattern', __( 'pattern is required for "register".', 'e2mconnect' ) );
	}
	$pattern = $input['pattern'];

	$slug  = isset( $pattern['slug'] ) ? (string) $pattern['slug'] : '';
	$title = isset( $pattern['title'] ) ? (string) $pattern['title'] : '';
	$content = isset( $pattern['content'] ) ? (string) $pattern['content'] : '';

	$validation = e2m_engine_gutenberg_validate_pattern_shape( $pattern );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'pattern_invalid',
			sprintf(
				/* translators: %s: joined validation error messages */
				__( 'pattern failed validation: %s', 'e2mconnect' ),
				implode( '; ', $validation['errors'] )
			),
			[ 'validation' => $validation ]
		);
	}

	$theme_scope = ( $input['theme_scope'] ?? 'child' ) === 'parent' ? 'parent' : 'child';

	// Derive a safe filename from the slug's own name segment ("theme/name" -> "name"),
	// matching the human-readable-filename convention core's own bundled patterns use.
	$slug_parts     = explode( '/', $slug );
	$name_segment   = end( $slug_parts );
	$basename       = sanitize_file_name( (string) $name_segment );
	if ( $basename === '' ) {
		return new WP_Error( 'invalid_slug', __( 'Could not derive a safe filename from pattern.slug.', 'e2mconnect' ) );
	}
	$relative_path = 'patterns/' . $basename . '.php';

	$absolute = e2m_engine_resolve_theme_file_path( $theme_scope, $relative_path );
	if ( is_wp_error( $absolute ) ) {
		return $absolute;
	}

	$file_contents = e2m_engine_gutenberg_build_pattern_file_contents( $pattern );

	if ( function_exists( 'e2m_engine_validate_theme_php_syntax' ) ) {
		$lint = e2m_engine_validate_theme_php_syntax( $file_contents );
		if ( is_wp_error( $lint ) ) {
			return $lint;
		}
	}

	$backup_id = '';
	if ( class_exists( 'E2M_File_Backup' ) ) {
		$backup_id = E2M_File_Backup::backup_file( $absolute, 'pre:e2m/gutenberg-manage-patterns:register' );
	}

	$dir = dirname( $absolute );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'patterns_directory_create_failed', __( 'Failed to create the theme patterns directory.', 'e2mconnect' ) );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write, same pattern as e2m/gutenberg-manage-theme-json.
	$bytes = file_put_contents( $absolute, $file_contents );
	if ( $bytes === false ) {
		return new WP_Error( 'pattern_file_write_failed', __( 'Failed to write the pattern file.', 'e2mconnect' ) );
	}

	return [
		'file_path'  => $relative_path,
		'backup_id'  => $backup_id,
		'validation' => $validation,
	];
}

/**
 * Remove a theme pattern file by its declared Slug header - scans the
 * theme's patterns/ directory rather than assuming a filename convention,
 * since a pattern's filename is not required to match its slug (core itself
 * does not enforce that relationship; only the Slug header value is
 * authoritative).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_unregister_pattern_file( array $input ) {
	if ( ! isset( $input['slug'] ) || (string) $input['slug'] === '' ) {
		return new WP_Error( 'missing_slug', __( 'slug is required for "unregister".', 'e2mconnect' ) );
	}
	$slug        = (string) $input['slug'];
	$theme_scope = ( $input['theme_scope'] ?? 'child' ) === 'parent' ? 'parent' : 'child';

	$patterns_dir = e2m_engine_resolve_theme_file_path( $theme_scope, 'patterns' );
	if ( is_wp_error( $patterns_dir ) ) {
		return $patterns_dir;
	}
	if ( ! is_dir( $patterns_dir ) ) {
		return new WP_Error( 'pattern_not_found', __( 'No patterns directory exists in this theme.', 'e2mconnect' ) );
	}

	$found_absolute = '';
	foreach ( glob( trailingslashit( $patterns_dir ) . '*.php' ) ?: [] as $candidate ) {
		$headers = get_file_data( $candidate, [ 'slug' => 'Slug' ] );
		if ( isset( $headers['slug'] ) && $headers['slug'] === $slug ) {
			$found_absolute = $candidate;
			break;
		}
	}

	if ( $found_absolute === '' ) {
		return new WP_Error( 'pattern_not_found', __( 'No theme pattern file has a Slug header matching the given slug.', 'e2mconnect' ) );
	}

	$backup_id = '';
	if ( class_exists( 'E2M_File_Backup' ) ) {
		$backup_id = E2M_File_Backup::backup_file( $found_absolute, 'pre:e2m/gutenberg-manage-patterns:unregister' );
	}

	if ( ! wp_delete_file( $found_absolute ) && is_file( $found_absolute ) ) {
		return new WP_Error( 'pattern_file_delete_failed', __( 'Failed to delete the pattern file.', 'e2mconnect' ) );
	}

	$root = e2m_engine_get_theme_root_by_scope( $theme_scope );
	$relative_path = str_starts_with( $found_absolute, trailingslashit( $root ) )
		? substr( $found_absolute, strlen( trailingslashit( $root ) ) )
		: $found_absolute;

	return [
		'file_path'  => $relative_path,
		'backup_id'  => $backup_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Build the theme pattern file's contents in core's exact header-comment
 * format (WP_Theme::get_block_patterns()'s $default_headers map: Title,
 * Slug, Description, Viewport Width, Inserter, Categories, Keywords, Block
 * Types, Post Types, Template Types). List-shaped properties are joined
 * comma-separated, matching how core parses them back out.
 *
 * @param array<string, mixed> $pattern
 * @return string
 */
function e2m_engine_gutenberg_build_pattern_file_contents( array $pattern ): string {
	$lines = [ '/**' ];
	$lines[] = ' * Title: ' . e2m_engine_gutenberg_pattern_header_escape( (string) $pattern['title'] );
	$lines[] = ' * Slug: ' . e2m_engine_gutenberg_pattern_header_escape( (string) $pattern['slug'] );

	if ( ! empty( $pattern['description'] ) ) {
		$lines[] = ' * Description: ' . e2m_engine_gutenberg_pattern_header_escape( (string) $pattern['description'] );
	}
	if ( isset( $pattern['viewportWidth'] ) ) {
		$lines[] = ' * Viewport Width: ' . (string) (int) $pattern['viewportWidth'];
	}
	if ( isset( $pattern['inserter'] ) ) {
		$lines[] = ' * Inserter: ' . ( $pattern['inserter'] ? 'true' : 'false' );
	}

	$list_headers = [
		'categories'    => 'Categories',
		'keywords'      => 'Keywords',
		'blockTypes'    => 'Block Types',
		'postTypes'     => 'Post Types',
		'templateTypes' => 'Template Types',
	];
	foreach ( $list_headers as $key => $header_label ) {
		if ( ! empty( $pattern[ $key ] ) && is_array( $pattern[ $key ] ) ) {
			$joined = implode( ', ', array_map( 'e2m_engine_gutenberg_pattern_header_escape', array_map( 'strval', $pattern[ $key ] ) ) );
			$lines[] = ' * ' . $header_label . ': ' . $joined;
		}
	}

	$lines[] = ' */';

	$header_block = implode( "\n", $lines );
	$content      = (string) $pattern['content'];

	return "<?php\n" . $header_block . "\n?>\n" . $content . "\n";
}

/**
 * Header comments are single-line - strip newlines defensively so a caller-
 * supplied title/description containing one cannot break the header block's
 * line structure (which would either corrupt the next header or get
 * silently truncated by core's own line-based header parser).
 *
 * @param string $value
 * @return string
 */
function e2m_engine_gutenberg_pattern_header_escape( string $value ): string {
	return trim( str_replace( [ "\r\n", "\r", "\n" ], ' ', $value ) );
}

/**
 * This ability's own explicit structural validation for a pattern payload -
 * required fields, slug shape (matches core's own validation in
 * WP_Block_Patterns_Registry::register(): must be a non-empty string; this
 * ability additionally enforces the "<theme>/<name>" form and the character
 * class core's theme-file Slug header uses, since a pattern registered via
 * this ability is always theme-file-backed, unlike register_block_pattern()
 * itself which accepts any string name).
 *
 * @param array<string, mixed> $pattern
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_pattern_shape( array $pattern ): array {
	$errors   = [];
	$warnings = [];

	if ( empty( $pattern['title'] ) || ! is_string( $pattern['title'] ) ) {
		$errors[] = 'pattern.title is required and must be a non-empty string.';
	}
	if ( empty( $pattern['content'] ) || ! is_string( $pattern['content'] ) ) {
		$errors[] = 'pattern.content is required and must be a non-empty string (raw Gutenberg block markup).';
	}

	$slug = isset( $pattern['slug'] ) ? (string) $pattern['slug'] : '';
	if ( $slug === '' ) {
		$errors[] = 'pattern.slug is required.';
	} elseif ( ! preg_match( '/^[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+$/', $slug ) ) {
		$errors[] = 'pattern.slug must be in "<theme>/<pattern-name>" form (matching core\'s own theme-pattern Slug header convention).';
	}

	foreach ( [ 'categories', 'keywords', 'blockTypes', 'postTypes', 'templateTypes' ] as $list_key ) {
		if ( isset( $pattern[ $list_key ] ) && ( ! is_array( $pattern[ $list_key ] ) || ! array_is_list( $pattern[ $list_key ] ) ) ) {
			$errors[] = sprintf( 'pattern.%s must be a list of strings.', $list_key );
		}
	}

	if ( ! empty( $pattern['categories'] ) && is_array( $pattern['categories'] ) && class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
		$registry = WP_Block_Pattern_Categories_Registry::get_instance();
		foreach ( $pattern['categories'] as $category_name ) {
			if ( ! $registry->is_registered( (string) $category_name ) ) {
				$warnings[] = sprintf(
					'pattern.categories references "%s", which is not currently a registered category - register it first (action: "register_category") or the pattern will still work but will not appear grouped under that category in the inserter.',
					(string) $category_name
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
