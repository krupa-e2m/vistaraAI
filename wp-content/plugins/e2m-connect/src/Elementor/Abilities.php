<?php
/**
 * Native Elementor abilities (WP Abilities API) — the drop-in replacement for
 * the elementor-mcp plugin's tools. Registered by Abilities\Manager and
 * exposed to MCP clients through the MCP Adapter plugin.
 *
 * Each definition returns the shape Manager::register() expects:
 *   label, description, category, readonly?, destructive?, input_schema,
 *   execute (callable), permission (callable).
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Elementor;

use E2M\Connect\Abilities\Manager;

class Abilities {

    private static function canEdit(): callable {
        return static fn() => current_user_can('edit_posts');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array {
        $cat  = Manager::CAT_ELEMENTOR;
        $edit = self::canEdit();

        return [
            /* ----------------------------- read ----------------------------- */

            'elementor-get-page-data' => [
                'label'        => __('Elementor: get page data', 'e2m-connect'),
                'description'  => __('Return the full _elementor_data element tree for a post.', 'e2m-connect'),
                'category'     => $cat,
                'readonly'     => true,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id'],
                    'properties' => ['post_id' => ['type' => 'integer']],
                ],
                'execute' => static function ($input) {
                    $id = (int) ($input['post_id'] ?? 0);
                    return ['post_id' => $id, 'data' => Helper::readData($id)];
                },
            ],

            'elementor-get-structure' => [
                'label'        => __('Elementor: get page structure', 'e2m-connect'),
                'description'  => __('Return a lightweight outline (ids, element/widget types, nesting) of a page.', 'e2m-connect'),
                'category'     => $cat,
                'readonly'     => true,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id'],
                    'properties' => ['post_id' => ['type' => 'integer']],
                ],
                'execute' => static function ($input) {
                    $id = (int) ($input['post_id'] ?? 0);
                    return ['post_id' => $id, 'structure' => Helper::structure(Helper::readData($id))];
                },
            ],

            'elementor-find-element' => [
                'label'        => __('Elementor: find element', 'e2m-connect'),
                'description'  => __('Return a single element (and its settings) by id.', 'e2m-connect'),
                'category'     => $cat,
                'readonly'     => true,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'element_id'],
                    'properties' => [
                        'post_id'    => ['type' => 'integer'],
                        'element_id' => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $node = Helper::findElement(Helper::readData((int) ($input['post_id'] ?? 0)), (string) ($input['element_id'] ?? ''));
                    return $node === null ? new \WP_Error('not_found', __('Element not found.', 'e2m-connect')) : $node;
                },
            ],

            'elementor-list-widgets' => [
                'label'       => __('Elementor: list widgets', 'e2m-connect'),
                'description' => __('List all registered Elementor widget types with titles and categories.', 'e2m-connect'),
                'category'    => $cat,
                'readonly'    => true,
                'permission'  => $edit,
                'execute'     => static fn() => ['widgets' => Helper::listWidgets()],
            ],

            'elementor-get-widget-schema' => [
                'label'        => __('Elementor: get widget schema', 'e2m-connect'),
                'description'  => __('Introspect a widget type\'s controls (settings keys, types, defaults, options).', 'e2m-connect'),
                'category'     => $cat,
                'readonly'     => true,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['widget_type'],
                    'properties' => ['widget_type' => ['type' => 'string']],
                ],
                'execute' => static function ($input) {
                    $schema = Helper::widgetSchema((string) ($input['widget_type'] ?? ''));
                    return $schema === null
                        ? new \WP_Error('not_found', __('Unknown widget type or Elementor not loaded.', 'e2m-connect'))
                        : ['widget_type' => $input['widget_type'], 'controls' => $schema];
                },
            ],

            'elementor-get-kit-globals' => [
                'label'       => __('Elementor: get kit globals', 'e2m-connect'),
                'description' => __('Return the active kit\'s global colors and typography (design tokens).', 'e2m-connect'),
                'category'    => $cat,
                'readonly'    => true,
                'permission'  => $edit,
                'execute'     => static fn() => Helper::kitGlobals(),
            ],

            /* ---------------------------- mutate ---------------------------- */

            'elementor-create-page' => [
                'label'        => __('Elementor: create page', 'e2m-connect'),
                'description'  => __('Create a new page with Elementor builder mode enabled, optionally seeding its element tree.', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => static fn() => current_user_can('edit_pages'),
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['title'],
                    'properties' => [
                        'title'  => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
                        'data'   => ['type' => 'array'],
                    ],
                ],
                'execute' => static function ($input) {
                    $id = wp_insert_post([
                        'post_type'   => 'page',
                        'post_title'  => sanitize_text_field($input['title'] ?? ''),
                        'post_status' => in_array($input['status'] ?? 'draft', ['draft', 'publish', 'pending', 'private'], true) ? $input['status'] : 'draft',
                    ], true);
                    if (is_wp_error($id)) {
                        return $id;
                    }
                    $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];
                    Helper::writeData((int) $id, $data, true);
                    return ['post_id' => (int) $id, 'edit_link' => get_edit_post_link($id, 'raw'), 'permalink' => get_permalink($id)];
                },
            ],

            'elementor-set-page-data' => [
                'label'        => __('Elementor: set page data', 'e2m-connect'),
                'description'  => __('Replace a page\'s entire _elementor_data tree (validated, slash-escaped, CSS regenerated).', 'e2m-connect'),
                'category'     => $cat,
                'destructive'  => true,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'data'],
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'data'    => ['type' => 'array'],
                    ],
                ],
                'execute' => static function ($input) {
                    $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];
                    $res  = Helper::writeData((int) ($input['post_id'] ?? 0), $data, true);
                    return $res['success'] ? $res : new \WP_Error('write_failed', $res['error'] ?? 'Write failed', $res);
                },
            ],

            'elementor-add-element' => [
                'label'        => __('Elementor: add element', 'e2m-connect'),
                'description'  => __('Insert an element (container/widget subtree) under a parent (or top-level) at a position. Fresh ids are assigned.', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'element'],
                    'properties' => [
                        'post_id'   => ['type' => 'integer'],
                        'element'   => ['type' => 'object'],
                        'parent_id' => ['type' => 'string'],
                        'position'  => ['type' => 'integer'],
                    ],
                ],
                'execute' => static function ($input) {
                    $postId = (int) ($input['post_id'] ?? 0);
                    $tree   = Helper::readData($postId);
                    $el     = Helper::reassignIds((array) ($input['element'] ?? []));
                    $ok     = Helper::addElement($tree, $input['parent_id'] ?? null, $el, (int) ($input['position'] ?? -1));
                    if (!$ok) {
                        return new \WP_Error('parent_not_found', __('Parent element not found.', 'e2m-connect'));
                    }
                    $res = Helper::writeData($postId, $tree, true);
                    return $res['success'] ? ['post_id' => $postId, 'element_id' => $el['id']] : new \WP_Error('write_failed', $res['error'] ?? 'Write failed', $res);
                },
            ],

            'elementor-update-element' => [
                'label'        => __('Elementor: update element', 'e2m-connect'),
                'description'  => __('Merge settings into an existing element by id, then regenerate CSS.', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'element_id', 'settings'],
                    'properties' => [
                        'post_id'    => ['type' => 'integer'],
                        'element_id' => ['type' => 'string'],
                        'settings'   => ['type' => 'object'],
                    ],
                ],
                'execute' => static function ($input) {
                    $postId = (int) ($input['post_id'] ?? 0);
                    $tree   = Helper::readData($postId);
                    $ok     = Helper::updateElementSettings($tree, (string) ($input['element_id'] ?? ''), (array) ($input['settings'] ?? []));
                    if (!$ok) {
                        return new \WP_Error('not_found', __('Element not found.', 'e2m-connect'));
                    }
                    $res = Helper::writeData($postId, $tree, true);
                    return $res['success'] ? ['post_id' => $postId, 'element_id' => $input['element_id'], 'updated' => true] : new \WP_Error('write_failed', $res['error'] ?? 'Write failed', $res);
                },
            ],

            'elementor-remove-element' => [
                'label'        => __('Elementor: remove element', 'e2m-connect'),
                'description'  => __('Remove an element (and its children) by id.', 'e2m-connect'),
                'category'     => $cat,
                'destructive'  => true,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'element_id'],
                    'properties' => [
                        'post_id'    => ['type' => 'integer'],
                        'element_id' => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $postId = (int) ($input['post_id'] ?? 0);
                    $tree   = Helper::readData($postId);
                    if (!Helper::removeElement($tree, (string) ($input['element_id'] ?? ''))) {
                        return new \WP_Error('not_found', __('Element not found.', 'e2m-connect'));
                    }
                    $res = Helper::writeData($postId, $tree, true);
                    return $res['success'] ? ['post_id' => $postId, 'removed' => true] : new \WP_Error('write_failed', $res['error'] ?? 'Write failed', $res);
                },
            ],

            'elementor-duplicate-element' => [
                'label'        => __('Elementor: duplicate element', 'e2m-connect'),
                'description'  => __('Deep-clone an element with fresh ids and append the copy next to the original.', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'element_id'],
                    'properties' => [
                        'post_id'    => ['type' => 'integer'],
                        'element_id' => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $postId = (int) ($input['post_id'] ?? 0);
                    $tree   = Helper::readData($postId);
                    $orig   = Helper::findElement($tree, (string) ($input['element_id'] ?? ''));
                    if ($orig === null) {
                        return new \WP_Error('not_found', __('Element not found.', 'e2m-connect'));
                    }
                    $clone = Helper::reassignIds($orig);
                    // Append at top level (simple + safe); agents can move it after.
                    $tree[] = $clone;
                    $res = Helper::writeData($postId, $tree, true);
                    return $res['success'] ? ['post_id' => $postId, 'new_element_id' => $clone['id']] : new \WP_Error('write_failed', $res['error'] ?? 'Write failed', $res);
                },
            ],

            'elementor-update-kit-globals' => [
                'label'        => __('Elementor: update kit globals', 'e2m-connect'),
                'description'  => __('Update the active kit\'s global colors/typography (design tokens).', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => static fn() => current_user_can('manage_options'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'system_colors'     => ['type' => 'array'],
                        'custom_colors'     => ['type' => 'array'],
                        'system_typography' => ['type' => 'array'],
                        'custom_typography' => ['type' => 'array'],
                    ],
                ],
                'execute' => static function ($input) {
                    $res = Helper::updateKitGlobals((array) $input);
                    return $res['success'] ? $res : new \WP_Error('kit_update_failed', $res['error'] ?? 'Update failed');
                },
            ],

            'elementor-regenerate-css' => [
                'label'        => __('Elementor: regenerate CSS', 'e2m-connect'),
                'description'  => __('Clear and rebuild cached CSS for a post (or the whole site when no post given).', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => $edit,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => ['post_id' => ['type' => 'integer']],
                ],
                'execute' => static function ($input) {
                    $id = isset($input['post_id']) ? (int) $input['post_id'] : null;
                    Helper::regenerateCss($id);
                    return ['regenerated' => true, 'post_id' => $id];
                },
            ],

            'elementor-sideload-image' => [
                'label'        => __('Elementor: sideload image', 'e2m-connect'),
                'description'  => __('Download an image from a URL into the media library and return its attachment id + URL.', 'e2m-connect'),
                'category'     => $cat,
                'permission'   => static fn() => current_user_can('upload_files'),
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['image_url'],
                    'properties' => [
                        'image_url' => ['type' => 'string'],
                        'alt_text'  => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    $url = esc_url_raw((string) ($input['image_url'] ?? ''));
                    if ($url === '') {
                        return new \WP_Error('no_url', __('image_url is required.', 'e2m-connect'));
                    }
                    $id = media_sideload_image($url, 0, null, 'id');
                    if (is_wp_error($id)) {
                        return $id;
                    }
                    if (!empty($input['alt_text'])) {
                        update_post_meta((int) $id, '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
                    }
                    return ['attachment_id' => (int) $id, 'url' => wp_get_attachment_url((int) $id)];
                },
            ],
        ];
    }
}
