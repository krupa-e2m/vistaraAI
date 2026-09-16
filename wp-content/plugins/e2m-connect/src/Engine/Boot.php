<?php
/**
 * Boots the vendored E2M Connect engine that ships inside e2m-connect/engine/.
 *
 * This is the physical copy of E2M Connect's `e2m-core` (abilities, memory,
 * sandbox, analytics, safety) so E2M Connect owns ~259 abilities and the three
 * subsystems without depending on the E2M Connect plugin being installed.
 *
 * We deliberately did NOT copy E2M Connect's bundled `e2m-libs` (the Jetpack
 * autoloader + a second MCP Adapter): the engine only needs the
 * `WP\MCP\Core\McpAdapter` class, which the standalone MCP Adapter plugin
 * already provides, and its loader guards that load with file_exists().
 *
 * Guard: if the real E2M Connect plugin is active (constants already defined),
 * we skip booting our copy so the two never double-declare.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Engine;

class Boot {

    const INSTALLED_OPTION = 'e2m_connect_engine_installed';
    // Bumped to 0.1.2 so maybeInstall() re-runs on already-installed sites and
    // schedules the new daily backup-cleanup cron (idempotent otherwise).
    const ENGINE_VERSION   = '0.1.2';

    /** True once WE have booted the vendored engine in this request. */
    private static bool $booted = false;

    /** Plugin file of the standalone MCP Adapter plugin (if installed). */
    const STANDALONE_MCP_ADAPTER = 'mcp-adapter/mcp-adapter.php';

    public static function dir(): string {
        return E2M_CONNECT_PATH . 'engine/';
    }

    /**
     * Load the MCP Adapter from the copy bundled in engine/e2m-libs/, so
     * e2m-connect no longer depends on the standalone "MCP Adapter" plugin
     * (the engine + Mcp\Server only need the `WP\MCP\Core\McpAdapter` class).
     *
     * Order of precedence, to avoid a fatal class-redeclaration:
     *   1. Already loaded (class exists)         → nothing to do.
     *   2. Standalone MCP Adapter plugin active   → defer to it, don't load ours.
     *   3. Otherwise                              → load the bundled copy.
     *
     * Runs at plugin include time (before plugins_loaded) so the classes are
     * available when e2m_engine_bootstrap_mcp_adapter() runs on plugins_loaded
     * and when Mcp\Server hooks `mcp_adapter_init`.
     */
    public static function loadMcpAdapter(): void {
        if (class_exists('WP\\MCP\\Core\\McpAdapter')) {
            return;
        }

        if (self::standaloneMcpAdapterActive()) {
            return; // The standalone plugin will provide the classes itself.
        }

        $adapterDir = self::dir() . 'e2m-libs/wordpress/mcp-adapter/';
        $entry      = $adapterDir . 'mcp-adapter.php';
        if (!is_readable($entry)) {
            return;
        }

        // The bundled MCP Adapter ships without a Composer `vendor/` directory:
        // it has zero third-party runtime dependencies — every class is a
        // first-party `WP\MCP\*` PSR-4 class under includes/. So rather than
        // forcing installers to run `composer install` (there isn't even a
        // composer.json), we register a tiny PSR-4 autoloader for the WP\MCP\
        // namespace ourselves and tell the adapter to skip its own Composer
        // autoloader via WP_MCP_AUTOLOAD=false (a bypass it explicitly supports).
        // This makes installs from the GitHub source/zip work with no build step
        // and silences the "Composer autoloader was not found" admin notice.
        if (!defined('WP_MCP_AUTOLOAD')) {
            define('WP_MCP_AUTOLOAD', false);
        }

        $includes = $adapterDir . 'includes/';
        spl_autoload_register(static function (string $class) use ($includes): void {
            $prefix = 'WP\\MCP\\';
            $len    = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }
            $file = $includes . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }
        });

        require_once $entry;
    }

    /**
     * Whether the standalone MCP Adapter plugin is active. Checked against the
     * raw options (is_plugin_active() is not available this early), covering
     * both single-site and network activation.
     */
    private static function standaloneMcpAdapterActive(): bool {
        $active = (array) get_option('active_plugins', []);
        if (in_array(self::STANDALONE_MCP_ADAPTER, $active, true)) {
            return true;
        }
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($network[self::STANDALONE_MCP_ADAPTER])) {
                return true;
            }
        }
        return false;
    }

    /** Whether e2m-connect's vendored engine is the active owner this request. */
    public static function available(): bool {
        return self::$booted;
    }

    /**
     * Define the constants the engine expects, then require its loader.
     * Mirrors e2mconnect-0.1.1/e2m-os.php exactly (minus e2m-libs).
     * Runs at plugin include time so the engine's plugins_loaded/init hooks
     * register before those actions fire.
     */
    public static function boot(): void {
        // Skip if the standalone E2M Connect plugin already booted the engine,
        // or our copy is absent.
        if (defined('E2M_ENGINE_VERSION') || !is_dir(self::dir() . 'e2m-core')) {
            return;
        }

        $dir = self::dir();

        if (!defined('E2M_ENGINE_VERSION'))   define('E2M_ENGINE_VERSION', self::ENGINE_VERSION);
        if (!defined('E2M_ENGINE_PLUGIN_FILE')) define('E2M_ENGINE_PLUGIN_FILE', E2M_CONNECT_FILE);
        if (!defined('E2M_ENGINE_PLUGIN_DIR')) define('E2M_ENGINE_PLUGIN_DIR', $dir);
        if (!defined('E2M_ENGINE_PLUGIN_URL')) define('E2M_ENGINE_PLUGIN_URL', E2M_CONNECT_URL . 'engine/');
        if (!defined('E2M_ENGINE_URL'))        define('E2M_ENGINE_URL', E2M_CONNECT_URL . 'engine/');
        if (!defined('E2M_ENGINE_MAX_EXECUTION_TIME')) define('E2M_ENGINE_MAX_EXECUTION_TIME', 30);
        if (!defined('E2M_ENGINE_SANDBOX_DIR')) {
            define('E2M_ENGINE_SANDBOX_DIR', trailingslashit(WP_CONTENT_DIR) . 'e2m-connect-sandbox/');
        }
        // Backup home lives OUTSIDE the plugin dir so it survives plugin
        // deactivation, updates, and re-vendoring (file/Elementor/DB backups).
        if (!defined('E2M_ENGINE_BACKUP_DIR')) {
            define('E2M_ENGINE_BACKUP_DIR', trailingslashit(WP_CONTENT_DIR) . 'e2m-connect-backups/');
        }

        $loader = $dir . 'e2m-core/plugin_loader.php';
        if (is_readable($loader)) {
            require_once $loader; // self-instantiates e2m_engine_Load::instance()
            self::$booted = true;
        }

        if (!is_dir(E2M_ENGINE_SANDBOX_DIR)) {
            wp_mkdir_p(E2M_ENGINE_SANDBOX_DIR);
        }

        $sandbox = $dir . 'e2m-core/sandbox/bootstrap.php';
        if (is_readable($sandbox)) {
            require_once $sandbox;
        }

        // First-run install of the engine's DB tables + seeds (the engine's
        // own register_activation_hook never fired for our plugin).
        add_action('admin_init', [self::class, 'maybeInstall']);
        // Audit-log prune cron the engine expects.
        add_action('e2m_engine_audit_log_prune', [self::class, 'pruneAuditLog']);
        // Daily cleanup of backups older than the retention window (default 30d).
        add_action('e2m_engine_backup_cleanup', [self::class, 'pruneBackups']);
    }

    /**
     * Create the engine's tables + seeds once. Idempotent (dbDelta-based
     * create methods are safe to re-run; guarded by an option regardless).
     */
    public static function maybeInstall(): void {
        if (get_option(self::INSTALLED_OPTION) === self::ENGINE_VERSION) {
            return;
        }
        $dir = self::dir();

        self::safeRequire($dir . 'e2m-core/dependencies.php');

        if (self::safeRequire($dir . 'e2m-core/class-e2m-mcp-analytics.php') && method_exists('E2M_Engine_Analytics', 'maybe_create_table')) {
            \E2M_Engine_Analytics::maybe_create_table();
        }
        if (self::safeRequire($dir . 'e2m-core/safety/class-e2m-audit-log.php') && method_exists('E2M_Audit_Log', 'maybe_create_table')) {
            \E2M_Audit_Log::maybe_create_table();
        }
        if (self::safeRequire($dir . 'e2m-core/safety/class-e2m-domain-lock.php') && method_exists('E2M_Domain_Lock', 'capture_on_activate')) {
            \E2M_Domain_Lock::capture_on_activate();
        }
        if (self::safeRequire($dir . 'e2m-core/memory/class-e2m-memory-index-table.php') && method_exists('E2M_Memory_Index_Table', 'maybe_create_table')) {
            \E2M_Memory_Index_Table::maybe_create_table();
        }
        if (self::safeRequire($dir . 'e2m-core/memory/class-e2m-memory-seeds.php') && method_exists('E2M_Memory_Seeds', 'install_seeds')) {
            \E2M_Memory_Seeds::install_seeds();
        }

        // Schedule the crons the engine relies on.
        foreach ([
            'e2m_engine_audit_log_prune'    => HOUR_IN_SECONDS,
            'e2m_engine_backup_cleanup'  => 4 * HOUR_IN_SECONDS,
            'e2m_memory_decay_scan'      => 2 * HOUR_IN_SECONDS,
            'e2m_memory_pending_promote' => 3 * HOUR_IN_SECONDS,
        ] as $hook => $offset) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + $offset, 'daily', $hook);
            }
        }

        update_option(self::INSTALLED_OPTION, self::ENGINE_VERSION, false);
    }

    public static function pruneAuditLog(): void {
        $file = self::dir() . 'e2m-core/safety/class-e2m-audit-log.php';
        if (self::safeRequire($file) && method_exists('E2M_Audit_Log', 'prune_old')) {
            \E2M_Audit_Log::prune_old();
        }
    }

    /**
     * Daily cleanup: delete file/Elementor/DB backups older than the retention
     * window (backup_retention_days, default 30), then age-prune the per-post
     * meta-snapshot trail to the same window. Keeps the backup store bounded
     * with no manual intervention. Backups live outside the plugin dir, so
     * they persist across deactivation; only this cron removes them.
     */
    public static function pruneBackups(): void {
        $dir  = self::dir();
        $days = 30;
        if (self::safeRequire($dir . 'e2m-core/dependencies.php') && function_exists('e2m_engine_get_settings')) {
            $settings = e2m_engine_get_settings();
            $days     = (int) ($settings['backup_retention_days'] ?? 30);
        }
        if ($days <= 0) {
            return; // retention disabled -> keep forever
        }

        if (self::safeRequire($dir . 'e2m-core/safety/class-e2m-backup-store.php') && method_exists('E2M_Backup_Store', 'prune_older_than')) {
            \E2M_Backup_Store::prune_older_than($days);
        }
        if (self::safeRequire($dir . 'e2m-core/safety/class-e2m-meta-snapshot.php') && method_exists('E2M_Meta_Snapshot', 'prune_all_older_than')) {
            \E2M_Meta_Snapshot::prune_all_older_than($days);
        }
    }

    private static function safeRequire(string $file): bool {
        if (is_readable($file)) {
            require_once $file;
            return true;
        }
        return false;
    }
}
