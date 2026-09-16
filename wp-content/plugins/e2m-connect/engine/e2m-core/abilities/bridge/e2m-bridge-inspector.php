<?php
/**
 * E2M Connect MCP - Single-Ability Inspector
 *
 * Lets an MCP client fetch the full schema, metadata, and annotations
 * for one specific ability before deciding to call it.
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
 * Gate: verify the requested ability exists and is publicly exposed.
 *
 * @param  array<string,mixed>|null $args Incoming request arguments.
 * @return true|WP_Error
 */
function e2m_engine_gate_ability_info( ?array $args ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'e2m_forbidden', __( 'Insufficient permissions.', 'e2mconnect' ) );
    }

    $identifier = trim( (string) ( $args['ability_name'] ?? '' ) );

    if ( $identifier === '' ) {
        return new WP_Error( 'e2m_empty_identifier', __( 'Provide the ability_name to inspect.', 'e2mconnect' ) );
    }

    $target = wp_get_ability( $identifier );

    if ( ! $target ) {
        return new WP_Error(
            'e2m_not_registered',
            /* translators: %s: ability identifier */
            sprintf( __( 'No ability named "%s" is registered.', 'e2mconnect' ), $identifier )
        );
    }

    $flags = $target->get_meta();
    if ( empty( $flags['mcp']['public'] ) ) {
        return new WP_Error(
            'e2m_not_exposed',
            /* translators: %s: ability identifier */
            sprintf( __( '"%s" exists but is not exposed via MCP.', 'e2mconnect' ), $identifier )
        );
    }

    return true;
}

/**
 * Build the ability detail payload.
 *
 * @param  array<string,mixed>|null $args Incoming request arguments.
 * @return array<string,mixed>
 */
function e2m_engine_inspect_ability( ?array $args ): array {
    $identifier = trim( (string) ( $args['ability_name'] ?? '' ) );
    $target     = wp_get_ability( $identifier );
    $inspected  = gmdate( 'c' );

    if ( ! $target ) {
        return [
            'ok'               => false,
            'bridge'           => 'e2m_engine_bridge_v2',
            'inspected_at_gmt' => $inspected,
            'ability'          => $identifier,
            /* translators: %s: ability identifier */
            'error'            => sprintf( __( '"%s" could not be resolved.', 'e2mconnect' ), $identifier ),
        ];
    }

    $meta        = $target->get_meta();
    $annotations = (array) ( $meta['annotations'] ?? [] );

    $detail = [
        'ok'               => true,
        'bridge'           => 'e2m_engine_bridge_v2',
        'inspected_at_gmt' => $inspected,
        'ability'          => $target->get_name(),
        'descriptor'       => [
            'name'          => $target->get_name(),
            'label'         => $target->get_label(),
            'description'   => $target->get_description(),
            'category'      => (string) ( $meta['category'] ?? '' ),
            'input_schema'  => $target->get_input_schema(),
            'output_schema' => $target->get_output_schema(),
            'meta'          => $meta,
        ],
        'capability_profile' => [
            'public'      => (bool) ( $meta['mcp']['public'] ?? false ),
            'readonly'    => (bool) ( $annotations['readonly'] ?? false ),
            'destructive' => (bool) ( $annotations['destructive'] ?? false ),
            'idempotent'  => (bool) ( $annotations['idempotent'] ?? false ),
        ],
    ];

    return $detail;
}

$e2m_inspect_ability_config = [
    'label'       => __( 'E2M Inspect Tool', 'e2mconnect' ),
    'description' => __( 'Returns full tool specification using the E2M Bridge inspection model.', 'e2mconnect' ),
    'category'    => 'e2m-bridge',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'ability_name' => [
                'type'        => 'string',
                'description' => 'Fully-qualified ability identifier to inspect.',
            ],
        ],
        'required'             => [ 'ability_name' ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'ok'               => [ 'type' => 'boolean' ],
            'bridge'           => [ 'type' => 'string' ],
            'inspected_at_gmt' => [ 'type' => 'string' ],
            'ability'          => [ 'type' => 'string' ],
            'error'            => [ 'type' => 'string' ],
            'descriptor'       => [
                'type'       => 'object',
                'properties' => [
                    'name'          => [ 'type' => 'string' ],
                    'label'         => [ 'type' => 'string' ],
                    'description'   => [ 'type' => 'string' ],
                    'category'      => [ 'type' => 'string' ],
                    'input_schema'  => [ 'type' => 'object', 'additionalProperties' => true ],
                    'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],
                    'meta'          => [ 'type' => 'object', 'additionalProperties' => true ],
                ],
                'required' => [ 'name', 'label', 'description', 'category', 'input_schema', 'output_schema', 'meta' ],
            ],
            'capability_profile' => [
                'type'       => 'object',
                'properties' => [
                    'public'      => [ 'type' => 'boolean' ],
                    'readonly'    => [ 'type' => 'boolean' ],
                    'destructive' => [ 'type' => 'boolean' ],
                    'idempotent'  => [ 'type' => 'boolean' ],
                ],
                'required' => [ 'public', 'readonly', 'destructive', 'idempotent' ],
            ],
        ],
        'required' => [ 'ok', 'bridge', 'inspected_at_gmt', 'ability' ],
    ],

    'permission_callback' => 'e2m_engine_gate_ability_info',
    'execute_callback'    => 'e2m_engine_inspect_ability',

    'meta' => [
        'mcp'         => [ 'public' => true, 'tier' => 'essential' ],
        'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
    ],
];

$e2m_inspect_ability_ids = [
    'e2m-bridge/inspect-tool',
];

foreach ( $e2m_inspect_ability_ids as $e2m_ability_id ) {
    if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $e2m_ability_id ) ) {
        continue;
    }
    wp_register_ability( $e2m_ability_id, $e2m_inspect_ability_config );
}
