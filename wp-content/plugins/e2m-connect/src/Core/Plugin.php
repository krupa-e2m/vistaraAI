<?php
/**
 * Main plugin orchestrator (MCP-only build).
 *
 * This plugin's only job is to expose abilities over MCP. All AI reasoning runs
 * in the developer's local Claude Code, which connects via the MCP bridge. The
 * old server-side chat, onboarding wizard, health check, Node sidecar, and
 * .claude/ config generation have been removed.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Core;

use E2M\Connect\Abilities\Manager as Abilities;

final class Plugin {

    private static ?Plugin $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register e2m-connect's own abilities (content, maintenance, SEO, etc.)
        // on the core Abilities API hooks (front + REST + admin).
        Abilities::init();
        // Expose those abilities as MCP tools via the bundled MCP Adapter.
        \E2M\Connect\Mcp\Server::init();
        // Keep the vendored engine's admin hub labelled "E2M Connect" (cosmetic).
        \E2M\Connect\Engine\Branding::init();
        // Repaint the hub orange-on-dark (E2M theme) over the engine's design system.
        \E2M\Connect\Engine\Theme::init();
        // E2M dashboard landing for the hub (admin only).
        \E2M\Connect\Admin\Dashboard::init();
        // Backups & Rollback hub tab (admin only).
        \E2M\Connect\Admin\Backups::init();
        // NOTE: the "E2M Plugin" client panel (\E2M\Connect\Admin\PluginGuide) is
        // rendered directly by the engine's MCP Connect tab — no init() needed.
        // Admin screen to add/remove custom AI abilities (admin only).
        \E2M\Connect\Admin\AbilitiesAdmin::init();
    }
}
