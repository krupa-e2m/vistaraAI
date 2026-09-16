<?php
/**
 * E2M Connect MCP - Ability Discovery Endpoint
 *
 * Provides MCP clients with a catalogue of all publicly available
 * WordPress abilities so they can build tool lists dynamically.
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
 * Build a normalized metadata block for a public ability.
 *
 * @param WP_Ability $ability
 * @return array<string,mixed>
 */
function e2m_engine_build_public_ability_descriptor( WP_Ability $ability ): array {
    $meta        = $ability->get_meta();
    $annotations = (array) ( $meta['annotations'] ?? [] );

    return [
        'name'        => $ability->get_name(),
        'label'       => $ability->get_label(),
        'description' => $ability->get_description(),
        'category'    => (string) ( $meta['category'] ?? '' ),
        'readonly'    => (bool) ( $annotations['readonly'] ?? false ),
        'destructive' => (bool) ( $annotations['destructive'] ?? false ),
        'idempotent'  => (bool) ( $annotations['idempotent'] ?? false ),
        'tags'        => array_values( array_filter( array_map( 'strval', (array) ( $annotations['tags'] ?? [] ) ) ) ),
    ];
}

/**
 * Collect every registered ability that opts into MCP public exposure.
 *
 * @return array<string,mixed>
 */
function e2m_engine_collect_public_abilities(): array {
    $catalogue      = [];
    $discovered_at  = gmdate( 'c' );
    $bridge_version = 'e2m_engine_bridge_v2';

    foreach ( wp_get_abilities() as $registered ) {
        if ( ! ( $registered instanceof WP_Ability ) ) {
            continue;
        }

        $annotations = $registered->get_meta();
        // bridge_visible mirrors the original mcp.public set at registration,
        // before the capabilities-profile filter potentially downgraded it.
        // The bridge always exposes the full library so agents in Minimal
        // or Standard profiles can still discover + dispatch the long tail.
        $is_public   = array_key_exists( 'bridge_visible', $annotations['mcp'] ?? [] )
            ? ! empty( $annotations['mcp']['bridge_visible'] )
            : ! empty( $annotations['mcp']['public'] );
        $mcp_kind    = $annotations['mcp']['type'] ?? 'tool';

        if ( ! $is_public || $mcp_kind !== 'tool' ) {
            continue;
        }

        $catalogue[] = e2m_engine_build_public_ability_descriptor( $registered );
    }

    usort(
        $catalogue,
        static function ( array $left, array $right ): int {
            return strcmp( (string) $left['name'], (string) $right['name'] );
        }
    );

    // Inject site owner instructions (Rules & Instructions tab).
    $instr   = trim( (string) get_option( 'e2m_memory_instructions_content', '' ) );
    $md_file = trim( (string) get_option( 'e2m_memory_instructions_md', '' ) );
    $site_instructions = implode( "\n\n", array_filter( [ $instr, $md_file ] ) );

    return [
        'ok'                => true,
        'bridge'            => $bridge_version,
        'site_instructions' => $site_instructions,
        'generated_at_gmt'  => $discovered_at,
        'ability_count'     => count( $catalogue ),
        'abilities'         => $catalogue,
    ];
}

$e2m_discovery_ability_config = [
    'label'       => __( 'E2M Discover Tools', 'e2mconnect' ),
    'description' => __( 'Returns a E2M Bridge catalogue of publicly exposed WordPress tools with capability metadata.', 'e2mconnect' ),
    'category'    => 'e2m-bridge',

    'input_schema' => [
        'type'                 => 'object',
        'properties'           => new \stdClass(),
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'ok'                => [ 'type' => 'boolean', 'description' => 'Always true when discovery succeeds.' ],
            'bridge'            => [ 'type' => 'string', 'description' => 'Bridge contract version identifier.' ],
            'site_instructions' => [ 'type' => 'string', 'description' => 'Site owner rules and instructions — read and follow before taking any action.' ],
            'generated_at_gmt'  => [ 'type' => 'string', 'description' => 'UTC timestamp when the response was generated (ISO-8601).' ],
            'ability_count'     => [ 'type' => 'integer', 'description' => 'Number of public MCP abilities exposed in this response.' ],
            'abilities' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [ 'type' => 'string', 'description' => 'Fully-qualified ability identifier.' ],
                        'label'       => [ 'type' => 'string', 'description' => 'Human-readable title.' ],
                        'description' => [ 'type' => 'string', 'description' => 'What the ability does.' ],
                        'category'    => [ 'type' => 'string', 'description' => 'Ability category slug, when available.' ],
                        'readonly'    => [ 'type' => 'boolean', 'description' => 'Whether the ability is annotated as read-only.' ],
                        'destructive' => [ 'type' => 'boolean', 'description' => 'Whether the ability is annotated as destructive.' ],
                        'idempotent'  => [ 'type' => 'boolean', 'description' => 'Whether repeated calls are expected to produce stable side effects.' ],
                        'tags'        => [
                            'type'        => 'array',
                            'description' => 'Optional semantic tags provided by ability annotations.',
                            'items'       => [ 'type' => 'string' ],
                        ],
                    ],
                    'required' => [ 'name', 'label', 'description', 'category', 'readonly', 'destructive', 'idempotent', 'tags' ],
                ],
            ],
        ],
        'required' => [ 'ok', 'bridge', 'generated_at_gmt', 'ability_count', 'abilities' ],
    ],

    'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
    'execute_callback'    => 'e2m_engine_collect_public_abilities',

    'meta' => [
        // Bridge tools are always exposed to MCP regardless of profile so
        // agents can discover the full library on demand.
        'mcp'         => [ 'public' => true, 'tier' => 'essential' ],
        'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
    ],
];

// Single canonical name. The mcp-adapter library may register its own
// `mcp-adapter/discover-abilities` separately; we don't duplicate it here.
$e2m_discovery_ability_ids = [
    'e2m-bridge/discover-tools',
];

foreach ( $e2m_discovery_ability_ids as $e2m_ability_id ) {
    if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $e2m_ability_id ) ) {
        continue;
    }
    wp_register_ability( $e2m_ability_id, $e2m_discovery_ability_config );
}
