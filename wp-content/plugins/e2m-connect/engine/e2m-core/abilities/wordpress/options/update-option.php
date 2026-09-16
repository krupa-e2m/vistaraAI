<?php
/**
 * E2M Connect MCP - Update Option
 *
 * Writes a value to wp_options with the same secret-key deny list used by
 * get-option. Values are passed through as-is (WordPress will serialise
 * arrays and objects on write); callers are expected to respect the option's
 * existing shape.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-option', [
	'label'       => __( '[Options] Update Option', 'e2mconnect' ),
	'description' => 'Updates a wp_options value. Secret-shaped keys are blocked.',
	'category'    => 'e2m-admin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'key'      => [ 'type' => 'string', 'minLength' => 1 ],
			'value'    => [ 'description' => 'Any JSON value. Objects and arrays are serialised by core.' ],
			'autoload' => [ 'type' => 'boolean', 'default' => true ],
		],
		'required'             => [ 'key' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'key'     => [ 'type' => 'string' ],
			'updated' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_option_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => false ],
		'annotations'  => [
			'title'       => 'Update Option',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-option ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_option_ability( array $input ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to update options.', 'e2mconnect' ) );
	}

	$key = isset( $input['key'] ) ? trim( (string) $input['key'] ) : '';
	if ( $key === '' ) {
		return new WP_Error( 'invalid_key', __( 'Option key is required.', 'e2mconnect' ) );
	}

	$value    = array_key_exists( 'value', $input ) ? $input['value'] : null;
	$autoload = ! empty( $input['autoload'] ) ? 'yes' : 'no';

	$updated = update_option( $key, $value, $autoload );

	return [
		'key'     => $key,
		'updated' => (bool) $updated,
	];
}
