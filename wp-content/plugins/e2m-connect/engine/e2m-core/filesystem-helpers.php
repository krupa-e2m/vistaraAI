<?php
/**
 * E2M Connect MCP - Filesystem helper utilities.
 *
 * Path resolution, sandbox boundary enforcement, sensitive-file
 * protection, and PHP-extension detection used across all
 * filesystem abilities.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =====================================================================
 * 1. PATH RESOLUTION
 * =====================================================================
 * Normalises a caller-supplied path into a fully-qualified, boundary-
 * verified location under ABSPATH.
 * ================================================================== */

/**
 * Turn a raw (possibly relative) path into an absolute one and confirm
 * it falls within the WordPress installation root.
 *
 * If the target does not exist yet its *parent* is resolved instead so
 * the boundary check still works for new files.
 *
 * @param string $raw_path     User-supplied path (relative or absolute).
 * @param bool   $require_real Reject the path when the file is absent.
 * @param bool   $deny_links   Reject symbolic links even when they exist.
 * @return string|WP_Error     Clean absolute path on success.
 */
function e2m_engine_resolve_path( string $raw_path, bool $require_real = false, bool $deny_links = false ) {

    // 1. Absolutise --------------------------------------------------------
    $absolute = $raw_path;
    if ( $raw_path !== '' && $raw_path[0] !== '/' && $raw_path[0] !== '\\' ) {
        $absolute = rtrim( ABSPATH, '/\\' ) . '/' . ltrim( $raw_path, '/\\' );
    }

    // 2. Establish the boundary -------------------------------------------
    $wp_root = realpath( ABSPATH );
    if ( $wp_root === false ) {
        $wp_root = rtrim( ABSPATH, '/\\' );
    }

    // 3. Resolve - two strategies depending on whether the leaf exists ----
    if ( $require_real ) {
        $clean = realpath( $absolute );
        if ( $clean === false ) {
            return new WP_Error(
                'e2m_path_missing',
                /* translators: %s: filesystem path */
                sprintf( __( 'Nothing exists at path: %s', 'e2mconnect' ), $raw_path )
            );
        }
    } else {
        // Leaf may not exist yet - resolve through the closest real ancestor.
        $ancestor = realpath( dirname( $absolute ) );
        $clean    = $ancestor !== false
            ? $ancestor . DIRECTORY_SEPARATOR . basename( $absolute )
            : $absolute;
    }

    // 4. Optionally reject symlinks ----------------------------------------
    if ( $deny_links && file_exists( $clean ) && is_link( $clean ) ) {
        return new WP_Error(
            'e2m_symlink_rejected',
            __( 'Symlinks are disallowed for this operation.', 'e2mconnect' )
        );
    }

    // 5. Boundary enforcement - must remain under ABSPATH -----------------
    // Append a directory separator BEFORE the prefix check, otherwise a
    // sibling directory like "/var/www/html-evil/file" passes when the
    // root is "/var/www/html". The path itself can equal the root with no
    // trailing separator, so we permit equality explicitly.
    $wp_root_with_sep = rtrim( $wp_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
    if ( $clean !== rtrim( $wp_root, DIRECTORY_SEPARATOR ) && strpos( $clean . DIRECTORY_SEPARATOR, $wp_root_with_sep ) !== 0 ) {
        return new WP_Error(
            'e2m_path_restricted',
            sprintf(
                /* translators: 1: resolved path, 2: allowed root */
                __( 'Path "%1$s" escapes the allowed root "%2$s".', 'e2mconnect' ),
                $clean,
                $wp_root
            )
        );
    }

    return $clean;
}

/* =====================================================================
 * 2. SANDBOX HELPERS
 * ===================================================================== */

/**
 * Return (and optionally create) the sandbox directory.
 *
 * @param bool $auto_create Attempt to create the dir when absent.
 * @return string Trailing-slash absolute path.
 */
function e2m_engine_get_sandbox_dir( bool $auto_create = false ): string {
    if ( $auto_create && ! is_dir( E2M_ENGINE_SANDBOX_DIR ) ) {
        wp_mkdir_p( E2M_ENGINE_SANDBOX_DIR );
    }
    return E2M_ENGINE_SANDBOX_DIR;
}

/**
 * Confirm that a resolved path resides strictly inside the sandbox.
 *
 * @param string $target Absolute path to validate.
 * @return true|WP_Error
 */
function e2m_engine_validate_sandbox_path( string $target ) {

    $sandbox_root = realpath( e2m_engine_get_sandbox_dir() );

    if ( $sandbox_root === false ) {
        return new WP_Error(
            'e2m_sandbox_missing',
            __( 'Sandbox directory does not exist on disk.', 'e2mconnect' )
        );
    }

    // Symlinks could redirect outside the sandbox.
    if ( is_link( $target ) ) {
        return new WP_Error(
            'e2m_sandbox_symlink',
            __( 'Symlinks inside the sandbox are forbidden.', 'e2mconnect' )
        );
    }

    // Resolve - for new files, fall back to resolving the parent.
    $real_target = realpath( $target );
    if ( $real_target === false ) {
        $parent_real = realpath( dirname( $target ) );
        $real_target = $parent_real !== false
            ? $parent_real . DIRECTORY_SEPARATOR . basename( $target )
            : $target;
    }

    // Trailing separator avoids "sandbox-evil/" matching "sandbox/".
    if ( strpos( $real_target, $sandbox_root . DIRECTORY_SEPARATOR ) !== 0 ) {
        return new WP_Error(
            'e2m_outside_sandbox',
            sprintf(
                /* translators: %s: sandbox directory path */
                __( 'Operation restricted to the sandbox at %s.', 'e2mconnect' ),
                e2m_engine_get_sandbox_dir()
            )
        );
    }

    return true;
}

/* =====================================================================
 * 3. DISABLED-FILE DETECTION
 * ===================================================================== */

/**
 * Does the filename end with the `.disabled` marker?
 *
 * @param string $filepath Path or filename.
 * @return bool
 */
function e2m_engine_is_disabled_file( string $filepath ): bool {
    return substr( $filepath, -9 ) === '.disabled';
}

/* =====================================================================
 * 4. SENSITIVE-FILE GUARD
 * =====================================================================
 * Blocks MCP abilities from touching credentials, config, logs,
 * VCS metadata, or SQL dumps - regardless of user capabilities.
 * ================================================================== */

/**
 * Return a WP_Error when the path points to a protected resource;
 * null when the path is safe to operate on.
 *
 * @param string $abs_path Resolved absolute path.
 * @return WP_Error|null
 */
function e2m_engine_check_sensitive_file( string $abs_path ): ?WP_Error {

    $file_name   = strtolower( basename( $abs_path ) );
    $file_dir    = realpath( dirname( $abs_path ) ) ?: dirname( $abs_path );
    $root_dir    = realpath( ABSPATH ) ?: rtrim( ABSPATH, '/\\' );
    $content_dir = realpath( WP_CONTENT_DIR ) ?: rtrim( WP_CONTENT_DIR, '/\\' );

    // -- Environment variable files (any depth) ---------------------------
    $env_patterns = [ '.env', '.env.local', '.env.production', '.env.staging', '.env.development' ];
    if ( in_array( $file_name, $env_patterns, true ) ) {
        return new WP_Error( 'e2m_sensitive_file', sprintf( 'Protected environment file: "%s".', basename( $abs_path ) ) );
    }

    // -- Core config files at the WP root --------------------------------
    $core_protected = [ 'wp-config.php', 'wp-config-sample.php', '.htaccess', 'web.config' ];
    if ( $file_dir === $root_dir && in_array( $file_name, $core_protected, true ) ) {
        return new WP_Error( 'e2m_sensitive_file', sprintf( 'Protected WP core file: "%s".', basename( $abs_path ) ) );
    }

    // -- wp-content drop-ins and log files --------------------------------
    $content_guarded = [ 'debug.log', 'db.php', 'object-cache.php', 'advanced-cache.php' ];
    if ( $file_dir === $content_dir && in_array( $file_name, $content_guarded, true ) ) {
        return new WP_Error( 'e2m_sensitive_file', sprintf( 'Protected wp-content file: "%s".', basename( $abs_path ) ) );
    }

    // -- VCS directories at any depth (.git, .svn, .hg) -------------------
    $vcs_markers = [ '.git', '.svn', '.hg' ];
    foreach ( explode( DIRECTORY_SEPARATOR, $abs_path ) as $segment ) {
        if ( in_array( strtolower( $segment ), $vcs_markers, true ) ) {
            return new WP_Error( 'e2m_sensitive_file', 'Version-control metadata is protected.' );
        }
    }

    // -- SQL dump files at any depth --------------------------------------
    if ( strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) ) === 'sql' ) {
        return new WP_Error( 'e2m_sensitive_file', 'SQL dump files are protected.' );
    }

    return null;
}

/* =====================================================================
 * 5. PHP-EXTENSION & SANDBOX ENFORCEMENT
 * ===================================================================== */

/**
 * Does this filename have a PHP-executable extension?
 *
 * @param string $filepath Path or filename.
 * @return bool
 */
function e2m_engine_is_php_extension( string $filepath ): bool {
    $ext = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
    return in_array( $ext, [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'phps' ], true );
}

/**
 * Compatibility helper kept for callers that still invoke the old guard.
 *
 * @param string $abs_path Resolved absolute path.
 * @return true
 */
function e2m_engine_assert_safe_mutable_file_type( string $abs_path ) {
    // Allowed write zones (WP.org guideline: do not write outside these areas).
    $uploads_dir  = trailingslashit( wp_upload_dir()['basedir'] );
    $themes_dir   = trailingslashit( get_theme_root() );
    $plugins_dir  = trailingslashit( WP_PLUGIN_DIR );
    $sandbox_dir  = trailingslashit( E2M_ENGINE_SANDBOX_DIR );
    $mu_dir       = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( WPMU_PLUGIN_DIR ) : '';

    $normalised = trailingslashit( $abs_path );

    $allowed = [
        $uploads_dir,
        $themes_dir,
        $plugins_dir,
        $sandbox_dir,
    ];
    if ( $mu_dir !== '' ) {
        $allowed[] = $mu_dir;
    }

    foreach ( $allowed as $zone ) {
        if ( str_starts_with( $normalised, $zone ) || $abs_path === rtrim( $zone, '/\\' ) ) {
            return true;
        }
    }

    return new WP_Error(
        'e2m_write_disallowed',
        sprintf(
            /* translators: %s: absolute file path */
            __( 'Writing to "%s" is not permitted. Allowed zones: themes, plugins, uploads, and the E2M Connect sandbox.', 'e2mconnect' ),
            esc_html( $abs_path )
        )
    );
}

/**
 * PHP files may only live inside the sandbox.
 *
 * @param string $abs_path Resolved absolute path.
 * @return true|WP_Error
 */
function e2m_engine_check_php_sandbox( string $abs_path ) {
    if ( ! e2m_engine_is_php_extension( $abs_path ) ) {
        return true;
    }
    return e2m_engine_validate_sandbox_path( $abs_path );
}

/**
 * Enforce strict sandbox-only policy for mutating filesystem abilities.
 *
 * @param string $abs_path Resolved absolute path.
 * @return true|WP_Error
 */
function e2m_engine_enforce_sandbox_writes( string $abs_path ) {
    return e2m_engine_validate_sandbox_path( $abs_path );
}
