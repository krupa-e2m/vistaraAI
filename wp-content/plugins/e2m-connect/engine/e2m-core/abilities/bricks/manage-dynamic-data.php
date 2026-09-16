<?php
/**
 * E2M Connect MCP - Bricks Manage Dynamic Data
 *
 * Connect and manage Bricks dynamic data sources and bindings on page
 * elements. Bricks dynamic data is stored as `{dynamic_tag}` strings
 * inside element settings. This ability lists available dynamic tags
 * and applies/removes dynamic bindings on specific element settings keys.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-dynamic-data', [
	'label'       => __( '[Bricks] Manage Dynamic Data', 'e2mconnect' ),
	'description' => 'Connect and manage Bricks dynamic data sources and bindings — list available tags, apply bindings to elements, and remove them.',
	'category'    => 'e2m-bricks',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [
				'type'        => 'integer',
				'description' => 'The post/page ID to operate on (required for bind, unbind, list_bindings).',
			],
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list_tags', 'list_bindings', 'bind', 'unbind' ],
				'description' => 'list_tags — show available dynamic tags; list_bindings — show current bindings on a page; bind — apply a tag to an element setting; unbind — remove a dynamic binding.',
			],
			'element_id' => [
				'type'        => 'string',
				'description' => 'Bricks element ID to target (required for bind and unbind).',
			],
			'setting_key' => [
				'type'        => 'string',
				'description' => 'Element settings key to bind (e.g. "text", "heading", "url", "image").',
			],
			'dynamic_tag' => [
				'type'        => 'string',
				'description' => 'The Bricks dynamic tag to bind (e.g. "{post_title}", "{post_excerpt}", "{acf_field:my_field}").',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'post_id'  => [ 'type' => 'integer' ],
			'tags'     => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'bindings' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'applied'  => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_bricks_manage_dynamic_data_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Dynamic Data',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-dynamic-data ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_dynamic_data_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] ?? '' );

	// list_tags — no post_id needed, just read available dynamic tags.
	if ( $action === 'list_tags' ) {
		$tags = e2m_engine_bricks_get_available_dynamic_tags();
		return [ 'action' => 'list_tags', 'tags' => $tags ];
	}

	$post_id = (int) ( $input['post_id'] ?? 0 );
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', __( 'A valid post_id is required for this action.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
	$elements = is_array( $elements ) ? $elements : [];

	if ( $action === 'list_bindings' ) {
		// Walk all elements, collect any settings values that look like {tag}.
		$bindings = [];
		foreach ( $elements as $el ) {
			$settings = $el['settings'] ?? [];
			foreach ( $settings as $key => $value ) {
				if ( is_string( $value ) && preg_match( '/\{[^}]+\}/', $value ) ) {
					preg_match_all( '/\{[^}]+\}/', $value, $matches );
					$bindings[] = [
						'element_id'  => $el['id'] ?? '',
						'element_name' => $el['name'] ?? '',
						'setting_key' => $key,
						'value'       => $value,
						'tags'        => $matches[0],
					];
				}
			}
		}
		return [ 'action' => 'list_bindings', 'post_id' => $post_id, 'bindings' => $bindings ];
	}

	if ( $action === 'bind' ) {
		$element_id  = sanitize_key( $input['element_id'] ?? '' );
		$setting_key = sanitize_key( $input['setting_key'] ?? '' );
		$dynamic_tag = sanitize_text_field( $input['dynamic_tag'] ?? '' );

		if ( $element_id === '' || $setting_key === '' || $dynamic_tag === '' ) {
			return new WP_Error( 'missing_fields', __( 'element_id, setting_key, and dynamic_tag are required to bind.', 'e2mconnect' ) );
		}

		$found = false;
		foreach ( $elements as &$el ) {
			if ( ( $el['id'] ?? '' ) === $element_id ) {
				$el['settings'][ $setting_key ] = $dynamic_tag;
				$found = true;
				break;
			}
		}
		unset( $el );

		if ( ! $found ) {
			return new WP_Error( 'element_not_found', __( 'Element not found on this page.', 'e2mconnect' ) );
		}

		update_post_meta( $post_id, '_bricks_page_content_2', $elements );
		return [ 'action' => 'bind', 'post_id' => $post_id, 'applied' => true ];
	}

	if ( $action === 'unbind' ) {
		$element_id  = sanitize_key( $input['element_id'] ?? '' );
		$setting_key = sanitize_key( $input['setting_key'] ?? '' );

		if ( $element_id === '' || $setting_key === '' ) {
			return new WP_Error( 'missing_fields', __( 'element_id and setting_key are required to unbind.', 'e2mconnect' ) );
		}

		foreach ( $elements as &$el ) {
			if ( ( $el['id'] ?? '' ) === $element_id ) {
				unset( $el['settings'][ $setting_key ] );
				break;
			}
		}
		unset( $el );

		update_post_meta( $post_id, '_bricks_page_content_2', $elements );
		return [ 'action' => 'unbind', 'post_id' => $post_id, 'applied' => true ];
	}

	return new WP_Error( 'invalid_action', __( 'Invalid action. Use list_tags, list_bindings, bind, or unbind.', 'e2mconnect' ) );
}

/**
 * Return a list of known Bricks dynamic tags with their descriptions.
 *
 * @return array<int, array<string, string>>
 */
function e2m_engine_bricks_get_available_dynamic_tags(): array {
	$core_tags = [
		// Post
		[ 'tag' => '{post_id}',            'label' => 'Post ID',            'group' => 'post' ],
		[ 'tag' => '{post_title}',          'label' => 'Post Title',          'group' => 'post' ],
		[ 'tag' => '{post_excerpt}',        'label' => 'Post Excerpt',        'group' => 'post' ],
		[ 'tag' => '{post_content}',        'label' => 'Post Content',        'group' => 'post' ],
		[ 'tag' => '{post_date}',           'label' => 'Post Date',           'group' => 'post' ],
		[ 'tag' => '{post_modified}',       'label' => 'Post Modified Date',  'group' => 'post' ],
		[ 'tag' => '{post_author_name}',    'label' => 'Post Author Name',    'group' => 'post' ],
		[ 'tag' => '{post_url}',            'label' => 'Post URL',            'group' => 'post' ],
		[ 'tag' => '{post_thumbnail_url}',  'label' => 'Featured Image URL',  'group' => 'post' ],
		[ 'tag' => '{post_terms:category}', 'label' => 'Post Categories',     'group' => 'post' ],
		[ 'tag' => '{post_terms:post_tag}', 'label' => 'Post Tags',           'group' => 'post' ],
		// Site
		[ 'tag' => '{site_name}',           'label' => 'Site Name',           'group' => 'site' ],
		[ 'tag' => '{site_tagline}',        'label' => 'Site Tagline',        'group' => 'site' ],
		[ 'tag' => '{site_url}',            'label' => 'Site URL',            'group' => 'site' ],
		[ 'tag' => '{site_logo}',           'label' => 'Site Logo',           'group' => 'site' ],
		// User
		[ 'tag' => '{user_name}',           'label' => 'Current User Name',   'group' => 'user' ],
		[ 'tag' => '{user_email}',          'label' => 'Current User Email',  'group' => 'user' ],
		[ 'tag' => '{user_display_name}',   'label' => 'User Display Name',   'group' => 'user' ],
		// Archive
		[ 'tag' => '{archive_title}',       'label' => 'Archive Title',       'group' => 'archive' ],
		[ 'tag' => '{archive_description}', 'label' => 'Archive Description', 'group' => 'archive' ],
		// WooCommerce (present only when WC is active).
		[ 'tag' => '{woo_product_title}',   'label' => 'WooCommerce Product Title',  'group' => 'woocommerce' ],
		[ 'tag' => '{woo_product_price}',   'label' => 'WooCommerce Product Price',  'group' => 'woocommerce' ],
		[ 'tag' => '{woo_product_image}',   'label' => 'WooCommerce Product Image',  'group' => 'woocommerce' ],
	];

	// Include ACF tags if ACF is active.
	if ( function_exists( 'acf_get_field_groups' ) ) {
		$core_tags[] = [ 'tag' => '{acf_field:FIELD_NAME}', 'label' => 'ACF Field (replace FIELD_NAME)', 'group' => 'acf' ];
	}

	return $core_tags;
}
