<?php
/**
 * E2M Connect MCP - Ability Execution Bridge
 *
 * Routes an MCP tool-call to the matching WordPress ability,
 * performs permission checks, and wraps the result in a
 * standardised success/error envelope.
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
 * Gate: capability + ability existence + MCP visibility + ability-level permissions.
 *
 * @param  array<string,mixed>|null $args Incoming request arguments.
 * @return true|WP_Error
 */
function e2m_engine_gate_ability_run( ?array $args ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'e2m_no_cap', __( 'You lack the required capability.', 'e2mconnect' ) );
    }

    $identifier = trim( (string) ( $args['ability_name'] ?? '' ) );

    if ( $identifier === '' ) {
        return new WP_Error( 'e2m_blank_ability', __( 'ability_name is required.', 'e2mconnect' ) );
    }

    $target = wp_get_ability( $identifier );
    if ( ! $target ) {
        return new WP_Error(
            'e2m_unresolved',
            /* translators: %s: ability name */
            sprintf( __( 'Ability "%s" does not exist.', 'e2mconnect' ), $identifier )
        );
    }

    $flags = $target->get_meta();
    if ( empty( $flags['mcp']['public'] ) ) {
        return new WP_Error(
            'e2m_hidden',
            /* translators: %s: ability name */
            sprintf( __( '"%s" is not available via MCP.', 'e2mconnect' ), $identifier )
        );
    }

    // Let the ability itself decide whether the current user may run it
    // with the supplied payload.
    $payload           = $args['parameters'] ?? null;
    $sanitised_payload = is_array( $payload ) && $payload !== [] ? $payload : null;
    $perm_result       = $target->check_permissions( $sanitised_payload );

    if ( is_wp_error( $perm_result ) ) {
        return $perm_result;
    }

    if ( $perm_result !== true ) {
        return new WP_Error(
            'e2m_forbidden_ability',
            /* translators: %s: ability name */
            sprintf( __( 'Permission denied for "%s".', 'e2mconnect' ), $identifier )
        );
    }

    return true;
}

/**
 * Build structured error payload.
 *
 * @param string               $code
 * @param string               $message
 * @param array<string,mixed>  $details
 * @return array<string,mixed>
 */
function e2m_engine_bridge_error_payload( string $code, string $message, array $details = [] ): array {
    $payload = [
        'code'    => $code,
        'message' => $message,
    ];

    if ( $details !== [] ) {
        $payload['details'] = $details;
    }

    return $payload;
}

/**
 * Dispatch the ability and wrap the outcome.
 *
 * @param  array<string,mixed>|null $args Incoming request arguments.
 * @return array<string,mixed>
 */
function e2m_engine_dispatch_ability( ?array $args ): array {
    $started_at = microtime( true );
    $identifier        = trim( (string) ( $args['ability_name'] ?? '' ) );
    $payload           = $args['parameters'] ?? null;
    $sanitised_payload = is_array( $payload ) && $payload !== [] ? $payload : null;
    $request_id        = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'e2m_engine_', true );

    $base = [
        'ok'             => false,
        'bridge'         => 'e2m_engine_bridge_v2',
        'request_id'     => $request_id,
        'ability'        => $identifier,
        'started_at_gmt' => gmdate( 'c' ),
        'duration_ms'    => 0,
        'telemetry'      => [
            'payload_key_count' => is_array( $sanitised_payload ) ? count( $sanitised_payload ) : 0,
            'payload_keys'      => is_array( $sanitised_payload ) ? array_values( array_map( 'strval', array_keys( $sanitised_payload ) ) ) : [],
        ],
    ];

    $target = wp_get_ability( $identifier );
    if ( ! $target ) {
        $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $base['error']       = e2m_engine_bridge_error_payload(
            'e2m_unresolved',
            /* translators: %s: ability name */
            sprintf( __( 'Unresolved ability: %s', 'e2mconnect' ), $identifier )
        );
        return $base;
    }

    // ── Context & Rules: block write operations if readonly mode is on ────────
    $write_keywords = [ 'update', 'delete', 'create', 'save', 'write', 'import', 'inject', 'apply', 'build', 'execute', 'patch', 'move', 'remove', 'reorder', 'duplicate', 'upload', 'sideload', 'convert', 'restore', 'protect', 'assign', 'add-' ];
    $is_write = false;
    foreach ( $write_keywords as $kw ) {
        if ( str_contains( $identifier, $kw ) ) { $is_write = true; break; }
    }
    if ( $is_write && function_exists( 'e2m_engine_check_write_allowed' ) ) {
        $write_check = e2m_engine_check_write_allowed();
        if ( is_wp_error( $write_check ) ) {
            $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
            $base['error']       = e2m_engine_bridge_error_payload(
                'e2m_write_blocked',
                $write_check->get_error_message()
            );
            return $base;
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    // ── Agent safety: back up before the task runs ───────────────────────────
    // The bridge executes abilities in-process, so the REST-layer safety
    // gatekeeper (rest_pre_dispatch) never fires for bridge-dispatched calls —
    // this is the primary path agents use. Run the same pre-task backups here
    // (meta-snapshot + Elementor export + scoped DB backup / warning).
    //
    // The returned ids are surfaced as `backup_id` on the success envelope
    // below so a caller can revert this exact change in one follow-up
    // command instead of rediscovering it via list-post-history.
    $backup_ids = [ 'meta_snapshot_id' => '', 'elementor_export_id' => '' ];
    if ( class_exists( 'E2M_Safety_Gatekeeper' ) ) {
        $backup_ids = E2M_Safety_Gatekeeper::pre_execute_safety( $identifier, is_array( $sanitised_payload ) ? $sanitised_payload : [] );
    }
    // ─────────────────────────────────────────────────────────────────────────

    try {
        $outcome = $target->execute( $sanitised_payload );

        if ( is_wp_error( $outcome ) ) {
            $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
            $base['error']       = e2m_engine_bridge_error_payload(
                $outcome->get_error_code() ?: 'e2m_execution_error',
                $outcome->get_error_message(),
                [ 'data' => $outcome->get_error_data() ]
            );
            return $base;
        }

        $base['ok']          = true;
        $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $base['result']      = $outcome;

        // Only surface backup_id when a backup actually fired (read-only
        // abilities and calls with no resolvable post_id take none) - never
        // report an id that does not correspond to a real, restorable backup.
        $backup_id = $backup_ids['meta_snapshot_id'] !== '' ? $backup_ids['meta_snapshot_id'] : $backup_ids['elementor_export_id'];
        if ( $backup_id !== '' ) {
            $base['backup_id']  = $backup_id;
            $base['backup_ids'] = array_filter( $backup_ids, static fn( $id ) => $id !== '' );
        }

        return $base;
    } catch ( \Throwable $fault ) {
        $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $base['error']       = e2m_engine_bridge_error_payload(
            'e2m_exception',
            $fault->getMessage(),
            [ 'exception_class' => get_class( $fault ) ]
        );
        return $base;
    }
}

$e2m_dispatch_ability_config = [
    'label'       => __( 'E2M Dispatch Tool', 'e2mconnect' ),
    'description' => __( 'Executes a target WordPress tool using the E2M Bridge dispatch contract.', 'e2mconnect' ),
    'category'    => 'e2m-bridge',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'ability_name' => [
                'type'        => 'string',
                'description' => 'Fully-qualified ability identifier to dispatch.',
            ],
            'parameters' => [
                'type'                 => 'object',
                'description'          => 'Key-value arguments forwarded to the ability.',
                'additionalProperties' => true,
            ],
        ],
        'required'             => [ 'ability_name', 'parameters' ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'ok'             => [ 'type' => 'boolean', 'description' => 'Whether the ability completed successfully.' ],
            'bridge'         => [ 'type' => 'string', 'description' => 'Bridge contract version identifier.' ],
            'request_id'     => [ 'type' => 'string', 'description' => 'Unique request identifier for traceability.' ],
            'ability'        => [ 'type' => 'string', 'description' => 'Ability identifier that was executed.' ],
            'started_at_gmt' => [ 'type' => 'string', 'description' => 'UTC start timestamp (ISO-8601).' ],
            'duration_ms'    => [ 'type' => 'integer', 'description' => 'Measured execution duration in milliseconds.' ],
            'result'         => [ 'description' => 'Payload returned by the ability on success.' ],
            'backup_id'      => [ 'type' => 'string', 'description' => 'Id of the automatic pre-write backup taken before this call, if any (meta-snapshot id, preferred, else Elementor export id). Pass to e2m/restore-post-state or e2m/restore-elementor-backup to revert this exact change. Only present when a backup actually fired.' ],
            'backup_ids'     => [
                'type'                 => 'object',
                'description'          => 'Every backup id captured for this call, keyed by mechanism (meta_snapshot_id, elementor_export_id). Only present when at least one fired.',
                'additionalProperties' => true,
            ],
            'error'          => [
                'type'       => 'object',
                'properties' => [
                    'code'    => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                    'details' => [ 'type' => 'object', 'additionalProperties' => true ],
                ],
                'required' => [ 'code', 'message' ],
            ],
            'telemetry'      => [
                'type'       => 'object',
                'properties' => [
                    'payload_key_count' => [ 'type' => 'integer' ],
                    'payload_keys'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
                'required' => [ 'payload_key_count', 'payload_keys' ],
            ],
        ],
        'required' => [ 'ok', 'bridge', 'request_id', 'ability', 'started_at_gmt', 'duration_ms', 'telemetry' ],
    ],

    'permission_callback' => 'e2m_engine_gate_ability_run',
    'execute_callback'    => 'e2m_engine_dispatch_ability',

    'meta' => [
        'mcp'         => [ 'public' => true, 'tier' => 'essential' ],
        'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
    ],
];

$e2m_dispatch_ability_ids = [
    'e2m-bridge/dispatch-tool',
];

foreach ( $e2m_dispatch_ability_ids as $e2m_ability_id ) {
    if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $e2m_ability_id ) ) {
        continue;
    }
    wp_register_ability( $e2m_ability_id, $e2m_dispatch_ability_config );
}
