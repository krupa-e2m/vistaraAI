<?php
/**
 * Direct Elementor data manipulation — no Elementor MCP server required.
 *
 * Elementor stores a page's whole layout as a JSON string in the
 * `_elementor_data` post-meta row. Everything here reads/writes that meta
 * and regenerates the cached CSS, mirroring how Elementor's own
 * Document::save() and the elementor-mcp plugin work under the hood.
 *
 * Critical gotchas (confirmed against Elementor 4.x core):
 *   - WRITE must wrap the JSON in wp_slash(): WordPress unslashes meta on
 *     save, which would otherwise corrupt the escaped JSON.
 *   - After writing, the cached CSS (`_elementor_css`) must be invalidated
 *     and regenerated, or the page renders with stale styles.
 *   - Element IDs are 7-char hex and must be unique within a page.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Elementor;

class Helper {

    const DATA_META      = '_elementor_data';
    const EDIT_MODE_META = '_elementor_edit_mode';
    const VERSION_META   = '_elementor_version';
    const CSS_META       = '_elementor_css';
    const SETTINGS_META  = '_elementor_page_settings';

    /* ------------------------------- read ------------------------------- */

    /** @return array<int,mixed> the decoded element tree (top-level array). */
    public static function readData(int $postId): array {
        $raw = get_post_meta($postId, self::DATA_META, true);
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function isElementorPage(int $postId): bool {
        return get_post_meta($postId, self::EDIT_MODE_META, true) === 'builder'
            || get_post_meta($postId, self::DATA_META, true) !== '';
    }

    /* ------------------------------ write ------------------------------- */

    /**
     * Persist a full element tree to a post and regenerate CSS.
     *
     * @param array<int,mixed> $data top-level element array
     * @return array{success:bool, post_id:int, node_count:int, issues:array, error?:string}
     */
    public static function writeData(int $postId, array $data, bool $regenerate = true): array {
        $post = get_post($postId);
        if (!$post) {
            return ['success' => false, 'post_id' => $postId, 'node_count' => 0, 'issues' => [], 'error' => __('Post not found.', 'e2m-connect')];
        }

        $report = self::validateData($data);
        if (!$report['valid']) {
            return ['success' => false, 'post_id' => $postId, 'node_count' => $report['node_count'], 'issues' => $report['issues'], 'error' => __('Element tree failed validation.', 'e2m-connect')];
        }

        // The proven, runtime-independent write path (matches elementor-mcp's
        // CLI fallback and devpilot-ai). wp_slash is mandatory.
        update_post_meta($postId, self::DATA_META, wp_slash(wp_json_encode($data)));
        update_post_meta($postId, self::EDIT_MODE_META, 'builder');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($postId, self::VERSION_META, ELEMENTOR_VERSION);
        }

        if ($regenerate) {
            self::regenerateCss($postId);
        }

        return ['success' => true, 'post_id' => $postId, 'node_count' => $report['node_count'], 'issues' => []];
    }

    /**
     * Invalidate + rebuild a post's cached CSS (or flush all when $postId null).
     */
    public static function regenerateCss(?int $postId = null): void {
        if ($postId !== null) {
            delete_post_meta($postId, self::CSS_META);
        }
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }
        $plugin = \Elementor\Plugin::$instance;

        // Per-post regeneration when possible; otherwise clear the global cache.
        if ($postId !== null && class_exists('\Elementor\Core\Files\CSS\Post')) {
            try {
                $css = new \Elementor\Core\Files\CSS\Post($postId);
                $css->update();
                return;
            } catch (\Throwable $e) {
                // fall through to global flush
            }
        }
        if (isset($plugin->files_manager)) {
            $plugin->files_manager->clear_cache();
        }
    }

    /* ---------------------------- validation ---------------------------- */

    /**
     * Validate an element tree (or raw JSON string).
     *
     * @return array{valid:bool, issues:array<int,array<string,mixed>>, node_count:int}
     */
    public static function validateData(mixed $raw): array {
        $issues  = [];
        $seenIds = [];
        $count   = 0;
        $decoded = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['valid' => false, 'issues' => [['code' => 'bad_json', 'message' => 'Data is not valid JSON.']], 'node_count' => 0];
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        self::walkValidate($decoded, $issues, $seenIds, $count);

        return ['valid' => $issues === [], 'issues' => $issues, 'node_count' => $count];
    }

    private static function walkValidate(array $nodes, array &$issues, array &$seenIds, int &$count): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $count++;
            $id = $node['id'] ?? null;
            if (!$id) {
                $issues[] = ['code' => 'missing_id', 'message' => 'Element is missing an id.'];
            } elseif (isset($seenIds[$id])) {
                $issues[] = ['code' => 'duplicate_id', 'message' => "Duplicate element id: {$id}"];
            } else {
                $seenIds[$id] = true;
            }
            if (empty($node['elType'])) {
                $issues[] = ['code' => 'missing_el_type', 'message' => "Element {$id} is missing elType."];
            } elseif ($node['elType'] === 'widget' && empty($node['widgetType'])) {
                $issues[] = ['code' => 'missing_widget_type', 'message' => "Widget {$id} is missing widgetType."];
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                self::walkValidate($node['elements'], $issues, $seenIds, $count);
            }
        }
    }

    /* ------------------------------- IDs -------------------------------- */

    public static function generateId(): string {
        return substr(md5(uniqid('e2m_', true) . wp_rand(0, PHP_INT_MAX)), 0, 7);
    }

    /**
     * Deep-clone an element subtree assigning fresh IDs throughout.
     *
     * @param array<string,mixed> $element
     * @return array<string,mixed>
     */
    public static function reassignIds(array $element): array {
        $element['id'] = self::generateId();
        if (!empty($element['elements']) && is_array($element['elements'])) {
            $element['elements'] = array_map([self::class, 'reassignIds'], $element['elements']);
        }
        return $element;
    }

    /* --------------------------- tree queries --------------------------- */

    /**
     * A lightweight structural outline of the tree (id, type, widgetType,
     * child count) — cheap for an agent to reason about without the full data.
     *
     * @param array<int,mixed> $nodes
     * @return array<int,array<string,mixed>>
     */
    public static function structure(array $nodes): array {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $out[] = [
                'id'         => $node['id'] ?? null,
                'elType'     => $node['elType'] ?? null,
                'widgetType' => $node['widgetType'] ?? null,
                'children'   => isset($node['elements']) && is_array($node['elements'])
                    ? self::structure($node['elements'])
                    : [],
            ];
        }
        return $out;
    }

    /**
     * Find a node by id. Returns the node array (a copy) or null.
     *
     * @param array<int,mixed> $nodes
     * @return array<string,mixed>|null
     */
    public static function findElement(array $nodes, string $id): ?array {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $id) {
                return $node;
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                $found = self::findElement($node['elements'], $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /* -------------------------- tree mutations -------------------------- */

    /**
     * Merge settings into the element with $id (in place). Returns true if found.
     *
     * @param array<int,mixed> $nodes
     * @param array<string,mixed> $settings
     */
    public static function updateElementSettings(array &$nodes, string $id, array $settings): bool {
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $id) {
                $node['settings'] = array_merge($node['settings'] ?? [], $settings);
                return true;
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                if (self::updateElementSettings($node['elements'], $id, $settings)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Remove the element with $id (in place). Returns true if found.
     *
     * @param array<int,mixed> $nodes
     */
    public static function removeElement(array &$nodes, string $id): bool {
        foreach ($nodes as $i => &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $id) {
                array_splice($nodes, $i, 1);
                return true;
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                if (self::removeElement($node['elements'], $id)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Insert $element under $parentId (or top-level when null) at $position
     * (-1 = append). Ensures the element + descendants have IDs. Returns true
     * on success.
     *
     * @param array<int,mixed> $nodes
     * @param array<string,mixed> $element
     */
    public static function addElement(array &$nodes, ?string $parentId, array $element, int $position = -1): bool {
        if (empty($element['id'])) {
            $element = self::reassignIds($element);
        }

        if ($parentId === null || $parentId === '') {
            self::insertAt($nodes, $element, $position);
            return true;
        }

        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $parentId) {
                if (!isset($node['elements']) || !is_array($node['elements'])) {
                    $node['elements'] = [];
                }
                self::insertAt($node['elements'], $element, $position);
                return true;
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                if (self::addElement($node['elements'], $parentId, $element, $position)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<int,mixed> $arr
     * @param array<string,mixed> $element
     */
    private static function insertAt(array &$arr, array $element, int $position): void {
        if ($position < 0 || $position >= count($arr)) {
            $arr[] = $element;
        } else {
            array_splice($arr, $position, 0, [$element]);
        }
    }

    /* ----------------------------- widgets ------------------------------ */

    /** @return array<int,array<string,mixed>> */
    public static function listWidgets(): array {
        if (!class_exists('\Elementor\Plugin')) {
            return [];
        }
        $manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
        if (!$manager) {
            return [];
        }
        $out = [];
        foreach ($manager->get_widget_types() as $name => $widget) {
            $out[] = [
                'name'       => $name,
                'title'      => method_exists($widget, 'get_title') ? $widget->get_title() : $name,
                'categories' => method_exists($widget, 'get_categories') ? $widget->get_categories() : [],
            ];
        }
        return $out;
    }

    /**
     * Introspect a widget's controls (the "schema") at runtime.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public static function widgetSchema(string $widgetName): ?array {
        if (!class_exists('\Elementor\Plugin')) {
            return null;
        }
        $manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
        if (!$manager) {
            return null;
        }
        $widget = $manager->get_widget_types($widgetName);
        if (!$widget) {
            return null;
        }
        try {
            $raw = (array) $widget->get_controls();
        } catch (\Throwable $e) {
            return null;
        }
        $controls = [];
        foreach ($raw as $key => $control) {
            if (!is_array($control)) {
                continue;
            }
            $controls[] = [
                'name'    => (string) ($control['name'] ?? $key),
                'type'    => (string) ($control['type'] ?? ''),
                'label'   => (string) ($control['label'] ?? ''),
                'default' => $control['default'] ?? null,
                'options' => isset($control['options']) && is_array($control['options'])
                    ? array_map('strval', array_keys($control['options']))
                    : [],
            ];
        }
        return $controls;
    }

    /* ------------------------------- kit -------------------------------- */

    public static function activeKitId(): int {
        $id = (int) get_option('elementor_active_kit');
        if ($id > 0 && get_post($id)) {
            return $id;
        }
        $fallback = get_posts([
            'post_type'      => 'elementor_library',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_key'       => '_elementor_template_type',
            'meta_value'     => 'kit',
            'fields'         => 'ids',
        ]);
        return isset($fallback[0]) ? (int) $fallback[0] : 0;
    }

    /** @return array<string,mixed> */
    public static function kitGlobals(): array {
        $kitId = self::activeKitId();
        $raw   = $kitId > 0 ? get_post_meta($kitId, self::SETTINGS_META, true) : [];
        $settings = is_array($raw) ? $raw : [];
        return [
            'kit_id'            => $kitId,
            'system_colors'     => (array) ($settings['system_colors'] ?? []),
            'custom_colors'     => (array) ($settings['custom_colors'] ?? []),
            'system_typography' => (array) ($settings['system_typography'] ?? []),
            'custom_typography' => (array) ($settings['custom_typography'] ?? []),
        ];
    }

    /**
     * Merge new global colors/typography arrays into the active kit and
     * regenerate global CSS. Each arg replaces the matching key when provided.
     *
     * @param array<string,mixed> $changes keys: system_colors, custom_colors, system_typography, custom_typography
     * @return array{success:bool, kit_id:int, error?:string}
     */
    public static function updateKitGlobals(array $changes): array {
        $kitId = self::activeKitId();
        if ($kitId <= 0) {
            return ['success' => false, 'kit_id' => 0, 'error' => __('No active Elementor kit found.', 'e2m-connect')];
        }
        $raw      = get_post_meta($kitId, self::SETTINGS_META, true);
        $settings = is_array($raw) ? $raw : [];
        foreach (['system_colors', 'custom_colors', 'system_typography', 'custom_typography'] as $key) {
            if (isset($changes[$key]) && is_array($changes[$key])) {
                $settings[$key] = $changes[$key];
            }
        }
        update_post_meta($kitId, self::SETTINGS_META, wp_slash(wp_json_encode($settings)));
        self::regenerateCss($kitId);
        return ['success' => true, 'kit_id' => $kitId];
    }
}
