<?php
/**
 * E2M admin theme layer.
 *
 * The hub UI comes from the vendored engine's design system
 * (engine/e2m-ui/admin-design-system.css), which ships a purple light theme.
 * This loads an override stylesheet AFTER it to repaint the hub orange-on-dark
 * so it reads as E2M, not the upstream look. Token-driven, so it cascades to
 * every component. Re-vendor-safe: lives in e2m-connect, never edits the engine.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Engine;

final class Theme {

    /** Admin page hooks where the engine hub renders. */
    const HUB_HOOKS = ['toplevel_page_e2mconnect', 'toplevel_page_e2m-mcp'];

    /** Handles the engine registers for its CSS (we must load AFTER both). */
    const ENGINE_HANDLES = ['e2m-mcp-design-system', 'e2m-memory-admin'];

    public static function init(): void {
        if (!is_admin()) {
            return;
        }
        // The engine enqueues its design system at priority 999, so we hook
        // LATER (1000) to guarantee its handles are registered — then declare
        // them as dependencies so our override always prints last and wins.
        add_action('admin_enqueue_scripts', [self::class, 'enqueue'], 1000);
    }

    public static function enqueue(string $hook): void {
        if (!in_array($hook, self::HUB_HOOKS, true)) {
            return;
        }
        $deps = array_values(array_filter(
            self::ENGINE_HANDLES,
            static fn(string $h): bool => wp_style_is($h, 'registered')
        ));
        wp_enqueue_style(
            'e2m-admin-theme',
            E2M_CONNECT_URL . 'assets/admin/css/e2m-admin-theme.css',
            $deps,
            E2M_CONNECT_VERSION
        );
    }
}
