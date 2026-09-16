<?php
/**
 * Admin screen to add / remove custom AI abilities.
 *
 * Self-contained: registers its own submenu under the E2M Connect hub (or a
 * top-level fallback), renders an add form + a list with delete actions, and
 * persists through Abilities\Manager (option e2m_connect_custom_abilities). The
 * MCP server picks the new abilities up automatically on the next request.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Admin;

use E2M\Connect\Abilities\Manager;

final class AbilitiesAdmin {

    const MENU_SLUG    = 'e2m-connect-abilities';
    const PARENT_SLUG  = 'e2mconnect'; // the vendored engine hub
    const ACTION_SAVE  = 'e2m_connect_save_ability';
    const ACTION_DELETE = 'e2m_connect_delete_ability';
    const CAP          = 'manage_options';

    /** Hook suffix of this admin page (set when the menu is registered). */
    private static string $hook = '';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }
        // Priority 20 so the engine hub (priority 10) is registered first.
        add_action('admin_menu', [self::class, 'registerMenu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_action('admin_post_' . self::ACTION_SAVE, [self::class, 'handleSave']);
        add_action('admin_post_' . self::ACTION_DELETE, [self::class, 'handleDelete']);
    }

    public static function registerMenu(): void {
        global $admin_page_hooks;
        $title = __('AI Abilities', 'e2m-connect');
        $label = __('AI Abilities', 'e2m-connect');

        if (isset($admin_page_hooks[self::PARENT_SLUG])) {
            self::$hook = (string) add_submenu_page(self::PARENT_SLUG, $title, $label, self::CAP, self::MENU_SLUG, [self::class, 'render']);
        } else {
            // Fallback: standalone top-level page if the engine hub is absent.
            self::$hook = (string) add_menu_page($title, 'E2M Abilities', self::CAP, self::MENU_SLUG, [self::class, 'render'], 'dashicons-superhero', 58);
        }
    }

    /** Dark theme for this page only. */
    public static function enqueue(string $hook): void {
        if ($hook !== self::$hook) {
            return;
        }
        wp_enqueue_style(
            'e2m-abilities-admin',
            E2M_CONNECT_URL . 'assets/admin/css/e2m-abilities-admin.css',
            [],
            E2M_CONNECT_VERSION
        );
    }

    /** Tag the body so the dark CSS can scope to this page. */
    public static function bodyClass(string $classes): string {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && self::$hook !== '' && $screen->id === self::$hook) {
            $classes .= ' e2m-abilities-dark';
        }
        return $classes;
    }

    private static function pageUrl(array $args = []): string {
        return add_query_arg(array_merge(['page' => self::MENU_SLUG], $args), admin_url('admin.php'));
    }

    public static function handleSave(): void {
        if (!current_user_can(self::CAP) || !check_admin_referer(self::ACTION_SAVE)) {
            wp_die(esc_html__('Not allowed.', 'e2m-connect'));
        }
        $result = Manager::saveCustom([
            'label'       => $_POST['label'] ?? '',
            'slug'        => $_POST['slug'] ?? '',
            'description' => $_POST['description'] ?? '',
            'type'        => $_POST['type'] ?? 'note',
            'readonly'    => !empty($_POST['readonly']),
            'destructive' => !empty($_POST['destructive']),
            'config'      => [
                'post_type' => $_POST['post_type'] ?? 'post',
                'url'       => $_POST['url'] ?? '',
                'method'    => $_POST['method'] ?? 'GET',
                'body'      => $_POST['body'] ?? '',
            ],
        ]);
        $flag = !empty($result['success']) ? ['msg' => 'saved'] : ['err' => rawurlencode($result['error'] ?? 'error')];
        wp_safe_redirect(self::pageUrl($flag));
        exit;
    }

    public static function handleDelete(): void {
        if (!current_user_can(self::CAP) || !check_admin_referer(self::ACTION_DELETE)) {
            wp_die(esc_html__('Not allowed.', 'e2m-connect'));
        }
        $ok = Manager::deleteCustom((string) ($_POST['slug'] ?? ''));
        wp_safe_redirect(self::pageUrl([$ok ? 'msg' : 'err' => $ok ? 'deleted' : 'notfound']));
        exit;
    }

    public static function render(): void {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $rows    = Manager::listAll();
        $custom  = array_values(array_filter($rows, static fn($r) => empty($r['builtin'])));
        $builtin = array_values(array_filter($rows, static fn($r) => !empty($r['builtin'])));
        $action  = esc_url(admin_url('admin-post.php'));

        $logo = E2M_CONNECT_URL . 'assets/admin/img/e2m-mark.png';

        echo '<div class="wrap e2m-abilities-admin">';
        echo '<div class="e2m-abilities-brand"><img src="' . esc_url($logo) . '" alt="' . esc_attr__('E2M Connect', 'e2m-connect') . '" /><span>E2M <b>Connect</b></span></div>';
        echo '<h1>' . esc_html__('AI Abilities', 'e2m-connect') . '</h1>';
        echo '<p>' . esc_html__('Add custom abilities the AI can call over MCP. New abilities are exposed automatically — no rebuild needed.', 'e2m-connect') . '</p>';

        if (isset($_GET['msg'])) {
            $map = ['saved' => __('Ability saved.', 'e2m-connect'), 'deleted' => __('Ability deleted.', 'e2m-connect')];
            $m = $map[sanitize_key($_GET['msg'])] ?? '';
            if ($m) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($m) . '</p></div>';
        }
        if (isset($_GET['err'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(rawurldecode((string) wp_unslash($_GET['err']))) . '</p></div>';
        }

        // ---- Add form ----
        echo '<h2>' . esc_html__('Add a custom ability', 'e2m-connect') . '</h2>';
        echo '<form method="post" action="' . $action . '" style="max-width:760px">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        wp_nonce_field(self::ACTION_SAVE);
        echo '<table class="form-table" role="presentation"><tbody>';
        self::field('label', __('Name', 'e2m-connect'), 'text', '', __('Human label. The slug is derived from this.', 'e2m-connect'));
        self::field('description', __('Description', 'e2m-connect'), 'textarea', '', __('Tell the AI exactly what this ability does and when to use it. This text is what the model reads.', 'e2m-connect'));
        // type select
        echo '<tr><th scope="row"><label for="e2m-type">' . esc_html__('Type', 'e2m-connect') . '</label></th><td>';
        echo '<select name="type" id="e2m-type">';
        foreach (['note' => __('Note (returns its description)', 'e2m-connect'), 'create_post' => __('Create post', 'e2m-connect'), 'http_request' => __('HTTP request', 'e2m-connect')] as $val => $lbl) {
            echo '<option value="' . esc_attr($val) . '">' . esc_html($lbl) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('create_post → drafts a post of the given type. http_request → calls a URL. note → returns its description (useful for static guidance).', 'e2m-connect') . '</p></td></tr>';
        self::field('post_type', __('Post type (create_post)', 'e2m-connect'), 'text', 'post');
        self::field('url', __('URL (http_request)', 'e2m-connect'), 'text', '');
        echo '<tr><th scope="row"><label for="e2m-method">' . esc_html__('Method (http_request)', 'e2m-connect') . '</label></th><td><select name="method" id="e2m-method">';
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $m) echo '<option value="' . esc_attr($m) . '">' . esc_html($m) . '</option>';
        echo '</select></td></tr>';
        self::field('body', __('Body (http_request)', 'e2m-connect'), 'textarea', '');
        echo '<tr><th scope="row">' . esc_html__('Flags', 'e2m-connect') . '</th><td>';
        echo '<label><input type="checkbox" name="readonly" value="1"> ' . esc_html__('Read-only (safe, no changes)', 'e2m-connect') . '</label><br>';
        echo '<label><input type="checkbox" name="destructive" value="1"> ' . esc_html__('Destructive (warn before use)', 'e2m-connect') . '</label>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save ability', 'e2m-connect'));
        echo '</form>';

        // ---- Custom abilities list ----
        echo '<h2>' . esc_html__('Custom abilities', 'e2m-connect') . '</h2>';
        if (!$custom) {
            echo '<p>' . esc_html__('None yet.', 'e2m-connect') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Name', 'e2m-connect') . '</th><th>' . esc_html__('Tool name', 'e2m-connect') . '</th><th>' . esc_html__('Type', 'e2m-connect') . '</th><th></th></tr></thead><tbody>';
            foreach ($custom as $r) {
                echo '<tr><td><strong>' . esc_html($r['label']) . '</strong><br><span class="description">' . esc_html($r['description']) . '</span></td>';
                echo '<td><code>' . esc_html($r['name']) . '</code></td>';
                echo '<td>' . esc_html($r['type'] ?? 'note') . '</td>';
                echo '<td><form method="post" action="' . $action . '" onsubmit="return confirm(\'' . esc_js(__('Delete this ability?', 'e2m-connect')) . '\')">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_DELETE) . '">';
                echo '<input type="hidden" name="slug" value="' . esc_attr($r['slug']) . '">';
                wp_nonce_field(self::ACTION_DELETE);
                echo '<button class="button button-link-delete">' . esc_html__('Delete', 'e2m-connect') . '</button>';
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        }

        // ---- Built-in abilities (read-only reference) ----
        echo '<h2 style="margin-top:2em">' . esc_html__('Built-in abilities', 'e2m-connect') . '</h2>';
        echo '<p class="description">' . sprintf(esc_html__('%d built-in abilities are always available.', 'e2m-connect'), count($builtin)) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Name', 'e2m-connect') . '</th><th>' . esc_html__('Tool name', 'e2m-connect') . '</th></tr></thead><tbody>';
        foreach ($builtin as $r) {
            echo '<tr><td>' . esc_html($r['label']) . '</td><td><code>' . esc_html($r['name']) . '</code></td></tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }

    private static function field(string $name, string $label, string $type, string $default = '', string $help = ''): void {
        $id = 'e2m-' . $name;
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        if ($type === 'textarea') {
            echo '<textarea name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" rows="3" class="large-text">' . esc_textarea($default) . '</textarea>';
        } else {
            echo '<input type="text" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="' . esc_attr($default) . '" class="regular-text">';
        }
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }
}
