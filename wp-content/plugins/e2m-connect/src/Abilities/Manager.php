<?php
/**
 * Registers WordPress Abilities (WP 7 Abilities API) for E2M Connect.
 *
 * Two tiers:
 *   1. Built-in abilities — page/section authoring + maintenance operations
 *      that Claude (or any MCP client) can invoke against this site.
 *   2. Custom abilities — defined by an admin in the Abilities screen and
 *      stored in the `e2m_connect_custom_abilities` option, then registered
 *      dynamically here.
 *
 * Abilities are namespaced `e2m-connect/<slug>` and grouped under two
 * categories. They only register when core's Abilities API is present
 * (WordPress 6.9/7.0+); on older cores the screen still lists definitions but
 * nothing is registered.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Abilities;

class Manager {

    const NAMESPACE        = 'e2m-connect';
    const OPTION_CUSTOM    = 'e2m_connect_custom_abilities';
    const CAT_CONTENT      = 'e2m-connect-content';
    const CAT_MAINTENANCE  = 'e2m-connect-maintenance';
    const CAT_ELEMENTOR    = 'e2m-connect-elementor';
    const CAT_CUSTOM       = 'e2m-connect-custom';

    public static function init(): void {
        if (!function_exists('wp_register_ability')) {
            return; // Abilities API not available on this core.
        }
        add_action('wp_abilities_api_categories_init', [self::class, 'registerCategories']);
        add_action('wp_abilities_api_init', [self::class, 'registerAbilities']);
    }

    public static function available(): bool {
        return function_exists('wp_register_ability');
    }

    public static function registerCategories(): void {
        wp_register_ability_category(self::CAT_CONTENT, [
            'label'       => __('Content Authoring', 'e2m-connect'),
            'description' => __('Create and edit pages, posts and sections.', 'e2m-connect'),
        ]);
        wp_register_ability_category(self::CAT_MAINTENANCE, [
            'label'       => __('Site Maintenance', 'e2m-connect'),
            'description' => __('Inspect and update plugins, themes and site state.', 'e2m-connect'),
        ]);
        wp_register_ability_category(self::CAT_ELEMENTOR, [
            'label'       => __('Elementor', 'e2m-connect'),
            'description' => __('Read and build Elementor pages directly via _elementor_data — no Elementor MCP server required.', 'e2m-connect'),
        ]);
        wp_register_ability_category(self::CAT_CUSTOM, [
            'label'       => __('Custom Abilities', 'e2m-connect'),
            'description' => __('Admin-defined abilities for this site.', 'e2m-connect'),
        ]);
    }

    public static function registerAbilities(): void {
        foreach (self::builtinDefinitions() as $slug => $def) {
            self::register($slug, $def);
        }
        foreach (\E2M\Connect\Elementor\Abilities::definitions() as $slug => $def) {
            self::register($slug, $def);
        }
        foreach (self::customDefinitions() as $slug => $def) {
            self::register($slug, $def);
        }
    }

    /**
     * Fully-qualified names of every ability we register (built-in + Elementor
     * + custom). Used to tell the MCP Adapter which abilities to expose.
     *
     * @return array<int,string>
     */
    public static function allNames(): array {
        $names = [];
        foreach (array_keys(self::builtinDefinitions()) as $slug) {
            $names[] = self::NAMESPACE . '/' . $slug;
        }
        foreach (array_keys(\E2M\Connect\Elementor\Abilities::definitions()) as $slug) {
            $names[] = self::NAMESPACE . '/' . $slug;
        }
        foreach (self::customRecords() as $r) {
            if (!empty($r['slug'])) {
                $names[] = self::NAMESPACE . '/' . $r['slug'];
            }
        }
        return $names;
    }

    private static function register(string $slug, array $def): void {
        $args = [
            'label'               => $def['label'],
            'description'         => $def['description'],
            'category'            => $def['category'] ?? self::CAT_CONTENT,
            'input_schema'        => $def['input_schema'] ?? ['type' => 'object'],
            'output_schema'       => $def['output_schema'] ?? ['type' => 'object'],
            'execute_callback'    => $def['execute'],
            'permission_callback' => $def['permission'] ?? static fn() => current_user_can('manage_options'),
            'meta'                => [
                'show_in_rest' => true,
                'annotations'  => [
                    'readonly'    => $def['readonly'] ?? false,
                    'destructive' => $def['destructive'] ?? false,
                ],
            ],
        ];
        wp_register_ability(self::NAMESPACE . '/' . $slug, $args);
    }

    /* ============================================================
       Built-in abilities
       ============================================================ */

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function builtinDefinitions(): array {
        return [
            'list-pages' => [
                'label'       => __('List pages', 'e2m-connect'),
                'description' => __('Return existing pages with id, title, slug and status.', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'readonly'    => true,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search' => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $query = new \WP_Query([
                        'post_type'      => 'page',
                        'post_status'    => ['publish', 'draft', 'pending', 'private'],
                        'posts_per_page' => 50,
                        'orderby'        => 'title',
                        'order'          => 'ASC',
                        's'              => (string) ($input['search'] ?? ''),
                        'no_found_rows'  => true,
                    ]);
                    $pages = [];
                    foreach ($query->posts as $post) {
                        $pages[] = [
                            'id'     => (int) $post->ID,
                            'title'  => get_the_title($post),
                            'slug'   => $post->post_name,
                            'status' => $post->post_status,
                        ];
                    }
                    return ['pages' => $pages];
                },
            ],

            'create-page' => [
                'label'       => __('Create page', 'e2m-connect'),
                'description' => __('Create a new WordPress page with a title, content and status.', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'input_schema' => [
                    'type'     => 'object',
                    'required' => ['title'],
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'status'  => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
                    ],
                ],
                'execute' => static function ($input) {
                    $id = wp_insert_post([
                        'post_type'    => 'page',
                        'post_title'   => sanitize_text_field($input['title'] ?? ''),
                        'post_content' => (string) ($input['content'] ?? ''),
                        'post_status'  => in_array($input['status'] ?? 'draft', ['draft', 'publish', 'pending', 'private'], true) ? $input['status'] : 'draft',
                    ], true);
                    if (is_wp_error($id)) {
                        return $id;
                    }
                    return ['id' => (int) $id, 'edit_link' => get_edit_post_link($id, 'raw'), 'permalink' => get_permalink($id)];
                },
            ],

            'update-page-content' => [
                'label'       => __('Update page content', 'e2m-connect'),
                'description' => __('Replace the content of an existing page or post by ID.', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'destructive' => true,
                'input_schema' => [
                    'type'     => 'object',
                    'required' => ['id', 'content'],
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'content' => ['type' => 'string'],
                        'title'   => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $post = get_post((int) ($input['id'] ?? 0));
                    if (!$post) {
                        return new \WP_Error('not_found', __('Post not found.', 'e2m-connect'));
                    }
                    $update = ['ID' => $post->ID, 'post_content' => (string) ($input['content'] ?? '')];
                    if (!empty($input['title'])) {
                        $update['post_title'] = sanitize_text_field($input['title']);
                    }
                    $res = wp_update_post($update, true);
                    return is_wp_error($res) ? $res : ['id' => (int) $post->ID, 'updated' => true];
                },
            ],

            'add-section' => [
                'label'       => __('Add section to page', 'e2m-connect'),
                'description' => __('Append a block/HTML section to the end of an existing page (additive, non-destructive).', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'input_schema' => [
                    'type'     => 'object',
                    'required' => ['id', 'section'],
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'section' => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $post = get_post((int) ($input['id'] ?? 0));
                    if (!$post) {
                        return new \WP_Error('not_found', __('Post not found.', 'e2m-connect'));
                    }
                    $content = $post->post_content . "\n\n" . (string) ($input['section'] ?? '');
                    $res = wp_update_post(['ID' => $post->ID, 'post_content' => $content], true);
                    return is_wp_error($res) ? $res : ['id' => (int) $post->ID, 'appended' => true];
                },
            ],

            'duplicate-page' => [
                'label'       => __('Create page from existing content', 'e2m-connect'),
                'description' => __('Clone an existing page\'s content into a new draft page with a new title.', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'input_schema' => [
                    'type'     => 'object',
                    'required' => ['source_id', 'title'],
                    'properties' => [
                        'source_id' => ['type' => 'integer'],
                        'title'     => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $src = get_post((int) ($input['source_id'] ?? 0));
                    if (!$src) {
                        return new \WP_Error('not_found', __('Source page not found.', 'e2m-connect'));
                    }
                    $id = wp_insert_post([
                        'post_type'    => 'page',
                        'post_title'   => sanitize_text_field($input['title'] ?? ($src->post_title . ' (copy)')),
                        'post_content' => $src->post_content,
                        'post_status'  => 'draft',
                    ], true);
                    if (is_wp_error($id)) {
                        return $id;
                    }
                    // Copy Elementor data if present so the clone renders.
                    $el = get_post_meta($src->ID, '_elementor_data', true);
                    if ($el) {
                        update_post_meta($id, '_elementor_data', $el);
                        update_post_meta($id, '_elementor_edit_mode', 'builder');
                    }
                    return ['id' => (int) $id, 'edit_link' => get_edit_post_link($id, 'raw')];
                },
            ],

            'list-plugins' => [
                'label'       => __('List plugins', 'e2m-connect'),
                'description' => __('List installed plugins with version and update availability.', 'e2m-connect'),
                'category'    => self::CAT_MAINTENANCE,
                'readonly'    => true,
                'execute'     => static function () {
                    if (!function_exists('get_plugins')) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }
                    $all     = get_plugins();
                    $updates = get_site_transient('update_plugins');
                    $active  = (array) get_option('active_plugins', []);
                    $out     = [];
                    foreach ($all as $file => $data) {
                        $out[] = [
                            'file'            => $file,
                            'name'            => $data['Name'],
                            'version'         => $data['Version'],
                            'active'          => in_array($file, $active, true),
                            'update_available'=> isset($updates->response[$file]),
                        ];
                    }
                    return $out;
                },
            ],

            'update-plugins' => [
                'label'       => __('Update plugins', 'e2m-connect'),
                'description' => __('Update specific plugins (by file path) or all plugins with available updates.', 'e2m-connect'),
                'category'    => self::CAT_MAINTENANCE,
                'destructive' => true,
                'input_schema' => [
                    'type'     => 'object',
                    'properties' => [
                        'plugins' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'execute' => static function ($input) {
                    return Maintenance::updatePlugins($input['plugins'] ?? []);
                },
            ],

            'update-themes' => [
                'label'       => __('Update themes', 'e2m-connect'),
                'description' => __('Update specific themes (by stylesheet) or all themes with available updates.', 'e2m-connect'),
                'category'    => self::CAT_MAINTENANCE,
                'destructive' => true,
                'input_schema' => [
                    'type'     => 'object',
                    'properties' => [
                        'themes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'execute' => static function ($input) {
                    return Maintenance::updateThemes($input['themes'] ?? []);
                },
            ],

            'site-info' => [
                'label'       => __('Get site info', 'e2m-connect'),
                'description' => __('Return core version, active theme, PHP version and site URL.', 'e2m-connect'),
                'category'    => self::CAT_MAINTENANCE,
                'readonly'    => true,
                'execute'     => static function () {
                    $theme = wp_get_theme();
                    return [
                        'wp_version'  => get_bloginfo('version'),
                        'php_version' => PHP_VERSION,
                        'site_url'    => home_url(),
                        'theme'       => $theme->get('Name') . ' ' . $theme->get('Version'),
                    ];
                },
            ],

            // Yoast SEO write support — Yoast itself only registers read-only
            // score abilities, so this fills the gap (set SEO title / meta
            // description / focus keyword on any post).
            'get-seo-meta' => [
                'label'       => __('Get SEO meta', 'e2m-connect'),
                'description' => __('Read the Yoast SEO title, meta description and focus keyword for a post.', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'readonly'    => true,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['post_id'],
                    'properties' => ['post_id' => ['type' => 'integer']],
                ],
                'execute' => static function ($input) {
                    $id = (int) ($input['post_id'] ?? 0);
                    if (!get_post($id)) {
                        return new \WP_Error('not_found', __('Post not found.', 'e2m-connect'));
                    }
                    return [
                        'post_id'          => $id,
                        'seo_title'        => (string) get_post_meta($id, '_yoast_wpseo_title', true),
                        'meta_description' => (string) get_post_meta($id, '_yoast_wpseo_metadesc', true),
                        'focus_keyword'    => (string) get_post_meta($id, '_yoast_wpseo_focuskw', true),
                    ];
                },
            ],

            'update-seo-meta' => [
                'label'       => __('Update SEO meta (Yoast)', 'e2m-connect'),
                'description' => __('Set the Yoast SEO title, meta description and/or focus keyword for a post.', 'e2m-connect'),
                'category'    => self::CAT_CONTENT,
                'destructive' => true,
                'input_schema' => [
                    'type'     => 'object',
                    'required' => ['post_id'],
                    'properties' => [
                        'post_id'          => ['type' => 'integer'],
                        'seo_title'        => ['type' => 'string'],
                        'meta_description' => ['type' => 'string'],
                        'focus_keyword'    => ['type' => 'string'],
                    ],
                ],
                'execute' => static function ($input) {
                    $id = (int) ($input['post_id'] ?? 0);
                    if (!get_post($id)) {
                        return new \WP_Error('not_found', __('Post not found.', 'e2m-connect'));
                    }
                    $changed = [];
                    if (array_key_exists('seo_title', $input)) {
                        update_post_meta($id, '_yoast_wpseo_title', sanitize_text_field($input['seo_title']));
                        $changed[] = 'seo_title';
                    }
                    if (array_key_exists('meta_description', $input)) {
                        update_post_meta($id, '_yoast_wpseo_metadesc', sanitize_textarea_field($input['meta_description']));
                        $changed[] = 'meta_description';
                    }
                    if (array_key_exists('focus_keyword', $input)) {
                        update_post_meta($id, '_yoast_wpseo_focuskw', sanitize_text_field($input['focus_keyword']));
                        $changed[] = 'focus_keyword';
                    }
                    return ['post_id' => $id, 'updated' => $changed, 'yoast_active' => defined('WPSEO_VERSION')];
                },
            ],
        ];
    }

    /* ============================================================
       Custom abilities (admin-defined)
       ============================================================ */

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function customRecords(): array {
        $records = get_option(self::OPTION_CUSTOM, []);
        return is_array($records) ? array_values($records) : [];
    }

    public static function saveCustom(array $record): array {
        $slug = sanitize_title($record['slug'] ?? ($record['label'] ?? ''));
        if ($slug === '') {
            return ['success' => false, 'error' => __('A name/slug is required.', 'e2m-connect')];
        }
        if (isset(self::builtinDefinitions()[$slug])) {
            return ['success' => false, 'error' => __('That slug collides with a built-in ability.', 'e2m-connect')];
        }

        $type = in_array($record['type'] ?? 'note', ['note', 'create_post', 'http_request'], true) ? $record['type'] : 'note';

        $config = is_array($record['config'] ?? null) ? $record['config'] : [];
        $method = strtoupper((string) ($config['method'] ?? 'GET'));
        $method = in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true) ? $method : 'GET';

        $clean = [
            'slug'        => $slug,
            'label'       => sanitize_text_field($record['label'] ?? $slug),
            'description' => sanitize_textarea_field($record['description'] ?? ''),
            'type'        => $type,
            'readonly'    => !empty($record['readonly']),
            'destructive' => !empty($record['destructive']),
            'config'      => [
                'post_type' => sanitize_key($config['post_type'] ?? 'post'),
                'url'       => esc_url_raw($config['url'] ?? ''),
                'method'    => $method,
                'body'      => sanitize_textarea_field($config['body'] ?? ''),
            ],
        ];

        $records = self::customRecords();
        $replaced = false;
        foreach ($records as $i => $r) {
            if (($r['slug'] ?? '') === $slug) {
                $records[$i] = $clean;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $records[] = $clean;
        }
        update_option(self::OPTION_CUSTOM, $records, false);
        return ['success' => true, 'slug' => $slug];
    }

    public static function deleteCustom(string $slug): bool {
        $slug    = sanitize_title($slug);
        $records = self::customRecords();
        $next    = array_values(array_filter($records, static fn($r) => ($r['slug'] ?? '') !== $slug));
        if (count($next) === count($records)) {
            return false;
        }
        update_option(self::OPTION_CUSTOM, $next, false);
        return true;
    }

    /**
     * Turn stored custom records into registerable definitions.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function customDefinitions(): array {
        $out = [];
        foreach (self::customRecords() as $r) {
            $slug = $r['slug'] ?? '';
            if ($slug === '') {
                continue;
            }
            $config = $r['config'] ?? [];
            $type   = $r['type'] ?? 'note';

            $out[$slug] = [
                'label'       => $r['label'] ?? $slug,
                'description' => $r['description'] ?? '',
                'category'    => self::CAT_CUSTOM,
                'readonly'    => !empty($r['readonly']),
                'destructive' => !empty($r['destructive']),
                'execute'     => static function ($input) use ($type, $config, $r) {
                    switch ($type) {
                        case 'create_post':
                            $id = wp_insert_post([
                                'post_type'    => $config['post_type'] ?: 'post',
                                'post_title'   => sanitize_text_field($input['title'] ?? ''),
                                'post_content' => (string) ($input['content'] ?? ''),
                                'post_status'  => 'draft',
                            ], true);
                            return is_wp_error($id) ? $id : ['id' => (int) $id];

                        case 'http_request':
                            $url = $config['url'] ?? '';
                            if ($url === '') {
                                return new \WP_Error('no_url', __('No URL configured for this ability.', 'e2m-connect'));
                            }
                            $args = ['method' => $config['method'] ?? 'GET', 'timeout' => 20];
                            if (!empty($config['body'])) {
                                $args['body'] = $config['body'];
                            }
                            $resp = wp_remote_request($url, $args);
                            if (is_wp_error($resp)) {
                                return $resp;
                            }
                            return [
                                'status' => wp_remote_retrieve_response_code($resp),
                                'body'   => wp_remote_retrieve_body($resp),
                            ];

                        case 'note':
                        default:
                            return ['note' => $r['description'] ?? ''];
                    }
                },
            ];
        }
        return $out;
    }

    /**
     * All abilities (built-in + custom) as plain rows for the admin list and
     * REST, even when the Abilities API isn't registered yet.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(): array {
        $rows = [];
        $builtins = self::builtinDefinitions() + \E2M\Connect\Elementor\Abilities::definitions();
        foreach ($builtins as $slug => $def) {
            $rows[] = [
                'name'        => self::NAMESPACE . '/' . $slug,
                'slug'        => $slug,
                'label'       => $def['label'],
                'description' => $def['description'],
                'category'    => $def['category'] ?? self::CAT_CONTENT,
                'readonly'    => $def['readonly'] ?? false,
                'destructive' => $def['destructive'] ?? false,
                'builtin'     => true,
            ];
        }
        foreach (self::customRecords() as $r) {
            $rows[] = [
                'name'        => self::NAMESPACE . '/' . ($r['slug'] ?? ''),
                'slug'        => $r['slug'] ?? '',
                'label'       => $r['label'] ?? '',
                'description' => $r['description'] ?? '',
                'category'    => self::CAT_CUSTOM,
                'readonly'    => !empty($r['readonly']),
                'destructive' => !empty($r['destructive']),
                'type'        => $r['type'] ?? 'note',
                'config'      => $r['config'] ?? [],
                'builtin'     => false,
            ];
        }
        return $rows;
    }
}
