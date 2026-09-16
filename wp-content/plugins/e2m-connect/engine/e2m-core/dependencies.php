<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check whether Abilities API is available.
 */
function e2m_engine_has_abilities_api(): bool
{
    return class_exists('WP_Ability');
}

/**
 * Check whether MCP Adapter classes are available.
 */
function e2m_engine_has_mcp_adapter(): bool
{
    return class_exists('WP\\MCP\\Core\\McpAdapter');
}

/**
 * Check whether Elementor is available.
 */
function e2m_engine_has_elementor(): bool
{
    return did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin');
}

/**
 * Check whether WooCommerce is active on this site.
 *
 * The WC module (and its 22 abilities) only loads when this returns true, so
 * sites without WooCommerce never see the shop abilities in discovery.
 */
function e2m_engine_has_woocommerce(): bool
{
    return class_exists('WooCommerce') || function_exists('WC');
}

/**
 * Check whether Bricks Builder is the active theme.
 *
 * Bricks lives as a theme rather than a plugin, so detection looks at the
 * stylesheet slug. Used to gate Bricks-specific abilities so they don't
 * pollute the catalogue on sites that have never seen Bricks.
 */
function e2m_engine_has_bricks(): bool
{
    if (function_exists('bricks_is_builder')) {
        return true;
    }
    $theme = wp_get_theme();
    return $theme && in_array(strtolower((string) $theme->get_stylesheet()), ['bricks', 'bricks-child'], true);
}

/**
 * Check whether Advanced Custom Fields (ACF) is available on this site.
 * Works with both ACF Free and ACF Pro.
 */
function e2m_engine_has_acf(): bool
{
    return defined('ACF_VERSION') && function_exists('acf_get_field_groups');
}

/**
 * Check whether ACF Pro is available (needed for Options Pages).
 */
function e2m_engine_has_acf_pro(): bool
{
    return defined('ACF_PRO') || (defined('ACF_VERSION') && class_exists('ACF_Pro'));
}

/**
 * Check whether JetEngine is available on this site.
 */
function e2m_engine_has_jetengine(): bool
{
    return defined('JET_ENGINE_VERSION') && function_exists('jet_engine');
}

/**
 * Check whether Pods Framework plugin is available.
 */
function e2m_engine_has_pods(): bool
{
    return defined('PODS_VERSION') && function_exists('pods_api');
}

/**
 * Check whether ASE Pro (Admin and Site Enhancements) custom fields are available.
 * ASE Pro exposes get_cf() and update_cf() functions when the custom fields feature is active.
 */
function e2m_engine_has_ase(): bool
{
    return function_exists('get_cf') && function_exists('update_cf');
}

/**
 * Check whether ACPT (Advanced Custom Post Types) plugin is available.
 */
function e2m_engine_has_acpt(): bool
{
    return defined('ACPT_LITE_PLUGIN_VERSION') || defined('ACPT_PLUGIN_VERSION') || function_exists('get_acpt_field');
}

/**
 * Check whether WPBakery Page Builder is active on this site.
 * Also detects TagDiv Composer (Newspaper theme bundles a modified WPBakery).
 */
function e2m_engine_has_wpbakery(): bool
{
    if ( defined( 'WPB_VC_VERSION' )
        || class_exists( 'Vc_Manager' )
        || class_exists( 'Vc_Base' )
        || function_exists( 'vc_map' )
    ) {
        return true;
    }
    // TagDiv Composer (Newspaper theme bundles modified WPBakery).
    if ( defined( 'TD_COMPOSER' ) || class_exists( 'td_api_module' ) ) {
        return true;
    }
    return false;
}

/**
 * Check whether Oxygen Builder is active on this site.
 * Oxygen 3/4 defines CT_VERSION; Oxygen 6 (Breakdance/Oxygen 6) defines BREAKDANCE_MODE==='oxygen'.
 */
function e2m_engine_has_oxygen(): bool
{
    // Oxygen 3/4 classic
    if ( defined('CT_VERSION')
        || function_exists('oxygen_vsb_get_components')
        || class_exists('OxygenElement')
    ) {
        return true;
    }
    // Oxygen 6 (Breakdance codebase, mode set to "oxygen")
    if ( defined('BREAKDANCE_MODE') && BREAKDANCE_MODE === 'oxygen' ) {
        return true;
    }
    return false;
}

/**
 * Check whether Breakdance Builder is active on this site.
 * Checks the version constant, plugin class, init function, and active_plugins list.
 * Also checks __BREAKDANCE_VERSION (used by some Breakdance versions).
 * Excludes Oxygen 6 mode (BREAKDANCE_MODE === 'oxygen').
 */
function e2m_engine_has_breakdance(): bool
{
    // Exclude Oxygen 6 which shares the same codebase but is NOT Breakdance.
    if ( defined('BREAKDANCE_MODE') && BREAKDANCE_MODE === 'oxygen' ) {
        return false;
    }
    if ( defined( 'BREAKDANCE_PLUGIN_VERSION' )
        || defined( '__BREAKDANCE_VERSION' )
        || class_exists( 'Breakdance\\Plugin' )
        || function_exists( 'breakdance_init' )
    ) {
        return true;
    }
    $active = (array) get_option( 'active_plugins', [] );
    return in_array( 'breakdance/plugin.php', $active, true );
}

/**
 * Check whether Beaver Builder is active on this site.
 * Detects FLBuilder class, FLBuilderModel class, or FL_BUILDER_VERSION constant.
 */
function e2m_engine_has_beaver(): bool
{
    return class_exists( 'FLBuilder' )
        || class_exists( 'FLBuilderModel' )
        || defined( 'FL_BUILDER_VERSION' );
}

/**
 * Check whether Divi theme or Divi Builder plugin is active on this site.
 * Detects both Divi as a theme (ET_BUILDER_VERSION) and the standalone
 * Divi Builder plugin (ET_CORE_VERSION / ET_Builder_Module class).
 */
function e2m_engine_has_divi(): bool
{
    return defined('ET_BUILDER_VERSION')
        || defined('ET_CORE_VERSION')
        || class_exists('ET_Builder_Module');
}

/**
 * Check whether Meta Box plugin is available on this site.
 */
function e2m_engine_has_metabox(): bool
{
    return defined('RWMB_VER') || class_exists('RW_Meta_Box');
}

/**
 * Check whether the MB Relationships add-on is active.
 */
function e2m_engine_has_metabox_relationships(): bool
{
    return class_exists('MB_Relationships_API');
}

/**
 * Check whether AI abilities are enabled in plugin settings.
 *
 * PERFORMANCE: Result is cached for the duration of the request to avoid
 * repeated get_option() calls (sandbox-loader, admin bar, config filter).
 *
 * @param bool $flush Force a fresh read (e.g. after AJAX toggle).
 */
function e2m_engine_is_enabled(bool $flush = false): bool
{
    static $cached = null;
    if ($cached !== null && !$flush) {
        return $cached;
    }

    $value = get_option('e2m_engine_ai_abilities_enabled', true);
    $cached = ($value === '1' || $value === true);

    return $cached;
}

/**
 * Returns the unique MCP server key for this site, e.g. "e2m-wordpress-xkq".
 * A random 3-letter suffix is generated once and persisted in wp_options so the
 * key stays stable across page loads but differs between installations.
 */
function e2m_engine_server_key(): string
{
    // Build key from site name: "{site_slug}-e2m_engine"
    $site_name = (string) get_option( 'blogname', 'wordpress' );
    $site_slug = strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', $site_name ), '-' ) );
    if ( $site_slug === '' ) {
        $site_slug = 'wordpress';
    }
    return $site_slug . '_e2m_engine';
}

/**
 * Get all E2M MCP module settings.
 *
 * Uses a static cache to avoid repeated get_option() calls within the same request.
 * Pass $flush = true to force a fresh read (e.g. after saving settings).
 *
 * @param bool $flush Whether to bypass the static cache.
 * @return array{sandbox_enabled: bool, modules: array<string, bool>}
 */
function e2m_engine_get_settings(bool $flush = false): array
{
    static $cached = null;

    if (null !== $cached && !$flush) {
        return $cached;
    }

    $defaults = [
        'safe_mode_enabled' => false,
        'safe_mode_previous_disabled' => [],
        // Sandbox PHP execution is on by default — agents can write and run
        // PHP files in the sandbox directory immediately after install.
        // Admins can disable it from the Sandbox tab if not needed.
        'sandbox_enabled' => true,
        // Per-file size cap (KB) for any write inside the sandbox dir.
        // Stops a runaway AI from creating a multi-megabyte file. 0 = no limit.
        'sandbox_max_file_size_kb' => 100,
        'disabled_abilities' => [],
        'modules' => [
            'wordpress'        => true,
            'elementor'        => true,
            'woocommerce'      => true,
            'bricks'           => true,
            'acf'              => true,
            'jetengine'        => true,
            'metabox'          => true,
            'acpt'             => true,
            'ase'              => true,
            'pods'             => true,
            'divi'             => true,
            'oxygen'           => true,
            'breakdance'       => true,
            'wpbakery'         => true,
            'beaver'           => true,
        ],
        'analytics_enabled' => true,
        'analytics_retention_days' => 30,
        'analytics_log_level' => 'all',
        'analytics_store_request' => false,
        'analytics_store_response' => false,
        'analytics_max_entries' => 5000,
        'analytics_notify_enabled' => false,
        'analytics_notify_email' => '',
        'analytics_notify_frequency' => 'off',
        'analytics_store_ip' => true,
        'analytics_anonymize_ip' => false,
        'analytics_store_user_identity' => true,
        'webhook_enabled' => false,
        'webhook_url' => '',
        'webhook_events' => 'all',
        'webhook_secret' => '',
        'server_instructions' => [
            'wp_version'        => true,
            'php_version'       => true,
            'theme_info'        => true,
            'plugins_list'      => true,
            'elementor_version' => true,
            'custom_text'       => '',
        ],
        // -- MCP token surface ---------------------------------------------
        // Profile picks how much of the tool catalogue is exposed to MCP
        // clients upfront. Default 'full' so every applicable ability is
        // available directly; admins can tighten via Safety settings.
        //   minimal  - bridge tools only (~3 KB upfront)
        //   standard - bridge + ~10 most-used essentials (~12 KB upfront)
        //   full     - every applicable ability direct (~30-50 KB upfront)
        'capabilities_profile' => 'full',
        // -- Safety stack ---------------------------------------------------
        // When true, destructive abilities return a preview payload unless
        // the caller explicitly passes dry_run=false. Default off so first
        // run for new admins doesn't silently no-op writes.
        'dry_run_default' => false,
        // Ring-buffer size for meta-snapshots kept per post. 0 disables.
        'snapshots_retention' => 10,
        // Scoped DB backup taken before content/DB-affecting tasks. When true,
        // the gatekeeper backs up the exact rows a task targets (post+meta,
        // named options, term+meta, user+meta) so changes are rollback-able.
        'db_backup_enabled' => true,
        // Backup retention in DAYS for the unified file/Elementor/DB store.
        // A daily cron prunes anything older. 0 = keep forever.
        'backup_retention_days' => 30,
        // Token-bucket cap: ops / minute / (user OR ip). 0 disables.
        // Generous default so legitimate burst workloads (audit scans, bulk
        // edits) never trip it. Operators can tighten in Safety settings.
        'rate_limit_ops_per_min' => 600,
        // Specific post IDs that MCP cannot modify. Human-only override list.
        'protected_posts' => [],
        // How many days to retain audit log entries. 0 = forever.
        'audit_retention_days' => 30,
        // Optional live event stream for destructive ops.
        'webhook_destructive_alerts' => false,
        // Hostname-drift defence + sandbox crash recovery.
        'domain_lock_enabled' => true,
        'crash_recovery_enabled' => true,
        // UI affordance - shows a red "AI ACTIVE" badge in the admin bar.
        'admin_bar_indicator' => true,
    ];

    $saved = get_option('e2m_engine_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    $settings = $defaults;
    if (isset($saved['safe_mode_enabled'])) {
        $settings['safe_mode_enabled'] = (bool) $saved['safe_mode_enabled'];
    }
    if (isset($saved['safe_mode_previous_disabled']) && is_array($saved['safe_mode_previous_disabled'])) {
        $settings['safe_mode_previous_disabled'] = array_values(array_map('sanitize_text_field', $saved['safe_mode_previous_disabled']));
    }
    if (isset($saved['sandbox_enabled'])) {
        $settings['sandbox_enabled'] = (bool) $saved['sandbox_enabled'];
    }
    if (isset($saved['disabled_abilities']) && is_array($saved['disabled_abilities'])) {
        $settings['disabled_abilities'] = array_values(array_map('sanitize_text_field', $saved['disabled_abilities']));
    }
    if (isset($saved['modules']) && is_array($saved['modules'])) {
        foreach ($defaults['modules'] as $key => $default_val) {
            $settings['modules'][$key] = isset($saved['modules'][$key]) ? (bool) $saved['modules'][$key] : $default_val;
        }
    }
    if (isset($saved['analytics_enabled'])) {
        $settings['analytics_enabled'] = (bool) $saved['analytics_enabled'];
    }
    if (isset($saved['analytics_retention_days'])) {
        $settings['analytics_retention_days'] = max(0, (int) $saved['analytics_retention_days']);
    }
    if (isset($saved['analytics_log_level'])) {
        $allowed_levels = ['all', 'errors', 'off'];
        $level = sanitize_text_field($saved['analytics_log_level']);
        $settings['analytics_log_level'] = in_array($level, $allowed_levels, true) ? $level : 'all';
    }
    if (isset($saved['analytics_store_request'])) {
        $settings['analytics_store_request'] = (bool) $saved['analytics_store_request'];
    }
    if (isset($saved['analytics_store_response'])) {
        $settings['analytics_store_response'] = (bool) $saved['analytics_store_response'];
    }
    if (isset($saved['analytics_max_entries'])) {
        $settings['analytics_max_entries'] = max(0, (int) $saved['analytics_max_entries']);
    }
    if (isset($saved['analytics_notify_enabled'])) {
        $settings['analytics_notify_enabled'] = (bool) $saved['analytics_notify_enabled'];
    }
    if (isset($saved['analytics_notify_email'])) {
        $settings['analytics_notify_email'] = sanitize_email($saved['analytics_notify_email']);
    }
    if (isset($saved['analytics_notify_frequency'])) {
        $allowed_freq = ['off', 'session', 'daily'];
        $freq = sanitize_text_field($saved['analytics_notify_frequency']);
        $settings['analytics_notify_frequency'] = in_array($freq, $allowed_freq, true) ? $freq : 'off';
    }
    if (isset($saved['analytics_store_ip'])) {
        $settings['analytics_store_ip'] = (bool) $saved['analytics_store_ip'];
    }
    if (isset($saved['analytics_anonymize_ip'])) {
        $settings['analytics_anonymize_ip'] = (bool) $saved['analytics_anonymize_ip'];
    }
    if (isset($saved['analytics_store_user_identity'])) {
        $settings['analytics_store_user_identity'] = (bool) $saved['analytics_store_user_identity'];
    }
    if (isset($saved['webhook_enabled'])) {
        $settings['webhook_enabled'] = (bool) $saved['webhook_enabled'];
    }
    if (isset($saved['webhook_url'])) {
        $settings['webhook_url'] = esc_url_raw($saved['webhook_url']);
    }
    if (isset($saved['webhook_events'])) {
        $allowed = ['all', 'destructive', 'errors'];
        $val = sanitize_text_field($saved['webhook_events']);
        $settings['webhook_events'] = in_array($val, $allowed, true) ? $val : 'all';
    }
    if (isset($saved['webhook_secret'])) {
        $settings['webhook_secret'] = sanitize_text_field($saved['webhook_secret']);
    }
    if (isset($saved['server_instructions']) && is_array($saved['server_instructions'])) {
        foreach ($defaults['server_instructions'] as $key => $default_val) {
            if ($key === 'custom_text') {
                $settings['server_instructions'][$key] = isset($saved['server_instructions'][$key])
                    ? sanitize_textarea_field($saved['server_instructions'][$key])
                    : $default_val;
            } else {
                $settings['server_instructions'][$key] = isset($saved['server_instructions'][$key])
                    ? (bool) $saved['server_instructions'][$key]
                    : $default_val;
            }
        }
    }

    // Capabilities-profile merge.
    if (isset($saved['capabilities_profile'])) {
        $allowed_profiles = ['ultra-minimal', 'minimal', 'standard', 'full'];
        $profile = sanitize_text_field((string) $saved['capabilities_profile']);
        $settings['capabilities_profile'] = in_array($profile, $allowed_profiles, true) ? $profile : 'minimal';
    }

    // Safety stack mergers.
    if (isset($saved['dry_run_default'])) {
        $settings['dry_run_default'] = (bool) $saved['dry_run_default'];
    }
    if (isset($saved['snapshots_retention'])) {
        $settings['snapshots_retention'] = max(0, min(100, (int) $saved['snapshots_retention']));
    }
    if (isset($saved['rate_limit_ops_per_min'])) {
        $settings['rate_limit_ops_per_min'] = max(0, (int) $saved['rate_limit_ops_per_min']);
    }
    if (isset($saved['protected_posts']) && is_array($saved['protected_posts'])) {
        $settings['protected_posts'] = array_values(array_unique(array_map('intval', $saved['protected_posts'])));
    }
    if (isset($saved['audit_retention_days'])) {
        $settings['audit_retention_days'] = max(0, (int) $saved['audit_retention_days']);
    }
    if (isset($saved['webhook_destructive_alerts'])) {
        $settings['webhook_destructive_alerts'] = (bool) $saved['webhook_destructive_alerts'];
    }
    if (isset($saved['domain_lock_enabled'])) {
        $settings['domain_lock_enabled'] = (bool) $saved['domain_lock_enabled'];
    }
    if (isset($saved['crash_recovery_enabled'])) {
        $settings['crash_recovery_enabled'] = (bool) $saved['crash_recovery_enabled'];
    }
    if (isset($saved['admin_bar_indicator'])) {
        $settings['admin_bar_indicator'] = (bool) $saved['admin_bar_indicator'];
    }

    $cached = $settings;

    return $settings;
}

/**
 * Check whether a specific ability is disabled by the user.
 *
 * @param string $ability_name Fully qualified ability name, e.g. 'e2m/delete-file'.
 */
function e2m_engine_is_ability_disabled(string $ability_name): bool
{
    $settings = e2m_engine_get_settings();
    return in_array($ability_name, $settings['disabled_abilities'], true);
}

/**
 * Check whether Safe Mode (read-only) is active.
 */
function e2m_engine_is_safe_mode(): bool
{
    $settings = e2m_engine_get_settings();
    return $settings['safe_mode_enabled'] ?? false;
}

/**
 * Determine if an ability is read-only using WP Abilities API annotations + name heuristics.
 *
 * @param string $ability_name Fully qualified ability name.
 * @return bool True if the ability only reads data.
 */
function e2m_engine_is_ability_readonly(string $ability_name): bool
{
    if (function_exists('wp_get_ability')) {
        $ability = wp_get_ability($ability_name);
        if ($ability instanceof WP_Ability) {
            $meta = $ability->get_meta();
            $readonly = $meta['annotations']['readonly'] ?? null;
            if ($readonly === true) {
                return true;
            }
            if ($readonly === false) {
                return false;
            }
        }
    }

    // Fallback: name-pattern heuristic for abilities with null annotations.
    return (bool) preg_match('/(get-|list-|read-|find-|search-|export-|discover|info|schema)/', $ability_name);
}

/**
 * Toggle Safe Mode on or off. Saves/restores previous disabled_abilities.
 *
 * @param bool $enable True to enable safe mode, false to disable.
 */
function e2m_engine_toggle_safe_mode(bool $enable): void
{
    $saved = get_option('e2m_engine_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    if ($enable) {
        // Save current disabled list so we can restore it later.
        $saved['safe_mode_previous_disabled'] = $saved['disabled_abilities'] ?? [];

        // Build list of ALL non-readonly abilities to disable.
        $disable = [];
        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                if (!($ability instanceof WP_Ability)) {
                    continue;
                }
                $name = $ability->get_name();
                if (!e2m_engine_is_ability_readonly($name)) {
                    $disable[] = $name;
                }
            }
        }

        $saved['disabled_abilities'] = $disable;
        $saved['safe_mode_enabled'] = true;
    } else {
        // Restore previous disabled list.
        $saved['disabled_abilities'] = $saved['safe_mode_previous_disabled'] ?? [];
        $saved['safe_mode_previous_disabled'] = [];
        $saved['safe_mode_enabled'] = false;
    }

    update_option('e2m_engine_settings', $saved);
    // Flush cache.
    e2m_engine_get_settings(true);
}

/**
 * Check whether the sandbox loader is enabled.
 */
function e2m_engine_is_sandbox_enabled(): bool
{
    $settings = e2m_engine_get_settings();
    return $settings['sandbox_enabled'];
}

/**
 * Check whether a specific ability module is enabled.
 *
 * @param string $module One of: wordpress, elementor, the_plus_addons, nexter_extension, nexter_blocks
 */
function e2m_engine_is_module_enabled(string $module): bool
{
    $settings = e2m_engine_get_settings();
    return $settings['modules'][$module] ?? false;
}

/**
 * Return the active capabilities profile: 'minimal' | 'standard' | 'full'.
 *
 * The profile determines which abilities ship as direct MCP tools vs which
 * are reachable only through the e2m-bridge dispatch layer. Defaulting
 * to 'minimal' keeps the upfront token surface tight; bridge tools are
 * always exposed so agents can still reach the long tail on demand.
 */
function e2m_engine_get_capabilities_profile(): string
{
    $settings = e2m_engine_get_settings();
    $profile  = (string) ($settings['capabilities_profile'] ?? 'minimal');
    return in_array($profile, ['ultra-minimal', 'minimal', 'standard', 'full'], true) ? $profile : 'minimal';
}

/**
 * Hook on wp_register_ability_args - rewrite mcp.public based on the
 * active capabilities profile + the ability's declared mcp.tier.
 *
 * Tiers (declared per-ability via meta.mcp.tier):
 *   bridge    - bridge tools only; shown in every profile including ultra-minimal
 *   essential - shown in minimal + standard + full (top-10 most-used)
 *   standard  - shown in standard + full (default when not declared)
 *   full      - shown only in full (niche / power-user tools)
 *
 * Profiles:
 *   ultra-minimal - bridge tools only (~3 KB upfront, 3 tools). Every op
 *                   goes through discover-tools -> inspect-tool -> dispatch-tool.
 *   minimal       - bridge + essentials (~5-10 KB).
 *   standard      - bridge + essentials + standard tier (~30-50 KB).
 *   full          - everything mcp.public=true (full surface).
 *
 * In minimal + ultra-minimal we ALSO strip output_schema from the ability
 * meta so the MCP-side payload sheds another 30-40% per tool. Standard +
 * full keep the full schema for richer agent UX.
 *
 * The original mcp.public is preserved as mcp.bridge_visible so the bridge
 * dispatch + discover layers can still see + invoke abilities that were
 * downgraded for this profile.
 */
function e2m_engine_apply_capability_profile_filter(array $args, string $name): array
{
    $meta = $args['meta'] ?? [];
    if (!isset($meta['mcp']) || !is_array($meta['mcp'])) {
        return $args;
    }

    $original_public = !empty($meta['mcp']['public']);
    if (!array_key_exists('bridge_visible', $meta['mcp'])) {
        $meta['mcp']['bridge_visible'] = $original_public;
    }

    if (!$original_public) {
        $args['meta'] = $meta;
        return $args; // never-public abilities stay hidden in every profile
    }

    $tier = (string) ($meta['mcp']['tier'] ?? 'standard');
    $profile = e2m_engine_get_capabilities_profile();

    $tiers_visible = match ($profile) {
        'ultra-minimal' => ['bridge'],
        'minimal'       => ['bridge', 'essential'],
        'standard'      => ['bridge', 'essential', 'standard'],
        default         => ['bridge', 'essential', 'standard', 'full'],
    };

    // Bridge tools auto-promote: tier=essential AND name starts with e2m-bridge/
    // counts as the 'bridge' tier for ultra-minimal compatibility. This avoids
    // making every bridge file declare two tiers.
    if ($tier === 'essential' && str_starts_with((string) $name, 'e2m-bridge/')) {
        $tier = 'bridge';
    }

    if (!in_array($tier, $tiers_visible, true)) {
        $meta['mcp']['public'] = false;
    }

    $args['meta'] = $meta;

    // In light profiles, drop output_schema so the MCP-tool payload is lean.
    // RegisterAbilityAsMcpTool reads the schema from the registered args, so
    // setting it to null/empty here means it's never serialised into tools/list.
    if (
        in_array($profile, ['ultra-minimal', 'minimal'], true)
        && !empty($meta['mcp']['public'])
        && isset($args['output_schema'])
    ) {
        unset($args['output_schema']);
    }

    return $args;
}
add_filter('wp_register_ability_args', 'e2m_engine_apply_capability_profile_filter', 10, 2);

/**
 * Permission callback shared by MCP abilities.
 */
function e2m_engine_permission_callback(): bool
{
    return current_user_can('manage_options');
}

/**
 * Check if site owner has set "readonly / view only" mode in Context & Rules.
 * Returns WP_Error if write operations are blocked, true otherwise.
 *
 * @return true|WP_Error
 */
function e2m_engine_check_write_allowed(): bool|WP_Error
{
    $instructions = strtolower( (string) get_option( 'e2m_memory_instructions_content', '' ) );
    $goal         = strtolower( (string) get_option( 'e2m_memory_instructions_goal', '' ) );
    $combined     = $instructions . ' ' . $goal;

    // Keywords that indicate read-only / no-update mode.
    $readonly_keywords = [
        'only view', 'view only', 'readonly', 'read only', 'read-only', 'read-only mode',
        'no update', 'dont update', "don't update", 'no updates', 'updates not allowed',
        'no delete', 'dont delete', "don't delete", 'deletion not allowed',
        'no changes', 'no editing', 'no write', 'only read', 'read only access',
        'write protected', 'write disabled', 'modifications not allowed',
    ];

    foreach ( $readonly_keywords as $keyword ) {
        if ( str_contains( $combined, $keyword ) ) {
            return new WP_Error(
                'e2m_write_blocked',
                '🚫 Write operations are blocked by the site owner. Rule: "' . esc_html( substr( $instructions, 0, 120 ) ) . '..." — Only VIEW is allowed.'
            );
        }
    }

    return true;
}



/**
 * Block write abilities if site owner has enabled readonly / view-only mode.
 *
 * NOTE: The instructions enforcement is now handled architecturally —
 * only 3 bridge tools are exposed (discover, dispatch, inspect), so Claude
 * MUST call e2m-bridge/discover-tools first, which always returns
 * site_instructions in its response. No transient gate needed.
 */
add_action( 'wp_before_execute_ability', function( string $ability_name ): void {

    $write_keywords = [
        'update', 'delete', 'create', 'save', 'write', 'import', 'inject',
        'apply', 'build', 'execute', 'patch', 'move', 'remove', 'reorder',
        'duplicate', 'upload', 'sideload', 'convert', 'restore', 'protect',
        'assign', 'add-',
    ];

    foreach ( $write_keywords as $kw ) {
        if ( str_contains( $ability_name, $kw ) ) {
            $check = e2m_engine_check_write_allowed();
            if ( is_wp_error( $check ) ) {
                throw new \RuntimeException( $check->get_error_message() );
            }
            break;
        }
    }

}, 10, 1 );

/**
 * Permission callback for write/destructive abilities.
 * Checks both user capability AND site owner's Context & Rules.
 */
function e2m_engine_write_permission_callback(): bool|WP_Error
{
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    $check = e2m_engine_check_write_allowed();
    if ( is_wp_error( $check ) ) {
        return $check;
    }
    return true;
}

/**
 * Permission callback for Elementor page/post editing abilities.
 *
 * This must be available before any design/layout abilities are registered,
 * otherwise WordPress 6.9+ will reject the string callback as non-callable.
 *
 * @param array<string, mixed>|null $input Ability input arguments.
 */
function e2m_engine_elementor_post_permission(?array $input = null): bool
{
    if (!current_user_can('edit_posts')) {
        return false;
    }

    $post_id = absint($input['post_id'] ?? 0);
    if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
        return false;
    }

    return true;
}

/**
 * Render dependency notices in wp-admin.
 */
function e2m_engine_render_dependency_notices(): void
{
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (!e2m_engine_has_abilities_api()) {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('E2M Connect MCP requires the WordPress Abilities API. Please install and activate it to enable AI abilities.', 'e2mconnect')
            . '</p></div>';
    }

    if (!e2m_engine_has_mcp_adapter()) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('E2M Connect could not load its bundled MCP Adapter. If the standalone "MCP Adapter" plugin is active, deactivate it so E2M Connect can use its own bundled copy.', 'e2mconnect')
            . '</p></div>';
    }

}

/**
 * Build server instructions text from enabled toggles.
 */
function e2m_engine_build_server_instructions(): string
{
    $settings = e2m_engine_get_settings();
    $si = $settings['server_instructions'];
    $lines = [];

    // --- SITE OWNER INSTRUCTIONS (injected first, plain text) ---
    $instr   = trim( (string) get_option( 'e2m_memory_instructions_content', '' ) );
    $md_file = trim( (string) get_option( 'e2m_memory_instructions_md', '' ) );

    if ( $instr !== '' || $md_file !== '' ) {
        if ( $instr !== '' ) {
            $lines[] = $instr;
        }
        if ( $md_file !== '' ) {
            $lines[] = $md_file;
        }
        $lines[] = '---';
        $lines[] = '';
    }
    // -------------------------------------------------------------------------

    $lines[] = 'You are connected to a WordPress site via E2M MCP (E2M Connect).';
    $lines[] = 'TOOLS: Always use e2m-* tools only. Write PHP files to the E2M sandbox: ' . E2M_ENGINE_SANDBOX_DIR;

    if ($si['wp_version']) {
        $lines[] = 'WordPress version: ' . get_bloginfo('version');
    }
    if ($si['php_version']) {
        $lines[] = 'PHP version: ' . PHP_VERSION;
    }
    if ($si['theme_info']) {
        $theme = wp_get_theme();
        $lines[] = 'Active theme: ' . $theme->get('Name') . ' ' . $theme->get('Version');
        if ($theme->parent()) {
            $lines[] = 'Parent theme: ' . $theme->parent()->get('Name') . ' ' . $theme->parent()->get('Version');
        }
    }
    if ($si['elementor_version'] && defined('ELEMENTOR_VERSION')) {
        $lines[] = 'Elementor version: ' . ELEMENTOR_VERSION;
    }
    if ($si['plugins_list']) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = get_option('active_plugins', []);
        $plugins = get_plugins();
        $active_names = [];
        foreach ($active as $plugin_file) {
            if (isset($plugins[$plugin_file])) {
                $active_names[] = $plugins[$plugin_file]['Name'] . ' ' . $plugins[$plugin_file]['Version'];
            }
        }
        if ($active_names) {
            $lines[] = 'Active plugins: ' . implode(', ', $active_names);
        }
    }
    if (trim($si['custom_text']) !== '') {
        $lines[] = trim($si['custom_text']);
    }

    // ── Inject Context & Rules (Memory tab) ──────────────────────────────────
    $goal    = trim( (string) get_option( 'e2m_memory_instructions_goal', '' ) );
    $instr   = trim( (string) get_option( 'e2m_memory_instructions_content', '' ) );
    $md_file = trim( (string) get_option( 'e2m_memory_instructions_md', '' ) );

    if ( $goal !== '' || $instr !== '' || $md_file !== '' ) {
        $lines[] = '';
        $lines[] = '=== SITE CONTEXT & RULES (set by site owner — follow strictly) ===';

        if ( $goal !== '' ) {
            $lines[] = '';
            $lines[] = '## Goal';
            $lines[] = $goal;
        }

        if ( $instr !== '' ) {
            $lines[] = '';
            $lines[] = '## Instructions & Rules';
            $lines[] = $instr;
        }

        if ( $md_file !== '' ) {
            $lines[] = '';
            $lines[] = '## Additional Rules (from uploaded file)';
            $lines[] = $md_file;
        }

        $lines[] = '';
        $lines[] = '=== END OF SITE CONTEXT & RULES ===';
    }

    $instructions = implode("\n", $lines);

    /**
     * Filter the assembled server-instructions string before it's handed to
     * the MCP adapter. The memory subsystem (E2M_Memory_Context_Injector)
     * uses this to prepend status=always memories and append a catalogue of
     * slug-callable memories. Returning the value unchanged is a valid no-op.
     *
     * Two priorities are conventionally used:
     *   - 5  : memory always-block (prepend)
     *   - 20 : slug-callable index (append)
     *
     * @since 0.1.0
     * @param string $instructions Fully assembled instructions text.
     */
    return (string) apply_filters( 'e2m_engine_server_instructions', $instructions );
}


/**
 * Bootstrap MCP Adapter when dependencies are ready.
 */
function e2m_engine_bootstrap_mcp_adapter(): void
{
    if (!e2m_engine_has_abilities_api() || !e2m_engine_has_mcp_adapter()) {
        return;
    }

    \WP\MCP\Core\McpAdapter::instance();
}

// -- Ability Source Tracking -------------------------------------------------

/**
 * Global map: ability_name => plugin file path.
 * Populated by the wp_register_ability_args filter.
 *
 * @var array<string, string>
 */
global $e2m_engine_ability_sources;
$e2m_engine_ability_sources = [];

/**
 * Filter: record which plugin file registered each ability.
 *
 * PERFORMANCE: Uses debug_backtrace() which is expensive. This data is only
 * needed for the admin UI (ability source badges), so the filter is only
 * registered during admin requests. On frontend and REST (MCP) requests,
 * the backtrace is skipped entirely - zero overhead.
 */
if (is_admin()) {
    add_filter('wp_register_ability_args', function (array $args, string $name): array {
        global $e2m_engine_ability_sources;

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $plugins_dir = WP_PLUGIN_DIR . '/';

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && str_starts_with($file, $plugins_dir)) {
                $relative = substr($file, strlen($plugins_dir));
                $slash = strpos($relative, '/');
                if ($slash !== false) {
                    $e2m_engine_ability_sources[$name] = substr($relative, 0, $slash);
                }
                break;
            }
        }

        return $args;
    }, 1, 2);
}

/**
 * Get the plugin slug that registered a given ability.
 *
 * @param string $ability_name Fully-qualified ability name.
 * @return string Plugin directory slug, or '' if unknown.
 */
function e2m_engine_get_ability_source(string $ability_name): string
{
    global $e2m_engine_ability_sources;
    return $e2m_engine_ability_sources[$ability_name] ?? '';
}

/**
 * Get display-friendly plugin info from a plugin directory slug.
 * Returns ['name' => 'Human Name', 'slug' => 'dir-slug', 'version' => '1.0.0'].
 *
 * @param string $plugin_slug Plugin directory name.
 * @return array{name: string, slug: string, version: string}
 */
function e2m_engine_get_plugin_info(string $plugin_slug): array
{
    static $cache = [];

    if (isset($cache[$plugin_slug])) {
        return $cache[$plugin_slug];
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    foreach ($all_plugins as $file => $data) {
        if (str_starts_with($file, $plugin_slug . '/')) {
            $cache[$plugin_slug] = [
                'name'    => $data['Name'] ?? $plugin_slug,
                'slug'    => $plugin_slug,
                'version' => $data['Version'] ?? '',
            ];
            return $cache[$plugin_slug];
        }
    }

    // Fallback: humanize the slug.
    $cache[$plugin_slug] = [
        'name'    => ucwords(str_replace(['-', '_'], ' ', $plugin_slug)),
        'slug'    => $plugin_slug,
        'version' => '',
    ];
    return $cache[$plugin_slug];
}
