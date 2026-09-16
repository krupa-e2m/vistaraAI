<?php
/**
 * Exposes E2M Connect's WordPress abilities (content, maintenance, Elementor,
 * custom) as MCP tools through the MCP Adapter plugin.
 *
 * The MCP Adapter calls do_action('mcp_adapter_init', $adapter); we hook that
 * and register one server whose tool list is every ability we register. The
 * resulting endpoint is:
 *
 *     /wp-json/mcp/e2m-connect-server   (streamable HTTP transport)
 *
 * Tool names the adapter advertises are the ability name with '/' → '-', e.g.
 * the ability `e2m-connect/elementor-set-page-data` becomes the MCP tool
 * `e2m-connect-elementor-set-page-data`.
 *
 * If the MCP Adapter plugin is inactive the hook never fires — this is a
 * no-op and abilities remain reachable via the WordPress Abilities REST API.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Mcp;

use E2M\Connect\Abilities\Manager;

class Server {

    const SERVER_ID    = 'e2m-connect';
    const ROUTE_NS     = 'mcp';
    const ROUTE        = 'e2m-connect-server';

    public static function init(): void {
        add_action('mcp_adapter_init', [self::class, 'register']);
    }

    /** @param mixed $adapter \WP\MCP\Core\McpAdapter */
    public static function register($adapter): void {
        if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }
        if (!class_exists('\WP\MCP\Transport\HttpTransport')) {
            return;
        }

        // Expose every ability registered on the site — e2m-connect's own
        // abilities PLUS the vendored engine's ~180 builder/content/safety/
        // memory abilities. Falls back to just ours if enumeration is empty
        // (e.g. hook-ordering edge cases).
        $tools = [];
        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = is_object($ability) && method_exists($ability, 'get_name')
                    ? $ability->get_name()
                    : (is_string($ability) ? $ability : '');
                if ($name !== '') {
                    $tools[] = $name;
                }
            }
        }
        if (empty($tools)) {
            $tools = Manager::allNames();
        }
        $tools = array_values(array_unique($tools));
        if (empty($tools)) {
            return;
        }

        try {
            $adapter->create_server(
                self::SERVER_ID,
                self::ROUTE_NS,
                self::ROUTE,
                __('E2M Connect', 'e2m-connect'),
                __('Content, Elementor and maintenance abilities exposed by E2M Connect.', 'e2m-connect'),
                'v' . E2M_CONNECT_VERSION,
                [\WP\MCP\Transport\HttpTransport::class],
                null,   // error handler (adapter default)
                null,   // observability handler
                $tools, // ability names → MCP tools
                [],     // resources
                [],     // prompts
                null    // transport permission (adapter default: capability check)
            );
        } catch (\Throwable $e) {
            // Never fatal the request because the MCP server couldn't register.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[e2m-connect] MCP server registration failed: ' . $e->getMessage());
            }
        }
    }

    /** Full REST endpoint for the server (for snippets / health). */
    public static function endpoint(): string {
        return rest_url(self::ROUTE_NS . '/' . self::ROUTE);
    }
}
