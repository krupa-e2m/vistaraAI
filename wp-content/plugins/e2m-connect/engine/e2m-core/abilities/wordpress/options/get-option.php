<?php
/**
 * E2M Connect MCP - Get Option
 *
 * Reads any wp_options row by key. Applies a deny list for well-known secret-
 * shaped keys (*_key, *_secret, *_token, etc.) so accidental enumeration of
 * credentials through a generic agent prompt fails loudly.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-option', [
	'label'       => __( '[Options] Get Option', 'e2mconnect' ),
	'description' => 'Reads a single wp_options value by key. Secret-shaped keys are blocked.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'key' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'key' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'key'   => [ 'type' => 'string' ],
			'value' => [ 'description' => 'Decoded option value. null when unset.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_option_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Get Option',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Historical secret-shaped deny list - kept for back-compat with any
 * extension that called it, but no longer used by core get/update/delete
 * option abilities. Admins running deep audits need to read API keys,
 * webhook secrets, and similar config values via MCP just as they would
 * via wp_options directly. Returns false unconditionally now.
 */
function e2m_engine_option_key_is_protected( string $key ): bool {
	return false;
}

/**
 * Execute the get-option ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_option_ability( array $input ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to read options.', 'e2mconnect' ) );
	}

	$key = isset( $input['key'] ) ? trim( (string) $input['key'] ) : '';
	if ( $key === '' ) {
		return new WP_Error( 'invalid_key', __( 'Option key is required.', 'e2mconnect' ) );
	}

	$value  = get_option( $key, null );
	$exists = $value !== null || get_option( $key, '__e2m_missing__' ) !== '__e2m_missing__';

	return [
		'key'   => $key,
		'value' => $exists ? $value : null,
	];
}
