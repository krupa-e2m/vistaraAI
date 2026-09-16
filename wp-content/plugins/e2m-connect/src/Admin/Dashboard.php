<?php
/**
 * E2M Connect dashboard — the hub's landing screen.
 *
 * Instead of dropping the user straight into the engine's "Connect" settings
 * tab (the SproutOS-style entry), we add a `dashboard` tab, make it the default
 * landing, and render an E2M home screen: welcome + MCP status + action tiles.
 *
 * Uses the engine's own extension hooks (e2m_connect_hub_*), so it needs ZERO
 * edits to the vendored engine and survives a re-vendor.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Admin;

final class Dashboard {

    const TAB = 'dashboard';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }
        add_filter('e2m_connect_hub_default_tab', [self::class, 'defaultTab']);
        add_filter('e2m_connect_hub_valid_tabs', [self::class, 'validTabs']);
        add_filter('e2m_connect_hub_nav', [self::class, 'nav']);
        add_filter('e2m_connect_hub_page_meta', [self::class, 'pageMeta']);
        add_action('e2m_connect_hub_render', [self::class, 'render'], 10, 2);
    }

    public static function defaultTab($default) {
        return self::TAB;
    }

    public static function validTabs($tabs) {
        $tabs = is_array($tabs) ? $tabs : [];
        if (!in_array(self::TAB, $tabs, true)) {
            $tabs[] = self::TAB;
        }
        return $tabs;
    }

    public static function nav($items) {
        $items = is_array($items) ? $items : [];
        $home = [self::TAB => [
            'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
            'label' => __('Dashboard', 'e2m-connect'),
        ]];
        return $home + $items; // dashboard first
    }

    public static function pageMeta($meta) {
        $meta = is_array($meta) ? $meta : [];
        $meta[self::TAB] = [
            'title' => __('Dashboard', 'e2m-connect'),
            'desc'  => __('Your E2M Connect control center — connect a client, manage abilities, and review activity.', 'e2m-connect'),
        ];
        return $meta;
    }

    /** @param string $tab */
    public static function render($tab, $settings = []): void {
        if ($tab !== self::TAB) {
            return;
        }
        $hub      = static fn(string $t): string => esc_url(add_query_arg(['page' => 'e2mconnect', 'tab' => $t], admin_url('admin.php')));
        $endpoint = esc_url(rest_url('mcp/mcp-adapter-default-server'));
        $version  = defined('E2M_CONNECT_VERSION') ? E2M_CONNECT_VERSION : '';

        $tiles = [
            ['url' => $hub('connect'),      'icon' => '🔌', 'title' => __('MCP Connect', 'e2m-connect'),     'desc' => __('App passwords & how AI clients connect.', 'e2m-connect')],
            ['url' => $hub('ai-abilities'), 'icon' => '🧩', 'title' => __('AI Abilities', 'e2m-connect'),    'desc' => __('Choose which tools are exposed over MCP.', 'e2m-connect')],
            ['url' => esc_url(add_query_arg(['page' => 'e2m-connect-abilities'], admin_url('admin.php'))), 'icon' => '➕', 'title' => __('Custom Abilities', 'e2m-connect'), 'desc' => __('Add your own AI abilities from admin.', 'e2m-connect')],
            ['url' => $hub('memory'),        'icon' => '🧠', 'title' => __('AI Memory', 'e2m-connect'),       'desc' => __('Rules and context the AI always follows.', 'e2m-connect')],
            ['url' => $hub('sandbox'),       'icon' => '📦', 'title' => __('Sandbox', 'e2m-connect'),         'desc' => __('Custom ability files & runtime.', 'e2m-connect')],
            ['url' => $hub('activity'),      'icon' => '📊', 'title' => __('Analytics', 'e2m-connect'),       'desc' => __('Usage, cost and detailed logs.', 'e2m-connect')],
        ];
        ?>
        <div class="e2m-dash">
            <div class="e2m-dash-hero">
                <div>
                    <div class="e2m-dash-welcome"><?php esc_html_e('Welcome to E2M Connect 👋', 'e2m-connect'); ?></div>
                    <p class="e2m-dash-sub"><?php esc_html_e('One plugin that exposes this site to AI over MCP. Pick a destination below, or connect from Claude Code with', 'e2m-connect'); ?> <code>/e2m-connect</code>.</p>
                </div>
                <div class="e2m-dash-statuscard">
                    <span class="e2m-dash-pill"><span class="dot"></span><?php esc_html_e('MCP ready', 'e2m-connect'); ?></span>
                    <div class="e2m-dash-endpoint">
                        <span><?php esc_html_e('Bridge endpoint', 'e2m-connect'); ?></span>
                        <code><?php echo esc_html($endpoint); ?></code>
                    </div>
                </div>
            </div>

            <div class="e2m-dash-grid">
                <?php foreach ($tiles as $t): ?>
                    <a class="e2m-dash-tile" href="<?php echo $t['url']; // already escaped ?>">
                        <span class="ico" aria-hidden="true"><?php echo esc_html($t['icon']); ?></span>
                        <h3><?php echo esc_html($t['title']); ?></h3>
                        <p><?php echo esc_html($t['desc']); ?></p>
                        <span class="go" aria-hidden="true">→</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($version): ?>
                <p class="e2m-dash-foot"><?php printf(esc_html__('E2M Connect v%s · client side connects with the E2M Connect Claude Code plugin.', 'e2m-connect'), esc_html($version)); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
