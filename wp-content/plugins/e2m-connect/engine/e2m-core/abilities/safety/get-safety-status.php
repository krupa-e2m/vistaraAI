<?php
/**
 * E2M Connect MCP - Get Safety Status.
 *
 * Read-only snapshot of every safety lever on this site: effective mode,
 * detected environment, dry-run default, rate-limit cap, approval mode,
 * domain-lock state, crash flag, retained snapshot count. MCP clients
 * call this first when they want to decide how aggressive to be on this
 * particular install.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-safety-status', [
	'label'       => __( '[Safety] Safety Status', 'e2mconnect' ),
	'description' => 'Reports the effective safety mode, detected environment, rate-limit settings, domain-lock state, and crash recovery flag.',
	'category'    => 'e2m-safety',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'environment'          => [ 'type' => 'string' ],
			'dry_run_default'      => [ 'type' => 'boolean' ],
			'rate_limit_ops_per_min' => [ 'type' => 'integer' ],
			'snapshots_retention'  => [ 'type' => 'integer' ],
			'audit_retention_days' => [ 'type' => 'integer' ],
			'protected_posts'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'domain_lock'          => [
				'type'       => 'object',
				'properties' => [
					'enabled'          => [ 'type' => 'boolean' ],
					'locked_site_url'  => [ 'type' => 'string' ],
					'current_site_url' => [ 'type' => 'string' ],
					'trusted'          => [ 'type' => 'boolean' ],
				],
			],
			'crash_flag' => [
				'type'       => 'object',
				'properties' => [
					'tripped' => [ 'type' => 'boolean' ],
					'file'    => [ 'type' => 'string' ],
					'line'    => [ 'type' => 'integer' ],
					'message' => [ 'type' => 'string' ],
					'ts'      => [ 'type' => 'string' ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_get_safety_status_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Safety Status',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-safety-status ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_get_safety_status_ability( array $input ): array {
	$settings = e2m_engine_get_settings();
	return [
		'environment'            => E2M_Safety::environment(),
		'dry_run_default'        => (bool) ( $settings['dry_run_default'] ?? false ),
		'rate_limit_ops_per_min' => (int) ( $settings['rate_limit_ops_per_min'] ?? 60 ),
		'snapshots_retention'    => (int) ( $settings['snapshots_retention'] ?? 10 ),
		'audit_retention_days'   => (int) ( $settings['audit_retention_days'] ?? 30 ),
		'protected_posts'        => array_map( 'intval', (array) ( $settings['protected_posts'] ?? [] ) ),
		'domain_lock'            => E2M_Domain_Lock::status(),
		'crash_flag'             => E2M_Crash_Recovery::status(),
	];
}
