<?php
/**
 * Plugin/theme update helpers behind the maintenance abilities.
 *
 * Runs the WordPress upgrader in a quiet (no-output) skin so it can be driven
 * programmatically from an ability invocation.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Abilities;

class Maintenance {

    private static function bootstrap(): void {
        if (!class_exists('\Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    /**
     * @param array<int,string> $plugins plugin file paths; empty = all with updates
     * @return array<string,mixed>|\WP_Error
     */
    public static function updatePlugins(array $plugins) {
        self::bootstrap();
        wp_update_plugins();

        if (empty($plugins)) {
            $updates = get_site_transient('update_plugins');
            $plugins = isset($updates->response) ? array_keys($updates->response) : [];
        }
        if (empty($plugins)) {
            return ['updated' => [], 'message' => __('No plugin updates available.', 'e2m-connect')];
        }

        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result   = $upgrader->bulk_upgrade($plugins);

        return [
            'requested' => $plugins,
            'result'    => array_map(static fn($r) => is_wp_error($r) ? $r->get_error_message() : (bool) $r, (array) $result),
            'errors'    => $skin->get_errors()->get_error_messages(),
        ];
    }

    /**
     * @param array<int,string> $themes stylesheet slugs; empty = all with updates
     * @return array<string,mixed>|\WP_Error
     */
    public static function updateThemes(array $themes) {
        self::bootstrap();
        wp_update_themes();

        if (empty($themes)) {
            $updates = get_site_transient('update_themes');
            $themes  = isset($updates->response) ? array_keys($updates->response) : [];
        }
        if (empty($themes)) {
            return ['updated' => [], 'message' => __('No theme updates available.', 'e2m-connect')];
        }

        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader($skin);
        $result   = $upgrader->bulk_upgrade($themes);

        return [
            'requested' => $themes,
            'result'    => array_map(static fn($r) => is_wp_error($r) ? $r->get_error_message() : (bool) $r, (array) $result),
            'errors'    => $skin->get_errors()->get_error_messages(),
        ];
    }
}
