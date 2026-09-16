<?php
/**
 * E2M Connect MCP - File Writer
 *
 * Persists content to disk with encoding support, optional backup,
 * dry-run preview, executable-file safeguards, and syntax linting.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register ability definition.

wp_register_ability( 'e2m/write-file', [
    'label'       => __( '[File] Write', 'e2mconnect' ),
    'description' => 'Writes content to a file inside the allowed E2M workspace. Supports UTF-8 and base64 encoding, overwrite / append modes, automatic parent-directory creation, optional pre-write backup (.bak), and dry-run preview.',
    'category' => 'e2m-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'    => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Destination path - relative paths resolve from ABSPATH.' ],
            'content' => [ 'type' => 'string', 'description' => 'Payload to persist.' ],
            'encoding' => [
                'type' => 'string', 'enum' => [ 'utf-8', 'base64' ], 'default' => 'utf-8',
                'description' => 'How the content field is encoded.',
            ],
            'mode' => [
                'type' => 'string', 'enum' => [ 'overwrite', 'append' ], 'default' => 'overwrite',
                'description' => 'Write strategy - overwrite replaces the file, append adds to it.',
            ],
            'create_directories' => [ 'type' => 'boolean', 'default' => true, 'description' => 'Auto-create missing parent directories.' ],
            'backup'  => [ 'type' => 'boolean', 'default' => false, 'description' => 'Create a .bak snapshot before overwriting an existing file.' ],
            'dry_run' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Validate without writing; returns a preview of what would happen.' ],
        ],
        'required'             => [ 'path', 'content' ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'                => [ 'type' => 'string',  'description' => 'Resolved destination path.' ],
            'bytes_written'       => [ 'type' => 'integer', 'description' => 'Bytes persisted (0 for dry-run).' ],
            'created'             => [ 'type' => 'boolean', 'description' => 'True when a brand-new file was created.' ],
            'directories_created' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Directories that were (or would be) created.' ],
            'size'                => [ 'type' => 'integer', 'description' => 'Final file size.' ],
            'backup_path'         => [ 'type' => 'string',  'description' => 'Absolute path to the backup copy (empty when the file was new or backups unavailable).' ],
            'backup_id'           => [ 'type' => 'string',  'description' => 'Rollback id for the pre-write backup (use with e2m/rollback-file).' ],
            'dry_run'             => [ 'type' => 'boolean', 'description' => 'True when this was a preview.' ],
        ],
    ],

    'execute_callback'    => 'e2m_engine_write_file',
    'permission_callback' => 'e2m_engine_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => [ 'public' => true ],
        'annotations'  => [
            'title'        => 'Write File',
            'instructions' => implode( "\n", [
                'PHP SANDBOX:',
                'PHP-executable files (.php, .phtml, .phar, etc.) can ONLY live in',
                E2M_ENGINE_SANDBOX_DIR . '. Non-PHP goes anywhere under ABSPATH.',
                '',
                'SAFETY:',
                'backup=true creates a .bak before overwriting.',
                'dry_run=true validates without touching disk.',
            ] ),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
] );

// Helper functions.

/**
 * Interpret the content payload based on declared encoding.
 *
 * @return string|WP_Error Decoded bytes.
 */
function e2m_engine_unpack_payload( string $raw, string $enc ): string|WP_Error {
    if ( $enc === 'base64' ) {
        $decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary file payload from client.
        if ( $decoded === false ) {
            return new WP_Error( 'e2m_base64_invalid', 'Malformed base64 payload.' );
        }
        return $decoded;
    }
    return $raw;
}

/**
 * Ensure the directory tree exists up to the given leaf.
 *
 * @return string[]|WP_Error  Paths created (empty when already present).
 */
function e2m_engine_provision_directory( string $dir_path ): array|WP_Error {
    if ( is_dir( $dir_path ) ) {
        return [];
    }

    // Walk up to discover which levels are missing.
    $needed = [];
    $probe  = $dir_path;
    while ( ! is_dir( $probe ) && dirname( $probe ) !== $probe ) {
        $needed[] = $probe;
        $probe    = dirname( $probe );
    }

    if ( ! wp_mkdir_p( $dir_path ) ) {
        return new WP_Error( 'e2m_mkdir_failed', sprintf( 'Could not create: %s', $dir_path ) );
    }

    return array_reverse( $needed );
}

/**
 * Assemble the full source for syntax checking when appending.
 */
function e2m_engine_combined_source( string $dest, string $new_content, string $write_mode ): string {
    if ( $write_mode !== 'append' || ! file_exists( $dest ) ) {
        return $new_content;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $prev = file_get_contents( $dest );
    return ( $prev !== false ) ? $prev . $new_content : $new_content;
}

/**
 * PHP lint check using the internal tokenizer parser when available.
 * Gracefully degrades when parsing support is unavailable.
 *
 * @return true|WP_Error
 */
function e2m_engine_lint_php( string $source ) {
    if ( PHP_BINARY === '' ) {
        return true;
    }

    $tmp = tempnam( sys_get_temp_dir(), 'e2m_lint_' );
    if ( $tmp === false ) {
        return true;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
    file_put_contents( $tmp, $source, LOCK_EX );

    // Use Sandbox Helper if available.
    if ( class_exists( 'E2M_Engine_Sandbox_Helper' ) ) {
        $binary = E2M_Engine_Sandbox_Helper::resolve_php_binary();
        if ( $binary !== '' ) {
            $lint = E2M_Engine_Sandbox_Helper::lint_php_file( $tmp, $binary );
            wp_delete_file( $tmp );
            if ( ! $lint['ok'] ) {
                $msg = str_replace( $tmp, 'source.php', $lint['message'] );
                return new WP_Error( 'e2m_syntax_error', 'PHP lint failed: ' . $msg );
            }
            return true;
        }
    }

    // Fallback: skip lint if no safe method available.
    wp_delete_file( $tmp );
    return true;
}

// Handle request and return response payload.

function e2m_engine_write_file( array $input ) {

    $dest = e2m_engine_resolve_path( (string) $input['path'], require_real: false );
    if ( is_wp_error( $dest ) ) {
        return $dest;
    }

    // Non-PHP files can be written anywhere under ABSPATH (theme CSS, JSON
    // configs, JS bundles, etc.). PHP files are still restricted to the
    // sandbox via check_php_sandbox below - that guard alone is enough to
    // prevent prompt-injected agents from installing backdoor PHP into
    // themes/plugins, while leaving normal authoring + deep-audit flows
    // unrestricted.

    $enc      = (string) ( $input['encoding'] ?? 'utf-8' );
    $strategy = (string) ( $input['mode'] ?? 'overwrite' );
    $auto_dir = ( $input['create_directories'] ?? true ) !== false;
    $snapshot = ! empty( $input['backup'] );
    $preview  = ! empty( $input['dry_run'] );
    $php_file = e2m_engine_is_php_extension( $dest );

    $safe_type = e2m_engine_assert_safe_mutable_file_type( $dest );
    if ( is_wp_error( $safe_type ) ) {
        return $safe_type;
    }

    // Validate sandbox restrictions for PHP-executable files.
    if ( $php_file ) {
        $sandbox = e2m_engine_check_php_sandbox( $dest );
        if ( is_wp_error( $sandbox ) ) {
            return $sandbox;
        }
    }

    // Decode incoming content payload.
    $body = e2m_engine_unpack_payload( (string) $input['content'], $enc );
    if ( is_wp_error( $body ) ) {
        return $body;
    }

    // Enforce sandbox max-file-size cap (PHP files always go to the sandbox).
    if ( $php_file ) {
        $cap_kb = (int) ( ( function_exists( 'e2m_engine_get_settings' ) ? e2m_engine_get_settings() : [] )['sandbox_max_file_size_kb'] ?? 100 );
        if ( $cap_kb > 0 ) {
            $final_bytes = strlen( e2m_engine_combined_source( $dest, $body, $strategy ) );
            if ( $final_bytes > $cap_kb * 1024 ) {
                return new WP_Error(
                    'e2m_file_too_large',
                    sprintf(
                        /* translators: 1: final file size in KB, 2: configured cap in KB */
                        'Sandbox file too large: %1$d KB exceeds the configured cap of %2$d KB. Raise the limit in the Sandbox tab or split content across multiple files.',
                        (int) ceil( $final_bytes / 1024 ),
                        $cap_kb
                    )
                );
            }
        }
    }

    $already_exists = file_exists( $dest );
    $is_new         = ! $already_exists;
    $parent         = dirname( $dest );

    // Ensure parent directory exists and is writable.
    if ( ! is_dir( $parent ) && ! $auto_dir ) {
        return new WP_Error( 'e2m_no_parent', sprintf( 'Parent directory absent: %s', $parent ) );
    }

    $dirs_made = e2m_engine_provision_directory( $parent );
    if ( is_wp_error( $dirs_made ) ) {
        return $dirs_made;
    }

    // Run syntax validation for PHP content before writing.
    if ( $php_file ) {
        $full_source = e2m_engine_combined_source( $dest, $body, $strategy );
        $lint        = e2m_engine_lint_php( $full_source );
        if ( is_wp_error( $lint ) ) {
            return $lint;
        }
    }

    // Stop before writing when dry_run is enabled.
    if ( $preview ) {
        return [
            'path'                => $dest,
            'bytes_written'       => 0,
            'created'             => $is_new,
            'directories_created' => $dirs_made,
            'size'                => $already_exists ? (int) filesize( $dest ) : strlen( $body ),
            'backup_path'         => '',
            'dry_run'             => true,
        ];
    }

    // Safety backup. We always snapshot an existing file before overwriting
    // it (not just when backup=true) so every agent write is rollback-able
    // through the unified backup store; the legacy `backup` flag is honoured
    // but no longer required. New files get a "created" marker so rollback can
    // remove them. The store keeps payloads in a protected, secret directory.
    $bak_path  = '';
    $backup_id = '';
    if ( class_exists( 'E2M_File_Backup' ) ) {
        $backup_id = E2M_File_Backup::backup_file( $dest, 'pre:e2m/write-file' );
        if ( $backup_id !== '' ) {
            $rec = E2M_Backup_Store::get( $backup_id );
            if ( is_array( $rec ) ) {
                $bak_path = E2M_Backup_Store::absolute_path( $rec );
            }
        }
    }

    // Persist content to disk.
    $write_flags = LOCK_EX | ( $strategy === 'append' ? FILE_APPEND : 0 );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
    $written     = file_put_contents( $dest, $body, $write_flags );

    if ( $written === false ) {
        return new WP_Error( 'e2m_write_failed', sprintf( 'Write failed: %s', $dest ) );
    }

    // Set sane permissions on newly created files.
    if ( $is_new ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        @chmod( $dest, 0644 );
    }

    return [
        'path'                => $dest,
        'bytes_written'       => $written,
        'created'             => $is_new,
        'directories_created' => $dirs_made,
        'size'                => (int) filesize( $dest ),
        'backup_path'         => $bak_path,
        'backup_id'           => $backup_id,
        'dry_run'             => false,
    ];
}
