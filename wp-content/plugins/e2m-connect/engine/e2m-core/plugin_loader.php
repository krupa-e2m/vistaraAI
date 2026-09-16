<?php
/**
 * Core plugin bootstrap class.
 *
 * SECURITY: All admin UI, settings, and management features require
 * the 'manage_options' capability (Administrator role only).
 * No plugin admin features are accessible to other user roles.
 *
 * PERFORMANCE: Files are loaded conditionally based on context:
 * - Frontend: Only dependencies, filesystem helpers, and analytics hooks (if enabled)
 * - Admin: Full admin pages, analytics table check, cron scheduling
 * - REST API: Dependencies, filesystem helpers, analytics logging
 *
 * @link    https://posimyth.com/
 * @since   1.0.0
 * @package E2M_Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin loader - singleton.
 *
 * @since 1.0.0
 */
final class e2m_engine_Load
{
    /** @var self|null */
    private static $instance;

    /** @var bool Whether admin-pages.php has been loaded. */
    private bool $admin_files_loaded = false;

    /**
     * Get Singleton Instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register bootstrap hooks and load required files.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        // Load core files needed on ALL requests (lightweight).
        $this->load_core_files();

        // MCP adapter bootstrap - needed on REST requests.
        add_action('plugins_loaded', 'e2m_engine_bootstrap_mcp_adapter');

        // MCP server config filter - only fires during MCP REST requests.
        add_filter('mcp_adapter_default_server_config', [$this, 'e2m_extend_mcp_server_config']);

        // Fix empty schema properties - only fires during REST responses.
        add_filter('rest_pre_echo_response', [$this, 'e2m_fix_empty_schema_properties']);

        // Admin-only hooks - nothing here runs on frontend.
        if (is_admin()) {
            // PERFORMANCE: Defer loading the 2,950-line admin-pages.php until
            // we actually need it - either on our own plugin page, or for AJAX
            // handlers that reference functions defined there.
            add_action('admin_menu', function (): void {
                $this->load_admin_files();
                e2m_engine_register_admin_menu();
            });

            add_action('admin_notices', 'e2m_engine_render_dependency_notices');

            add_action('admin_init', function (): void {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
                // Load the admin file for any E2M admin page. The main
                // page is `e2mconnect`; the Memory React app lives at
                // `e2m-memory` and shares the same submenu registrar.
                if ( $page !== 'e2mconnect' && $page !== 'e2m-memory' ) {
                    return;
                }
                // Load admin-pages.php on-demand - admin_init fires before admin_menu.
                $this->load_admin_files();
                if ($tab === 'connect') {
                    e2m_engine_handle_revoke_password();
                }
                if ($tab === 'sandbox') {
                    e2m_engine_handle_sandbox_actions();
                }
            });

            // AJAX handlers - load admin-pages.php on-demand for the callbacks.
            $ajax_loader = function (): void {
                $this->load_admin_files();
            };

            add_action('wp_ajax_e2m_engine_view_sandbox_source', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_view_sandbox_source();
            });
            add_action('wp_ajax_e2m_engine_toggle_safe_mode', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_toggle_safe_mode();
            });
            add_action('wp_ajax_e2m_engine_test_webhook', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_test_webhook();
            });
            add_action('wp_ajax_e2m_engine_get_session_entries', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_get_session_entries();
            });
            add_action('wp_ajax_e2m_engine_toggle_ability', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_toggle_ability();
            });
            add_action('wp_ajax_e2m_engine_bulk_toggle_abilities', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_bulk_toggle_abilities();
            });
            add_action('wp_ajax_e2m_engine_save_setting', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_save_setting();
            });

            // Safety tab AJAX handler (single endpoint - keys whitelisted).
            add_action('wp_ajax_e2m_engine_save_safety_setting', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_save_safety_setting();
            });

            // Backups tab: roll back a single file/Elementor/DB backup.
            add_action('wp_ajax_e2m_engine_rollback_backup', function () use ($ajax_loader): void {
                $ajax_loader();
                e2m_engine_ajax_rollback_backup();
            });
        }

        // Admin bar indicator is now registered by E2M_Admin_Bar::register()
        // from load_core_files(); the legacy helper methods below remain for
        // back-compat but are no longer hooked.

        // Analytics - hooks REST filters (lightweight) + admin AJAX + cron.
        $this->init_analytics();
    }

    /**
     * Load files needed on ALL requests (frontend, admin, REST).
     * These are lightweight and define helper functions + ability registration hooks.
     */
    private function load_core_files(): void
    {
        if (file_exists(E2M_ENGINE_PLUGIN_DIR . 'e2m-libs/autoload_packages.php')) {
            $this->load_jetpack_autoloader(E2M_ENGINE_PLUGIN_DIR . 'e2m-libs/autoload_packages.php');
        }

        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/dependencies.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/filesystem-helpers.php';

        // Safety stack - loaded before abilities register so ability files
        // can call into them at registration time. These are lightweight
        // final classes with no side effects at load.
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-safety.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-audit-log.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-rate-limiter.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-domain-lock.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-crash-recovery.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-admin-bar.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-meta-snapshot.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-backup-store.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-file-backup.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-elementor-backup.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-risk-classifier.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-db-backup.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/safety/class-e2m-safety-gatekeeper.php';

        // Safety runtime bootstrap.
        E2M_Crash_Recovery::register();
        E2M_Admin_Bar::register();
        E2M_Safety_Gatekeeper::register();

        // Memory subsystem bootstrap. Registers the e2m_memory CPT on
        // init priority 5 and self-heals the index table on admin loads.
        // Lightweight — no DB queries at load time.
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/memory/bootstrap.php';

        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/abilities_register/class-abilities-register.php';
    }

    /**
     * Safely load the generated Jetpack autoloader bootstrap.
     *
     * Another plugin may have already loaded the exact same generated autoloader
     * class, which would make requiring our local copy fatal. In that case we
     * reuse the existing class and run init() so this plugin still registers its
     * own package map.
     */
    private function load_jetpack_autoloader(string $autoload_packages_file): void
    {
        $autoloader_class = $this->get_jetpack_autoloader_class($autoload_packages_file);

        if ($autoloader_class && class_exists($autoloader_class, false) && is_callable([$autoloader_class, 'init'])) {
            $autoloader_class::init();
            return;
        }

        require_once $autoload_packages_file;
    }

    /**
     * Read the generated autoloader namespace without executing the file.
     */
    private function get_jetpack_autoloader_class(string $autoload_packages_file): ?string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $contents = file_get_contents($autoload_packages_file);
        if ($contents === false) {
            return null;
        }

        if (!preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]) . '\\Autoloader';
    }

    /**
     * Load admin UI file on-demand.
     *
     * PERFORMANCE: Only loads the ~2,950-line admin-pages.php when actually
     * needed (plugin page render or AJAX handler), not on every admin request.
     */
    private function load_admin_files(): void
    {
        if ($this->admin_files_loaded) {
            return;
        }
        $this->admin_files_loaded = true;
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/admin-pages.php';
        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/admin-safety-tab.php';
        // The 0.1.0 Memory tab renders from e2m-core/memory/admin/memory-page.php
        // (loaded eagerly from memory/bootstrap.php so the admin_init POST handler
        // sees it). The legacy e2m-core/admin-memory-tab.php from the 0.0.x line
        // is removed in 0.1.0.
    }

    /**
     * Initialize analytics - deferred when tracking is off.
     *
     * PERFORMANCE: When analytics log_level is 'off' and analytics is disabled,
     * the 1,187-line analytics class is NOT loaded on frontend/REST requests.
     * It is only loaded in admin (for the UI) or when cron fires.
     */
    private function init_analytics(): void
    {
        $settings = e2m_engine_get_settings();
        $tracking_off = ($settings['analytics_log_level'] === 'off') && !$settings['analytics_enabled'];

        // When tracking is completely off, only load for admin UI or cron.
        if ($tracking_off && !is_admin()) {
            // Register lightweight cron handlers that load the class on-demand.
            add_action('e2m_engine_analytics_cleanup', static function (): void {
                require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/class-e2m-mcp-analytics.php';
                E2M_Engine_Analytics::instance()->cleanup_old_logs();
            });
            add_action('e2m_engine_daily_digest', static function (): void {
                require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/class-e2m-mcp-analytics.php';
                E2M_Engine_Analytics::instance()->send_daily_digest();
            });
            return;
        }

        require_once E2M_ENGINE_PLUGIN_DIR . 'e2m-core/class-e2m-mcp-analytics.php';

        // DB table check only in admin (activation hook handles first-time).
        if (is_admin()) {
            E2M_Engine_Analytics::maybe_create_table();
        }

        E2M_Engine_Analytics::instance();
    }

    /**
     * Add public E2M abilities to the MCP server tool list.
     *
     * @param mixed $config The default MCP adapter server config.
     * @return mixed
     */
    public function e2m_extend_mcp_server_config($config)
    {
        if (!is_array($config) || !e2m_engine_is_enabled() || !function_exists('wp_get_abilities')) {
            return $config;
        }

        $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : [];
        $bridge_map = [
            'mcp-adapter/discover-abilities' => 'e2m-bridge/discover-tools',
            'mcp-adapter/get-ability-info'   => 'e2m-bridge/inspect-tool',
            'mcp-adapter/execute-ability'    => 'e2m-bridge/dispatch-tool',
        ];

        foreach ($tools as $index => $tool_name) {
            if (!is_string($tool_name) || !isset($bridge_map[$tool_name])) {
                continue;
            }
            $tools[$index] = $bridge_map[$tool_name];
        }

        // Claude must call e2m-bridge/discover-tools first to see all abilities,
        // which guarantees it reads site_instructions before taking any action.
        // Actions are dispatched via e2m-bridge/dispatch-tool.
        foreach ( [ 'e2m-bridge/discover-tools', 'e2m-bridge/dispatch-tool', 'e2m-bridge/inspect-tool' ] as $bt ) {
            if ( ! in_array( $bt, $tools, true ) ) {
                $tools[] = $bt;
            }
        }

        $config['tools'] = array_values(array_unique($tools));

        $instructions = e2m_engine_build_server_instructions();
        if ($instructions !== '') {
            $config['server_description'] = $instructions;
        }

        return $config;
    }

    /**
     * Fix empty schema properties arrays so MCP clients receive valid JSON objects.
     *
     * @param mixed $result REST response payload.
     * @return mixed
     */
    public function e2m_fix_empty_schema_properties($result)
    {
        if (!is_array($result)) {
            return $result;
        }

        $result_obj = $result['result'] ?? null;
        if (!($result_obj instanceof \stdClass)) {
            return $result;
        }

        $tools = $result_obj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }

        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }

                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }

        $result_obj->tools = $tools;

        return $result;
    }

    /**
     * Show E2M MCP status in the admin bar (administrators only).
     *
     * @param WP_Admin_Bar $admin_bar
     */
    public function e2m_admin_bar_indicator($admin_bar)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $is_on = e2m_engine_is_enabled();
        $label = $is_on ? __('E2M MCP: ON', 'e2mconnect') : __('E2M MCP: OFF', 'e2mconnect');

        $admin_bar->add_node([
            'id'    => 'e2m-mcp-status',
            'title' => '<span class="e2m-mcp-bar-dot ' . ($is_on ? 'on' : 'off') . '"></span>' . esc_html($label),
            'href'  => admin_url('admin.php?page=e2mconnect'),
        ]);
    }

    /**
     * Inline CSS for admin bar indicator (administrators only, ~200 bytes).
     */
    public function e2m_admin_bar_css()
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }
        ?>
        <style>
            /* Canonical values: --so-bar-online / --so-bar-offline in e2m-ui/admin-design-system.css */
            #wp-admin-bar-e2m-mcp-status .e2m-mcp-bar-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 6px;
                vertical-align: middle;
            }
            #wp-admin-bar-e2m-mcp-status .e2m-mcp-bar-dot.on { background: #00D084; }
            #wp-admin-bar-e2m-mcp-status .e2m-mcp-bar-dot.off { background: #CC1818; }
            #adminmenu .toplevel_page_e2m-mcp .toplevel_page_e2m-mcp .wp-menu-image.svg {
                    background-size: 30px auto !important;
            }
        </style>
        <?php
    }
}

e2m_engine_Load::instance();

/**
 * Output per-page custom JS saved by the e2m/elementor-add-custom-js ability.
 *
 * Uses wp_add_inline_script() instead of raw <script> tags, as required
 * by WordPress.org plugin guidelines.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! is_singular() ) {
		return;
	}

	$post_id  = get_queried_object_id();
	$snippets = get_post_meta( $post_id, '_e2m_custom_js', true );

	if ( ! is_array( $snippets ) || $snippets === [] ) {
		return;
	}

	// Register a lightweight inline-only handle so wp_add_inline_script has something to attach to.
	wp_register_script( 'e2m-custom-js-' . $post_id, false, [], false, [ 'in_footer' => true ] );
	wp_enqueue_script( 'e2m-custom-js-' . $post_id );

	foreach ( $snippets as $snippet ) {
		if ( ! empty( $snippet['code'] ) ) {
			wp_add_inline_script( 'e2m-custom-js-' . $post_id, $snippet['code'] );
		}
	}
}, 10 );
