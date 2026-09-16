<?php
/**
 * E2M Connect MCP - Sandbox Loader Bootstrap
 *
 * @package E2M Connect_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/sandbox/class-e2m-mcp-sandbox-helper.php';
require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/sandbox/class-e2m-mcp-sandbox-loader.php';

/**
 * Boot sandbox loader in controlled contexts.
 *
 * Prevents frontend crash loops from sandbox file mistakes.
 */
add_action('init', static function (): void {
    $is_rest = defined('REST_REQUEST') && REST_REQUEST;
    $is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
    $is_cli  = defined('WP_CLI') && WP_CLI;

    if (!is_admin() && !$is_rest && !$is_ajax && !$is_cli) {
        return;
    }

    E2M_Engine_Sandbox_Loader::boot();
}, 20);
