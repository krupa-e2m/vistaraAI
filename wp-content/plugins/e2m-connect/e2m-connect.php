<?php
/**
 * Plugin Name: E2M Connect
 * Plugin URI: https://e2m.solutions
 * Description: Figma-to-WordPress AI builder. Drives Claude Code to build ACF Pro themes or Elementor pages from Figma designs — without leaving WP admin.
 * Version: 0.2.6
 * Author: E2M
 * Author URI: https://e2m.solutions
 * Text Domain: e2m-connect
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace E2M\Connect;

if (!defined('ABSPATH')) {
    exit;
}

define('E2M_CONNECT_VERSION', '0.2.6');
define('E2M_CONNECT_FILE', __FILE__);
define('E2M_CONNECT_PATH', plugin_dir_path(__FILE__));
define('E2M_CONNECT_URL', plugin_dir_url(__FILE__));
define('E2M_CONNECT_BASENAME', plugin_basename(__FILE__));

final class Init {

    private static ?Init $instance = null;

    const MINIMUM_PHP_VERSION = '8.1';

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Let a client authenticate when the site sits behind a server-level
        // HTTP Basic-Auth gate (staging "password protection"). Runs first,
        // before any auth resolves. See maybePromoteFallbackAuthHeader().
        self::maybePromoteFallbackAuthHeader();

        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            add_action('admin_notices', [$this, 'adminNoticePHPVersion']);
            return;
        }

        spl_autoload_register([$this, 'autoload']);

        // Load the bundled MCP Adapter (engine/e2m-libs/) before anything that
        // needs the WP\MCP classes. Self-guards: no-op if the class already
        // exists or the standalone MCP Adapter plugin is active. This is why
        // e2m-connect no longer requires that plugin to be installed separately.
        Engine\Boot::loadMcpAdapter();

        // Boot the vendored E2M Connect engine (engine/) at include time so its
        // plugins_loaded/init hooks register before those actions fire. No-op
        // if the standalone E2M Connect plugin is active or engine/ is absent.
        Engine\Boot::boot();

        add_action('plugins_loaded', static function (): void {
            Core\Plugin::instance();
        }, 20);

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function autoload(string $class): void {
        $prefix = 'E2M\\Connect\\';
        $len = strlen($prefix);

        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative = substr($class, $len);
        $file = E2M_CONNECT_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }

    public function activate(): void {
        // MCP-only build: nothing to set up here. The engine creates its own
        // tables/abilities on admin_init (Engine\Boot::maybeInstall).
    }

    public function deactivate(): void {
        // No-op.
    }

    public function adminNoticePHPVersion(): void {
        $message = sprintf(
            esc_html__('E2M Connect requires PHP %s or higher. You are running PHP %s.', 'e2m-connect'),
            self::MINIMUM_PHP_VERSION,
            PHP_VERSION
        );
        printf('<div class="notice notice-error"><p>%s</p></div>', $message);
    }

    /**
     * Support authenticating from a fallback header when the site sits behind a
     * server-level HTTP Basic-Auth gate (e.g. staging "password protection"),
     * which occupies the standard Authorization header.
     *
     * A client sends its WordPress Application Password in
     *   X-E2M-Authorization: Basic <base64("user:app_password")>
     * while the real Authorization header carries the staging Basic-Auth
     * credentials. We copy the fallback into the slots WordPress core reads for
     * Application Password auth, so the normal auth path works unchanged.
     *
     * This does NOT weaken security: the app password is still validated by
     * core exactly as usual — only the header it is read from differs. The
     * staging gate was already checked by the web server before PHP ran, so
     * overwriting the PHP-level copy of the credentials is safe.
     *
     * Background: WordPress core Trac #51939 (Basic Auth staging protections vs
     * Application Passwords) — never fixed in core, so we handle it here.
     */
    public static function maybePromoteFallbackAuthHeader(): void {
        $header = '';
        foreach (['HTTP_X_E2M_AUTHORIZATION', 'REDIRECT_HTTP_X_E2M_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                $header = trim((string) $_SERVER[$key]);
                break;
            }
        }

        if ($header === '' || stripos($header, 'Basic ') !== 0) {
            return;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return;
        }

        [$user, $pass] = explode(':', $decoded, 2);
        if ($user === '') {
            return;
        }

        // Promote into the slots WordPress reads for Application Password auth.
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
        $_SERVER['PHP_AUTH_USER']      = $user;
        $_SERVER['PHP_AUTH_PW']        = $pass;
    }
}

Init::instance();
