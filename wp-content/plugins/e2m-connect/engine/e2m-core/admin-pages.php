<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get application passwords for the current user.
 *
 * @return array List of application password entries.
 */
function e2m_engine_get_passwords(): array
{
    $user = wp_get_current_user();
    if (!$user->exists()) {
        return [];
    }
    return WP_Application_Passwords::get_user_application_passwords($user->ID);
}

/**
 * Handle creating a new application password via POST.
 *
 * @return string|\WP_Error|null The new password string on success, WP_Error on failure, null if no action.
 */
function e2m_engine_handle_create_password()
{
    if (!isset($_POST['e2m_engine_create_password'])) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return new \WP_Error('forbidden', __('You do not have permission to create application passwords.', 'e2mconnect'));
    }

    check_admin_referer('e2m_engine_create_password');

    if (!wp_is_application_passwords_available()) {
        return new \WP_Error('unavailable', __('Application passwords are not available on this site.', 'e2mconnect'));
    }

    $name = sanitize_text_field(wp_unslash($_POST['e2m_engine_password_name'] ?? ''));
    if ($name === '') {
        return new \WP_Error('empty_name', __('Please enter a name for the application password.', 'e2mconnect'));
    }

    $user = wp_get_current_user();
    $result = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => $name]);

    if (is_wp_error($result)) {
        return $result;
    }

    // $result is [ password, item ].
    return $result[0];
}

/**
 * Handle revoking an application password via POST.
 * Redirects back to the connect tab after processing.
 */
function e2m_engine_handle_revoke_password(): void
{
    if (!isset($_POST['e2m_engine_revoke_password'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $uuid = sanitize_text_field(wp_unslash($_POST['e2m_engine_revoke_uuid'] ?? ''));
    if ($uuid === '') {
        return;
    }

    check_admin_referer('e2m_engine_revoke_password_' . $uuid);

    $user = wp_get_current_user();
    WP_Application_Passwords::delete_application_password($user->ID, $uuid);

    wp_safe_redirect(add_query_arg([
        'page'              => 'e2mconnect',
        'tab'               => 'connect',
        'e2m_engine_result' => 'revoked',
    ], admin_url('admin.php')));
    exit;
}

/**
 * Render hidden form fields to preserve settings that are not part of the current form.
 *
 * When a single toggle or tab-specific form is submitted, we need to carry forward
 * all existing settings so they are not lost during the save in e2m_engine_render_main_page().
 *
 * @param array  $settings   Current plugin settings array.
 * @param string $active_tab The tab being rendered (used to skip fields that the form already provides).
 */
function e2m_engine_render_hidden_settings(array $settings, string $active_tab): void
{
    // AI Abilities enabled - preserve when not on ai-abilities tab.
    if ($active_tab !== 'ai-abilities') {
        if (get_option('e2m_engine_ai_abilities_enabled', '1') === '1') {
            echo '<input type="hidden" name="e2m_engine_ai_abilities_enabled" value="1" />';
        }
    }

    // Sandbox enabled + max file size - preserve when not on sandbox tab.
    if ($active_tab !== 'sandbox') {
        if ($settings['sandbox_enabled']) {
            echo '<input type="hidden" name="e2m_engine_sandbox_enabled" value="1" />';
        }
        echo '<input type="hidden" name="e2m_engine_sandbox_max_file_size_kb" value="' . esc_attr((string) ($settings['sandbox_max_file_size_kb'] ?? 100)) . '" />';
    }

    // Analytics settings - preserve when not on activity tab.
    if ($active_tab !== 'activity' && $active_tab !== 'analytics') {
        if ($settings['analytics_enabled'] ?? true) {
            echo '<input type="hidden" name="e2m_engine_analytics_enabled" value="1" />';
        }
        echo '<input type="hidden" name="e2m_engine_analytics_retention" value="' . esc_attr((string) ($settings['analytics_retention_days'] ?? 30)) . '" />';
        echo '<input type="hidden" name="e2m_engine_analytics_log_level" value="' . esc_attr($settings['analytics_log_level'] ?? 'all') . '" />';
        if ($settings['analytics_store_request'] ?? false) {
            echo '<input type="hidden" name="e2m_engine_analytics_store_request" value="1" />';
        }
        if ($settings['analytics_store_response'] ?? false) {
            echo '<input type="hidden" name="e2m_engine_analytics_store_response" value="1" />';
        }
        echo '<input type="hidden" name="e2m_engine_analytics_max_entries" value="' . esc_attr((string) ($settings['analytics_max_entries'] ?? 5000)) . '" />';
        if ($settings['analytics_notify_enabled'] ?? false) {
            echo '<input type="hidden" name="e2m_engine_analytics_notify_enabled" value="1" />';
        }
        echo '<input type="hidden" name="e2m_engine_analytics_notify_email" value="' . esc_attr($settings['analytics_notify_email'] ?? '') . '" />';
        echo '<input type="hidden" name="e2m_engine_analytics_notify_frequency" value="' . esc_attr($settings['analytics_notify_frequency'] ?? 'off') . '" />';
    }

    // Privacy / data storage settings - preserve when not on privacy tab.
    if ($active_tab !== 'privacy' && $active_tab !== '') {
        if ($settings['analytics_store_ip'] ?? true) {
            echo '<input type="hidden" name="e2m_engine_analytics_store_ip" value="1" />';
        }
        if ($settings['analytics_anonymize_ip'] ?? false) {
            echo '<input type="hidden" name="e2m_engine_analytics_anonymize_ip" value="1" />';
        }
        if ($settings['analytics_store_user_identity'] ?? true) {
            echo '<input type="hidden" name="e2m_engine_analytics_store_user_identity" value="1" />';
        }
        // Webhook settings.
        if ($settings['webhook_enabled'] ?? false) {
            echo '<input type="hidden" name="e2m_engine_webhook_enabled" value="1" />';
        }
        echo '<input type="hidden" name="e2m_engine_webhook_url" value="' . esc_attr($settings['webhook_url'] ?? '') . '" />';
        echo '<input type="hidden" name="e2m_engine_webhook_events" value="' . esc_attr($settings['webhook_events'] ?? 'all') . '" />';
        echo '<input type="hidden" name="e2m_engine_webhook_secret" value="***UNCHANGED***" />';

        // Server instructions.
        $si = $settings['server_instructions'] ?? [];
        foreach (['wp_version', 'php_version', 'theme_info', 'plugins_list', 'elementor_version'] as $si_key) {
            if ($si[$si_key] ?? false) {
                echo '<input type="hidden" name="e2m_engine_si_' . esc_attr($si_key) . '" value="1" />';
            }
        }
        echo '<input type="hidden" name="e2m_engine_si_custom_text" value="' . esc_attr($si['custom_text'] ?? '') . '" />';
    }
}

/**
 * Register single MCP admin menu page with tabbed layout.
 */
function e2m_engine_register_admin_menu(): void
{
    $menu_icon_file = E2M_ENGINE_PLUGIN_DIR . 'e2m-ui/menu-icon.svg';
    $menu_icon      = 'dashicons-admin-generic';

    if (is_readable($menu_icon_file)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $svg_contents = file_get_contents($menu_icon_file);
        if ($svg_contents !== false && $svg_contents !== '') {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG data URI for admin menu icon.
            $menu_icon = 'data:image/svg+xml;base64,' . base64_encode($svg_contents);
        }
    }

    add_menu_page(
        __('E2M Connect', 'e2mconnect'),
        'E2M Connect',
        'manage_options',
        'e2mconnect',
        'e2m_engine_render_main_page',
        $menu_icon,
        58
    );
}

/**
 * Enqueue the global design system CSS on MCP admin pages.
 */
function e2m_engine_enqueue_admin_assets(string $hook): void
{
    // Keep the E2M menu icon sized correctly on every wp-admin screen.
    wp_register_style('e2m-mcp-menu-icon-fix', false, [], E2M_ENGINE_VERSION);
    wp_enqueue_style('e2m-mcp-menu-icon-fix');
    wp_add_inline_style(
        'e2m-mcp-menu-icon-fix',
        '#toplevel_page_e2mconnect .wp-menu-image,' .
        '#toplevel_page_e2mconnect.current .wp-menu-image,' .
        '#toplevel_page_e2mconnect.wp-has-current-submenu .wp-menu-image,' .
        '#toplevel_page_e2mconnect .wp-menu-image img,' .
        '#toplevel_page_e2mconnect.current .wp-menu-image img,' .
        '#toplevel_page_e2mconnect.wp-has-current-submenu .wp-menu-image img,' .
        '#toplevel_page_e2mconnect .wp-menu-image.svg,' .
        '#toplevel_page_e2mconnect.current .wp-menu-image.svg,' .
        '#toplevel_page_e2mconnect.wp-has-current-submenu .wp-menu-image.svg' .
        '{background-size:30px auto!important;}'
    );

    // Hide third-party admin notices and banners on E2M Connect dashboard pages.
    wp_add_inline_style(
        'e2m-mcp-menu-icon-fix',
        'body.toplevel_page_e2mconnect div.notice,' .
        'body.toplevel_page_e2mconnect div.notice-errors,' .
        'body.toplevel_page_e2mconnect div.notice .notice-errors,' .
        'body.toplevel_page_e2mconnect div.notice-warning,' .
        'body.toplevel_page_e2mconnect div.is-dismissible,' .
        'body.toplevel_page_e2mconnect div.MuiBox-root,' .
        'body.toplevel_page_e2mconnect div.nf-admin-notice,' .
        'body.toplevel_page_e2mconnect div.error,' .
        'body.toplevel_page_e2mconnect div.nri-nice-review-wrapper,' .
        'body.toplevel_page_e2mconnect div.pa-notice-wrap,' .
        'body.toplevel_page_e2mconnect div.pa-review-notice,' .
        'body.toplevel_page_e2mconnect div.fs-notice,' .
        'body.toplevel_page_e2mconnect div.update-nag,' .
        'body.toplevel_page_e2mconnect div#bps-inpage-message,' .
        'body.toplevel_page_e2mconnect div#bps-status-display,' .
        'body.toplevel_page_e2mconnect div.nri-nice-review,' .
        'body.toplevel_page_e2mconnect .loginpress-review-notice,' .
        'body.toplevel_page_e2mconnect .mwp-notice-container,' .
        'body.toplevel_page_theplus_welcome_page div.notice,' .
        'body.toplevel_page_theplus_welcome_page div.notice-errors,' .
        'body.toplevel_page_theplus_welcome_page div.notice .notice-errors,' .
        'body.toplevel_page_theplus_welcome_page div.notice-warning,' .
        'body.toplevel_page_theplus_welcome_page div.is-dismissible,' .
        'body.toplevel_page_theplus_welcome_page div.MuiBox-root,' .
        'body.toplevel_page_theplus_welcome_page div.nf-admin-notice,' .
        'body.toplevel_page_theplus_welcome_page div.error,' .
        'body.toplevel_page_theplus_welcome_page div.nri-nice-review-wrapper,' .
        'body.toplevel_page_theplus_welcome_page div.pa-notice-wrap,' .
        'body.toplevel_page_theplus_welcome_page div.pa-review-notice,' .
        'body.toplevel_page_theplus_welcome_page div.fs-notice,' .
        'body.toplevel_page_theplus_welcome_page div.update-nag,' .
        'body.toplevel_page_theplus_welcome_page div#bps-inpage-message,' .
        'body.toplevel_page_theplus_welcome_page div#bps-status-display,' .
        'body.toplevel_page_theplus_welcome_page div.nri-nice-review,' .
        'body.toplevel_page_theplus_welcome_page div.nri-nice-review-wrapper,' .
        'body.toplevel_page_theplus_welcome_page .loginpress-review-notice,' .
        'body.toplevel_page_theplus_welcome_page .mwp-notice-container{display:none!important;}'
    );

    $allowed_hooks = [
        'toplevel_page_e2mconnect',
        'toplevel_page_e2m-mcp',
    ];

    if (!in_array($hook, $allowed_hooks, true)) {
        return;
    }
    wp_enqueue_style(
        'e2m-mcp-design-system',
        E2M_ENGINE_URL . 'e2m-ui/admin-design-system.css',
        [],
        E2M_ENGINE_VERSION
    );

    // Memory tab styles. Loaded on the same hook so the design-system
    // tokens are already available when these rules cascade.
    wp_enqueue_style(
        'e2m-memory-admin',
        E2M_ENGINE_URL . 'e2m-ui/memory-admin.css',
        [ 'e2m-mcp-design-system' ],
        E2M_ENGINE_VERSION
    );
}
add_action('admin_enqueue_scripts', 'e2m_engine_enqueue_admin_assets', 999);

/**
 * Render the unified MCP admin page with sidebar + content panel.
 */
function e2m_engine_render_main_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // -- Handle settings save --
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified immediately after this gate check.
    if ( isset( $_POST['e2m_engine_save_settings'] ) && sanitize_text_field( wp_unslash( $_POST['e2m_engine_save_settings'] ) ) !== '' ) {
        check_admin_referer( 'e2m_engine_settings' );

        // Detect which tab submitted the form so we can preserve sandbox state
        // when a non-sandbox tab saves (the checkbox won't be present in POST).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $submitting_tab = sanitize_text_field( wp_unslash( $_POST['e2m_engine_active_tab'] ?? 'connect' ) );

        $enabled = isset($_POST['e2m_engine_ai_abilities_enabled']) ? '1' : '0';
        update_option('e2m_engine_ai_abilities_enabled', $enabled);
        e2m_engine_is_enabled(true); // flush static cache

        // Per-ability toggles: only recalculate when the abilities form was submitted
        // (e2m_engine_all_abilities[] present). Otherwise preserve existing disabled list.
        if (isset($_POST['e2m_engine_all_abilities']) && is_array($_POST['e2m_engine_all_abilities'])) {
            $all_ability_names = array_map('sanitize_text_field', wp_unslash($_POST['e2m_engine_all_abilities']));
            $enabled_abilities = isset($_POST['e2m_engine_ability']) && is_array($_POST['e2m_engine_ability'])
                ? array_map('sanitize_text_field', wp_unslash($_POST['e2m_engine_ability']))
                : [];
            $disabled_abilities = array_values(array_diff($all_ability_names, $enabled_abilities));
        } else {
            $existing = e2m_engine_get_settings(true);
            $disabled_abilities = $existing['disabled_abilities'] ?? [];
        }

        $si = [
            'wp_version'        => isset($_POST['e2m_engine_si_wp_version']),
            'php_version'       => isset($_POST['e2m_engine_si_php_version']),
            'theme_info'        => isset($_POST['e2m_engine_si_theme_info']),
            'plugins_list'      => isset($_POST['e2m_engine_si_plugins_list']),
            'elementor_version' => isset($_POST['e2m_engine_si_elementor_version']),
            'custom_text'       => sanitize_textarea_field(wp_unslash($_POST['e2m_engine_si_custom_text'] ?? '')),
        ];

        // Preserve existing module toggles (including Safety-tab-owned keys
        // like modules.woocommerce) - the main form does not expose them,
        // so reading get_option here avoids wiping them on every save.
        $existing_modules = get_option('e2m_engine_settings', []);
        $existing_modules = is_array($existing_modules) && isset($existing_modules['modules']) && is_array($existing_modules['modules'])
            ? $existing_modules['modules']
            : [];
        $preserved_modules = array_merge(
            [
                'wordpress'   => true,
                'elementor'   => true,
                'woocommerce' => true,
            ],
            $existing_modules
        );

        $new_settings = [
            'sandbox_enabled'    => $submitting_tab === 'sandbox'
                ? isset( $_POST['e2m_engine_sandbox_enabled'] )
                : ( e2m_engine_get_settings()['sandbox_enabled'] ?? true ),
            'sandbox_max_file_size_kb' => $submitting_tab === 'sandbox'
                ? max( 0, (int) wp_unslash( $_POST['e2m_engine_sandbox_max_file_size_kb'] ?? 100 ) )
                : ( e2m_engine_get_settings()['sandbox_max_file_size_kb'] ?? 100 ),
            'disabled_abilities' => $disabled_abilities,
            'modules'            => $preserved_modules,
            'server_instructions'          => $si,
            'analytics_enabled'            => isset($_POST['e2m_engine_analytics_enabled']),
            'analytics_retention_days'     => absint(wp_unslash($_POST['e2m_engine_analytics_retention'] ?? 30)),
            'analytics_log_level'          => sanitize_text_field(wp_unslash($_POST['e2m_engine_analytics_log_level'] ?? 'all')),
            'analytics_store_request'      => isset($_POST['e2m_engine_analytics_store_request']),
            'analytics_store_response'     => isset($_POST['e2m_engine_analytics_store_response']),
            'analytics_max_entries'        => absint(wp_unslash($_POST['e2m_engine_analytics_max_entries'] ?? 5000)),
            'analytics_notify_enabled'     => isset($_POST['e2m_engine_analytics_notify_enabled']),
            'analytics_notify_email'       => sanitize_email(wp_unslash($_POST['e2m_engine_analytics_notify_email'] ?? '')),
            'analytics_notify_frequency'   => sanitize_text_field(wp_unslash($_POST['e2m_engine_analytics_notify_frequency'] ?? 'off')),
            'analytics_store_ip'           => isset($_POST['e2m_engine_analytics_store_ip']),
            'analytics_anonymize_ip'       => isset($_POST['e2m_engine_analytics_anonymize_ip']),
            'analytics_store_user_identity' => isset($_POST['e2m_engine_analytics_store_user_identity']),
            'webhook_enabled'              => isset($_POST['e2m_engine_webhook_enabled']),
            'webhook_url'                  => esc_url_raw(wp_unslash($_POST['e2m_engine_webhook_url'] ?? '')),
            'webhook_events'               => sanitize_text_field(wp_unslash($_POST['e2m_engine_webhook_events'] ?? 'all')),
            'webhook_secret'               => sanitize_text_field(wp_unslash($_POST['e2m_engine_webhook_secret'] ?? '')),
        ];

        // Preserve webhook secret when the hidden-field sentinel is used
        // (non-privacy tab saves should not wipe the secret).
        $existing = get_option('e2m_engine_settings', []);
        if ($new_settings['webhook_secret'] === '***UNCHANGED***') {
            $new_settings['webhook_secret'] = $existing['webhook_secret'] ?? '';
        }

        // Preserve safe mode state across saves.
        $new_settings['safe_mode_enabled'] = $existing['safe_mode_enabled'] ?? false;
        $new_settings['safe_mode_previous_disabled'] = $existing['safe_mode_previous_disabled'] ?? [];

        // Preserve Safety-tab-owned keys. They are edited via their own AJAX
        // handlers; without this merge the main form submit would blow them
        // away because it rewrites the whole option.
        $safety_keys = [
            'dry_run_default', 'snapshots_retention', 'rate_limit_ops_per_min',
            'protected_posts', 'audit_retention_days', 'domain_lock_enabled',
            'crash_recovery_enabled', 'admin_bar_indicator', 'webhook_destructive_alerts',
        ];
        foreach ($safety_keys as $k) {
            if (array_key_exists($k, $existing)) {
                $new_settings[$k] = $existing[$k];
            }
        }

        update_option('e2m_engine_settings', $new_settings);
        e2m_engine_get_settings(true);
    }

    $settings = e2m_engine_get_settings();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $active_tab = sanitize_text_field(wp_unslash($_POST['e2m_engine_active_tab'] ?? $_GET['tab'] ?? apply_filters('e2m_connect_hub_default_tab', 'connect')));
    $valid_tabs = ['connect', 'ai-abilities', 'sandbox', 'activity', 'analytics', 'privacy', 'safety', 'memory', 'agent_context']; // 'safety' kept for legacy redirect below
    $valid_tabs = apply_filters('e2m_connect_hub_valid_tabs', $valid_tabs);
    if (!in_array($active_tab, $valid_tabs, true)) {
        $active_tab = apply_filters('e2m_connect_hub_default_tab', 'connect');
    }
    // Legacy: redirect analytics tab to unified activity tab.
    if ($active_tab === 'analytics') {
        $active_tab = 'activity';
    }
    // Legacy: safety tab merged into connect tab.
    if ($active_tab === 'safety') {
        $active_tab = 'connect';
    }
    // Legacy URL alias — `agent_context` was the pre-0.1.0 slug, now unified
    // under `memory`. Bookmarked URLs continue to land on the Memory tab.
    if ($active_tab === 'agent_context') {
        $active_tab = 'memory';
    }

    $grouped_abilities = e2m_engine_get_grouped_abilities();
    $total_abilities = 0;
    foreach ($grouped_abilities as $g) { $total_abilities += count($g); }

    $logo_url = defined('E2M_CONNECT_URL') ? E2M_CONNECT_URL . 'assets/admin/img/e2m-mark.png' : E2M_ENGINE_URL . 'e2m-ui/logo-white.svg';

    $nav_items = [
        'connect'      => [
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
            'label' => __('MCP Connect', 'e2mconnect'),
        ],
        'ai-abilities' => [
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 2v2.5M14.5 2v2.5M9.5 19.5V22M14.5 19.5V22M2 9.5h2.5M2 14.5h2.5M19.5 9.5H22M19.5 14.5H22"/><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
            'label' => __('AI Abilities', 'e2mconnect'),
            'badge' => $total_abilities > 0 ? $total_abilities : null,
        ],
        'sandbox'      => [
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
            'label' => __('Sandbox', 'e2mconnect'),
        ],
        'activity'     => [
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="10" width="4" height="11" rx="1"/><rect x="10" y="3" width="4" height="18" rx="1"/><rect x="17" y="7" width="4" height="14" rx="1"/></svg>',
            'label' => __('Analytics', 'e2mconnect'),
        ],
        'memory' => [
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 0 0-4 4v1H7a3 3 0 0 0-3 3v1a3 3 0 0 0 1 2.24V18a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3v-4.76A3 3 0 0 0 20 11v-1a3 3 0 0 0-3-3h-1V6a4 4 0 0 0-4-4Z"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>',
            'label' => __('AI Memory', 'e2mconnect'),
        ],
        'privacy'      => [
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
            'label' => __('Settings', 'e2mconnect'),
        ],
    ];

    $page_meta = [
        'connect'      => ['title' => __('MCP Connect', 'e2mconnect'),    'desc' => __('Set up application passwords and connect AI clients via MCP.', 'e2mconnect')],
        'ai-abilities' => ['title' => __('AI Abilities', 'e2mconnect'), 'desc' => __('Control which tools are exposed to connected AI clients.', 'e2mconnect')],
        'sandbox'      => ['title' => __('Sandbox', 'e2mconnect'),      'desc' => __('Manage custom ability files in the sandbox directory.', 'e2mconnect')],
        'activity'     => ['title' => __('Analytics', 'e2mconnect'),    'desc' => __('Track AI activity, usage stats, and detailed logs.', 'e2mconnect')],
        'privacy'      => ['title' => __('Settings', 'e2mconnect'),     'desc' => __('Configure MCP abilities, sandbox environment, privacy controls, and analytics tracking.', 'e2mconnect')],
        'memory' => ['title' => __('AI Memory', 'e2mconnect'), 'desc' => __('Persistent, audited memory for AI agents — what they always apply, what they fetch on demand, what stays anchored to the conversation that produced it.', 'e2mconnect')],
    ];

    // E2M Connect folds its own pages (Onboarding, Chat, Agents, Skills, Health,
    // Settings) into this hub's sidebar so the whole plugin is one screen.
    $nav_items = apply_filters('e2m_connect_hub_nav', $nav_items);
    $page_meta = apply_filters('e2m_connect_hub_page_meta', $page_meta);

    ?>
    <div class="so-shell">
        <!-- ═══ Header Bar ═══ -->
        <header class="so-header">
            <div class="so-header-left">
                <button type="button" class="so-hamburger" id="so-hamburger" aria-label="<?php esc_attr_e('Toggle menu', 'e2mconnect'); ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="3" y1="5" x2="17" y2="5"/><line x1="3" y1="10" x2="17" y2="10"/><line x1="3" y1="15" x2="17" y2="15"/></svg>
                </button>
                <div class="so-header-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="E2M Connect" style="max-height:32px;width:auto" />
                </div>
                <?php if (defined('E2M_CONNECT_VERSION')): ?>
                    <span class="so-header-version">v<?php echo esc_html(E2M_CONNECT_VERSION); ?></span>
                <?php endif; ?>
                <div class="so-header-modes">
                    <button type="button" class="so-mode-btn active"><?php esc_html_e('Paste MCP Config', 'e2mconnect'); ?></button>
                    <button type="button" class="so-mode-btn active-mcp"><?php esc_html_e('Connect via MCP', 'e2mconnect'); ?></button>
                </div>
            </div>
            <div class="so-header-status"><span class="dot"></span><?php esc_html_e('MCP ready', 'e2mconnect'); ?></div>
        </header>

        <!-- ═══ Body (Sidebar + Content) ═══ -->
        <div class="so-body">
            <!-- Sidebar -->
            <aside class="so-sidebar">
                <div class="so-sidebar-inner">
                    <nav class="so-sidebar-nav">
                        <?php
                        // E2M: section labels grouping the engine's tabs.
                        $e2m_nav_groups = [
                            'connect'      => __('Connect', 'e2mconnect'),
                            'ai-abilities' => __('Tools', 'e2mconnect'),
                            'activity'     => __('Insights', 'e2mconnect'),
                            'privacy'      => __('Settings', 'e2mconnect'),
                        ];
                        ?>
                        <?php foreach ($nav_items as $key => $item): ?>
                            <?php if (isset($e2m_nav_groups[$key])): ?>
                                <div class="so-sidebar-group"><?php echo esc_html($e2m_nav_groups[$key]); ?></div>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'e2mconnect', 'tab' => $key], admin_url('admin.php'))); ?>"
                               class="so-nav-item <?php echo $active_tab === $key ? 'active' : ''; ?>"
                               data-tab="<?php echo esc_attr($key); ?>">
                                <span class="so-nav-icon"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG icon markup from trusted internal data ?></span>
                                <?php echo esc_html($item['label']); ?>
                                <?php if (!empty($item['badge'])): ?>
                                    <span class="so-nav-badge"><?php echo esc_html($item['badge']); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </aside>
            <div class="so-sidebar-overlay" id="so-sidebar-overlay"></div>

            <!-- Content Panel -->
            <main class="so-content so-tab-<?php echo esc_attr($active_tab); ?>">
                <div class="so-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;">
                    <div>
                        <h1 class="so-page-title"><?php echo esc_html($page_meta[$active_tab]['title']); ?></h1>
                        <p class="so-page-desc"><?php echo esc_html($page_meta[$active_tab]['desc']); ?></p>
                    </div>
                    <?php if (in_array($active_tab, ['ai-abilities', 'sandbox', 'activity', 'memory'], true)):
                        $toggle_name = match($active_tab) {
                            'ai-abilities' => 'e2m_engine_ai_abilities_enabled',
                            'sandbox' => 'e2m_engine_sandbox_enabled',
                            'activity' => 'e2m_engine_analytics_enabled',
                            'memory' => 'memory_enabled',
                        };
                        $toggle_checked = match($active_tab) {
                            'ai-abilities' => e2m_engine_is_enabled(),
                            'sandbox' => $settings['sandbox_enabled'],
                            'activity' => $settings['analytics_enabled'] ?? true,
                            'memory' => function_exists('e2m_memory_is_enabled') ? e2m_memory_is_enabled() : true,
                        };
                    ?>
                        <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;margin-top:var(--so-space-1);">
                            <?php if ($active_tab === 'activity'): ?>
                                <?php $analytics_is_active = $settings['analytics_enabled'] ?? true; ?>
                                <!-- Settings button (visible only when analytics enabled) + Toggle -->
                                <div style="display:inline-flex;align-items:center;gap:8px;">
                                    <button type="button" id="so-analytics-settings-btn" class="so-btn so-btn-outline so-btn-sm" style="<?php echo $analytics_is_active ? '' : 'visibility:hidden;pointer-events:none;'; ?>" title="<?php esc_attr_e('Logging Settings', 'e2mconnect'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        <?php esc_html_e('Settings', 'e2mconnect'); ?>
                                    </button>
                                    <label class="so-toggle" id="so-analytics-toggle" aria-label="<?php esc_attr_e('Enable Analytics', 'e2mconnect'); ?>">
                                        <input type="checkbox" name="analytics_enabled" value="1" <?php checked($analytics_is_active); ?> />
                                        <span class="so-toggle-track"></span>
                                    </label>
                                </div>
                            <?php elseif ($active_tab === 'ai-abilities'): ?>
                                <div style="display:inline-flex;align-items:center;gap:8px;">
                                    <button type="button" id="so-ai-abilities-settings-btn" class="so-btn so-btn-outline so-btn-sm" title="<?php esc_attr_e('AI Abilities Settings', 'e2mconnect'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        <?php esc_html_e('Settings', 'e2mconnect'); ?>
                                    </button>
                                    <form method="post">
                                        <?php wp_nonce_field('e2m_engine_settings'); ?>
                                        <input type="hidden" name="e2m_engine_active_tab" class="e2m-mcp-active-tab-field" value="<?php echo esc_attr($active_tab); ?>" />
                                        <?php e2m_engine_render_hidden_settings($settings, $active_tab); ?>
                                        <label class="so-toggle" aria-label="<?php esc_attr_e('Enable AI Abilities', 'e2mconnect'); ?>">
                                            <input type="checkbox" name="<?php echo esc_attr($toggle_name); ?>" value="1" <?php checked($toggle_checked); ?> onchange="this.form.submit();" />
                                            <span class="so-toggle-track"></span>
                                        </label>
                                        <input type="hidden" name="e2m_engine_save_settings" value="1" />
                                    </form>
                                </div>
                            <?php elseif ($active_tab === 'memory'): ?>
                                <form method="post">
                                    <?php wp_nonce_field('e2m_memory_action'); ?>
                                    <input type="hidden" name="e2m_memory_action" value="toggle_enabled" />
                                    <label class="so-toggle" aria-label="<?php esc_attr_e('Enable AI Memory', 'e2mconnect'); ?>">
                                        <input type="checkbox" name="memory_enabled" value="1" <?php checked($toggle_checked); ?> onchange="this.form.submit();" />
                                        <span class="so-toggle-track"></span>
                                    </label>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <?php wp_nonce_field('e2m_engine_settings'); ?>
                                    <input type="hidden" name="e2m_engine_active_tab" class="e2m-mcp-active-tab-field" value="<?php echo esc_attr($active_tab); ?>" />
                                    <?php e2m_engine_render_hidden_settings($settings, $active_tab); ?>
                                    <label class="so-toggle" aria-label="<?php esc_attr_e('Enable Sandbox', 'e2mconnect'); ?>">
                                        <input type="checkbox" name="<?php echo esc_attr($toggle_name); ?>" value="1" <?php checked($toggle_checked); ?> onchange="this.form.submit();" />
                                        <span class="so-toggle-track"></span>
                                    </label>
                                    <input type="hidden" name="e2m_engine_save_settings" value="1" />
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($active_tab === 'activity'): ?>
                        <?php
                        $log_level = $settings['analytics_log_level'] ?? 'all';
                        $retention = $settings['analytics_retention_days'] ?? 30;
                        $max       = $settings['analytics_max_entries'] ?? 5000;
                        ?>
                        <!-- Logging Settings Modal (Figma 8135:20824) -->
                        <div id="so-analytics-settings-popup" class="so-modal-backdrop">
                            <div class="so-modal">
                                <!-- Header -->
                                <div class="so-modal-header">
                                    <span class="so-modal-title"><?php esc_html_e('Logging Settings', 'e2mconnect'); ?></span>
                                    <button type="button" id="so-analytics-settings-close" class="so-modal-close">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                                <!-- Body: Three dropdowns -->
                                <?php
                                $log_level_labels = ['all' => __('All requests', 'e2mconnect'), 'errors' => __('Errors only', 'e2mconnect'), 'off' => __('Off', 'e2mconnect')];
                                $retention_labels  = [7 => __('7 days', 'e2mconnect'), 14 => __('14 days', 'e2mconnect'), 30 => __('30 days', 'e2mconnect'), 90 => __('90 days', 'e2mconnect'), 0 => __('Forever', 'e2mconnect')];
                                $max_labels        = [500 => '500', 1000 => '1K', 2500 => '2.5K', 5000 => '5K', 10000 => '10K', 0 => __('No limit', 'e2mconnect')];
                                ?>
                                <div class="so-modal-body" style="flex-direction:row;gap:12px;">
                                    <!-- Log level -->
                                    <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                                        <label class="so-modal-label"><?php esc_html_e('Log level', 'e2mconnect'); ?></label>
                                        <input type="hidden" id="so-settings-log-level" value="<?php echo esc_attr($log_level); ?>" />
                                        <div class="so-dropdown so-modal-dropdown" data-input-id="so-settings-log-level" style="width:100%;">
                                            <button type="button" class="so-dropdown-trigger" style="width:100%;justify-content:space-between;">
                                                <span class="so-dropdown-label"><?php echo esc_html($log_level_labels[$log_level] ?? $log_level); ?></span>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                            </button>
                                            <div class="so-dropdown-menu">
                                                <?php foreach ($log_level_labels as $val => $lbl): ?>
                                                    <button type="button" class="so-dropdown-item<?php echo (string) $log_level === (string) $val ? ' active' : ''; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lbl); ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Retention -->
                                    <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                                        <label class="so-modal-label"><?php esc_html_e('Retention', 'e2mconnect'); ?></label>
                                        <input type="hidden" id="so-settings-retention" value="<?php echo esc_attr($retention); ?>" />
                                        <div class="so-dropdown so-modal-dropdown" data-input-id="so-settings-retention" style="width:100%;">
                                            <button type="button" class="so-dropdown-trigger" style="width:100%;justify-content:space-between;">
                                                <span class="so-dropdown-label"><?php echo esc_html($retention_labels[(int) $retention] ?? $retention); ?></span>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                            </button>
                                            <div class="so-dropdown-menu">
                                                <?php foreach ($retention_labels as $val => $lbl): ?>
                                                    <button type="button" class="so-dropdown-item<?php echo (int) $retention === (int) $val ? ' active' : ''; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lbl); ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Max entries -->
                                    <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                                        <label class="so-modal-label"><?php esc_html_e('Max entries', 'e2mconnect'); ?></label>
                                        <input type="hidden" id="so-settings-max-entries" value="<?php echo esc_attr($max); ?>" />
                                        <div class="so-dropdown so-modal-dropdown" data-input-id="so-settings-max-entries" style="width:100%;">
                                            <button type="button" class="so-dropdown-trigger" style="width:100%;justify-content:space-between;">
                                                <span class="so-dropdown-label"><?php echo esc_html($max_labels[(int) $max] ?? $max); ?></span>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                            </button>
                                            <div class="so-dropdown-menu">
                                                <?php foreach ($max_labels as $val => $lbl): ?>
                                                    <button type="button" class="so-dropdown-item<?php echo (int) $max === (int) $val ? ' active' : ''; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lbl); ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Footer -->
                                <div class="so-modal-footer">
                                    <button type="button" id="so-analytics-settings-cancel" class="so-btn so-btn-outline so-btn-sm"><?php esc_html_e('Cancel', 'e2mconnect'); ?></button>
                                    <button type="button" id="so-analytics-settings-save" class="so-btn so-btn-primary so-btn-sm"><?php esc_html_e('Save', 'e2mconnect'); ?></button>
                                </div>
                            </div>
                        </div>
                        <script>
                        (function() {
                            var btn = document.getElementById('so-analytics-settings-btn');
                            var popup = document.getElementById('so-analytics-settings-popup');
                            var closeBtn = document.getElementById('so-analytics-settings-close');
                            var cancelBtn = document.getElementById('so-analytics-settings-cancel');
                            var saveBtn = document.getElementById('so-analytics-settings-save');
                            var nonce = <?php echo wp_json_encode(wp_create_nonce('e2m_engine_save_setting')); ?>;

                            function openModal() { popup.classList.add('open'); }
                            function closeModal() {
                                popup.classList.remove('open');
                                popup.querySelectorAll('.so-dropdown.open').forEach(function(d) { d.classList.remove('open'); });
                            }

                            /* Open/close */
                            if (btn) btn.addEventListener('click', function(e) { e.stopPropagation(); openModal(); });
                            if (closeBtn) closeBtn.addEventListener('click', closeModal);
                            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
                            /* Close on backdrop click */
                            if (popup) popup.addEventListener('click', function(e) { if (e.target === popup) closeModal(); });
                            /* Close on Escape */
                            document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && popup.classList.contains('open')) closeModal(); });

                            /* Close modal dropdowns on outside click */
                            document.addEventListener('click', function() {
                                popup.querySelectorAll('.so-dropdown.open').forEach(function(d) { d.classList.remove('open'); });
                            });

                            /* Init modal dropdowns */
                            popup.querySelectorAll('.so-modal-dropdown').forEach(function(dd) {
                                var trigger = dd.querySelector('.so-dropdown-trigger');
                                var label = dd.querySelector('.so-dropdown-label');
                                var inputId = dd.getAttribute('data-input-id');
                                var hiddenInput = inputId ? document.getElementById(inputId) : null;

                                trigger.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    popup.querySelectorAll('.so-dropdown.open').forEach(function(other) {
                                        if (other !== dd) other.classList.remove('open');
                                    });
                                    dd.classList.toggle('open');
                                    /* Position the fixed dropdown menu relative to trigger */
                                    if (dd.classList.contains('open')) {
                                        var menu = dd.querySelector('.so-dropdown-menu');
                                        var rect = trigger.getBoundingClientRect();
                                        menu.style.top = (rect.bottom + 4) + 'px';
                                        menu.style.left = rect.left + 'px';
                                        menu.style.width = rect.width + 'px';
                                    }
                                });

                                dd.querySelectorAll('.so-dropdown-item').forEach(function(item) {
                                    item.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        dd.querySelectorAll('.so-dropdown-item').forEach(function(i) { i.classList.remove('active'); });
                                        item.classList.add('active');
                                        label.textContent = item.textContent;
                                        if (hiddenInput) hiddenInput.value = item.getAttribute('data-value');
                                        dd.classList.remove('open');
                                    });
                                });
                            });

                            /* Save via AJAX */
                            if (saveBtn) saveBtn.addEventListener('click', function() {
                                var settings = [
                                    { key: 'analytics_log_level', value: document.getElementById('so-settings-log-level').value },
                                    { key: 'analytics_retention_days', value: document.getElementById('so-settings-retention').value },
                                    { key: 'analytics_max_entries', value: document.getElementById('so-settings-max-entries').value }
                                ];
                                var saved = 0;
                                settings.forEach(function(s) {
                                    var data = new FormData();
                                    data.append('action', 'e2m_engine_save_setting');
                                    data.append('_ajax_nonce', nonce);
                                    data.append('key', s.key);
                                    data.append('value', s.value);
                                    fetch(ajaxurl, { method: 'POST', body: data }).then(function() {
                                        saved++;
                                        if (saved === settings.length) { closeModal(); location.reload(); }
                                    });
                                });
                            });

                            /* Analytics enable/disable toggle */
                            var toggle = document.querySelector('#so-analytics-toggle input[type="checkbox"]');
                            if (toggle) {
                                toggle.addEventListener('change', function() {
                                    var enabled = this.checked;
                                    var content = document.getElementById('so-analytics-content');
                                    var disabled = document.getElementById('so-analytics-disabled');
                                    if (btn) { btn.style.visibility = enabled ? '' : 'hidden'; btn.style.pointerEvents = enabled ? '' : 'none'; }
                                    if (content) content.classList.toggle('so-hidden', !enabled);
                                    if (disabled) disabled.classList.toggle('so-hidden', enabled);
                                    if (!enabled) closeModal();
                                    var data = new FormData();
                                    data.append('action', 'e2m_engine_save_setting');
                                    data.append('_ajax_nonce', nonce);
                                    data.append('key', 'analytics_enabled');
                                    data.append('value', enabled ? '1' : '0');
                                    fetch(ajaxurl, { method: 'POST', body: data });
                                });
                            }
                        })();
                        </script>
                    <?php endif; ?>

                    <?php /* Save button removed - settings auto-save via AJAX */ ?>
                </div>

                <?php
                // Show sandbox auto-disable notices (stored as transient).
                $sandbox_notices = get_transient('e2m_engine_sandbox_notices');
                if (is_array($sandbox_notices) && $sandbox_notices !== []) {
                    delete_transient('e2m_engine_sandbox_notices');
                    foreach ($sandbox_notices as $notice_msg) {
                        printf(
                            '<div class="so-inline-notice so-inline-notice-warning so-inline-notice--sandbox">'
                            . '<strong>%s</strong> %s</div>',
                            esc_html__('Sandbox:', 'e2mconnect'),
                            esc_html($notice_msg)
                        );
                    }
                }
                ?>

                <?php
                switch ($active_tab) {
                    case 'connect':
                        e2m_engine_render_connect_tab_content($settings);
                        break;
                    case 'ai-abilities':
                        e2m_engine_render_ai_abilities_tab_content($settings, $grouped_abilities, $total_abilities);
                        break;
                    case 'sandbox':
                        e2m_engine_render_sandbox_tab_content($settings);
                        break;
                    case 'activity':
                    case 'analytics': // Legacy - redirect to unified activity tab.
                        e2m_engine_render_activity_tab_content($settings);
                        break;
                    case 'privacy':
                        e2m_engine_render_privacy_tab_content($settings);
                        break;
                    case 'memory':
                    case 'agent_context': // back-compat alias
                        e2m_memory_admin_render_tab();
                        break;
                    default:
                        // E2M Connect tabs (onboarding, chat, agents, skills,
                        // health, settings) render their own content here so
                        // the whole plugin lives in this one hub screen.
                        do_action('e2m_connect_hub_render', $active_tab, $settings);
                        break;
                }
                ?>
            </main>
        </div>
    </div>

    <script>
    (function () {
        document.querySelectorAll('.e2m-mcp-active-tab-field').forEach(function (f) {
            f.value = <?php echo wp_json_encode($active_tab); ?>;
        });

        // -- Mobile hamburger menu --
        var hamburger = document.getElementById('so-hamburger');
        var sidebar = document.querySelector('.so-sidebar');
        var overlay = document.getElementById('so-sidebar-overlay');

        function openMenu() {
            sidebar.classList.add('open');
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeMenu() {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }

        if (hamburger) hamburger.addEventListener('click', function() {
            sidebar.classList.contains('open') ? closeMenu() : openMenu();
        });
        if (overlay) overlay.addEventListener('click', closeMenu);

        // Close menu on nav item click (mobile)
        document.querySelectorAll('.so-nav-item').forEach(function(item) {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 1080) closeMenu();
            });
        });

        // Close on resize back to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1080) closeMenu();
        });

        // -- Custom dropdowns (.so-dropdown). Global initializer so dropdowns
        // work on every tab (Memory, Webhooks, Analytics, etc.). Skips modal
        // dropdowns which are wired up separately by the modal's own script.
        // Per-dd opt-out from auto-submit via data-submit-on-change="false".
        document.querySelectorAll('.so-dropdown:not(.so-modal-dropdown)').forEach(function(dd) {
            if (dd.dataset.soDropdownInit === '1') return;
            dd.dataset.soDropdownInit = '1';

            var trigger = dd.querySelector('.so-dropdown-trigger');
            var label = dd.querySelector('.so-dropdown-label');
            var inputName = dd.getAttribute('data-input');
            var hiddenInput = inputName ? dd.parentElement.querySelector('input[name="' + inputName + '"]') : null;
            var form = dd.closest('form');
            var autoSubmit = dd.getAttribute('data-submit-on-change') !== 'false';

            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                document.querySelectorAll('.so-dropdown.open').forEach(function(other) {
                    if (other !== dd) other.classList.remove('open');
                });
                dd.classList.toggle('open');
            });

            dd.querySelectorAll('.so-dropdown-item').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    var val = item.getAttribute('data-value');
                    dd.querySelectorAll('.so-dropdown-item').forEach(function(i) { i.classList.remove('active'); });
                    item.classList.add('active');
                    label.textContent = item.textContent;
                    if (hiddenInput) hiddenInput.value = val;
                    dd.classList.remove('open');
                    if (autoSubmit && form) form.submit();
                });
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.so-dropdown')) {
                document.querySelectorAll('.so-dropdown.open').forEach(function(dd) { dd.classList.remove('open'); });
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.so-dropdown.open').forEach(function(dd) { dd.classList.remove('open'); });
            }
        });
    })();
    </script>
    <?php
}

/**
 * Render the Connect tab content (extracted from original connect page).
 */
function e2m_engine_render_connect_tab_content( array $settings = [] ): void
{
    // Delegate to existing connect page renderer, but we're already inside the wrap.
    e2m_engine_render_connect_page_inner();
}

/**
 * Render AI Abilities tab content.
 */
function e2m_engine_render_ai_abilities_tab_content(array $settings, array $grouped_abilities, int $total_abilities): void
{
    $enabled = e2m_engine_is_enabled();
    $disabled_list = $settings['disabled_abilities'];
    $external_summary = e2m_engine_get_external_abilities_summary($grouped_abilities);

    if (!$enabled) {
        ?>
        <div class="so-card" style="text-align:center;color:var(--so-text-secondary);">
            <span class="dashicons dashicons-warning" style="font-size:36px;display:block;margin:0 auto var(--so-space-3);color:var(--so-warning);"></span>
            <p style="font-size:var(--so-text-base);margin:0 0 var(--so-space-2);"><?php esc_html_e('AI Abilities are currently disabled.', 'e2mconnect'); ?></p>
            <p style="font-size:var(--so-text-sm);margin:0;"><?php esc_html_e('Enable the toggle in the top-right to configure individual abilities.', 'e2mconnect'); ?></p>
        </div>
        <?php
        return;
    }

    if (empty($grouped_abilities)) {
        ?>
        <div class="so-card"><p class="so-card-desc"><?php esc_html_e('No abilities registered yet.', 'e2mconnect'); ?></p></div>
        <?php
        return;
    }
    ?>
    <form method="post" id="e2m-mcp-abilities-form">
        <?php wp_nonce_field('e2m_engine_settings'); ?>
        <input type="hidden" name="e2m_engine_active_tab" class="e2m-mcp-active-tab-field" value="ai-abilities" />
        <?php e2m_engine_render_hidden_settings($settings, 'ai-abilities'); ?>

        <?php if ($external_summary['total'] > 0): ?>
            <div class="so-card" style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                    <div>
                        <h3 class="so-card-title" style="margin-bottom:6px;"><?php esc_html_e('Other Tools', 'e2mconnect'); ?></h3>
                        <p class="so-card-desc" style="margin:0;">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: number of abilities, 2: number of plugins. */
                                    __('E2M detected %1$d abilities registered by %2$d other plugins. They are listed below with the rest of your tools.', 'e2mconnect'),
                                    $external_summary['total'],
                                    count($external_summary['sources'])
                                )
                            );
                            ?>
                        </p>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($external_summary['sources'] as $source): ?>
                            <span class="e2m-ability-tag" style="background:var(--so-tag-bg);color:var(--so-tag-fg);border-color:var(--so-tag-border);">
                                <?php echo esc_html($source['name'] . ' (' . $source['count'] . ')'); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Safe Mode Banner -->
        <?php $is_safe_mode = e2m_engine_is_safe_mode(); ?>
        <div style="margin-bottom:var(--so-space-4);padding:12px;display:flex;align-items:center;justify-content:space-between;gap:var(--so-space-2);background:<?php echo $is_safe_mode ? 'var(--so-success-lightest)' : 'var(--so-bg-white)'; ?>;border:1px solid <?php echo $is_safe_mode ? 'var(--so-success-light)' : 'var(--so-border-weak)'; ?>;border-radius:12px;overflow:hidden;">
            <div style="display:flex;align-items:flex-start;gap:8px;flex:1;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?php echo $is_safe_mode ? 'var(--so-success-dark)' : 'var(--so-text-primary)'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 22V2"/></svg>
                <div style="display:flex;flex-direction:column;gap:2px;">
                    <span style="font-size:var(--so-text-base);font-weight:var(--so-weight-medium);line-height:20px;color:<?php echo $is_safe_mode ? 'var(--so-success-dark)' : 'var(--so-text-primary)'; ?>;"><?php esc_html_e('Safe Mode', 'e2mconnect'); ?></span>
                    <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-normal);line-height:16px;color:<?php echo $is_safe_mode ? 'var(--so-success-dark)' : 'var(--so-text-secondary)'; ?>;">
                        <?php if ($is_safe_mode): ?>
                            <?php esc_html_e('Only read-only abilities are active. AI cannot modify, create, or delete anything on your site.', 'e2mconnect'); ?>
                        <?php else: ?>
                            <?php esc_html_e('Enable to restrict AI to read-only abilities only. No changes can be made to your site.', 'e2mconnect'); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <button type="button" id="e2m-mcp-safe-mode-btn" class="so-btn <?php echo $is_safe_mode ? 'so-btn-destructive' : 'so-btn-primary'; ?>" style="height:32px;padding:7px 16px;border-radius:8px;font-size:var(--so-text-label);font-weight:var(--so-weight-normal);line-height:16px;flex-shrink:0;" data-active="<?php echo $is_safe_mode ? '1' : '0'; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 22V2"/></svg>
                <?php echo $is_safe_mode ? esc_html__('Disable Safe Mode', 'e2mconnect') : esc_html__('Enable Safe Mode', 'e2mconnect'); ?>
            </button>
        </div>

        <script>
        (function() {
            var btn = document.getElementById('e2m-mcp-safe-mode-btn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var isActive = btn.getAttribute('data-active') === '1';
                var action = isActive ? 'disable' : 'enable';
                var msg = isActive
                    ? <?php echo wp_json_encode(__('Disable Safe Mode and restore previous ability settings?', 'e2mconnect')); ?>
                    : <?php echo wp_json_encode(__('Enable Safe Mode? This will disable all non-read-only abilities. Your current settings will be saved and can be restored later.', 'e2mconnect')); ?>;
                if (!confirm(msg)) return;
                btn.disabled = true;
                btn.textContent = <?php echo wp_json_encode(__('Saving...', 'e2mconnect')); ?>;
                var data = new FormData();
                data.append('action', 'e2m_engine_toggle_safe_mode');
                data.append('enable', isActive ? '0' : '1');
                data.append('_wpnonce', <?php echo wp_json_encode(wp_create_nonce('e2m_engine_safe_mode')); ?>);
                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (r.success) { location.reload(); }
                        else { alert(r.data || <?php echo wp_json_encode(__('Error', 'e2mconnect')); ?>); btn.disabled = false; }
                    })
                    .catch(function() { alert(<?php echo wp_json_encode(__('Network error. Please try again.', 'e2mconnect')); ?>); btn.disabled = false; });
            });
        })();
        </script>

        <div class="so-card" style="padding:0;border:none;box-shadow:none;background:transparent;">

            <!-- Toolbar: Plugin Filter + Search -->
            <?php
            // Build unique plugin list for the filter dropdown.
            $filter_plugins = [];
            foreach ($grouped_abilities as $grp_name => $grp_abs) {
                foreach ($grp_abs as $_ab) {
                    $s = $_ab['source'] ?? '';
                    if ($s !== '' && !isset($filter_plugins[$s])) {
                        $filter_plugins[$s] = e2m_engine_get_plugin_info($s);
                    }
                }
            }
            ksort($filter_plugins);

            // Build usage counts from analytics (if enabled).
            $analytics_on = $settings['analytics_enabled'] ?? false;
            $usage_counts = [];
            if ($analytics_on && class_exists('E2M_Engine_Analytics')) {
                global $wpdb;
                $log_table = $wpdb->prefix . 'e2m_engine_logs';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) )) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $raw = $wpdb->get_results( $wpdb->prepare( 'SELECT ability_name, COUNT(*) as cnt FROM %i WHERE ability_name IS NOT NULL GROUP BY ability_name', $log_table ) );
                    $log_map = [];
                    foreach ($raw as $r) { $log_map[$r->ability_name] = (int) $r->cnt; }
                    foreach ($grouped_abilities as $_grp) {
                        foreach ($_grp as $_a) {
                            $log_key = str_replace('/', '-', $_a['name']);
                            $usage_counts[$_a['name']] = $log_map[$log_key] ?? 0;
                        }
                    }
                }
            }

            // Count annotations across all abilities.
            $anno_totals = ['readonly' => 0, 'destructive' => 0, 'additive' => 0];
            foreach ($grouped_abilities as $_grp) {
                foreach ($_grp as $_a) {
                    $ann = $_a['annotations'] ?? [];
                    if (!empty($ann['destructive'])) $anno_totals['destructive']++;
                    elseif (!empty($ann['readonly'])) $anno_totals['readonly']++;
                    elseif (isset($ann['readonly']) && $ann['readonly'] === false && isset($ann['destructive']) && $ann['destructive'] === false) $anno_totals['additive']++;
                }
            }
            ?>

            <!-- Filter Pills -->
            <?php
            // Collect unique tags across all abilities for filter pills.
            $all_tags = [];
            foreach ($grouped_abilities as $_grp) {
                foreach ($_grp as $_a) {
                    foreach ($_a['tags'] ?? [] as $_t) {
                        $lbl = $_t['label'];
                        if (!isset($all_tags[$lbl])) {
                            $all_tags[$lbl] = [
                                'label'  => $lbl,
                                'color'  => $_t['color'],
                                'bg'     => $_t['bg'] ?? ($_t['color'] . '18'),
                                'border' => $_t['border'] ?? ($_t['color'] . '40'),
                                'count'  => 0,
                            ];
                        }
                        $all_tags[$lbl]['count']++;
                    }
                }
            }
            uasort($all_tags, function($a, $b) { return $b['count'] - $a['count']; });
            ?>
            <div class="so-filter-pills" id="e2m-mcp-filter-pills">
                <span class="so-filter-pills-label"><?php esc_html_e('Filter:', 'e2mconnect'); ?></span>
                <?php foreach ($all_tags as $tag_label => $tag_info): ?>
                    <span class="so-filter-pill" data-tag="<?php echo esc_attr(strtolower($tag_label)); ?>" style="color:<?php echo esc_attr($tag_info['color']); ?>;background:<?php echo esc_attr($tag_info['bg']); ?>;border-color:<?php echo esc_attr($tag_info['border']); ?>;"><?php echo esc_html($tag_label); ?></span>
                <?php endforeach; ?>
            </div>

            <!-- Vertical Tabs: Left Nav + Right Panel -->
            <?php
            $group_sources = e2m_engine_get_group_sources($grouped_abilities);
            $own_slug = basename(E2M_ENGINE_PLUGIN_DIR);
            $group_index = 0;
            ?>
            <div class="so-abilities-split" id="e2m-mcp-ability-groups">
                <!-- Left: Plugin Nav -->
                <div class="so-abilities-nav">
                    <?php foreach ($grouped_abilities as $prefix => $abilities):
                        $grp_src_slug = $group_sources[$prefix] ?? '';
                        // Compute enabled count
                        $grp_enabled = 0;
                        foreach ($abilities as $_a) {
                            if (!in_array($_a['name'], $disabled_list, true)) $grp_enabled++;
                        }
                        // Compute tag breakdown for nav pills
                        $grp_tag_map = [];
                        foreach ($abilities as $_a) {
                            foreach ($_a['tags'] ?? [] as $_t) {
                                $lbl = $_t['label'];
                                if (!isset($grp_tag_map[$lbl])) $grp_tag_map[$lbl] = ['count' => 0, 'color' => $_t['color'], 'bg' => $_t['bg'] ?? ($_t['color'] . '18'), 'border' => $_t['border'] ?? ($_t['color'] . '40')];
                                $grp_tag_map[$lbl]['count']++;
                            }
                        }
                        uasort($grp_tag_map, function($a, $b) { return $b['count'] - $a['count']; });
                    ?>
                        <div class="so-abilities-nav-item<?php echo $group_index === 0 ? ' active' : ''; ?>" data-group="<?php echo esc_attr($prefix); ?>" data-group-source="<?php echo esc_attr($grp_src_slug); ?>">
                            <div class="so-abilities-nav-item-header">
                                <span class="so-abilities-nav-item-name"><?php echo esc_html($prefix); ?></span>
                                <span class="so-abilities-nav-item-count e2m-group-count"><?php echo (int) $grp_enabled < count($abilities) ? (int) $grp_enabled . '/' . (int) count($abilities) : (int) count($abilities) . '/' . (int) count($abilities); ?></span>
                            </div>
                            <?php if (!empty($grp_tag_map)): ?>
                                <div class="so-abilities-nav-item-tags">
                                    <?php foreach (array_slice($grp_tag_map, 0, 3, true) as $t_label => $t_info): ?>
                                        <span class="e2m-ability-tag so-ability-tag" style="background:<?php echo esc_attr($t_info['bg']); ?>;color:<?php echo esc_attr($t_info['color']); ?>;border-color:<?php echo esc_attr($t_info['border']); ?>;"><?php echo esc_html($t_label); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php $group_index++; endforeach; ?>
                </div>

                <!-- Right: Ability Panels -->
                <?php $group_index = 0; foreach ($grouped_abilities as $prefix => $abilities):
                    $grp_src_slug = $group_sources[$prefix] ?? '';
                    // Tag breakdown with counts for panel header
                    $grp_tag_map = [];
                    foreach ($abilities as $_a) {
                        foreach ($_a['tags'] ?? [] as $_t) {
                            $lbl = $_t['label'];
                            if (!isset($grp_tag_map[$lbl])) $grp_tag_map[$lbl] = ['count' => 0, 'color' => $_t['color'], 'bg' => $_t['bg'] ?? ($_t['color'] . '18'), 'border' => $_t['border'] ?? ($_t['color'] . '40')];
                            $grp_tag_map[$lbl]['count']++;
                        }
                    }
                    uasort($grp_tag_map, function($a, $b) { return $b['count'] - $a['count']; });
                    $grp_enabled = 0;
                    $grp_selectable_count = 0;
                    $grp_enabled_selectable = 0;
                    foreach ($abilities as $_a) {
                        $ability_enabled = !in_array($_a['name'], $disabled_list, true);
                        if ($ability_enabled) {
                            $grp_enabled++;
                        }

                        if (!$is_safe_mode) {
                            $grp_selectable_count++;
                            if ($ability_enabled) {
                                $grp_enabled_selectable++;
                            }
                            continue;
                        }

                        if (e2m_engine_is_ability_readonly($_a['name'])) {
                            $grp_selectable_count++;
                            // In safe mode, read-only abilities are treated as selected.
                            $grp_enabled_selectable++;
                        }
                    }
                ?>
                    <div class="so-abilities-panel<?php echo $group_index === 0 ? ' active' : ''; ?>" data-group="<?php echo esc_attr($prefix); ?>" data-group-source="<?php echo esc_attr($grp_src_slug); ?>">
                        <!-- Panel Header -->
                        <div class="so-abilities-panel-header">
                            <span class="so-abilities-panel-title"><?php echo esc_html($prefix); ?></span>
                            <span class="so-abilities-panel-pills">
                                <?php $pill_shown = 0; foreach ($grp_tag_map as $t_label => $t_info):
                                    if ($pill_shown >= 2) break;
                                ?>
                                    <span class="so-abilities-panel-pill" style="color:<?php echo esc_attr($t_info['color']); ?>;border-color:<?php echo esc_attr($t_info['border']); ?>;background:<?php echo esc_attr($t_info['bg']); ?>;"><?php echo (int) $t_info['count']; ?> <?php echo esc_html($t_label); ?></span>
                                <?php $pill_shown++; endforeach; ?>
                                <?php if (count($grp_tag_map) > 2): ?>
                                    <span class="so-abilities-panel-more">+<?php echo count($grp_tag_map) - 2; ?> <?php esc_html_e('more', 'e2mconnect'); ?></span>
                                <?php endif; ?>
                            </span>
                            <label class="so-abilities-panel-select-all">
                                <input type="checkbox" class="e2m-mcp-group-toggle" data-group="<?php echo esc_attr($prefix); ?>" <?php checked($grp_selectable_count > 0 && $grp_enabled_selectable === $grp_selectable_count); ?> <?php disabled($is_safe_mode); ?> />
                                <?php echo $is_safe_mode ? esc_html__('Locked in Safe Mode', 'e2mconnect') : esc_html__('Enable All', 'e2mconnect'); ?>
                            </label>
                        </div>

                        <!-- Search -->
                        <div class="so-abilities-search-wrap">
                            <svg class="so-abilities-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                            <input type="search" class="so-input e2m-mcp-panel-search" style="max-width:100%;padding-left:38px;" placeholder="<?php esc_attr_e('Search', 'e2mconnect'); ?>" />
                        </div>

                        <!-- Abilities List -->
                        <div class="so-abilities-list">
                            <?php foreach ($abilities as $ab):
                                $is_disabled = in_array($ab['name'], $disabled_list, true);
                                $is_readonly = e2m_engine_is_ability_readonly($ab['name']);
                                $is_locked_by_safe_mode = $is_safe_mode;
                                $is_checked = $is_safe_mode ? $is_readonly : !$is_disabled;
                                $ab_tags = $ab['tags'] ?? [];
                                $tag_search = '';
                                foreach ($ab_tags as $t) { $tag_search .= ' ' . strtolower($t['label']); }
                                $tag_json_items = [];
                                foreach ($ab_tags as $t) { $tag_json_items[] = ['label' => $t['label'], 'color' => $t['color']]; }
                                $ab_ann = $ab['annotations'] ?? [];
                                if (!empty($ab_ann['destructive'])) { $ab_anno_class = 'destructive'; }
                                elseif (!empty($ab_ann['readonly'])) { $ab_anno_class = 'readonly'; }
                                elseif (isset($ab_ann['readonly']) && $ab_ann['readonly'] === false && isset($ab_ann['destructive']) && $ab_ann['destructive'] === false) { $ab_anno_class = 'additive'; }
                                else { $ab_anno_class = 'unknown'; }
                                $ab_usage = $usage_counts[$ab['name']] ?? -1;
                                if ($ab_usage >= 20) { $ab_usage_class = 'most'; }
                                elseif ($ab_usage >= 4) { $ab_usage_class = 'moderate'; }
                                elseif ($ab_usage >= 1) { $ab_usage_class = 'rarely'; }
                                elseif ($ab_usage === 0) { $ab_usage_class = 'never'; }
                                else { $ab_usage_class = ''; }
                            ?>
                                <label class="so-abilities-list-item e2m-mcp-ability-item" data-ability="<?php echo esc_attr(strtolower($ab['name'] . ' ' . $ab['label'] . $tag_search)); ?>" data-anno="<?php echo esc_attr($ab_anno_class); ?>" data-usage="<?php echo esc_attr($ab_usage_class); ?>" data-tags-lower="<?php echo esc_attr($tag_search); ?>">
                                    <input type="hidden" name="e2m_engine_all_abilities[]" value="<?php echo esc_attr($ab['name']); ?>" />
                                    <input type="checkbox" name="e2m_engine_ability[]" value="<?php echo esc_attr($ab['name']); ?>" class="e2m-mcp-ability-cb" data-group="<?php echo esc_attr($prefix); ?>" data-readonly="<?php echo $is_readonly ? '1' : '0'; ?>" data-tags="<?php echo esc_attr(wp_json_encode($tag_json_items)); ?>" <?php checked($is_checked); ?> <?php disabled($is_locked_by_safe_mode); ?> />
                                    <span class="so-abilities-list-item-name"><?php echo esc_html($ab['name']); ?></span>
                                    <?php if ($ab['label'] !== $ab['name']): ?>
                                        <span class="so-abilities-list-item-label">&mdash; <?php echo esc_html($ab['label']); ?></span>
                                    <?php endif; ?>
                                    <?php
                                    $source_slug = $ab['source'] ?? '';
                                    if ($source_slug !== '' && $source_slug !== basename(E2M_ENGINE_PLUGIN_DIR)):
                                        $source_info = e2m_engine_get_plugin_info($source_slug);
                                    ?>
                                        <span class="so-abilities-list-item-label" style="color:var(--so-tag-muted);">
                                            &mdash; <?php /* translators: %s: plugin source name */ echo esc_html(sprintf(__('Source: %s', 'e2mconnect'), $source_info['name'])); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($ab_tags)):
                                        $primary_tag = $ab_tags[0];
                                        $pt_bg = $primary_tag['bg'] ?? ($primary_tag['color'] . '18');
                                        $pt_border = $primary_tag['border'] ?? ($primary_tag['color'] . '40');
                                    ?>
                                        <span class="so-abilities-list-item-tag">
                                            <span class="e2m-ability-tag so-ability-tag" style="background:<?php echo esc_attr($pt_bg); ?>;color:<?php echo esc_attr($primary_tag['color']); ?>;border-color:<?php echo esc_attr($pt_border); ?>;" title="<?php echo esc_attr($primary_tag['label']); ?>"><?php echo esc_html($primary_tag['label']); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php $group_index++; endforeach; ?>
            </div>
        </div>
    </form>

    <!-- AI Permissions Settings Modal -->
    <div id="so-ai-abilities-settings-popup" class="so-modal-backdrop">
        <div class="so-modal" style="max-width:640px;width:100%;">
            <div class="so-modal-header">
                <span class="so-modal-title"><?php esc_html_e('AI Abilities', 'e2mconnect'); ?></span>
                <button type="button" id="so-ai-abilities-settings-close" class="so-modal-close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="so-modal-body" style="flex-direction:column;gap:0;padding:0;overflow-y:auto;max-height:70vh;">
                <?php e2m_engine_render_safety_tab_content($settings); ?>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var nonce = <?php echo wp_json_encode(wp_create_nonce('e2m_engine_toggle_ability')); ?>;

        // -- AJAX save helper ----------------------------------------
        function saveAbility(abilityName, enabled) {
            var data = new FormData();
            data.append('action', 'e2m_engine_toggle_ability');
            data.append('ability', abilityName);
            data.append('enable', enabled ? '1' : '0');
            data.append('_wpnonce', nonce);
            fetch(ajaxurl, { method: 'POST', body: data });
        }

        function saveBulkAbilities(abilityNames, enabled) {
            var data = new FormData();
            data.append('action', 'e2m_engine_bulk_toggle_abilities');
            data.append('enable', enabled ? '1' : '0');
            data.append('_wpnonce', nonce);
            abilityNames.forEach(function (n) { data.append('abilities[]', n); });
            fetch(ajaxurl, { method: 'POST', body: data });
        }

        // -- Vertical Tab Switching -----------------------------------
        var navItems = document.querySelectorAll('.so-abilities-nav-item');
        var panels   = document.querySelectorAll('.so-abilities-panel');

        navItems.forEach(function (nav) {
            nav.addEventListener('click', function () {
                var group = nav.getAttribute('data-group');
                navItems.forEach(function (n) { n.classList.remove('active'); });
                panels.forEach(function (p) { p.classList.remove('active'); });
                nav.classList.add('active');
                var target = document.querySelector('.so-abilities-panel[data-group="' + group + '"]');
                if (target) target.classList.add('active');
            });
        });

        // -- Filter Pills --------------------------------------------
        var filterPills = document.querySelectorAll('.so-filter-pill');
        var activeFilter = '';

        filterPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                var tag = pill.getAttribute('data-tag');
                if (activeFilter === tag) {
                    activeFilter = '';
                    filterPills.forEach(function (p) { p.classList.remove('dimmed'); p.classList.remove('active'); });
                } else {
                    activeFilter = tag;
                    filterPills.forEach(function (p) {
                        if (p.getAttribute('data-tag') === tag) {
                            p.classList.add('active');
                            p.classList.remove('dimmed');
                        } else {
                            p.classList.add('dimmed');
                            p.classList.remove('active');
                        }
                    });
                }
                applyAbilityFilters();
            });
        });

        // -- Per-Panel Search ----------------------------------------
        document.querySelectorAll('.e2m-mcp-panel-search').forEach(function (input) {
            input.addEventListener('input', function () {
                applyAbilityFilters();
            });
        });

        // -- Central filter function ---------------------------------
        function applyAbilityFilters() {
            panels.forEach(function (panel) {
                var searchInput = panel.querySelector('.e2m-mcp-panel-search');
                var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
                var items = panel.querySelectorAll('.e2m-mcp-ability-item');
                items.forEach(function (item) {
                    var ok = true;
                    if (q && (item.getAttribute('data-ability') || '').indexOf(q) === -1) ok = false;
                    if (activeFilter && (item.getAttribute('data-tags-lower') || '').indexOf(activeFilter) === -1) ok = false;
                    item.style.display = ok ? '' : 'none';
                });
            });

            navItems.forEach(function (nav) {
                var group = nav.getAttribute('data-group');
                var panel = document.querySelector('.so-abilities-panel[data-group="' + group + '"]');
                if (!panel) return;
                var vis = panel.querySelectorAll('.e2m-mcp-ability-item:not([style*="display: none"])');
                nav.style.display = (activeFilter && vis.length === 0) ? 'none' : '';
            });
        }

        // -- Update enabled count ------------------------------------
        function updateGroupCount(group) {
            var panel = document.querySelector('.so-abilities-panel[data-group="' + group + '"]');
            var nav = document.querySelector('.so-abilities-nav-item[data-group="' + group + '"]');
            if (!panel || !nav) return;
            var cbs = panel.querySelectorAll('.e2m-mcp-ability-cb');
            var en = Array.from(cbs).filter(function (cb) { return cb.checked; }).length;
            var countEl = nav.querySelector('.e2m-group-count');
            if (countEl) countEl.textContent = en + '/' + cbs.length;
        }

        // -- Select All toggles (with auto-save) --------------------
        document.querySelectorAll('.e2m-mcp-group-toggle').forEach(function (toggle) {
            var group = toggle.getAttribute('data-group');
            var panel = document.querySelector('.so-abilities-panel[data-group="' + group + '"]');
            if (!panel) return;
            var cbs = panel.querySelectorAll('.e2m-mcp-ability-cb');

            toggle.addEventListener('change', function () {
                if (toggle.disabled) {
                    return;
                }
                var names = [];
                Array.from(cbs).filter(function (cb) { return !cb.disabled; }).forEach(function (cb) {
                    cb.checked = toggle.checked;
                    names.push(cb.value);
                });
                updateGroupCount(group);
                if (names.length > 0) {
                    saveBulkAbilities(names, toggle.checked);
                }
            });

            cbs.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var selectable = Array.from(cbs).filter(function (c) { return !c.disabled; });
                    toggle.checked = selectable.length > 0 && selectable.every(function (c) { return c.checked; });
                    updateGroupCount(group);
                    saveAbility(cb.value, cb.checked);
                });
            });
        });

        /* AI Permissions Settings Modal */
        (function () {
            var popup = document.getElementById('so-ai-abilities-settings-popup');
            var openBtn = document.getElementById('so-ai-abilities-settings-btn');
            var closeBtn = document.getElementById('so-ai-abilities-settings-close');
            if (!popup) return;
            function openModal() { popup.classList.add('open'); }
            function closeModal() { popup.classList.remove('open'); }
            if (openBtn) openBtn.addEventListener('click', function (e) { e.stopPropagation(); openModal(); });
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            popup.addEventListener('click', function (e) { if (e.target === popup) closeModal(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && popup.classList.contains('open')) closeModal(); });
        })();
    })();
    </script>
    <?php
}

/**
 * AJAX handler: Toggle Safe Mode on/off.
 */
function e2m_engine_ajax_toggle_safe_mode(): void
{
    check_ajax_referer('e2m_engine_safe_mode');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'e2mconnect'), 403);
    }

    $enable = (bool) sanitize_text_field(wp_unslash($_POST['enable'] ?? ''));
    e2m_engine_toggle_safe_mode($enable);

    wp_send_json_success([
        'safe_mode' => $enable,
        'message'   => $enable
            ? __('Safe Mode enabled - only read-only abilities are active.', 'e2mconnect')
            : __('Safe Mode disabled - previous ability settings restored.', 'e2mconnect'),
    ]);
}

/**
 * AJAX handler: Toggle a single ability on/off (auto-save).
 */
function e2m_engine_ajax_toggle_ability(): void
{
    check_ajax_referer('e2m_engine_toggle_ability');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'e2mconnect'), 403);
    }

    $ability = sanitize_text_field(wp_unslash($_POST['ability'] ?? ''));
    $enable  = (bool) sanitize_text_field(wp_unslash($_POST['enable'] ?? ''));

    if ($ability === '') {
        wp_send_json_error(__('Ability name is required.', 'e2mconnect'));
    }

    if (e2m_engine_is_safe_mode()) {
        wp_send_json_error(__('Safe Mode is active. Disable Safe Mode to change ability selections.', 'e2mconnect'), 409);
    }

    $settings = e2m_engine_get_settings(true);
    $disabled = $settings['disabled_abilities'] ?? [];

    if ($enable) {
        $disabled = array_values(array_diff($disabled, [$ability]));
    } else {
        if (!in_array($ability, $disabled, true)) {
            $disabled[] = $ability;
        }
    }

    $saved = get_option('e2m_engine_settings', []);
    $saved['disabled_abilities'] = $disabled;
    update_option('e2m_engine_settings', $saved);

    wp_send_json_success(['ability' => $ability, 'enabled' => $enable]);
}

/**
 * AJAX handler: Bulk toggle abilities (Select All / Deselect All).
 */
function e2m_engine_ajax_bulk_toggle_abilities(): void
{
    check_ajax_referer('e2m_engine_toggle_ability');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'e2mconnect'), 403);
    }

    $abilities = isset($_POST['abilities']) && is_array($_POST['abilities'])
        ? array_map('sanitize_text_field', wp_unslash($_POST['abilities']))
        : [];
    $enable = (bool) sanitize_text_field(wp_unslash($_POST['enable'] ?? ''));

    if (empty($abilities)) {
        wp_send_json_error(__('No abilities provided.', 'e2mconnect'));
    }

    if (e2m_engine_is_safe_mode()) {
        wp_send_json_error(__('Safe Mode is active. Disable Safe Mode to change ability selections.', 'e2mconnect'), 409);
    }

    $saved = get_option('e2m_engine_settings', []);
    $disabled = $saved['disabled_abilities'] ?? [];

    if ($enable) {
        $disabled = array_values(array_diff($disabled, $abilities));
    } else {
        foreach ($abilities as $ab) {
            if (!in_array($ab, $disabled, true)) {
                $disabled[] = $ab;
            }
        }
        $disabled = array_values($disabled);
    }

    $saved['disabled_abilities'] = $disabled;
    update_option('e2m_engine_settings', $saved);

    wp_send_json_success(['count' => count($abilities), 'enabled' => $enable]);
}

/**
 * AJAX handler: Save a single privacy/settings field (auto-save).
 */
function e2m_engine_ajax_save_setting(): void
{
    check_ajax_referer('e2m_engine_save_setting');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'e2mconnect'), 403);
    }

    $key   = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
    $value = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));

    if ($key === '') {
        wp_send_json_error(__('Setting key is required.', 'e2mconnect'));
    }

    // Whitelist of allowed setting keys
    $allowed_bool = [
        'analytics_enabled', 'analytics_store_request', 'analytics_store_response',
        'analytics_notify_enabled', 'analytics_store_ip', 'analytics_anonymize_ip',
        'analytics_store_user_identity', 'webhook_enabled',
    ];
    $allowed_string = [
        'analytics_retention_days', 'analytics_log_level', 'analytics_max_entries',
        'analytics_notify_frequency', 'analytics_notify_email',
        'webhook_url', 'webhook_events', 'webhook_secret',
    ];
    $allowed_si = [
        'e2m_engine_si_wp_version', 'e2m_engine_si_php_version',
        'e2m_engine_si_theme_info', 'e2m_engine_si_plugins_list',
        'e2m_engine_si_elementor_version',
    ];

    $saved = get_option('e2m_engine_settings', []);

    if (in_array($key, $allowed_bool, true)) {
        $saved[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    } elseif (in_array($key, $allowed_string, true)) {
        if ($key === 'webhook_url') {
            $saved[$key] = esc_url_raw($value);
        } elseif (in_array($key, ['analytics_retention_days', 'analytics_max_entries'], true)) {
            $saved[$key] = (int) $value;
        } else {
            $saved[$key] = sanitize_text_field($value);
        }
    } elseif (in_array($key, $allowed_si, true)) {
        $si_key = str_replace('e2m_engine_si_', '', $key);
        if (!isset($saved['server_instructions']) || !is_array($saved['server_instructions'])) {
            $saved['server_instructions'] = [];
        }
        $saved['server_instructions'][$si_key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    } else {
        wp_send_json_error(__('Unknown setting key.', 'e2mconnect'));
    }

    update_option('e2m_engine_settings', $saved);
    e2m_engine_get_settings(true);

    wp_send_json_success(['key' => $key, 'saved' => true]);
}

/**
 * AJAX handler: Send test webhook.
 */
function e2m_engine_ajax_test_webhook(): void
{
    check_ajax_referer('e2m_engine_test_webhook');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'e2mconnect'), 403);
    }

    $url    = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
    $secret = sanitize_text_field(wp_unslash($_POST['secret'] ?? ''));

    if ($url === '') {
        wp_send_json_error(__('Webhook URL is required.', 'e2mconnect'));
    }

    $result = E2M_Engine_Analytics::send_test_webhook($url, $secret);
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * Render Sandbox tab content with enable/disable toggle at top.
 */
function e2m_engine_render_sandbox_tab_content(array $settings): void
{
    ?>
    <?php
    if (!$settings['sandbox_enabled']) {
        ?>
        <div class="so-card" style="text-align:center;color:var(--so-text-secondary);">
            <span class="dashicons dashicons-editor-code" style="font-size:36px;display:block;margin:0 auto var(--so-space-3);color:var(--so-warning);opacity:0.6;"></span>
            <p style="font-size:var(--so-text-base);margin:0 0 var(--so-space-2);"><?php esc_html_e('Sandbox is disabled.', 'e2mconnect'); ?></p>
            <p style="font-size:var(--so-text-sm);margin:0;"><?php esc_html_e('Enable the toggle in the top-right to manage sandbox files.', 'e2mconnect'); ?></p>
        </div>
        <?php
    } else {
        e2m_engine_render_sandbox_page_inner();
    }
}

/**
 * Render the Activity Feed tab - session-grouped, human-readable log.
 */
function e2m_engine_render_activity_tab_content(array $settings): void
{
    $is_enabled = $settings['analytics_enabled'] ?? true;

    // Disabled placeholder - shown when analytics is off
    ?>
    <div id="so-analytics-disabled" class="so-card so-analytics-empty-state <?php echo $is_enabled ? 'so-hidden' : ''; ?>">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 12px;opacity:0.3;"><path d="M3 3l18 18"/><path d="M10.5 10.677a2 2 0 0 0 2.823 2.823"/><path d="M7.362 7.561C5.68 8.74 4.279 10.42 3 12c1.889 2.991 5.282 6 9 6 1.55 0 3.043-.523 4.395-1.35"/><path d="M12 6c3.718 0 7.111 3.009 9 6-.947 1.498-2.057 2.822-3.273 3.823"/></svg>
        <p style="font-size:var(--so-text-base);margin:0 0 var(--so-space-2);font-weight:500;color:var(--so-text-default);"><?php esc_html_e('Analytics is disabled', 'e2mconnect'); ?></p>
        <p style="font-size:var(--so-text-sm);margin:0;color:var(--so-text-tertiary);"><?php esc_html_e('Enable the toggle above to start tracking AI activity, usage stats, and detailed logs.', 'e2mconnect'); ?></p>
    </div>
    <?php

    // Enabled content - always rendered so toggle can show it without reload
    $summary    = E2M_Engine_Analytics::get_summary();
    $table_size = E2M_Engine_Analytics::get_table_size();

    $retention_days = $settings['analytics_retention_days'] ?? 30;
    $log_level_display = ucfirst($settings['analytics_log_level'] ?? 'all');
    $unique_users = $summary['unique_users'] ?? 0;
    ?>
    <!-- -- Analytics Content (hidden when disabled) ---------------- -->
    <div id="so-analytics-content" class="<?php echo $is_enabled ? '' : 'so-hidden'; ?>">
    <!-- -- Stat Cards ---------------------------------------------- -->
    <div style="display:flex;gap:12px;margin-bottom:16px;">
        <div class="so-stat-card">
            <div class="so-stat-value"><?php echo esc_html(number_format_i18n($summary['total'])); ?></div>
            <div class="so-stat-label"><?php esc_html_e('Total Calls', 'e2mconnect'); ?></div>
        </div>
        <div class="so-stat-card">
            <div class="so-stat-value" style="--stat-value-color: <?php echo $summary['errors'] > 0 ? 'var(--so-error)' : 'var(--so-text-default)'; ?>;"><?php echo esc_html(number_format_i18n($summary['errors'])); ?></div>
            <div class="so-stat-label"><?php esc_html_e('Failed Calls', 'e2mconnect'); ?></div>
        </div>
        <div class="so-stat-card">
            <div class="so-stat-value"><?php echo esc_html(number_format_i18n($summary['unique_sessions'])); ?></div>
            <div class="so-stat-label"><?php esc_html_e('Unique Sessions', 'e2mconnect'); ?></div>
        </div>
        <div class="so-stat-card">
            <div class="so-stat-value"><?php echo esc_html(number_format_i18n($unique_users)); ?></div>
            <div class="so-stat-label"><?php esc_html_e('Unique Users', 'e2mconnect'); ?></div>
        </div>
        <div class="so-stat-card">
            <div class="so-stat-value so-stat-value--sm" title="<?php echo esc_attr($summary['most_used'] ?? ''); ?>">
                <?php if ($summary['most_used']): ?>
                    <?php echo esc_html(ucfirst(str_replace(['e2m/', 'e2m-', 'nexter-', 'wdesignkit-'], '', $summary['most_used']))); ?>
                <?php else: ?>
                    <span style="opacity:0.4;">&mdash;</span>
                <?php endif; ?>
            </div>
            <div class="so-stat-label"><?php esc_html_e('Most Used Ability', 'e2mconnect'); ?></div>
        </div>
    </div>

    <!-- -- Info/Actions Bar ---------------------------------------- -->
    <div class="so-info-actions-bar">
        <span class="so-info-actions-bar-text">
            <?php
            printf(
                /* translators: %1$s = entry count, %2$s = size, %3$d = retention days, %4$s = log level */
                esc_html__('Storing %1$s entries (%2$s)  |  Retention: %3$s days  |  Level: %4$s', 'e2mconnect'),
                esc_html(number_format_i18n($summary['total'])),
                esc_html(size_format($table_size)),
                esc_html($retention_days),
                esc_html($log_level_display)
            );
            ?>
        </span>
        <div style="display:flex;gap:8px;">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=e2m_engine_export_csv'), 'e2m_engine_analytics_nonce', 'nonce')); ?>" class="so-btn-inline so-icon-button">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span class="so-icon-button-label"><?php esc_html_e('Export CSV', 'e2mconnect'); ?></span>
            </a>
            <button type="button" id="e2m-purge-logs-btn" class="so-btn-inline-danger so-icon-button so-icon-button--danger">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                <span class="so-icon-button-label"><?php esc_html_e('Clear All Logs', 'e2mconnect'); ?></span>
            </button>
        </div>
    </div>

    <?php
    // -- Render logs directly ------------------------------------
    e2m_engine_render_analytics_page_inner($settings);
    echo '</div><!-- /#so-analytics-content -->';
}

/**
 * AJAX: Get session entries for lazy loading in Activity Feed.
 */
function e2m_engine_ajax_get_session_entries(): void
{
    check_ajax_referer('e2m_engine_session_entries');
    if (!current_user_can('manage_options')) { wp_send_json_error('Denied', 403); }
    $sid = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
    if ($sid === '') { wp_send_json_error('Missing session'); }
    $entries = E2M_Engine_Analytics::get_session_entries($sid);
    // ob_start / ob_get_clean are always paired within this same scope — no
    // early exits occur between them, satisfying WP.org output-buffer guidelines.
    ob_start();
    if (empty($entries)) {
        echo '<p style="color:var(--so-text-tertiary);text-align:center;">No entries.</p>';
    } else {
        echo '<div style="display:flex;flex-direction:column;gap:2px;">';
        foreach ($entries as $e) {
            $risk = $e['risk_level'] ?? 'unknown';
            $color = E2M_Engine_Analytics::risk_color($risk);
            $label = E2M_Engine_Analytics::risk_label($risk);
            $human = E2M_Engine_Analytics::humanize_ability($e['ability_name']);
            $time = wp_date('g:i:s A', strtotime($e['created_at']));
            $ms = (int) ($e['execution_time_ms'] ?? 0);
            $err = ($e['response_status'] ?? '') === 'error';
            echo '<div style="display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:6px;' . ($err ? 'background:var(--so-error-lightest);' : '') . '">';
            echo '<span style="width:8px;height:8px;border-radius:50%;background:' . esc_attr($color) . ';flex-shrink:0;"></span>';
            echo '<span style="flex:1;font-size:13px;color:var(--so-text-primary);">' . esc_html($human);
            if ($err) echo ' <span style="color:var(--so-error);font-weight:600;font-size:11px;">(Error)</span>';
            echo '</span>';
            echo '<span class="e2m-anno-pill" style="background:' . esc_attr($color) . '18;color:' . esc_attr($color) . ';border-color:' . esc_attr($color) . '40;font-size:9px;padding:0 6px;">' . esc_html($label) . '</span>';
            echo '<span style="font-size:11px;color:var(--so-text-tertiary);white-space:nowrap;">';
            if ($ms > 0) echo esc_html($ms . 'ms') . ' &middot; ';
            echo esc_html($time) . '</span></div>';
        }
        echo '</div>';
    }
    // Explicitly capture into a variable before sending — buffer is always closed here.
    $html = ob_get_clean();
    wp_send_json_success(['html' => $html]);
}

/**
 * Render Privacy tab content.
 */
function e2m_engine_render_privacy_tab_content(array $settings): void
{
    $si = $settings['server_instructions'];
    ?>
    <form method="post" id="e2m-mcp-privacy-form">
        <?php wp_nonce_field('e2m_engine_settings'); ?>
        <input type="hidden" name="e2m_engine_active_tab" class="e2m-mcp-active-tab-field" value="privacy" />
        <?php e2m_engine_render_hidden_settings($settings, 'privacy'); ?>

        <!-- Two-column: Server Instructions + Analytics Data Collection -->
        <div class="so-settings-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:stretch;">

        <!-- Section 1: Server Instructions - Data Shared with AI -->
        <div class="so-settings-card">
            <div class="so-settings-card-header">
                <h3 class="so-settings-card-title"><?php esc_html_e('Server Instructions - Data Shared with AI', 'e2mconnect'); ?></h3>
                <p class="so-settings-card-desc"><?php esc_html_e('Control what data is included in the server instructions sent to AI models via MCP.', 'e2mconnect'); ?></p>
            </div>

            <?php
            $si_options = [
                'wp_version'        => ['label' => __('WordPress version', 'e2mconnect'), 'hint' => __('e.g. "WordPress version: 6.7"', 'e2mconnect')],
                'php_version'       => ['label' => __('PHP version', 'e2mconnect'), 'hint' => __('e.g. "PHP version: 8.2.10"', 'e2mconnect')],
                'theme_info'        => ['label' => __('Active theme name & version', 'e2mconnect'), 'hint' => __('Includes parent theme if using a child theme.', 'e2mconnect')],
                'elementor_version' => ['label' => __('Elementor version', 'e2mconnect'), 'hint' => __('Only sent when Elementor is active.', 'e2mconnect')],
                'plugins_list'      => ['label' => __('List of all active plugins with versions', 'e2mconnect'), 'hint' => __('Exposes your full plugin stack - disable if sensitive.', 'e2mconnect')],
            ];
            foreach ($si_options as $si_key => $si_info): ?>
                <div class="so-setting-row">
                    <div class="so-setting-row-info">
                        <div class="so-setting-row-label"><?php echo esc_html($si_info['label']); ?></div>
                        <div class="so-setting-row-desc"><?php echo esc_html($si_info['hint']); ?></div>
                    </div>
                    <label class="so-toggle">
                        <input type="checkbox" name="e2m_engine_si_<?php echo esc_attr($si_key); ?>" value="1" <?php checked($si[$si_key] ?? false, true); ?> />
                        <span class="so-toggle-track"></span>
                    </label>
                </div>
            <?php endforeach; ?>

        </div>

        <!-- Section 2: Analytics Data Collection -->
        <div class="so-settings-card">
            <div class="so-settings-card-header">
                <h3 class="so-settings-card-title"><?php esc_html_e('Analytics Data Collection', 'e2mconnect'); ?></h3>
                <p class="so-settings-card-desc"><?php esc_html_e('Configure what analytics data is collected when MCP tools are called.', 'e2mconnect'); ?></p>
            </div>

            <!-- Store IP addresses -->
            <div class="so-setting-row">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Store IP addresses', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Store the IP address of users making MCP calls.', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" id="e2m-store-ip" name="e2m_engine_analytics_store_ip" value="1" <?php checked($settings['analytics_store_ip'] ?? true, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>

            <!-- Anonymize IP - sub-toggle, visible only when Store IP is on -->
            <div id="e2m-anonymize-ip-row" class="so-setting-sub-row" style="<?php echo ($settings['analytics_store_ip'] ?? true) ? '' : 'display:none;'; ?>">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Anonymize IP addresses', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Mask the last octet of stored IP addresses (e.g., 192.168.1.xxx).', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" name="e2m_engine_analytics_anonymize_ip" value="1" <?php checked($settings['analytics_anonymize_ip'] ?? false, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>

            <!-- Store user identity -->
            <div class="so-setting-row">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Store user identity', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Links each log entry to the WordPress user ID. When disabled, logs are anonymous.', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" name="e2m_engine_analytics_store_user_identity" value="1" <?php checked($settings['analytics_store_user_identity'] ?? true, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>

            <!-- Store full request body -->
            <div class="so-setting-row">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Log full AI command details', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Saves the complete details of every AI command sent. May contain sensitive parameters.', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" name="e2m_engine_analytics_store_request" value="1" <?php checked($settings['analytics_store_request'] ?? false, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>

            <!-- Store full response body -->
            <div class="so-setting-row">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Log full AI response details', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Saves the complete details of every AI response received. May contain site content or user data.', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" name="e2m_engine_analytics_store_response" value="1" <?php checked($settings['analytics_store_response'] ?? false, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>
        </div>

        </div><!-- /two-column grid -->

        <div style="height:20px;"></div>

        <!-- Two-column: Email Notifications + Data Summary -->
        <div class="so-settings-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:stretch;">

        <!-- Section 3: Email Notifications -->
        <div class="so-settings-card">
            <div class="so-settings-card-header">
                <h3 class="so-settings-card-title"><?php esc_html_e('Email Notifications - Data in Emails', 'e2mconnect'); ?></h3>
                <p class="so-settings-card-desc"><?php esc_html_e('Control whether MCP activity summaries are sent via email and how often.', 'e2mconnect'); ?></p>
            </div>

            <div class="so-setting-row">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Enable email notifications', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Send email summaries of MCP tool activity to the site administrator.', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" name="e2m_engine_analytics_notify_enabled" value="1" <?php checked($settings['analytics_notify_enabled'] ?? false, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>

            <div id="e2m-notify-options" style="<?php echo ($settings['analytics_notify_enabled'] ?? false) ? '' : 'display:none;'; ?>">
                <?php $freq = $settings['analytics_notify_frequency'] ?? 'off'; ?>
                <input type="hidden" name="e2m_engine_analytics_notify_frequency" id="e2m-notify-freq" value="<?php echo esc_attr($freq); ?>" />
                <div class="so-setting-sub-row" style="gap:12px;padding:12px 16px;">
                    <span style="font-size:var(--so-text-sm);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:18px;white-space:nowrap;"><?php esc_html_e('Frequency:', 'e2mconnect'); ?></span>
                    <nav class="so-pill-tabs e2m-client-tabs so-notify-freq-tabs">
                        <div class="so-pill-tabs-indicator"></div>
                        <?php
                        $freq_options = ['off' => 'Off', 'session' => 'Every Session', 'daily' => 'Daily Digest'];
                        foreach ($freq_options as $val => $label): ?>
                            <button type="button" class="so-pill-tab<?php echo $freq === $val ? ' active' : ''; ?>" data-freq="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Section 3b: Webhook Notifications -->
        <div class="so-settings-card">
            <div class="so-settings-card-header">
                <h3 class="so-settings-card-title"><?php esc_html_e('Webhook Notifications', 'e2mconnect'); ?></h3>
                <p class="so-settings-card-desc"><?php esc_html_e('Send real-time JSON notifications to Slack, Discord, Zapier, or any custom URL when AI abilities are used.', 'e2mconnect'); ?></p>
            </div>

            <div class="so-setting-row">
                <div class="so-setting-row-info">
                    <div class="so-setting-row-label"><?php esc_html_e('Enable webhook notifications', 'e2mconnect'); ?></div>
                    <div class="so-setting-row-desc"><?php esc_html_e('Send JSON payloads to your configured endpoint when abilities are called.', 'e2mconnect'); ?></div>
                </div>
                <label class="so-toggle">
                    <input type="checkbox" id="e2m-webhook-enabled" name="e2m_engine_webhook_enabled" value="1" <?php checked($settings['webhook_enabled'] ?? false, true); ?> />
                    <span class="so-toggle-track"></span>
                </label>
            </div>

            <div id="e2m-webhook-options" style="<?php echo ($settings['webhook_enabled'] ?? false) ? '' : 'display:none;'; ?>">
                <div class="so-webhook-panel">
                    <!-- MCP Endpoint -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="so-webhook-label"><?php esc_html_e('MCP Endpoint', 'e2mconnect'); ?></label>
                        <div style="display:flex;gap:8px;align-items:start;">
                            <input type="url" name="e2m_engine_webhook_url" value="<?php echo esc_attr($settings['webhook_url'] ?? ''); ?>" placeholder="https://hooks.slack.com/services/.." class="so-webhook-input" />
                            <button type="button" id="e2m-webhook-test-btn" class="so-btn-inline so-webhook-test-btn">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><path d="M5.333 3.333L12 8l-6.667 4.667V3.333z" stroke="currentColor" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e('Play', 'e2mconnect'); ?>
                            </button>
                        </div>
                    </div>
                    <!-- Trigger Events + Signing Secret -->
                    <div style="display:flex;gap:8px;align-items:start;">
                        <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                            <label class="so-webhook-label"><?php esc_html_e('Trigger Events', 'e2mconnect'); ?></label>
                            <?php
                            $wh_events    = $settings['webhook_events'] ?? 'all';
                            $wh_options   = [
                                'all'         => __('All ability calls', 'e2mconnect'),
                                'destructive' => __('Destructive only', 'e2mconnect'),
                                'errors'      => __('Errors only', 'e2mconnect'),
                            ];
                            $wh_label_now = $wh_options[$wh_events] ?? $wh_events;
                            ?>
                            <input type="hidden" name="e2m_engine_webhook_events" value="<?php echo esc_attr($wh_events); ?>" />
                            <div class="so-dropdown so-dropdown--block" data-input="e2m_engine_webhook_events" data-submit-on-change="false">
                                <button type="button" class="so-dropdown-trigger">
                                    <span class="so-dropdown-label"><?php echo esc_html((string) $wh_label_now); ?></span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div class="so-dropdown-menu">
                                    <?php foreach ($wh_options as $val => $label): ?>
                                        <button type="button" class="so-dropdown-item<?php echo (string) $wh_events === (string) $val ? ' active' : ''; ?>" data-value="<?php echo esc_attr((string) $val); ?>"><?php echo esc_html((string) $label); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                            <label class="so-webhook-label"><?php esc_html_e('Signing Secret (optional)', 'e2mconnect'); ?></label>
                            <input type="password" name="e2m_engine_webhook_secret" value="<?php echo esc_attr($settings['webhook_secret'] ?? ''); ?>" placeholder="<?php esc_attr_e('Secret key for verifying requests (recommended)', 'e2mconnect'); ?>" class="so-webhook-input so-webhook-input--full" autocomplete="off" />
                        </div>
                    </div>
                    <!-- Help text -->
                    <p class="so-webhook-help">
                        <?php esc_html_e('Each webhook delivers the ability name, status, session, user, and timestamp. Adding a secret key enables request verification — your endpoint can use it to confirm the webhook came from your site and was not tampered with.', 'e2mconnect'); ?>
                    </p>
                </div>
            </div>

            <script>
            (function(){
                var cb = document.getElementById('e2m-webhook-enabled');
                var opts = document.getElementById('e2m-webhook-options');
                if (cb && opts) { cb.addEventListener('change', function() { opts.style.display = cb.checked ? '' : 'none'; }); }

                var testBtn = document.getElementById('e2m-webhook-test-btn');
                if (testBtn) {
                    testBtn.addEventListener('click', function() {
                        var url = document.querySelector('[name="e2m_engine_webhook_url"]').value;
                        if (!url) { alert(<?php echo wp_json_encode(__('Enter a webhook URL first.', 'e2mconnect')); ?>); return; }
                        testBtn.disabled = true;
                        var oldHTML = testBtn.innerHTML;
                        testBtn.textContent = '...';
                        var data = new FormData();
                        data.append('action', 'e2m_engine_test_webhook');
                        data.append('url', url);
                        data.append('secret', document.querySelector('[name="e2m_engine_webhook_secret"]').value);
                        data.append('_wpnonce', <?php echo wp_json_encode(wp_create_nonce('e2m_engine_test_webhook')); ?>);
                        fetch(ajaxurl, { method: 'POST', body: data })
                            .then(function(r) { return r.json(); })
                            .then(function(r) {
                                alert(r.success ? <?php echo wp_json_encode(__('Webhook test sent successfully!', 'e2mconnect')); ?> : (r.data || <?php echo wp_json_encode(__('Error', 'e2mconnect')); ?>));
                                testBtn.disabled = false;
                                testBtn.innerHTML = oldHTML;
                            })
                            .catch(function() { alert(<?php echo wp_json_encode(__('Network error. Please try again.', 'e2mconnect')); ?>); testBtn.disabled = false; testBtn.innerHTML = oldHTML; });
                    });
                }
            })();
            </script>
        </div>

        </div><!-- close 2-col grid -->

        <!-- Data Summary (full width below) -->
        <div style="display:grid;grid-template-columns:1fr;gap:20px;margin-top:20px;">

        <!-- Section 4: Data Summary -->
        <div class="so-settings-card" style="padding-bottom:0;overflow:hidden;">
            <div class="so-settings-card-header" style="border-bottom:none;padding-bottom:0;">
                <h3 class="so-settings-card-title"><?php esc_html_e('Data Summary', 'e2mconnect'); ?></h3>
            </div>
            <div class="so-table-scroll">
            <table class="so-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Data Type', 'e2mconnect'); ?></th>
                        <th><?php esc_html_e('Status', 'e2mconnect'); ?></th>
                        <th><?php esc_html_e('Destination', 'e2mconnect'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $analytics_on  = (bool) ($settings['analytics_enabled'] ?? false);
                    $tracking_on   = $analytics_on && ($settings['analytics_log_level'] ?? 'all') !== 'off';
                    $webhook_on    = (bool) ($settings['webhook_enabled'] ?? false);
                    $webhook_label = $webhook_on ? ' + ' . __('Webhook', 'e2mconnect') : '';

                    $summary_rows = [
                        // Server instructions → sent to AI client via MCP
                        ['label' => __('WordPress version', 'e2mconnect'), 'active' => (bool)($si['wp_version'] ?? true), 'dest' => __('AI client (MCP)', 'e2mconnect')],
                        ['label' => __('PHP version', 'e2mconnect'),       'active' => (bool)($si['php_version'] ?? true), 'dest' => __('AI client (MCP)', 'e2mconnect')],
                        ['label' => __('Theme info', 'e2mconnect'),        'active' => (bool)($si['theme_info'] ?? true),  'dest' => __('AI client (MCP)', 'e2mconnect')],
                        ['label' => __('Elementor version', 'e2mconnect'), 'active' => (bool)($si['elementor_version'] ?? true), 'dest' => __('AI client (MCP)', 'e2mconnect')],
                        ['label' => __('Plugin list', 'e2mconnect'),       'active' => (bool)($si['plugins_list'] ?? false), 'dest' => __('AI client (MCP)', 'e2mconnect')],

                        // Analytics → stored in database (only when tracking is on)
                        ['label' => __('IP addresses', 'e2mconnect'),
                         'active' => $tracking_on && (bool)($settings['analytics_store_ip'] ?? true),
                         'dest' => ($settings['analytics_anonymize_ip'] ?? false)
                             ? __('Database (anonymized)', 'e2mconnect') . $webhook_label
                             : __('Database', 'e2mconnect') . $webhook_label],
                        ['label' => __('User identity', 'e2mconnect'),
                         'active' => $tracking_on && (bool)($settings['analytics_store_user_identity'] ?? true),
                         'dest' => __('Database', 'e2mconnect') . $webhook_label],
                        ['label' => __('Request body', 'e2mconnect'),
                         'active' => $tracking_on && (bool)($settings['analytics_store_request'] ?? false),
                         'dest' => __('Database only', 'e2mconnect')],
                        ['label' => __('Response body', 'e2mconnect'),
                         'active' => $tracking_on && (bool)($settings['analytics_store_response'] ?? false),
                         'dest' => __('Database only', 'e2mconnect')],
                    ];
                    foreach ($summary_rows as $row):
                        $dot_color = $row['active'] ? 'var(--so-success)' : 'var(--so-text-weak)';
                    ?>
                        <tr>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;padding:0 8px;border:1px solid var(--so-border-weak);border-radius:var(--so-radius-full);font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);white-space:nowrap;height:24px;box-sizing:border-box;">
                                    <span style="width:6px;height:6px;border-radius:50%;background:<?php echo esc_attr($dot_color); ?>;flex-shrink:0;"></span>
                                    <?php echo $row['active'] ? esc_html__('Collected', 'e2mconnect') : esc_html__('Not collected', 'e2mconnect'); ?>
                                </span>
                            </td>
                            <td><?php echo $row['active'] ? esc_html($row['dest']) : '–'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /so-table-scroll -->

        </div>

        </div><!-- /two-column grid -->
    </form>

    <script>
    (function () {
        function bindToggle(name, subId) {
            var cb = document.querySelector('input[name="' + name + '"]');
            var sub = document.getElementById(subId);
            if (!cb || !sub) return;
            cb.addEventListener('change', function () { sub.style.display = this.checked ? '' : 'none'; });
        }
        bindToggle('e2m_engine_analytics_store_ip', 'e2m-anonymize-ip-row');
        // Frequency pill tabs - same animation as MCP Connect client tabs
        (function() {
            var container = document.querySelector('.so-notify-freq-tabs');
            if (!container) return;
            var tabs = container.querySelectorAll('.so-pill-tab');
            var indicator = container.querySelector('.so-pill-tabs-indicator');
            var hiddenInput = document.getElementById('e2m-notify-freq');

            function moveIndicator(tab, animate) {
                if (!indicator || !container) return;
                if (!animate) indicator.style.transition = 'none';
                var containerRect = container.getBoundingClientRect();
                var tabRect = tab.getBoundingClientRect();
                indicator.style.width = tabRect.width + 'px';
                indicator.style.transform = 'translateX(' + (tabRect.left - containerRect.left - 3) + 'px)';
                if (!animate) {
                    requestAnimationFrame(function() {
                        requestAnimationFrame(function() {
                            if (indicator) indicator.style.transition = '';
                        });
                    });
                }
            }

            // Position indicator on page load (only if visible)
            var activeTab = container.querySelector('.so-pill-tab.active');
            if (activeTab && container.offsetParent !== null) {
                moveIndicator(activeTab, false);
            }

            tabs.forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    tabs.forEach(function(t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    moveIndicator(tab, true);
                    if (hiddenInput) {
                        hiddenInput.value = tab.getAttribute('data-freq');
                        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });

            // When notify toggle is enabled and container becomes visible, reposition indicator
            var notifyCb = document.querySelector('input[name="e2m_engine_analytics_notify_enabled"]');
            var notifySub = document.getElementById('e2m-notify-options');
            if (notifyCb && notifySub) {
                notifyCb.addEventListener('change', function() {
                    notifySub.style.display = this.checked ? '' : 'none';
                    if (this.checked) {
                        requestAnimationFrame(function() {
                            var active = container.querySelector('.so-pill-tab.active');
                            if (active) moveIndicator(active, false);
                        });
                    }
                });
            }
        })();

        // -- Auto-save settings via AJAX --
        var settingsNonce = <?php echo wp_json_encode(wp_create_nonce('e2m_engine_save_setting')); ?>;

        function autoSave(key, value) {
            var data = new FormData();
            data.append('action', 'e2m_engine_save_setting');
            data.append('_ajax_nonce', settingsNonce);
            data.append('key', key);
            data.append('value', value);
            fetch(ajaxurl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (!r.success) console.error('Auto-save failed:', r.data);
                })
                .catch(function(e) { console.error('Auto-save error:', e); });
        }

        // Map input names to setting keys
        var nameToKey = {
            'e2m_engine_si_wp_version': 'e2m_engine_si_wp_version',
            'e2m_engine_si_php_version': 'e2m_engine_si_php_version',
            'e2m_engine_si_theme_info': 'e2m_engine_si_theme_info',
            'e2m_engine_si_plugins_list': 'e2m_engine_si_plugins_list',
            'e2m_engine_si_elementor_version': 'e2m_engine_si_elementor_version',
            'e2m_engine_analytics_enabled': 'analytics_enabled',
            'e2m_engine_analytics_store_request': 'analytics_store_request',
            'e2m_engine_analytics_store_response': 'analytics_store_response',
            'e2m_engine_analytics_notify_enabled': 'analytics_notify_enabled',
            'e2m_engine_analytics_store_ip': 'analytics_store_ip',
            'e2m_engine_analytics_anonymize_ip': 'analytics_anonymize_ip',
            'e2m_engine_analytics_store_user_identity': 'analytics_store_user_identity',
            'e2m_engine_webhook_enabled': 'webhook_enabled',
            'e2m_engine_analytics_notify_frequency': 'analytics_notify_frequency',
            'e2m_engine_webhook_url': 'webhook_url',
            'e2m_engine_webhook_events': 'webhook_events',
            'e2m_engine_webhook_secret': 'webhook_secret',
        };

        // Auto-save on checkbox/toggle change
        var form = document.getElementById('e2m-mcp-privacy-form');
        if (form) {
            form.addEventListener('change', function(e) {
                var el = e.target;
                var name = el.name;
                var key = nameToKey[name];
                if (!key) return;
                var value;
                if (el.type === 'checkbox') {
                    value = el.checked ? '1' : '0';
                } else {
                    value = el.value;
                }
                autoSave(key, value);
            });

            // Debounced auto-save for text/url inputs
            var debounceTimers = {};
            form.addEventListener('input', function(e) {
                var el = e.target;
                if (el.type !== 'text' && el.type !== 'url' && el.type !== 'password' && el.type !== 'email') return;
                var key = nameToKey[el.name];
                if (!key) return;
                clearTimeout(debounceTimers[key]);
                debounceTimers[key] = setTimeout(function() {
                    autoSave(key, el.value);
                }, 600);
            });

            // Prevent form submission (no more Save button)
            form.addEventListener('submit', function(e) { e.preventDefault(); });
        }
    })();
    </script>
    <?php
}

/**
 * Render a collapsible troubleshooting section.
 * @param string[] $items List of troubleshooting tips.
 */
function e2m_engine_render_troubleshooting(array $items): void
{
    if (empty($items)) return;
    ?>
    <details style="border-top:1px solid var(--so-border-weak);padding-top:var(--so-space-4);">
        <summary style="cursor:pointer;font-size:var(--so-text-base);color:var(--so-brand-primary);font-weight:var(--so-weight-medium);list-style:none;display:flex;align-items:center;gap:8px;line-height:var(--so-leading-caption);">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">
                <circle cx="8" cy="8" r="7" stroke="var(--so-brand-primary)" stroke-width="1.5"/>
                <path d="M8 7.5V11.5" stroke="var(--so-brand-primary)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="5.25" r="0.875" fill="var(--so-brand-primary)"/>
            </svg>
            <?php esc_html_e('Troubleshooting', 'e2mconnect'); ?>
        </summary>
        <ul style="margin:var(--so-space-3) 0 0;padding-left:var(--so-space-5);font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
            <?php foreach ($items as $item): ?>
                <li><?php echo esc_html($item); ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php
}

function e2m_engine_render_code_block(string $id, string $label, string $content, string $extra_button_html = ''): void
{
    ?>
    <div class="so-code-wrap">
        <div class="so-code-header">
            <span class="so-code-header-label">JSON</span>
            <div style="display:flex;align-items:center;gap:6px;">
                <button type="button" class="so-code-copy-btn" onclick="e2mMcpCopy('<?php echo esc_attr($id); ?>', this)">
                    <?php echo esc_html($label); ?>
                </button>
                <?php echo $extra_button_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <pre id="<?php echo esc_attr($id); ?>" class="so-code-block" style="border-radius:0 0 var(--so-radius-md) var(--so-radius-md);"><?php echo esc_html($content); ?></pre>
    </div>
    <?php
}

/**
 * Connect page: App password + Claude JSON config.
 */
function e2m_engine_render_connect_page_inner(): void
{
    $password_result = e2m_engine_handle_create_password();
    $create_error = is_wp_error($password_result) ? $password_result : null;
    $new_password = is_string($password_result) ? $password_result : null;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $result_message = (sanitize_text_field(wp_unslash($_GET['e2m_engine_result'] ?? '')) === 'revoked') ? __('Application password revoked.', 'e2mconnect') : null;

    $current_user = wp_get_current_user();
    $username = (string) $current_user->user_login;
    $rest_url = rest_url('mcp/mcp-adapter-default-server');
    $display_password = $new_password ?? 'YOUR-APP-PASSWORD';

    // Unique per-site server key, e.g. "e2m-wordpress-xkq".
    $server_key = e2m_engine_server_key();

    $desktop_config = [
        'mcpServers' => [
            $server_key => [
                'command' => 'npx',
                'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                'env' => [
                    'WP_API_URL' => $rest_url,
                    'WP_API_USERNAME' => $username,
                    'WP_API_PASSWORD' => $display_password,
                ],
            ],
        ],
    ];
    $cursor_config = $desktop_config;
    $vscode_config = [
        'servers' => [
            $server_key => $desktop_config['mcpServers'][$server_key],
        ],
    ];
    $claude_code_server = [
        'type' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => [
            'WP_API_URL' => $rest_url,
            'WP_API_USERNAME' => $username,
            'WP_API_PASSWORD' => $display_password,
        ],
    ];

    $desktop_json = (string) wp_json_encode($desktop_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $cursor_json = (string) wp_json_encode($cursor_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $vscode_json = (string) wp_json_encode($vscode_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $claude_code_json = (string) wp_json_encode($claude_code_server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $claude_code_command = "claude mcp add-json {$server_key} '" . "\n" . $claude_code_json . "\n" . "'";
    $passwords = e2m_engine_get_passwords();

    // Build the server JSON with unescaped slashes so URLs are clean inside the shell command.
    // Outer shell uses single-quotes so Python can use double-quotes freely inside.
    // Single-quotes inside the JSON value are escaped as '\'' (end-quote, literal-quote, re-open-quote).
    $server_json_for_shell = str_replace(
        "'",
        "'\\''",
        (string) wp_json_encode($desktop_config['mcpServers'][$server_key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    // Python one-liner: merges $server_key into claude_desktop_config.json, then restarts Claude Desktop (macOS).
    $py_merge = 'import json,os,pathlib,sys;'
        . 'p=pathlib.Path(os.path.expanduser("~"))/("Library/Application Support/Claude/claude_desktop_config.json" if sys.platform=="darwin" else os.path.join(os.environ.get("APPDATA",""),"Claude","claude_desktop_config.json"));'
        . 'cfg=json.loads(p.read_text(encoding="utf-8")) if p.exists() else {};'
        . 'cfg.setdefault("mcpServers",{})["' . $server_key . '"]=' . $server_json_for_shell . ';'
        . 'p.parent.mkdir(parents=True,exist_ok=True);'
        . 'p.write_text(json.dumps(cfg,indent=2),encoding="utf-8");'
        . 'print("Config saved!")';

    // Full command: merge config → quit Claude Desktop → reopen it.
    $autoconnect_cmd = "python3 -c '" . $py_merge . "'"
        . ' && osascript -e \'quit app "Claude"\' 2>/dev/null; sleep 2 && open -a "Claude" 2>/dev/null'
        . ' && echo "Done — Claude Desktop is restarting with E2M WordPress connected."';
    ?>
    <div>
        <?php if (!e2m_engine_is_enabled()): ?>
            <div class="so-notice so-notice-warning">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;"><path d="M8 1.5L14.5 13H1.5L8 1.5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M8 6.5V9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="8" cy="11" r="0.75" fill="currentColor"/></svg>
                <span style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;overflow:hidden;white-space:nowrap;">
                    <strong style="font-weight:var(--so-weight-medium);"><?php esc_html_e('AI Abilities are OFF.', 'e2mconnect'); ?></strong>
                    <?php esc_html_e('Tools are not exposed to MCP clients until you enable them in Settings.', 'e2mconnect'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=e2mconnect&tab=ai-abilities')); ?>" style="font-weight:var(--so-weight-medium);color:var(--so-brand-primary);text-decoration:none;"><?php esc_html_e('Open Settings', 'e2mconnect'); ?></a>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($create_error !== null): ?>
            <div class="so-notice so-notice-error"><?php echo esc_html($create_error->get_error_message()); ?></div>
        <?php endif; ?>
        <?php if ($result_message !== null): ?>
            <div class="so-notice so-notice-success"><?php echo esc_html($result_message); ?></div>
        <?php endif; ?>

        <!-- -- Two-column: Create Password + MCP Endpoint -- -->
        <div class="so-two-col" style="margin-bottom:var(--so-space-5);">
            <!-- Create Application Password -->
            <div class="so-card" style="display:flex;flex-direction:column;gap:var(--so-space-4);">
                <!-- Card Header: Title + Badge -->
                <div style="display:flex;flex-direction:column;gap:var(--so-space-2);">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;">
                        <h3 class="so-card-title"><?php esc_html_e('Create Application Password', 'e2mconnect'); ?></h3>
                        <?php
                        $app_pw_available = wp_is_application_passwords_available();
                        $badge_color = $app_pw_available ? 'var(--so-success)' : 'var(--so-error)';
                        $badge_label = $app_pw_available ? __('Available', 'e2mconnect') : __('Unavailable', 'e2mconnect');
                        ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;padding:0 8px;border:1px solid var(--so-border-weak);border-radius:var(--so-radius-full);font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);white-space:nowrap;height:24px;box-sizing:border-box;">
                            <svg width="8" height="8" viewBox="0 0 8 8" fill="<?php echo $app_pw_available ? 'var(--so-success)' : 'var(--so-error)'; ?>"><circle cx="4" cy="4" r="4"/></svg>
                            <?php echo esc_html($badge_label); ?>
                        </span>
                    </div>
                </div>

                <!-- Input Row -->
                <form method="post" style="margin:0;">
                    <?php wp_nonce_field('e2m_engine_create_password'); ?>
                    <div style="display:flex;gap:var(--so-space-3);align-items:stretch;">
                        <input type="text" class="so-input so-input-sm" name="e2m_engine_password_name" placeholder="<?php esc_attr_e('Enter Application Name...', 'e2mconnect'); ?>" style="flex:1;max-width:none;" />
                        <button type="submit" name="e2m_engine_create_password" class="so-btn so-btn-primary so-btn-sm" style="flex-shrink:0;" <?php disabled(!$app_pw_available); ?>>
                            <?php esc_html_e('Create Password', 'e2mconnect'); ?>
                        </button>
                    </div>
                </form>

                <?php if ($new_password !== null): ?>
                    <!-- Generated Password Success Box -->
                    <div style="padding:14px;border:1px solid var(--so-success);border-radius:var(--so-radius-md);background:var(--so-success-bg);display:flex;flex-direction:column;gap:var(--so-space-2);">
                        <p style="margin:0;font-size:var(--so-text-base);color:var(--so-success);font-weight:var(--so-weight-medium);line-height:var(--so-leading-caption);">
                            <?php esc_html_e('Your new password (copy it now - it won\'t be shown again):', 'e2mconnect'); ?>
                        </p>
                        <div style="display:flex;align-items:stretch;gap:var(--so-space-2);">
                            <input type="text" id="e2m-mcp-new-password" class="so-input so-input-sm so-input-value" value="<?php echo esc_attr($new_password); ?>" readonly tabindex="-1" style="flex:1;max-width:none;letter-spacing:1px;" />
                            <button type="button" class="so-btn so-btn-secondary so-btn-sm" onclick="e2mMcpCopy('e2m-mcp-new-password', this)">
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V3.5a1.5 1.5 0 00-1.5-1.5H3.5A1.5 1.5 0 002 3.5V9a1.5 1.5 0 001.5 1.5h2"/></svg>
                                <?php esc_html_e('Copy', 'e2mconnect'); ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Security Hint -->
                <div style="display:flex;gap:4px;align-items:flex-start;">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:0;"><path d="M11 6.5V5a3 3 0 00-6 0v1.5" stroke="var(--so-text-weak)" stroke-width="1.2" stroke-linecap="round"/><rect x="4" y="6.5" width="8" height="6.5" rx="1.5" stroke="var(--so-text-weak)" stroke-width="1.2"/></svg>
                    <p style="margin:0;font-size:var(--so-text-label);color:var(--so-text-weak);line-height:var(--so-leading-label);">
                        <?php esc_html_e('Each AI client should use its own password. The password is shown only once after creation.', 'e2mconnect'); ?>
                    </p>
                </div>
            </div>

            <!-- Connect Your MCP Client -->
            <div class="so-card" style="display:flex;flex-direction:column;gap:var(--so-space-4);">
                <!-- Card Header: Title + Badge -->
                <div style="display:flex;flex-direction:column;gap:var(--so-space-2);">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;">
                        <h3 class="so-card-title"><?php esc_html_e('Connect Your MCP Client', 'e2mconnect'); ?></h3>
                        <?php if ($new_password): ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:0 8px;border:1px solid var(--so-border-weak);border-radius:var(--so-radius-full);font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);white-space:nowrap;height:24px;box-sizing:border-box;">
                                <svg width="8" height="8" viewBox="0 0 8 8" fill="var(--so-success)"><circle cx="4" cy="4" r="4"/></svg>
                                <?php esc_html_e('Available', 'e2mconnect'); ?>
                            </span>
                        <?php else: ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:0 8px;border:1px solid var(--so-border-weak);border-radius:var(--so-radius-full);font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);white-space:nowrap;height:24px;box-sizing:border-box;">
                                <svg width="8" height="8" viewBox="0 0 8 8" fill="var(--so-warning)"><circle cx="4" cy="4" r="4"/></svg>
                                <?php esc_html_e('Needs password', 'e2mconnect'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Endpoint Box -->
                <div style="background:var(--so-bg-lightest);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-md);padding:12px;display:flex;flex-direction:column;gap:var(--so-space-2);">
                    <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-weak);line-height:normal;font-family:'Inter',var(--so-font);"><?php esc_html_e('MCP Endpoint', 'e2mconnect'); ?></span>
                    <div style="display:flex;gap:8px;align-items:stretch;">
                        <input type="text" id="e2m-mcp-endpoint" class="so-input so-input-sm so-input-value" value="<?php echo esc_attr($rest_url); ?>" readonly tabindex="-1" style="flex:1;max-width:none;" />
                        <button type="button" class="so-btn so-btn-secondary so-btn-sm" onclick="e2mMcpCopy('e2m-mcp-endpoint', this)" style="align-self:stretch;">
                            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V3.5a1.5 1.5 0 00-1.5-1.5H3.5A1.5 1.5 0 002 3.5V9a1.5 1.5 0 001.5 1.5h2"/></svg>
                            <?php esc_html_e('Copy', 'e2mconnect'); ?>
                        </button>
                    </div>
                    <p style="font-size:10px;color:var(--so-warning-dark);margin:0;line-height:14px;">
                        <?php esc_html_e('Replace YOUR-APP-PASSWORD in configs below.', 'e2mconnect'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- -- Existing Application Passwords -- -->
        <div class="so-table-scroll">
        <div style="background:var(--so-bg-lightest);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-lg);box-shadow:var(--so-shadow-xs);overflow:hidden;margin-bottom:var(--so-space-5);display:flex;flex-direction:column;min-width:500px;">
            <!-- Title -->
            <div style="background:var(--so-bg-white);padding:16px;display:flex;align-items:center;">
                <p style="margin:0;font-size:var(--so-text-body);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-body);"><?php esc_html_e('Existing Application Passwords', 'e2mconnect'); ?></p>
            </div>
            <!-- Header Row -->
            <div style="background:var(--so-bg-lightest);border:1px solid var(--so-border-weak);border-left:none;border-right:none;display:flex;align-items:center;padding:8px 16px;">
                <div style="flex:1;min-width:0;"><span style="font-size:var(--so-text-label);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-label);"><?php esc_html_e('Password Name', 'e2mconnect'); ?></span></div>
                <div style="flex:1;min-width:0;"><span style="font-size:var(--so-text-label);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-label);"><?php esc_html_e('Created', 'e2mconnect'); ?></span></div>
                <div style="flex:1;min-width:0;"><span style="font-size:var(--so-text-label);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-label);"><?php esc_html_e('Last Used', 'e2mconnect'); ?></span></div>
                <div style="width:68px;text-align:center;flex-shrink:0;"><span style="font-size:var(--so-text-label);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-label);"><?php esc_html_e('Action', 'e2mconnect'); ?></span></div>
            </div>
            <!-- Data Rows -->
            <?php if ($passwords === []): ?>
                <div style="background:var(--so-bg-white);padding:32px 16px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:12px;">
                    <span style="color:var(--so-text-weaker);font-size:var(--so-text-base);line-height:var(--so-leading-caption);"><?php esc_html_e('No application password created yet.', 'e2mconnect'); ?></span>
                    <a href="<?php echo esc_url( get_edit_user_link( get_current_user_id() ) . '#application-passwords-section' ); ?>" class="so-btn so-btn-primary so-btn-sm" style="text-decoration:none;"><?php esc_html_e('Create Application Password →', 'e2mconnect'); ?></a>
                </div>
            <?php else: ?>
                <?php $row_index = 0; foreach ($passwords as $pw): ?>
                    <?php
                    $uuid = (string) ($pw['uuid'] ?? '');
                    $created = isset($pw['created']) ? wp_date('Y-m-d H:i:s', (int) $pw['created']) : __('Unknown', 'e2mconnect');
                    $last_used = !empty($pw['last_used']) ? human_time_diff((int) $pw['last_used']) . ' ago' : __('Never', 'e2mconnect');
                    $row_bg = ($row_index % 2 === 0) ? 'var(--so-bg-white)' : 'var(--so-bg-lightest)';
                    $row_border = ($row_index % 2 !== 0) ? 'border-top:1px solid var(--so-border-weak);border-bottom:1px solid var(--so-border-weak);' : '';
                    ?>
                    <div style="background:<?php echo esc_attr($row_bg); ?>;<?php echo esc_attr($row_border); ?>display:flex;align-items:center;padding:10px 16px;">
                        <div style="flex:1;min-width:0;">
                            <span style="font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php echo esc_html((string) ($pw['name'] ?? '')); ?></span>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <span style="font-size:var(--so-text-base);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-caption);"><?php echo esc_html($created); ?></span>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <span style="font-size:var(--so-text-base);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-caption);"><?php echo esc_html($last_used); ?></span>
                        </div>
                        <div style="width:68px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=e2mconnect&tab=connect' ) ); ?>" style="margin:0;" onsubmit="return confirm('<?php echo esc_js(__('Revoke this password?', 'e2mconnect')); ?>');">
                                <?php wp_nonce_field('e2m_engine_revoke_password_' . $uuid); ?>
                                <input type="hidden" name="e2m_engine_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                                <button type="submit" name="e2m_engine_revoke_password" value="1" class="so-btn so-btn-danger so-btn-mini"><?php esc_html_e('Revoke', 'e2mconnect'); ?></button>
                            </form>
                        </div>
                    </div>
                <?php $row_index++; endforeach; ?>
            <?php endif; ?>
        </div>
        </div><!-- /so-table-scroll -->

        <?php
        // Build all config variants.
        $windsurf_config = [
            'mcpServers' => [
                $server_key => [
                    'command' => 'npx',
                    'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                    'env' => [
                        'WP_API_URL' => $rest_url,
                        'WP_API_USERNAME' => $username,
                        'WP_API_PASSWORD' => $display_password,
                    ],
                ],
            ],
        ];
        $zed_config = [
            'context_servers' => [
                $server_key => [
                    'command' => [
                        'path' => 'npx',
                        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                        'env' => [
                            'WP_API_URL' => $rest_url,
                            'WP_API_USERNAME' => $username,
                            'WP_API_PASSWORD' => $display_password,
                        ],
                    ],
                ],
            ],
        ];
        $cline_config = [
            'mcpServers' => [
                $server_key => [
                    'command' => 'npx',
                    'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                    'env' => [
                        'WP_API_URL' => $rest_url,
                        'WP_API_USERNAME' => $username,
                        'WP_API_PASSWORD' => $display_password,
                    ],
                ],
            ],
        ];
        $continue_config = [
            'experimental' => [
                'modelContextProtocolServers' => [
                    [
                        'transport' => [
                            'type' => 'stdio',
                            'command' => 'npx',
                            'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                            'env' => [
                                'WP_API_URL' => $rest_url,
                                'WP_API_USERNAME' => $username,
                                'WP_API_PASSWORD' => $display_password,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $generic_env = "WP_API_URL=" . $rest_url . "\nWP_API_USERNAME=" . $username . "\nWP_API_PASSWORD=" . $display_password;

        $windsurf_json = (string) wp_json_encode($windsurf_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $zed_json = (string) wp_json_encode($zed_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $cline_json = (string) wp_json_encode($cline_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $continue_json = (string) wp_json_encode($continue_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ?>

        <!-- -- Client Configuration -- -->
        <div class="so-card" style="margin-bottom:var(--so-space-5);padding:0;">
            <div style="padding:var(--so-space-4);display:flex;flex-direction:column;gap:var(--so-space-4);">
                <!-- Client Tabs (pill style with sliding indicator) -->
                <nav class="so-pill-tabs e2m-client-tabs" id="e2m-pill-tabs">
                    <div class="so-pill-tabs-indicator" id="e2m-pill-indicator"></div>
                    <?php
                    $clients = [
                        'e2m-plugin'     => 'E2M Plugin',
                        'custom'         => 'Custom',
                    ];
                    // Only the E2M Plugin and Custom clients are offered; always
                    // land on the E2M Plugin tab.
                    $default_client = 'e2m-plugin';
                    foreach ($clients as $ckey => $clabel): ?>
                        <a href="#" class="so-pill-tab <?php echo $ckey === $default_client ? 'active' : ''; ?>" data-client="<?php echo esc_attr($ckey); ?>"><?php echo esc_html($clabel); ?></a>
                    <?php $first = false; endforeach; ?>
                </nav>

            <!-- Client Panels -->

            <!-- E2M Plugin (install the E2M Connect agent plugin in your AI client) -->
            <div class="e2m-client-panel" id="e2m-client-e2m-plugin"<?php echo $default_client === 'e2m-plugin' ? '' : ' style="display:none;"'; ?>>
                <?php if (class_exists('\\E2M\\Connect\\Admin\\PluginGuide')) { \E2M\Connect\Admin\PluginGuide::renderPanel($username, $display_password); } ?>
            </div>

            <?php /* Claude Desktop / Claude Code / Cursor / VS Code / Windsurf / Zed / Cline / Continue client panels removed — only E2M Plugin and Custom are offered. */ ?>
            <?php if (false): ?>
            <!-- Claude Desktop -->
            <div class="e2m-client-panel" id="e2m-client-claude-desktop"<?php echo $default_client === 'claude-desktop' ? '' : ' style="display:none;"'; ?>>
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left">
                        <?php
                        $autoconnect_btn_html = '<button'
                            . ' type="button"'
                            . ' class="so-code-copy-btn e2m-autoconnect-btn"'
                            . ' onclick="e2mCopyAutoConnectMd(this)"'
                            . ' data-autoconnect="' . esc_attr($autoconnect_cmd) . '"'
                            . ' style="display:inline-flex;align-items:center;gap:5px;padding:4px 8px;position:relative;"'
                            . '>'
                            . '<svg width="14" height="14" viewBox="0 0 41 41" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="flex-shrink:0;">'
                            . '<path d="M37.532 16.87a9.963 9.963 0 0 0-.856-8.184 10.078 10.078 0 0 0-10.855-4.835 9.964 9.964 0 0 0-7.504-3.336 10.079 10.079 0 0 0-9.614 6.977 9.967 9.967 0 0 0-6.664 4.834 10.08 10.08 0 0 0 1.24 11.817 9.965 9.965 0 0 0 .856 8.185 10.079 10.079 0 0 0 10.855 4.835 9.965 9.965 0 0 0 7.504 3.336 10.079 10.079 0 0 0 9.617-6.981 9.967 9.967 0 0 0 6.663-4.834 10.079 10.079 0 0 0-1.243-11.814zm-17.109 23.712a7.474 7.474 0 0 1-4.801-1.735c.061-.033.168-.091.237-.134l7.964-4.6a1.294 1.294 0 0 0 .655-1.134V19.054l3.366 1.944a.12.12 0 0 1 .066.092v9.299a7.505 7.505 0 0 1-7.487 7.193zM4.186 34.063a7.462 7.462 0 0 1-.894-5.023c.06.036.162.099.237.141l7.964 4.6a1.297 1.297 0 0 0 1.308 0l9.724-5.614v3.888a.12.12 0 0 1-.048.103L14.46 37.2a7.505 7.505 0 0 1-10.274-3.137zm-2.32-16.526a7.47 7.47 0 0 1 3.908-3.285c0 .068-.004.19-.004.274v9.201a1.294 1.294 0 0 0 .654 1.132l9.723 5.614-3.366 1.944a.12.12 0 0 1-.114.012L4.8 27.427a7.505 7.505 0 0 1-2.934-9.89zm27.688 6.437l-9.724-5.615 3.367-1.943a.121.121 0 0 1 .114-.012l7.667 4.428a7.504 7.504 0 0 1-1.158 13.528v-9.476a1.293 1.293 0 0 0-.266-.91zm3.35-5.043c-.059-.037-.162-.099-.236-.141l-7.965-4.6a1.298 1.298 0 0 0-1.308 0l-9.723 5.614v-3.888a.12.12 0 0 1 .048-.103l7.418-4.281a7.498 7.498 0 0 1 11.766 7.399zm-21.063 6.929l-3.367-1.944a.12.12 0 0 1-.065-.092v-9.299a7.497 7.497 0 0 1 12.293-5.756 6.94 6.94 0 0 0-.236.134l-7.965 4.6a1.294 1.294 0 0 0-.654 1.132l-.006 11.225zm1.829-3.943l4.33-2.501 4.332 2.499v4.993l-4.331 2.5-4.331-2.5V21.917z" fill="currentColor"/>'
                            . '</svg>'
                            . esc_html__('Auto Connect', 'e2mconnect')
                            . '<span class="e2m-ac-tip">'
                            . esc_html__( 'Copy this command and paste it in your Terminal to configure Claude Desktop automatically.', 'e2mconnect' )
                            . '<em>' . esc_html__( 'Note: Python and Node.js must be installed on your system.', 'e2mconnect' ) . '</em>'
                            . '</span>'
                            . '</button>';
                        e2m_engine_render_code_block('e2m-mcp-claude-desktop-json', __('Copy JSON', 'e2mconnect'), $desktop_json, $autoconnect_btn_html);
                        ?>
                    </div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:var(--so-space-2);font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);">
                            <p style="margin:0;"><?php esc_html_e('1. Open Claude Desktop and go to Settings (gear icon).', 'e2mconnect'); ?></p>
                            <p style="margin:0;"><?php esc_html_e('2. Click "Developer" in the left sidebar, then "Edit Config".', 'e2mconnect'); ?></p>
                            <p style="margin:0;"><?php esc_html_e('3. This opens the config file. Paste the JSON below into it.', 'e2mconnect'); ?></p>
                            <p style="margin:0;"><?php esc_html_e('4. Save the file and restart Claude Desktop.', 'e2mconnect'); ?></p>
                            <p style="margin:0;"><?php
                            // translators: %s is the unique MCP server key, e.g. "e2m-wordpress-xkq".
                            echo esc_html( sprintf( __( '5. You should see "%s" in the MCP servers list.', 'e2mconnect' ), $server_key ) ); ?></p>
                        </div>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Config file location:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;">
                                macOS: ~/Library/Application Support/Claude/claude_desktop_config.json<br>
                                Windows: %APPDATA%\Claude\claude_desktop_config.json
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Claude Code -->
            <div class="e2m-client-panel" id="e2m-client-claude-code" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--so-space-2);">
                            <span style="font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);color:var(--so-text-tertiary);text-transform:uppercase;letter-spacing:0.5px;">Terminal Command</span>
                        </div>
                        <?php e2m_engine_render_code_block('e2m-mcp-claude-code-command', __('Copy Command', 'e2mconnect'), $claude_code_command); ?>
                    </div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('Open your terminal and navigate to your project directory.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Run the command on the left to add the MCP server.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Start a new Claude Code session - WordPress tools will be available.', 'e2mconnect'); ?></li>
                        </ol>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Access Level:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;"><?php esc_html_e('Adds to current project only. Use --scope user to give all projects access.', 'e2mconnect'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cursor -->
            <div class="e2m-client-panel" id="e2m-client-cursor" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left"><?php e2m_engine_render_code_block('e2m-mcp-cursor-json', __('Copy JSON', 'e2mconnect'), $cursor_json); ?></div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('In Cursor, go to Settings > Features > MCP Servers.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Click "+ Add new MCP Server".', 'e2mconnect'); ?></li>
                            <li><?php
                            // translators: %s is the unique MCP server key, e.g. "e2m-wordpress-xkq".
                            echo esc_html( sprintf( __( 'Select "Command" type, name it "%s".', 'e2mconnect' ), $server_key ) ); ?></li>
                            <li><?php esc_html_e('Or edit the config file and paste the JSON.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Restart Cursor. Look for the green dot.', 'e2mconnect'); ?></li>
                        </ol>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Config file location:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;">Project: .cursor/mcp.json | Global: ~/.cursor/mcp.json</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- VS Code -->
            <div class="e2m-client-panel" id="e2m-client-vscode" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left"><?php e2m_engine_render_code_block('e2m-mcp-vscode-json', __('Copy JSON', 'e2mconnect'), $vscode_json); ?></div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('Open Command Palette (Ctrl+Shift+P / Cmd+Shift+P).', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Type "MCP: Add Server" and select it.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Or create .vscode/mcp.json and paste the JSON.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Tools appear in GitHub Copilot Chat (Agent mode).', 'e2mconnect'); ?></li>
                        </ol>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Requirements:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;"><?php esc_html_e('VS Code 1.99+ with GitHub Copilot. Enable "chat.mcp.enabled" in settings.', 'e2mconnect'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Windsurf -->
            <div class="e2m-client-panel" id="e2m-client-windsurf" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left"><?php e2m_engine_render_code_block('e2m-mcp-windsurf-json', __('Copy JSON', 'e2mconnect'), $windsurf_json); ?></div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('Open Windsurf → Cascade (AI panel).', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Click the hammer icon (MCP) at the top.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Click "Configure" to open the config file.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Paste the JSON and save. Click "Refresh".', 'e2mconnect'); ?></li>
                        </ol>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Config file:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;">~/.codeium/windsurf/mcp_config.json</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Zed -->
            <div class="e2m-client-panel" id="e2m-client-zed" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left"><?php e2m_engine_render_code_block('e2m-mcp-zed-json', __('Copy JSON', 'e2mconnect'), $zed_json); ?></div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('Open Zed → Settings (Cmd+,).', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Add the JSON to your settings.json.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Or add to .zed/settings.json for per-project.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Restart Zed.', 'e2mconnect'); ?></li>
                        </ol>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Note:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;"><?php esc_html_e('Zed uses "context_servers" instead of "mcpServers".', 'e2mconnect'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cline -->
            <div class="e2m-client-panel" id="e2m-client-cline" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left"><?php e2m_engine_render_code_block('e2m-mcp-cline-json', __('Copy JSON', 'e2mconnect'), $cline_json); ?></div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('Open Cline in VS Code sidebar.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Click MCP Servers icon (plug icon).', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Click "Configure MCP Servers" to open config.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Paste JSON and save. Green dot = connected.', 'e2mconnect'); ?></li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Continue -->
            <div class="e2m-client-panel" id="e2m-client-continue" style="display:none;">
                <div class="e2m-tab-grid">
                    <div class="e2m-tab-left"><?php e2m_engine_render_code_block('e2m-mcp-continue-json', __('Copy JSON', 'e2mconnect'), $continue_json); ?></div>
                    <div class="e2m-tab-right">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;">
                            <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Setup Instructions', 'e2mconnect'); ?></p>
                            <button type="button" class="e2m-npm-guide-btn" onclick="document.getElementById('e2m-npm-guide-modal').style.display='flex'" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;font-size:var(--so-text-xs);font-weight:var(--so-weight-semibold);background:var(--so-bg-lightest);color:var(--so-text-weak);border:1px solid var(--so-border-weak);border-radius:var(--so-radius-sm);cursor:pointer;white-space:nowrap;transition:all .15s;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php esc_html_e('Check Node/npm', 'e2mconnect'); ?></button>
                        </div>
                        <ol style="margin:0;padding-left:0;font-size:var(--so-text-base);line-height:var(--so-leading-caption);color:var(--so-text-weak);list-style:none;display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <li><?php esc_html_e('Open Continue settings (gear icon).', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Click "Open config.json".', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Add the JSON to your config file.', 'e2mconnect'); ?></li>
                            <li><?php esc_html_e('Reload VS Code.', 'e2mconnect'); ?></li>
                        </ol>
                        <div style="background:var(--so-bg-lightest);border-radius:var(--so-radius-md);padding:12px 14px;display:flex;flex-direction:column;gap:6px;">
                            <span style="font-size:var(--so-text-label);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-label);"><?php esc_html_e('Config file:', 'e2mconnect'); ?></span>
                            <span style="font-size:10px;color:var(--so-text-weak);line-height:14px;">~/.continue/config.json</span>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; /* end disabled client panels */ ?>

            <!-- Custom -->
            <div class="e2m-client-panel" id="e2m-client-custom" style="display:none;">
                <!-- Top row: ENV code block + Connection Details (50/50) -->
                <div class="e2m-tab-grid" style="margin-bottom:var(--so-space-4);">
                    <!-- Left: ENV code block -->
                    <div class="e2m-tab-left">
                        <?php e2m_engine_render_code_block('e2m-mcp-env-vars', __('Copy JSON', 'e2mconnect'), $generic_env); ?>
                    </div>
                    <!-- Right: Connection Details -->
                    <div class="e2m-tab-right">
                        <p style="margin:0;font-size:var(--so-text-base);font-weight:var(--so-weight-medium);color:var(--so-text-default);line-height:var(--so-leading-caption);"><?php esc_html_e('Connection Details', 'e2mconnect'); ?></p>
                        <div style="display:flex;flex-direction:column;gap:var(--so-space-2);">
                            <?php
                            $details = [
                                'Transport' => 'stdio',
                                'Command'   => 'npx -y @automattic/mcp-wordpress-remote@latest',
                                'Endpoint'  => $rest_url,
                                'Username'  => $username,
                                'Password'  => $display_password,
                            ];
                            foreach ($details as $label => $value): ?>
                                <div style="display:flex;gap:32px;align-items:center;">
                                    <span style="font-size:var(--so-text-base);font-weight:var(--so-weight-normal);color:var(--so-text-weak);line-height:var(--so-leading-caption);white-space:nowrap;width:80px;flex-shrink:0;"><?php echo esc_html($label); ?></span>
                                    <code style="background:var(--so-bg-light);border:1px solid var(--so-bg-secondary);padding:2px 7px;border-radius:4px;font-size:10px;color:var(--so-text-weak);line-height:14px;font-family:var(--so-font);white-space:nowrap;"><?php echo esc_html($value); ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <!-- Bottom: Full-width JSON code block -->
                <?php e2m_engine_render_code_block('e2m-mcp-generic-json', __('Copy JSON', 'e2mconnect'), $desktop_json); ?>
            </div>

            </div><!-- end inner padding -->

            <!-- Troubleshooting footer (expandable) -->
            <details id="e2m-troubleshooting" style="border-top:1px solid var(--so-border-weak);padding:16px;">
                <summary style="cursor:pointer;font-size:var(--so-text-base);color:var(--so-brand-primary);font-weight:var(--so-weight-medium);list-style:none;display:flex;align-items:center;gap:8px;line-height:var(--so-leading-caption);">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">
                        <circle cx="8" cy="8" r="7" stroke="var(--so-brand-primary)" stroke-width="1.5"/>
                        <path d="M8 7.5V11.5" stroke="var(--so-brand-primary)" stroke-width="1.5" stroke-linecap="round"/>
                        <circle cx="8" cy="5.25" r="0.875" fill="var(--so-brand-primary)"/>
                    </svg>
                    <?php esc_html_e('Troubleshooting', 'e2mconnect'); ?>
                </summary>

                <?php if (false): /* troubleshooting for removed clients */ ?>
                <!-- Claude Desktop -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="claude-desktop" style="margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Server not showing? Restart Claude Desktop completely (quit from system tray).', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('"Connection failed" - Verify your site is accessible and HTTPS is working.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('"401 Unauthorized" - Check username and app password are correct.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Ensure Node.js v18+ and npx are installed on your system.', 'e2mconnect'); ?></li>
                </ul>

                <!-- Claude Code -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="claude-code" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Run "claude mcp list" to verify the server is registered.', 'e2mconnect'); ?></li>
                    <li><?php
                    // translators: %s is the unique MCP server key, e.g. "e2m-wordpress-xkq".
                    echo esc_html( sprintf( __( 'Use "claude mcp remove %s" to remove and re-add if needed.', 'e2mconnect' ), $server_key ) ); ?></li>
                    <li><?php esc_html_e('"401 Unauthorized" - Double-check the app password has no extra spaces or quotes.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Ensure Claude CLI is updated: npm update -g @anthropic-ai/claude-code', 'e2mconnect'); ?></li>
                </ul>

                <!-- Cursor -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="cursor" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Red dot = connection failed. Hover to see the error.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Toggle the server off/on in Settings > Features > MCP.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('"Cannot find npx" - Install Node.js and ensure npx is in PATH.', 'e2mconnect'); ?></li>
                </ul>

                <!-- VS Code -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="vscode" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Switch Copilot Chat to "Agent" mode (not "Ask" or "Edit").', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Ensure "chat.mcp.enabled" is true in VS Code settings.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('VS Code uses "servers" key (not "mcpServers") in .vscode/mcp.json.', 'e2mconnect'); ?></li>
                </ul>

                <!-- Windsurf -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="windsurf" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Click "Refresh" in MCP panel after editing config.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('MCP tools only work in Cascade mode.', 'e2mconnect'); ?></li>
                </ul>

                <!-- Zed -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="zed" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Key is "context_servers" (not "mcpServers"). Wrong key silently fails.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Requires latest Zed stable or preview build.', 'e2mconnect'); ?></li>
                </ul>

                <!-- Cline -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="cline" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Red dot = failed. Click server name for error logs.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Cline may prompt to approve MCP tools - click "Allow".', 'e2mconnect'); ?></li>
                </ul>

                <!-- Continue -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="continue" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('MCP is under "experimental" in Continue - use the correct key.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Update Continue extension for best compatibility.', 'e2mconnect'); ?></li>
                </ul>
                <?php endif; /* end troubleshooting for removed clients */ ?>

                <!-- Custom -->
                <ul class="e2m-troubleshoot-list" data-troubleshoot="custom" style="display:none;margin:12px 0 0;padding-left:20px;font-size:var(--so-text-base);color:var(--so-text-weak);line-height:1.8;list-style:disc;">
                    <li><?php esc_html_e('Node.js v18+ must be installed for npx to work.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('HTTPS required for production. Localhost works with HTTP.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('Config keys vary: mcpServers, servers, context_servers - check your client docs.', 'e2mconnect'); ?></li>
                    <li><?php esc_html_e('If using a security plugin, whitelist the MCP REST endpoint.', 'e2mconnect'); ?></li>
                </ul>
            </details>
        </div><!-- end card -->
    </div>

    <script>
    function e2mMcpCopy(id, button) {
        var el = document.getElementById(id);
        var text = el.value !== undefined && el.value !== '' ? el.value : el.textContent;
        navigator.clipboard.writeText(text).then(function () {
            var old = button.innerHTML;
            button.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="flex-shrink:0;margin-right:6px;"><circle cx="7" cy="7" r="7" fill="#A7EAAA"/><path d="M4.5 7L6.25 8.75L9.5 5.25" stroke="#181825" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Copied!';
            setTimeout(function () { button.innerHTML = old; }, 1500);
        });
    }
    function e2mCopyAutoConnectMd(button) {
        var cmd = button.getAttribute('data-autoconnect') || '';
        if (!cmd) return;
        navigator.clipboard.writeText(cmd).then(function () {
            var old = button.innerHTML;
            button.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="flex-shrink:0;margin-right:6px;"><circle cx="7" cy="7" r="7" fill="#A7EAAA"/><path d="M4.5 7L6.25 8.75L9.5 5.25" stroke="#181825" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Copied!';
            setTimeout(function () { button.innerHTML = old; }, 2000);
        });
    }
    (function () {
        var tabsContainer = document.getElementById('e2m-pill-tabs');
        var indicator = document.getElementById('e2m-pill-indicator');
        var pills = tabsContainer ? tabsContainer.querySelectorAll('.so-pill-tab') : [];
        var panels = document.querySelectorAll('.e2m-client-panel');

        function moveIndicator(tab) {
            if (!indicator || !tabsContainer) return;
            var containerRect = tabsContainer.getBoundingClientRect();
            var tabRect = tab.getBoundingClientRect();
            indicator.style.width = tabRect.width + 'px';
            indicator.style.transform = 'translateX(' + (tabRect.left - containerRect.left - 3) + 'px)';
        }

        // Position indicator on page load
        var activeTab = tabsContainer ? tabsContainer.querySelector('.so-pill-tab.active') : null;
        if (activeTab) {
            // No transition on initial position
            if (indicator) indicator.style.transition = 'none';
            moveIndicator(activeTab);
            // Re-enable transition after paint
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    if (indicator) indicator.style.transition = '';
                });
            });
        }

        var troubleshootLists = document.querySelectorAll('.e2m-troubleshoot-list');

        pills.forEach(function (pill) {
            pill.addEventListener('click', function (e) {
                e.preventDefault();
                var target = this.getAttribute('data-client');
                pills.forEach(function (p) { p.classList.remove('active'); });
                this.classList.add('active');
                moveIndicator(this);
                panels.forEach(function (p) {
                    p.style.display = p.id === 'e2m-client-' + target ? 'block' : 'none';
                });
                // Switch troubleshooting content to match active tab
                troubleshootLists.forEach(function (list) {
                    list.style.display = list.getAttribute('data-troubleshoot') === target ? '' : 'none';
                });
            });
        });

        // Reposition on window resize
        window.addEventListener('resize', function () {
            var active = tabsContainer ? tabsContainer.querySelector('.so-pill-tab.active') : null;
            if (active && indicator) {
                indicator.style.transition = 'none';
                moveIndicator(active);
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        if (indicator) indicator.style.transition = '';
                    });
                });
            }
        });
    })();
    </script>

    <!-- ═══ Node / npm Install Guide Modal ═══ -->
    <div id="e2m-npm-guide-modal" class="so-npm-guide-backdrop">
        <div class="so-npm-guide-card">

            <!-- Header -->
            <div class="so-npm-guide-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="so-npm-guide-header-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--so-status-success)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </div>
                    <div>
                        <p class="so-npm-guide-title"><?php esc_html_e( 'Node.js & npm Install Guide', 'e2mconnect' ); ?></p>
                        <p class="so-npm-guide-subtitle"><?php esc_html_e( 'Required to run npx MCP commands', 'e2mconnect' ); ?></p>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('e2m-npm-guide-modal').style.display='none'" class="so-npm-guide-close">&times;</button>
            </div>

            <!-- Body -->
            <div style="padding:24px;display:flex;flex-direction:column;gap:20px;">

                <!-- Step 1 -->
                <div class="so-npm-guide-step">
                    <div class="so-npm-guide-step-header">
                        <span class="so-npm-guide-step-num">1</span>
                        <span class="so-npm-guide-step-title"><?php esc_html_e( 'Check if already installed', 'e2mconnect' ); ?></span>
                    </div>
                    <div style="padding:16px;">
                        <p class="so-npm-guide-step-text" style="margin:0 0 10px;"><?php esc_html_e( 'Open Terminal (Mac/Linux) or Command Prompt (Windows) and run:', 'e2mconnect' ); ?></p>
                        <div class="e2m-ng-code">
                            <div class="e2m-ng-code-bar"><span>Terminal</span><button type="button" class="e2m-ng-copy" data-copy="node --version&#10;npm --version"><?php esc_html_e( 'Copy', 'e2mconnect' ); ?></button></div>
                            <pre><code>node --version
npm --version</code></pre>
                        </div>
                        <p class="so-npm-guide-step-hint" style="margin:8px 0 0;"><?php esc_html_e( 'If you see version numbers — you\'re all set, skip to Step 3.', 'e2mconnect' ); ?></p>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="so-npm-guide-step">
                    <div class="so-npm-guide-step-header">
                        <span class="so-npm-guide-step-num">2</span>
                        <span class="so-npm-guide-step-title"><?php esc_html_e( 'Install Node.js & npm', 'e2mconnect' ); ?></span>
                    </div>
                    <div style="padding:16px;">
                        <!-- OS tabs inside modal -->
                        <div class="e2m-ng-tabs" style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
                            <button type="button" class="e2m-ng-tab active" data-panel="ng-recommended"><?php esc_html_e( 'Recommended', 'e2mconnect' ); ?></button>
                            <button type="button" class="e2m-ng-tab" data-panel="ng-windows">Windows</button>
                            <button type="button" class="e2m-ng-tab" data-panel="ng-mac">macOS</button>
                            <button type="button" class="e2m-ng-tab" data-panel="ng-linux">Linux</button>
                        </div>

                        <div id="ng-recommended" class="e2m-ng-panel" style="display:block;">
                            <p class="so-npm-guide-step-text" style="margin:0 0 10px;"><?php esc_html_e( 'Download the official installer — installs both Node.js and npm.', 'e2mconnect' ); ?></p>
                            <a href="https://nodejs.org/en/download" target="_blank" rel="noopener noreferrer" class="so-npm-guide-cta">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <?php esc_html_e( 'Download Node.js (LTS)', 'e2mconnect' ); ?>
                            </a>
                            <p class="so-npm-guide-step-hint" style="margin:10px 0 0;"><?php esc_html_e( 'Choose LTS version. After install, restart your terminal.', 'e2mconnect' ); ?></p>
                        </div>

                        <div id="ng-windows" class="e2m-ng-panel" style="display:none;">
                            <p class="so-npm-guide-step-text" style="margin:0 0 8px;"><strong><?php esc_html_e( 'Option A — Official installer:', 'e2mconnect' ); ?></strong></p>
                            <a href="https://nodejs.org/en/download" target="_blank" rel="noopener noreferrer" class="so-npm-guide-cta">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <?php esc_html_e( 'Download for Windows', 'e2mconnect' ); ?>
                            </a>
                            <p class="so-npm-guide-step-text" style="margin:14px 0 6px;"><strong><?php esc_html_e( 'Option B — winget:', 'e2mconnect' ); ?></strong></p>
                            <div class="e2m-ng-code">
                                <div class="e2m-ng-code-bar"><span>PowerShell</span><button type="button" class="e2m-ng-copy" data-copy="winget install OpenJS.NodeJS.LTS"><?php esc_html_e( 'Copy', 'e2mconnect' ); ?></button></div>
                                <pre><code>winget install OpenJS.NodeJS.LTS</code></pre>
                            </div>
                        </div>

                        <div id="ng-mac" class="e2m-ng-panel" style="display:none;">
                            <p class="so-npm-guide-step-text" style="margin:0 0 6px;"><strong><?php esc_html_e( 'Option A — Homebrew:', 'e2mconnect' ); ?></strong></p>
                            <div class="e2m-ng-code">
                                <div class="e2m-ng-code-bar"><span>Terminal</span><button type="button" class="e2m-ng-copy" data-copy="brew install node"><?php esc_html_e( 'Copy', 'e2mconnect' ); ?></button></div>
                                <pre><code>brew install node</code></pre>
                            </div>
                            <p class="so-npm-guide-step-text" style="margin:12px 0 6px;"><strong><?php esc_html_e( 'Option B — Official installer:', 'e2mconnect' ); ?></strong></p>
                            <a href="https://nodejs.org/en/download" target="_blank" rel="noopener noreferrer" class="so-npm-guide-cta">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <?php esc_html_e( 'Download for macOS', 'e2mconnect' ); ?>
                            </a>
                        </div>

                        <div id="ng-linux" class="e2m-ng-panel" style="display:none;">
                            <p class="so-npm-guide-step-text" style="margin:0 0 6px;"><strong>Ubuntu / Debian:</strong></p>
                            <div class="e2m-ng-code">
                                <div class="e2m-ng-code-bar"><span>Terminal</span><button type="button" class="e2m-ng-copy" data-copy="sudo apt update&#10;sudo apt install nodejs npm -y"><?php esc_html_e( 'Copy', 'e2mconnect' ); ?></button></div>
                                <pre><code>sudo apt update
sudo apt install nodejs npm -y</code></pre>
                            </div>
                            <p class="so-npm-guide-step-text" style="margin:12px 0 6px;"><strong>Fedora / RHEL:</strong></p>
                            <div class="e2m-ng-code">
                                <div class="e2m-ng-code-bar"><span>Terminal</span><button type="button" class="e2m-ng-copy" data-copy="sudo dnf install nodejs npm -y"><?php esc_html_e( 'Copy', 'e2mconnect' ); ?></button></div>
                                <pre><code>sudo dnf install nodejs npm -y</code></pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="so-npm-guide-step">
                    <div class="so-npm-guide-step-header">
                        <span class="so-npm-guide-step-num">3</span>
                        <span class="so-npm-guide-step-title"><?php esc_html_e( 'Verify & restart terminal', 'e2mconnect' ); ?></span>
                    </div>
                    <div style="padding:16px;">
                        <p class="so-npm-guide-step-text" style="margin:0 0 10px;"><?php esc_html_e( 'Open a new terminal window and confirm:', 'e2mconnect' ); ?></p>
                        <div class="e2m-ng-code">
                            <div class="e2m-ng-code-bar"><span>Terminal</span><button type="button" class="e2m-ng-copy" data-copy="node --version&#10;npm --version"><?php esc_html_e( 'Copy', 'e2mconnect' ); ?></button></div>
                            <pre><code>node --version   # e.g. v20.11.0
npm --version    # e.g. 10.2.4</code></pre>
                        </div>
                    </div>
                </div>

            </div><!-- /body -->
        </div>
    </div>

    <?php // Styles for .e2m-ng-* live in e2m-ui/admin-design-system.css §21. ?>

    <script>
    (function(){
        // Close modal on backdrop click
        var modal = document.getElementById('e2m-npm-guide-modal');
        if(modal) {
            modal.addEventListener('click', function(e){
                if(e.target === modal) modal.style.display = 'none';
            });
        }

        // OS tab switching inside modal
        document.querySelectorAll('.e2m-ng-tab').forEach(function(btn){
            btn.addEventListener('click', function(){
                var panelId = btn.dataset.panel;
                btn.closest('.e2m-ng-tabs').querySelectorAll('.e2m-ng-tab').forEach(function(t){ t.classList.remove('active'); });
                document.querySelectorAll('.e2m-ng-panel').forEach(function(p){ p.style.display='none'; });
                btn.classList.add('active');
                var panel = document.getElementById(panelId);
                if(panel) panel.style.display='block';
            });
        });

        // Copy buttons
        document.querySelectorAll('.e2m-ng-copy').forEach(function(btn){
            btn.addEventListener('click', function(){
                var text = (btn.dataset.copy||'').replace(/&#10;/g,'\n');
                navigator.clipboard.writeText(text).then(function(){
                    var orig = btn.textContent;
                    btn.textContent = <?php echo wp_json_encode(__('Copied!', 'e2mconnect')); ?>;
                    btn.classList.add('ng-copied');
                    setTimeout(function(){ btn.textContent = orig; btn.classList.remove('ng-copied'); }, 2000);
                });
            });
        });
    })();
    </script>
    <?php
}

/**
 * Group registered abilities by prefix for the settings UI.
 *
 * @return array<string, array<int, array{name: string, label: string}>>
 */
function e2m_engine_get_grouped_abilities(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $own_slug = basename(E2M_ENGINE_PLUGIN_DIR);
    $groups   = [];

    foreach (wp_get_abilities() as $ability) {
        if (!($ability instanceof WP_Ability)) {
            continue;
        }

        $name     = $ability->get_name();
        $category = method_exists($ability, 'get_category') ? $ability->get_category() : '';
        $source   = e2m_engine_get_ability_source($name);

        // Abilities from THIS plugin use the existing fine-grained grouping.
        // Abilities from OTHER plugins are grouped under their plugin name.
        if ($source === '' || $source === $own_slug) {
            $group = e2m_engine_classify_ability($name, $category);
        } else {
            $info  = e2m_engine_get_plugin_info($source);
            $group = $info['name'];
        }

        // Build tags from WP Abilities API annotations (universal) + custom enrichment.
        $meta = $ability->get_meta();
        $annotations = $meta['annotations'] ?? [];
        $tags = e2m_engine_tags_from_annotations($annotations, $name);

        $groups[$group][] = [
            'name'        => $name,
            'label'       => $ability->get_label() ?: $name,
            'description' => $ability->get_description(),
            'tags'        => $tags,
            'source'      => $source,
            'annotations' => $annotations,
            'category'    => $category,
        ];
    }

    ksort($groups);
    return $groups;
}

/**
 * Build tags from WP Abilities API standard annotations.
 * Works universally for ANY plugin's abilities.
 *
 * Annotations: readonly, destructive, idempotent (bool|null).
 * Falls back to name-pattern heuristics when annotations are null.
 *
 * @param array  $annotations The annotations array from ability meta.
 * @param string $name        The ability name (used for fallback heuristics).
 * @return array<array{label:string,color:string,tip:string}>
 */
function e2m_engine_tags_from_annotations(array $annotations, string $name): array
{
    $tags = [];
    $readonly    = $annotations['readonly'] ?? null;
    $destructive = $annotations['destructive'] ?? null;
    $idempotent  = $annotations['idempotent'] ?? null;

    // -- Primary risk-level tag (mutually exclusive) ---------------
    // Colors from Figma node 8042:203941 - explicit bg/text/border triplets.

    if ($destructive === true) {
        $tags[] = [
            'label'  => __('High Risk', 'e2mconnect'),
            'color'  => '#f65258',
            'bg'     => '#fff2ef',
            'border' => '#fdc9c6',
            'tip'    => __('May perform destructive changes to your environment - enable with care', 'e2mconnect'),
        ];
    } elseif ($readonly === true) {
        $tags[] = [
            'label'  => __('Read Only', 'e2mconnect'),
            'color'  => '#2ea343',
            'bg'     => '#e7ffe7',
            'border' => '#a7eaaa',
            'tip'    => __('Does not modify your environment - safe to keep enabled', 'e2mconnect'),
        ];
    } elseif ($readonly === false && $destructive === false) {
        $tags[] = [
            'label'  => __('Create/Info', 'e2mconnect'),
            'color'  => '#5202fd',
            'bg'     => '#f2eeff',
            'border' => '#b8a9ff',
            'tip'    => __('Creates or updates content but does not delete - generally safe', 'e2mconnect'),
        ];
    } else {
        // Annotations are null - fall back to name-pattern heuristics.
        if (preg_match('/(delete|remove|destroy|purge)/', $name)) {
            $tags[] = ['label' => __('High Risk', 'e2mconnect'), 'color' => '#f65258', 'bg' => '#fff2ef', 'border' => '#fdc9c6', 'tip' => __('May perform destructive changes', 'e2mconnect')];
        } elseif (preg_match('/(get-|list-|read-|find-|search-|export-|discover|info)/', $name)) {
            $tags[] = ['label' => __('Read Only', 'e2mconnect'), 'color' => '#2ea343', 'bg' => '#e7ffe7', 'border' => '#a7eaaa', 'tip' => __('Only reads data, makes no changes', 'e2mconnect')];
        } elseif (preg_match('/(create-|add-|build-|import-|sideload|upload)/', $name)) {
            $tags[] = ['label' => __('Create/Info', 'e2mconnect'), 'color' => '#5202fd', 'bg' => '#f2eeff', 'border' => '#b8a9ff', 'tip' => __('Creates new content', 'e2mconnect')];
        } elseif (preg_match('/(update-|edit-|modify-|set-|toggle-|move-|reorder-)/', $name)) {
            $tags[] = ['label' => __('Caution', 'e2mconnect'), 'color' => '#ffa940', 'bg' => '#fff1eb', 'border' => '#ffc680', 'tip' => __('Modifies existing content or settings', 'e2mconnect')];
        }
    }

    // -- Secondary behavioral tag ----------------------------------

    if ($idempotent === true && $readonly !== true) {
        $tags[] = [
            'label'  => __('Idempotent', 'e2mconnect'),
            'color'  => '#5202fd',
            'bg'     => '#f2eeff',
            'border' => '#b8a9ff',
            'tip'    => __('Repeated calls with same input have no additional effect', 'e2mconnect'),
        ];
    }

    // -- Special capability tags (name-based enrichment) -----------

    if (str_contains($name, 'execute-php') || str_contains($name, 'batch-execute')) {
        $tags[] = [
            'label'  => __('Code Exec', 'e2mconnect'),
            'color'  => '#00b6c9',
            'bg'     => '#cff9ff',
            'border' => '#7af1ff',
            'tip'    => __('Executes arbitrary code on your server - highest privilege level', 'e2mconnect'),
        ];
    }

    return $tags;
}

/**
 * Build a map of group_name => source plugin slug for display purposes.
 *
 * @param array $grouped_abilities The grouped abilities array.
 * @return array<string, string> group_name => plugin slug
 */
function e2m_engine_get_group_sources(array $grouped_abilities): array
{
    $map = [];
    foreach ($grouped_abilities as $group_name => $abilities) {
        $slugs = [];
        foreach ($abilities as $ab) {
            $s = $ab['source'] ?? '';
            if ($s !== '') {
                $slugs[$s] = ($slugs[$s] ?? 0) + 1;
            }
        }
        // Use the most common slug in the group.
        if (!empty($slugs)) {
            arsort($slugs);
            $map[$group_name] = array_key_first($slugs);
        }
    }
    return $map;
}

/**
 * Summarize abilities registered by plugins other than E2M.
 *
 * @param array $grouped_abilities The grouped abilities array.
 * @return array{total:int,sources:array<int,array{name:string,count:int}>}
 */
function e2m_engine_get_external_abilities_summary(array $grouped_abilities): array
{
    $own_slug = basename(E2M_ENGINE_PLUGIN_DIR);
    $total = 0;
    $sources = [];

    foreach ($grouped_abilities as $abilities) {
        foreach ($abilities as $ability) {
            $source_slug = $ability['source'] ?? '';
            if ($source_slug === '' || $source_slug === $own_slug) {
                continue;
            }

            $info = e2m_engine_get_plugin_info($source_slug);
            $total++;

            if (!isset($sources[$source_slug])) {
                $sources[$source_slug] = [
                    'name' => $info['name'],
                    'count' => 0,
                ];
            }

            $sources[$source_slug]['count']++;
        }
    }

    uasort($sources, static function (array $a, array $b): int {
        return $b['count'] <=> $a['count'];
    });

    return [
        'total' => $total,
        'sources' => array_values($sources),
    ];
}

/**
 * Classify an ability into a display group based on its name and category.
 *
 * @param string $name     Fully qualified ability name.
 * @param string $category The ability's registered category (e.g. 'e2m-elementor').
 */
function e2m_engine_classify_ability(string $name, string $category = ''): string
{
    // E2M Bridge - protocol-level abilities.
    if (
        str_starts_with($name, 'e2m-bridge/')
        || str_starts_with($name, 'mcp-adapter/')
        || str_starts_with($name, 'mcp/')
    ) {
        return 'Bridge';
    }

    // Nexter Extension - sandbox-registered nexter abilities.
    if (str_starts_with($name, 'nexter/')) {
        return 'Nexter Extension';
    }

    // WDesignKit - widget builder abilities.
    if (str_starts_with($name, 'wdesignkit/')) {
        return 'WDesignKit';
    }

    // The Plus Addons - all ThePlus widget abilities.
    if (str_contains($name, 'theplus')) {
        return 'The Plus Addons';
    }

    // WooCommerce - abilities registered with category 'woocommerce-e2m'.
    if ($category === 'woocommerce-e2m' || str_starts_with($name, 'e2m/woocommerce/')) {
        return 'WooCommerce';
    }

    // Elementor - abilities registered via the elementor-mcp bridge (category = 'e2m-elementor')
    // or whose name explicitly contains 'elementor'.
    if ($category === 'e2m-elementor' || str_contains($name, 'elementor')) {
        return 'Elementor';
    }

    // Bricks Builder - abilities registered with category 'e2m-bricks'.
    if ($category === 'e2m-bricks' || str_starts_with($name, 'e2m/bricks-')) {
        return 'Bricks';
    }

    // Advanced Custom Fields - abilities registered with category 'e2m-acf'.
    if ($category === 'e2m-acf' || str_starts_with($name, 'e2m/acf-')) {
        return 'ACF';
    }

    // JetEngine - abilities registered with category 'e2m-jetengine'.
    if ($category === 'e2m-jetengine' || str_starts_with($name, 'e2m/jetengine-')) {
        return 'JetEngine';
    }

    // Meta Box - abilities registered with category 'e2m-metabox'.
    if ($category === 'e2m-metabox' || str_starts_with($name, 'e2m/metabox-')) {
        return 'Meta Box';
    }

    // ACPT - abilities registered with category 'e2m-acpt-plugin'.
    if ($category === 'e2m-acpt-plugin' || str_starts_with($name, 'e2m/acpt-')) {
        return 'ACPT';
    }

    // ASE - abilities registered with category 'e2m-ase'.
    if ($category === 'e2m-ase' || str_starts_with($name, 'e2m/ase-')) {
        return 'ASE';
    }

    // Pods - abilities registered with category 'e2m-pods'.
    if ($category === 'e2m-pods' || str_starts_with($name, 'e2m/pods-')) {
        return 'Pods';
    }

    // Divi - abilities registered with category 'e2m-divi'.
    if ($category === 'e2m-divi' || str_starts_with($name, 'e2m/divi-')) {
        return 'Divi';
    }

    // Oxygen Builder - abilities registered with category 'e2m-oxygen'.
    if ($category === 'e2m-oxygen' || str_starts_with($name, 'e2m/oxygen-')) {
        return 'Oxygen';
    }

    // Breakdance Builder - abilities registered with category 'e2m-breakdance'.
    if ($category === 'e2m-breakdance' || str_starts_with($name, 'e2m/breakdance-')) {
        return 'Breakdance';
    }

    // WPBakery Page Builder - abilities registered with category 'e2m-wpbakery'.
    if ($category === 'e2m-wpbakery' || str_starts_with($name, 'e2m/wpbakery-')) {
        return 'WPBakery';
    }

    // Beaver Builder - abilities registered with category 'e2m-beaver'.
    if ($category === 'e2m-beaver' || str_starts_with($name, 'e2m/beaver-')) {
        return 'Beaver Builder';
    }

    // E2M Connect - filesystem, code execution, pages, themes, sandbox settings.
    if (str_starts_with($name, 'e2m/')) {
        return 'E2M Connect';
    }

    // Any remaining unrecognized ability goes under WordPress Core.
    return 'E2M Connect';
}

/**
 * Render HTML for ability sensitivity tags.
 *
 * @param array $tags Tags from e2m_engine_get_ability_tags().
 */
function e2m_engine_render_ability_tags(array $tags): void
{
    foreach ($tags as $tag) {
        $bg     = $tag['bg'] ?? ($tag['color'] . '18');
        $border = $tag['border'] ?? ($tag['color'] . '40');
        printf(
            '<span class="e2m-ability-tag" style="background:%s;color:%s;border-color:%s;" title="%s">%s</span>',
            esc_attr($bg),
            esc_attr($tag['color']),
            esc_attr($border),
            esc_attr($tag['tip']),
            esc_html($tag['label'])
        );
    }
}
/**
 * AJAX handler: Return sandbox file source code for the View Code modal.
 *
 * SECURITY: Requires manage_options + nonce. Only reads files inside E2M_ENGINE_SANDBOX_DIR.
 */
function e2m_engine_ajax_view_sandbox_source(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'e2mconnect')], 403);
    }

    check_ajax_referer('e2m_engine_sandbox_source', 'nonce');

    if (!defined('E2M_ENGINE_SANDBOX_DIR')) {
        wp_send_json_error(['message' => __('Sandbox directory not configured.', 'e2mconnect')]);
    }

    // Accept relative paths inside the sandbox (single files OR
    // project subfiles like "myproject/myproject.php"). Allow letters,
    // digits, _ . - and /. Strip any other character. Block parent
    // traversal explicitly. The realpath check below is the final
    // security backstop.
    $raw_file = (string) wp_unslash($_POST['file'] ?? '');
    $file     = preg_replace('#[^A-Za-z0-9_./-]#', '', $raw_file);
    $file     = ltrim((string) $file, '/');
    if ($file === '' || str_contains($file, '..')) {
        wp_send_json_error(['message' => __('Invalid file name.', 'e2mconnect')]);
    }

    $sandbox_dir = E2M_ENGINE_SANDBOX_DIR;

    // Try both active and disabled paths. For projects, the .disabled
    // suffix lives on the directory name (already included in $file
    // when emitted from the listing), so the second branch rarely
    // applies there but stays safe for the single-file case.
    $filepath = $sandbox_dir . $file;
    if (!file_exists($filepath)) {
        $filepath = $sandbox_dir . $file . '.disabled';
    }

    if (!file_exists($filepath)) {
        wp_send_json_error(['message' => __('File not found.', 'e2mconnect')]);
    }

    // Verify the resolved path is inside the sandbox (prevent traversal).
    // Append a separator so a sibling like "sandbox-evil/" cannot match
    // "sandbox/" via prefix.
    $real_path = realpath($filepath);
    $real_sandbox = realpath($sandbox_dir);
    $sandbox_with_sep = $real_sandbox !== false ? rtrim($real_sandbox, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
    if ($real_path === false || $real_sandbox === false || !str_starts_with($real_path, $sandbox_with_sep)) {
        wp_send_json_error(['message' => __('Access denied.', 'e2mconnect')]);
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $contents = file_get_contents($real_path);
    if ($contents === false) {
        wp_send_json_error(['message' => __('Unable to read file.', 'e2mconnect')]);
    }

    wp_send_json_success(['content' => $contents]);
}

/**
 * Handle sandbox file actions (PRG pattern - runs in admin_init).
 */
function e2m_engine_handle_sandbox_actions(): void
{
    if (!current_user_can('manage_options') || !defined('E2M_ENGINE_SANDBOX_DIR')) {
        return;
    }

    $sandbox_dir = E2M_ENGINE_SANDBOX_DIR;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug for redirect target.
    $page_slug   = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
    if (!in_array($page_slug, ['e2mconnect', 'e2m-mcp'], true)) {
        $page_slug = 'e2mconnect';
    }

    // Check for bulk action first, then single action.
    $bulk_action = sanitize_text_field(wp_unslash($_POST['e2m_engine_sandbox_bulk_action'] ?? ''));
    $action = $bulk_action !== '' ? $bulk_action : sanitize_text_field(wp_unslash($_POST['e2m_engine_sandbox_action'] ?? ''));
    if ($action === '') {
        return;
    }

    $file = sanitize_text_field(wp_unslash($_POST['e2m_engine_sandbox_file'] ?? ''));
    $result = 'unknown';
    $result_file = $file;
    $is_project_action = str_ends_with($file, '/');
    $clean_name = $is_project_action ? rtrim($file, '/') : $file;

    // Helper: reject symlinks inside the sandbox (matches MCP ability protections).
    $reject_link = static function (string $path): bool {
        return file_exists($path) && is_link($path);
    };

    // Helper: recursively delete a directory.
    $rm_dir = static function (string $dir) use (&$rm_dir): void {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) as $item) {
            $base = basename($item);
            if ($base === '.' || $base === '..') { continue; }
            is_dir($item) ? $rm_dir($item) : wp_delete_file($item);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        @rmdir($dir);
    };

    // -- Bulk actions -----------------------------------------------
    if ($action === 'bulk_delete' || $action === 'bulk_disable' || $action === 'bulk_enable') {
        check_admin_referer('e2m_engine_sandbox_bulk');
        $selected = isset($_POST['e2m_engine_sandbox_selected']) && is_array($_POST['e2m_engine_sandbox_selected'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['e2m_engine_sandbox_selected']))
            : [];

        $count = 0;
        foreach ($selected as $sf) {
            if ($sf === '') { continue; }
            $is_proj = str_ends_with($sf, '/');
            $base = $is_proj ? rtrim($sf, '/') : $sf;
            $base = sanitize_file_name($base);

            if ($is_proj) {
                $dirpath = $sandbox_dir . $base;
                if ($reject_link($dirpath) || $reject_link($dirpath . '.disabled')) { continue; }
                if ($action === 'bulk_delete') {
                    $target = is_dir($dirpath) ? $dirpath : (is_dir($dirpath . '.disabled') ? $dirpath . '.disabled' : null);
                    if ($target) { $rm_dir($target); $count++; }
                } elseif ($action === 'bulk_disable' && is_dir($dirpath)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                    rename($dirpath, $dirpath . '.disabled'); $count++;
                } elseif ($action === 'bulk_enable' && is_dir($dirpath . '.disabled')) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                    rename($dirpath . '.disabled', $dirpath); $count++;
                }
            } else {
                $filepath = $sandbox_dir . $base;
                if ($reject_link($filepath) || $reject_link($filepath . '.disabled')) { continue; }
                if ($action === 'bulk_delete') {
                    $target = file_exists($filepath) ? $filepath : (file_exists($filepath . '.disabled') ? $filepath . '.disabled' : null);
                    if ($target) { wp_delete_file($target); wp_delete_file($filepath . '.validated'); $count++; }
                } elseif ($action === 'bulk_disable' && file_exists($filepath)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                    rename($filepath, $filepath . '.disabled'); $count++;
                } elseif ($action === 'bulk_enable' && file_exists($filepath . '.disabled')) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                    rename($filepath . '.disabled', $filepath);
                    $count++;
                }
            }
        }
        $result = 'bulk_' . str_replace('bulk_', '', $action);
        $result_file = (string) $count;

    // -- Single actions ---------------------------------------------
    } elseif ($action === 'exit_safe_mode') {
        check_admin_referer('e2m_engine_sandbox_exit_safe_mode');
        $crashed = $sandbox_dir . '.crashed';
        if (file_exists($crashed)) {
            wp_delete_file($crashed);
        }
        $result = 'safe_mode_cleared';

    // -- Project folder actions -------------------------------------
    } elseif ($is_project_action && $clean_name !== '') {
        $clean_name = sanitize_file_name($clean_name);
        check_admin_referer('e2m_engine_sandbox_' . $clean_name);
        $dirpath = $sandbox_dir . $clean_name;

        if ($reject_link($dirpath) || $reject_link($dirpath . '.disabled')) {
            wp_safe_redirect(add_query_arg(['page' => $page_slug, 'tab' => 'sandbox', 'e2m_result' => 'error'], admin_url('admin.php')));
            exit;
        }

        if ($action === 'disable' && is_dir($dirpath)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            rename($dirpath, $dirpath . '.disabled');
            $result = 'disabled';
        } elseif ($action === 'enable' && is_dir($dirpath . '.disabled')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            rename($dirpath . '.disabled', $dirpath);
            // Force re-validation of entry file.
            $entry = $dirpath . '/' . $clean_name . '.php';
            if (file_exists($entry . '.validated')) { wp_delete_file($entry . '.validated'); }
            $result = 'enabled';
        } elseif ($action === 'delete') {
            $target = is_dir($dirpath) ? $dirpath : (is_dir($dirpath . '.disabled') ? $dirpath . '.disabled' : null);
            if ($target) {
                $rm_dir($target);
                $result = 'deleted';
            }
        }
        $result_file = $clean_name . '/';

    // -- Single file actions ----------------------------------------
    } elseif ($file !== '') {
        $file = sanitize_file_name($file);
        check_admin_referer('e2m_engine_sandbox_' . $file);
        $filepath = $sandbox_dir . $file;

        if ($reject_link($filepath) || $reject_link($filepath . '.disabled')) {
            wp_safe_redirect(add_query_arg(['page' => $page_slug, 'tab' => 'sandbox', 'e2m_result' => 'error'], admin_url('admin.php')));
            exit;
        }

        if ($action === 'disable' && file_exists($filepath)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            rename($filepath, $filepath . '.disabled');
            $result = 'disabled';
        } elseif ($action === 'enable' && file_exists($filepath . '.disabled')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            rename($filepath . '.disabled', $filepath);
            wp_delete_file($filepath . '.validated');
            $result = 'enabled';
        } elseif ($action === 'delete' && (file_exists($filepath) || file_exists($filepath . '.disabled'))) {
            $target = file_exists($filepath) ? $filepath : $filepath . '.disabled';
            wp_delete_file($target);
            wp_delete_file($filepath . '.validated');
            $result = 'deleted';
        }
    }

    wp_safe_redirect(add_query_arg([
        'page' => $page_slug,
        'tab' => 'sandbox',
        'e2m_result' => $result,
        'e2m_file' => $result_file,
    ], admin_url('admin.php')));
    exit;
}

/**
 * Extract ability names from a sandbox file by parsing wp_register_ability() calls.
 *
 * @param string $filepath Absolute path to the sandbox file.
 * @return string[] List of feature names found (abilities, shortcodes, hooks, REST routes).
 */
function e2m_engine_extract_abilities_from_file(string $filepath): array
{
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $contents = @file_get_contents($filepath);
    if ($contents === false) {
        return [];
    }

    $names = [];

    // MCP abilities: wp_register_ability( 'name' )
    if (preg_match_all('/wp_register_ability\s*\(\s*[\'"]([^\'"]+)[\'"]/s', $contents, $matches)) {
        foreach ($matches[1] as $m) {
            $names[] = $m;
        }
    }

    // Shortcodes: add_shortcode( 'name' )
    if (preg_match_all('/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/s', $contents, $matches)) {
        foreach ($matches[1] as $m) {
            $names[] = '[' . $m . ']';
        }
    }

    // REST routes: register_rest_route( 'namespace', '/path' )
    if (preg_match_all('/register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/s', $contents, $matches)) {
        for ($i = 0; $i < count($matches[1]); $i++) {
            $names[] = $matches[1][$i] . $matches[2][$i];
        }
    }

    // Hooks: add_action/add_filter( 'hook_name' ) - only show custom ones, skip WP core hooks
    $core_hooks = ['init', 'admin_init', 'admin_notices', 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'plugins_loaded', 'admin_menu', 'rest_api_init', 'template_redirect', 'wp_loaded', 'save_post', 'the_content'];
    if (preg_match_all('/add_action\s*\(\s*[\'"]([^\'"]+)[\'"]/s', $contents, $matches)) {
        foreach ($matches[1] as $m) {
            if (!in_array($m, $core_hooks, true)) {
                $names[] = 'action:' . $m;
            }
        }
    }

    return array_unique($names);
}

/**
 * Render the Sandbox admin page.
 */
function e2m_engine_render_sandbox_page_inner(): void
{
    if (!defined('E2M_ENGINE_SANDBOX_DIR')) { return; }

    $sandbox_dir = E2M_ENGINE_SANDBOX_DIR;
    $page_slug   = sanitize_text_field(wp_unslash($_GET['page'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug, no data mutation occurs.
    if (!in_array($page_slug, ['e2mconnect', 'e2m-mcp'], true)) {
        $page_slug = 'e2mconnect';
    }
    $crashed_sentinel = $sandbox_dir . '.crashed';
    $is_safe_mode = file_exists($crashed_sentinel);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $result = sanitize_text_field(wp_unslash($_GET['e2m_result'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $result_file = sanitize_file_name(wp_unslash($_GET['e2m_file'] ?? ''));

    // Check if the crash was auto-isolated (per-file) vs full safe mode.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $crashed_content = $is_safe_mode ? trim((string) @file_get_contents($crashed_sentinel)) : '';
    $is_full_safe_mode = $is_safe_mode && ($crashed_content === '1' || $crashed_content === '');

    // -- Discover standalone files + project folders --------------
    $can_lint = class_exists( 'E2M_Engine_Sandbox_Helper' ) && E2M_Engine_Sandbox_Helper::has_exec_php_lint_support();
    $php_bin  = $can_lint ? E2M_Engine_Sandbox_Helper::resolve_php_binary() : '';

    $files = [];
    $total_abilities = 0;
    $enabled_count = 0;
    $disabled_count = 0;
    $syntax_error_count = 0;
    $total_size = 0;
    $project_count = 0;

    // Helper: check syntax for an entry (and all project PHP files).
    $check_syntax = static function (string $f) use ($can_lint, $php_bin): array {
        $is_validated = class_exists('E2M_Engine_Sandbox_Helper')
            ? E2M_Engine_Sandbox_Helper::is_validation_cache_fresh($f)
            : false;
        $has_error = false;

        if (!$is_validated && $can_lint && class_exists('E2M_Engine_Sandbox_Helper')) {
            $targets = E2M_Engine_Sandbox_Helper::collect_project_php_files($f);
            foreach ($targets as $target) {
                $lint = E2M_Engine_Sandbox_Helper::lint_php_file($target, $php_bin);
                if (!$lint['ok']) {
                    $has_error = true;
                    break;
                }
            }

            if (!$has_error) {
                E2M_Engine_Sandbox_Helper::create_validation_cache($f);
                $is_validated = true;
            }
        }
        return ['validated' => $is_validated, 'syntax_error' => $has_error];
    };

    // Helper: calculate total size of a directory recursively.
    $dir_size = static function (string $dir) use (&$dir_size): int {
        $size = 0;
        foreach (glob($dir . '/*') as $item) {
            $size += is_dir($item) ? $dir_size($item) : (int) filesize($item);
        }
        return $size;
    };

    // Helper: count files in directory recursively.
    $dir_file_count = static function (string $dir) use (&$dir_file_count): int {
        $count = 0;
        foreach (glob($dir . '/*') as $item) {
            $count += is_dir($item) ? $dir_file_count($item) : 1;
        }
        return $count;
    };

    // -- Standalone files --
    $active_files = [];
    $disabled_files = [];
    if (is_dir($sandbox_dir)) {
        foreach ((glob($sandbox_dir . '*') ?: []) as $entry) {
            if (is_dir($entry) || is_link($entry)) {
                continue;
            }

            $name = basename($entry);
            if ($name === '' || str_starts_with($name, '.') || str_ends_with($name, '.validated') || str_ends_with($name, '.bak')) {
                continue;
            }

            if (str_ends_with($name, '.disabled')) {
                $disabled_files[] = $entry;
            } else {
                $active_files[] = $entry;
            }
        }
    }

    foreach ($active_files as $f) {
        $name = basename($f);
        $is_php_file = str_ends_with(strtolower($name), '.php');
        $abilities = $is_php_file ? e2m_engine_extract_abilities_from_file($f) : [];
        $fsize = (int) filesize($f);
        $syntax = $is_php_file ? $check_syntax($f) : ['validated' => false, 'syntax_error' => false];
        if ($syntax['syntax_error']) { $syntax_error_count++; }
        $files[$name] = [
            'type' => 'file', 'path' => $f, 'status' => 'enabled', 'basename' => $name,
            'abilities' => $abilities, 'size' => $fsize,
            'validated' => $syntax['validated'], 'syntax_error' => $syntax['syntax_error'],
            'toggleable' => true,
        ];
        $total_abilities += count($abilities); $total_size += $fsize;
        if ($syntax['syntax_error']) { $disabled_count++; } else { $enabled_count++; }
    }
    foreach ($disabled_files as $f) {
        $name = str_replace('.disabled', '', basename($f));
        $is_php_file = str_ends_with(strtolower($name), '.php');
        $abilities = $is_php_file ? e2m_engine_extract_abilities_from_file($f) : [];
        $fsize = (int) filesize($f);
        $files[$name] = [
            'type' => 'file', 'path' => $f, 'status' => 'disabled', 'basename' => $name,
            'abilities' => $abilities, 'size' => $fsize,
            'validated' => false, 'syntax_error' => false,
            'toggleable' => true,
        ];
        $total_abilities += count($abilities); $total_size += $fsize; $disabled_count++;
    }

    // -- Project folders --
    $all_dirs = is_dir($sandbox_dir) ? (glob($sandbox_dir . '*', GLOB_ONLYDIR) ?: []) : [];
    $disabled_dirs = is_dir($sandbox_dir) ? (glob($sandbox_dir . '*.disabled', GLOB_ONLYDIR) ?: []) : [];
    $active_dirs = array_diff($all_dirs, $disabled_dirs);

    foreach ($active_dirs as $dir) {
        $dir_name = basename($dir);
        if (str_starts_with($dir_name, '.')) { continue; }
        $entry = file_exists($dir . '/' . $dir_name . '.php') ? $dir . '/' . $dir_name . '.php' : $dir . '/index.php';
        if (!file_exists($entry)) { continue; }

        $abilities = e2m_engine_extract_abilities_from_file($entry);
        $psize = $dir_size($dir);
        $pfiles = $dir_file_count($dir);
        $syntax = $check_syntax($entry);
        if ($syntax['syntax_error']) { $syntax_error_count++; }

        $files[$dir_name . '/'] = [
            'type' => 'project', 'path' => $dir, 'entry' => $entry, 'status' => 'enabled',
            'basename' => $dir_name, 'abilities' => $abilities, 'size' => $psize,
            'file_count' => $pfiles, 'validated' => $syntax['validated'],
            'syntax_error' => $syntax['syntax_error'], 'toggleable' => true,
        ];
        $total_abilities += count($abilities); $total_size += $psize; $enabled_count++;
        $project_count++;
    }
    foreach ($disabled_dirs as $dir) {
        $dir_name = str_replace('.disabled', '', basename($dir));
        if (str_starts_with($dir_name, '.')) { continue; }
        $entry = file_exists($dir . '/' . $dir_name . '.php') ? $dir . '/' . $dir_name . '.php' : $dir . '/index.php';
        $abilities = file_exists($entry) ? e2m_engine_extract_abilities_from_file($entry) : [];
        $psize = $dir_size($dir);
        $pfiles = $dir_file_count($dir);

        $files[$dir_name . '/'] = [
            'type' => 'project', 'path' => $dir, 'entry' => $entry ?? '', 'status' => 'disabled',
            'basename' => $dir_name, 'abilities' => $abilities, 'size' => $psize,
            'file_count' => $pfiles, 'validated' => false, 'syntax_error' => false, 'toggleable' => true,
        ];
        $total_abilities += count($abilities); $total_size += $psize; $disabled_count++;
        $project_count++;
    }

    // -- Nested per-file disables inside active project folders --
    // The top-level counter above only sees standalone .disabled files and whole-folder
    // .disabled dirs; a per-file disable that targets a file INSIDE a project folder
    // (e.g. booking-pro/includes/helper.php.disabled) was not reflected in the stat card.
    // Walk each active project recursively and add any nested .disabled file.
    foreach ($active_dirs as $__e2m_active_dir) {
        if (!is_dir($__e2m_active_dir)) { continue; }
        $__e2m_it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($__e2m_active_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($__e2m_it as $__e2m_nested) {
            if ($__e2m_nested->isFile() && str_ends_with($__e2m_nested->getFilename(), '.disabled')) {
                $disabled_count++;
            }
        }
    }

    ksort($files);
    $total_entries = count($files);
    // Total files = standalone files + all files inside project folders.
    $total_files = 0;
    foreach ($files as $f_info) {
        $total_files += ($f_info['type'] === 'project') ? (int) ($f_info['file_count'] ?? 1) : 1;
    }
    ?>
    <div>
        <?php if ($is_full_safe_mode): ?>
            <div class="so-notice so-notice-error" style="justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:var(--so-space-3);">
                    <span class="dashicons dashicons-warning" style="font-size:20px;flex-shrink:0;"></span>
                    <div>
                        <strong><?php esc_html_e('Safe Mode Active', 'e2mconnect'); ?></span>
                        <div style="font-size:var(--so-text-xs);margin-top:2px;font-weight:normal;"><?php esc_html_e('A sandbox file caused a fatal error that could not be automatically isolated. All files are suspended until the issue is resolved.', 'e2mconnect'); ?></div>
                    </div>
                </div>
                <form method="post" style="margin:0;flex-shrink:0;">
                    <?php wp_nonce_field('e2m_engine_sandbox_exit_safe_mode'); ?>
                    <input type="hidden" name="e2m_engine_sandbox_action" value="exit_safe_mode" />
                    <button type="submit" class="so-btn so-btn-primary so-btn-sm" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Exit safe mode and resume loading all sandbox files?', 'e2mconnect'))); ?>);">
                        <?php esc_html_e('Exit Safe Mode', 'e2mconnect'); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($result !== ''): ?>
            <script>(function(){ var u = new URL(location.href); u.searchParams.delete('e2m_result'); u.searchParams.delete('e2m_file'); history.replaceState({}, '', u.toString()); })();</script>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="so-stats-row">
            <div class="so-stat-card">
                <div class="so-stat-value"><?php echo (int) $total_files; ?></div>
                <div class="so-stat-label"><?php echo $project_count > 0 ? /* translators: %1$d: number of files, %2$d: number of projects */ sprintf(esc_html__('%1$d files · %2$d projects', 'e2mconnect'), (int) $total_files, (int) $project_count) : esc_html__('Total Files', 'e2mconnect'); ?></div>
            </div>
            <div class="so-stat-card">
                <div class="so-stat-value<?php echo $enabled_count > 0 ? ' success' : ''; ?>"><?php echo (int) $enabled_count; ?></div>
                <div class="so-stat-label"><?php esc_html_e('Active', 'e2mconnect'); ?></div>
            </div>
            <div class="so-stat-card">
                <div class="so-stat-value<?php echo $disabled_count > 0 ? ' error' : ''; ?>"><?php echo (int) $disabled_count; ?></div>
                <div class="so-stat-label"><?php esc_html_e('Disabled', 'e2mconnect'); ?></div>
            </div>
            <div class="so-stat-card">
                <div class="so-stat-value"><?php echo esc_html(size_format($total_size)); ?></div>
                <div class="so-stat-label"><?php esc_html_e('Total Size', 'e2mconnect'); ?></div>
            </div>
        </div>

        <!-- Sandbox Path + Settings -->
        <div class="so-card" style="padding:12px 16px;display:flex;align-items:center;gap:var(--so-space-4);flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:var(--so-space-2);">
                <span style="font-size:var(--so-text-base);color:var(--so-text-secondary);white-space:nowrap;"><?php esc_html_e('Sandbox directory:', 'e2mconnect'); ?></span>
                <code style="font-size:var(--so-text-label);background:var(--so-bg-lightest);color:var(--so-text-secondary);padding:4px 10px;border-radius:var(--so-radius-sm);border:1px solid var(--so-border-weak);"><?php echo esc_html($sandbox_dir); ?></code>
            </div>
            <form method="post" action="" style="margin:0;margin-left:auto;display:flex;align-items:center;gap:var(--so-space-2);">
                <?php wp_nonce_field('e2m_engine_settings'); ?>
                <input type="hidden" name="e2m_engine_save_settings" value="1" />
                <input type="hidden" name="e2m_engine_active_tab" class="e2m-mcp-active-tab-field" value="sandbox" />
                <?php if (!empty($settings['sandbox_enabled'])): ?>
                    <input type="hidden" name="e2m_engine_sandbox_enabled" value="1" />
                <?php endif; ?>
                <label for="e2m_engine_sandbox_max_file_size_kb" style="font-size:var(--so-text-base);color:var(--so-text-secondary);white-space:nowrap;" title="<?php esc_attr_e('Per-file write cap inside the sandbox. 0 = no limit.', 'e2mconnect'); ?>"><?php esc_html_e('Max file size (KB):', 'e2mconnect'); ?></label>
                <input id="e2m_engine_sandbox_max_file_size_kb" type="number" min="0" max="10240" step="1" name="e2m_engine_sandbox_max_file_size_kb" value="<?php echo esc_attr((string) ($settings['sandbox_max_file_size_kb'] ?? 100)); ?>" style="width:90px;" />
                <!-- <button type="submit" class="so-btn so-btn-primary so-btn-sm">'Save', 'e2mconnect'); ?></button> -->
            </form>
        </div>

        <!-- Files Table -->
        <div class="so-card so-card-flush">
                <div class="so-table-scroll">
                <table class="so-table">
                    <thead>
                        <tr>
                            <th style="width:40px;padding-left:16px;"><input type="checkbox" id="e2m-sandbox-select-all" class="so-checkbox" /></th>
                            <th><?php esc_html_e('Filename', 'e2mconnect'); ?></th>
                            <th><?php esc_html_e('Size', 'e2mconnect'); ?></th>
                            <th><?php esc_html_e('Last Modified', 'e2mconnect'); ?></th>
                            <th title="<?php esc_attr_e('WordPress hooks this file attaches to (e.g. add_action targets).', 'e2mconnect'); ?>"><?php esc_html_e('Hooks', 'e2mconnect'); ?></th>
                            <th><?php esc_html_e('Enabled', 'e2mconnect'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Action', 'e2mconnect'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                            <tr><td colspan="7" style="text-align:center;padding:40px 16px;color:var(--so-text-tertiary);">
                                <?php esc_html_e('No sandbox files found. Create ability files in the sandbox directory to get started.', 'e2mconnect'); ?>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($files as $name => $info):
                                $is_project = ($info['type'] === 'project');
                                $size_str = size_format($info['size']);
                                $mtime_path = $is_project ? ($info['entry'] ?? $info['path']) : $info['path'];
                                $mtime = file_exists($mtime_path) ? wp_date('M j, Y g:i A', filemtime($mtime_path)) : '-';
                                $abilities = $info['abilities'];
                                $ability_count = count($abilities);
                                $action_name = $is_project ? $info['basename'] . '/' : $info['basename'];
                                $is_enabled = ($info['status'] === 'enabled');
                                $has_syntax_error = !empty($info['syntax_error']);
                                $is_toggleable = !empty($info['toggleable']);

                                // Per-file runtime telemetry (count, last_run, last_error).
                                $runtime = class_exists('E2M_Engine_Sandbox_Helper')
                                    ? E2M_Engine_Sandbox_Helper::get_runtime($mtime_path)
                                    : [];
                                $run_count    = (int) ($runtime['count'] ?? 0);
                                $run_last_at  = (int) ($runtime['last_run'] ?? 0);
                                $run_duration = (int) ($runtime['last_duration_ms'] ?? 0);
                                $run_error    = (string) ($runtime['last_error'] ?? '');
                                $run_error_at = (int) ($runtime['last_error_at'] ?? 0);
                                $run_last_str = $run_last_at > 0 ? human_time_diff($run_last_at) : '';
                            ?>
                                <tr>
                                    <td style="padding-left:16px;"><input type="checkbox" class="e2m-sandbox-cb so-checkbox" name="e2m_engine_sandbox_selected[]" value="<?php echo esc_attr($action_name); ?>" form="e2m-sandbox-bulk-form" /></td>
                                    <td>
                                        <?php if ($is_project): ?>
                                            <code class="so-code-badge"><?php echo esc_html($info['basename']); ?>/</code>
                                        <?php else: ?>
                                            <code class="so-code-badge"><?php echo esc_html($name); ?></code>
                                        <?php endif; ?>
                                        <?php if ($has_syntax_error): ?>
                                            <span class="so-syntax-error-badge"><?php esc_html_e('Syntax Error', 'e2mconnect'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($run_error !== '' && $run_error_at >= $run_last_at): ?>
                                            <div class="so-sandbox-runtime so-sandbox-runtime--error" title="<?php echo esc_attr($run_error); ?>">
                                                <?php
                                                printf(
                                                    /* translators: %s: time-since label (e.g. "5 minutes") */
                                                    esc_html__('Last error %s ago', 'e2mconnect'),
                                                    esc_html(human_time_diff($run_error_at))
                                                );
                                                ?>
                                            </div>
                                        <?php elseif ($run_count > 0): ?>
                                            <div class="so-sandbox-runtime">
                                                <?php
                                                printf(
                                                    /* translators: 1: time-since label, 2: load count, 3: last duration ms */
                                                    esc_html__('Last ran %1$s ago · %2$d loads · %3$d ms', 'e2mconnect'),
                                                    esc_html($run_last_str),
                                                    (int) $run_count,
                                                    (int) $run_duration
                                                );
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($size_str); ?></td>
                                    <td><?php echo esc_html($mtime); ?></td>
                                    <td>
                                        <?php if ($ability_count === 0): ?>
                                            <span style="color:var(--so-text-tertiary);">-</span>
                                        <?php else: ?>
                                            <?php foreach (array_slice($abilities, 0, 2) as $aname): ?>
                                                <code class="so-code-badge" style="margin-right:2px;"><?php echo esc_html($aname); ?></code>
                                            <?php endforeach; ?>
                                            <?php if ($ability_count > 2): ?>
                                                <span style="font-size:12px;color:var(--so-text-weak);">+<?php echo (int) $ability_count - 2; ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_syntax_error): ?>
                                            <label class="so-toggle so-toggle-sm" title="<?php esc_attr_e('File has a syntax error — fix it to enable', 'e2mconnect'); ?>">
                                                <input type="checkbox" disabled />
                                                <span class="so-toggle-track" style="opacity:0.5;cursor:not-allowed;"></span>
                                            </label>
                                        <?php else: ?>
                                            <label class="so-toggle so-toggle-sm">
                                                <input type="checkbox" class="e2m-sandbox-toggle" data-file="<?php echo esc_attr($action_name); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('e2m_engine_sandbox_' . $info['basename'])); ?>" <?php checked($is_enabled); ?> />
                                                <span class="so-toggle-track"></span>
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <div style="display:inline-flex;align-items:center;gap:8px;">
                                            <?php
                                            // Compute the relative path passed to the View Code AJAX endpoint.
                                            // For single files: just the basename. For projects: the entry
                                            // file's path relative to E2M_ENGINE_SANDBOX_DIR (includes the
                                            // .disabled suffix on the dir when the project is disabled).
                                            $view_file = '';
                                            if (!$is_project) {
                                                $view_file = $info['basename'];
                                            } elseif (!empty($info['entry']) && defined('E2M_ENGINE_SANDBOX_DIR')) {
                                                $view_file = ltrim(str_replace(E2M_ENGINE_SANDBOX_DIR, '', $info['entry']), '/');
                                            }
                                            $view_label = $is_project ? __('View entry file', 'e2mconnect') : __('View Code', 'e2mconnect');
                                            ?>
                                            <?php if ($view_file !== ''): ?>
                                            <button type="button" class="so-icon-btn e2m-view-source" data-file="<?php echo esc_attr($view_file); ?>" title="<?php echo esc_attr($view_label); ?>">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                                            </button>
                                            <?php endif; ?>
                                            <form method="post" style="display:inline;margin:0;" onsubmit="return confirm(<?php /* translators: %s: file or project name */ echo esc_attr(wp_json_encode(sprintf($is_project ? __('Delete project "%s" and ALL its files?', 'e2mconnect') : __('Delete "%s"?', 'e2mconnect'), $info['basename']))); ?>);">
                                                <?php wp_nonce_field('e2m_engine_sandbox_' . $info['basename']); ?>
                                                <input type="hidden" name="e2m_engine_sandbox_action" value="delete" />
                                                <input type="hidden" name="e2m_engine_sandbox_file" value="<?php echo esc_attr($action_name); ?>" />
                                                <button type="submit" class="so-icon-btn so-icon-btn-danger" title="<?php esc_attr_e('Delete', 'e2mconnect'); ?>">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div><!-- /so-table-scroll -->
            <form method="post" id="e2m-sandbox-bulk-form" action="<?php echo esc_url(admin_url('admin.php?page=' . rawurlencode($page_slug) . '&tab=sandbox')); ?>" style="display:none;">
                <?php wp_nonce_field('e2m_engine_sandbox_bulk'); ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
                <input type="hidden" name="tab" value="sandbox" />
                <input type="hidden" name="e2m_engine_sandbox_bulk_action" id="e2m-sandbox-bulk-action" value="" />
            </form>
        </div>
    </div>

    <!-- Floating Bulk Action Bar -->
    <div id="e2m-sandbox-bulk-bar" class="so-bulk-bar">
        <span id="e2m-sandbox-bulk-count" class="so-bulk-bar-count"></span>
        <span class="so-bulk-bar-sep"></span>
        <span class="so-bulk-bar-label-off"><?php esc_html_e('Disable all', 'e2mconnect'); ?></span>
        <label class="so-toggle so-toggle-sm so-toggle-dark" id="e2m-sandbox-bulk-toggle">
            <input type="checkbox" checked />
            <span class="so-toggle-track"></span>
        </label>
        <span class="so-bulk-bar-label-on"><?php esc_html_e('Enable all', 'e2mconnect'); ?></span>
        <span class="so-bulk-bar-sep"></span>
        <button type="button" id="e2m-sandbox-bulk-delete" class="so-bulk-bar-btn so-bulk-bar-btn-danger">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            <?php esc_html_e('Delete all', 'e2mconnect'); ?>
        </button>
        <button type="button" id="e2m-sandbox-bulk-close" class="so-bulk-bar-close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>

    <!-- Bulk Confirm: Disable All -->
    <div id="e2m-confirm-disable" class="so-modal-backdrop">
        <div class="so-modal" style="width:420px;">
            <div class="so-modal-header">
                <span class="so-modal-title"><?php esc_html_e('Disable all selected files?', 'e2mconnect'); ?></span>
                <button type="button" class="so-modal-close e2m-confirm-close" data-target="e2m-confirm-disable">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="so-modal-footer">
                <button type="button" class="so-btn so-btn-outline so-btn-sm e2m-confirm-close" data-target="e2m-confirm-disable"><?php esc_html_e('Cancel', 'e2mconnect'); ?></button>
                <button type="button" class="so-btn so-btn-primary so-btn-sm" id="e2m-confirm-disable-yes"><?php esc_html_e('Yes', 'e2mconnect'); ?></button>
            </div>
        </div>
    </div>

    <!-- Bulk Confirm: Enable All -->
    <div id="e2m-confirm-enable" class="so-modal-backdrop">
        <div class="so-modal" style="width:420px;">
            <div class="so-modal-header">
                <span class="so-modal-title"><?php esc_html_e('Enable all selected files?', 'e2mconnect'); ?></span>
                <button type="button" class="so-modal-close e2m-confirm-close" data-target="e2m-confirm-enable">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="so-modal-footer">
                <button type="button" class="so-btn so-btn-outline so-btn-sm e2m-confirm-close" data-target="e2m-confirm-enable"><?php esc_html_e('Cancel', 'e2mconnect'); ?></button>
                <button type="button" class="so-btn so-btn-primary so-btn-sm" id="e2m-confirm-enable-yes"><?php esc_html_e('Yes', 'e2mconnect'); ?></button>
            </div>
        </div>
    </div>

    <!-- Bulk Confirm: Delete All -->
    <div id="e2m-confirm-delete" class="so-modal-backdrop">
        <div class="so-modal" style="width:420px;">
            <div class="so-modal-header">
                <span class="so-modal-title"><?php esc_html_e('Delete all selected files?', 'e2mconnect'); ?></span>
                <button type="button" class="so-modal-close e2m-confirm-close" data-target="e2m-confirm-delete">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="so-modal-footer">
                <button type="button" class="so-btn so-btn-outline so-btn-sm e2m-confirm-close" data-target="e2m-confirm-delete"><?php esc_html_e('Cancel', 'e2mconnect'); ?></button>
                <button type="button" class="so-btn so-btn-danger-filled so-btn-sm" id="e2m-confirm-delete-yes"><?php esc_html_e('Yes', 'e2mconnect'); ?></button>
            </div>
        </div>
    </div>

    <!-- View Code Modal -->
    <div id="e2m-source-modal" class="so-modal-backdrop">
        <div class="so-modal" style="width:520px;">
            <div class="so-modal-header">
                <span class="so-modal-title" id="e2m-source-title"></span>
                <button type="button" id="e2m-source-close" class="so-modal-close"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="so-code-wrap" style="max-height:420px;">
                <div class="so-code-header">
                    <span class="so-code-header-label" id="e2m-source-lang">PHP</span>
                    <button type="button" class="so-code-copy-btn" id="e2m-source-copy"><?php esc_html_e('Copy Code', 'e2mconnect'); ?></button>
                </div>
                <pre id="e2m-source-content" class="so-code-block" style="border-radius:0;overflow-y:auto;flex:1;"></pre>
            </div>
        </div>
    </div>

    <script>
    (function () {
        // Source modal
        var sourceModal = document.getElementById('e2m-source-modal');
        var sourceTitle = document.getElementById('e2m-source-title');
        var sourceContent = document.getElementById('e2m-source-content');
        var sourceClose = document.getElementById('e2m-source-close');
        function closeSourceModal() { sourceModal.classList.remove('open'); sourceContent.textContent = ''; }
        if (sourceClose) sourceClose.addEventListener('click', closeSourceModal);
        if (sourceModal) sourceModal.addEventListener('click', function (e) { if (e.target === sourceModal) closeSourceModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSourceModal(); });
        var sourceCopyBtn = document.getElementById('e2m-source-copy');
        var sourceLang = document.getElementById('e2m-source-lang');
        document.querySelectorAll('.e2m-view-source').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var fileName = this.getAttribute('data-file');
                sourceTitle.textContent = fileName;
                var ext = fileName.split('.').pop().toUpperCase();
                if (sourceLang) sourceLang.textContent = ext || 'CODE';
                sourceContent.textContent = <?php echo wp_json_encode(__('Loading...', 'e2mconnect')); ?>;
                sourceModal.classList.add('open');
                var fd = new FormData();
                fd.append('action', 'e2m_engine_view_sandbox_source');
                fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('e2m_engine_sandbox_source')); ?>);
                fd.append('file', fileName);
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) { sourceContent.textContent = res.success ? res.data.content : (res.data?.message || <?php echo wp_json_encode(__('Error', 'e2mconnect')); ?>); })
                    .catch(function () { sourceContent.textContent = <?php echo wp_json_encode(__('Network error. Please try again.', 'e2mconnect')); ?>; });
            });
        });
        /* Copy source code */
        if (sourceCopyBtn) sourceCopyBtn.addEventListener('click', function () {
            var text = sourceContent.textContent;
            if (!text) return;
            navigator.clipboard.writeText(text).then(function () {
                var orig = sourceCopyBtn.innerHTML;
                sourceCopyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="flex-shrink:0;margin-right:6px;"><circle cx="7" cy="7" r="7" fill="#A7EAAA"/><path d="M4.5 7L6.25 8.75L9.5 5.25" stroke="#181825" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Copied!';
                setTimeout(function () { sourceCopyBtn.innerHTML = orig; }, 1500);
            });
        });

        // Toggle enable/disable via form POST
        var sandboxFormAction = <?php echo wp_json_encode(admin_url('admin.php?page=' . rawurlencode($page_slug) . '&tab=sandbox')); ?>;
        document.querySelectorAll('.e2m-sandbox-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var action = this.checked ? 'enable' : 'disable';
                var fileName = this.getAttribute('data-file');
                var nonce = this.getAttribute('data-nonce');
                var form = document.createElement('form');
                form.method = 'post';
                form.action = sandboxFormAction;
                form.style.display = 'none';
                form.innerHTML = '<input type="hidden" name="_wpnonce" value="' + nonce + '" />' +
                    '<input type="hidden" name="_wp_http_referer" value="' + sandboxFormAction + '" />' +
                    '<input type="hidden" name="e2m_engine_sandbox_action" value="' + action + '" />' +
                    '<input type="hidden" name="e2m_engine_sandbox_file" value="' + fileName + '" />';
                document.body.appendChild(form);
                form.submit();
            });
        });

        // Bulk bar
        var selectAll = document.getElementById('e2m-sandbox-select-all');
        var checkboxes = document.querySelectorAll('.e2m-sandbox-cb');
        var bulkBar = document.getElementById('e2m-sandbox-bulk-bar');
        var bulkCount = document.getElementById('e2m-sandbox-bulk-count');
        var bulkForm = document.getElementById('e2m-sandbox-bulk-form');
        var bulkAction = document.getElementById('e2m-sandbox-bulk-action');
        var bulkClose = document.getElementById('e2m-sandbox-bulk-close');
        var bulkDelete = document.getElementById('e2m-sandbox-bulk-delete');
        var bulkToggle = document.querySelector('#e2m-sandbox-bulk-toggle input');

        function updateBulkBar() {
            var count = document.querySelectorAll('.e2m-sandbox-cb:checked').length;
            if (count > 0 && bulkBar) {
                bulkCount.textContent = count + ' item' + (count > 1 ? 's' : '') + ' selected';
                bulkBar.classList.add('visible');
            } else if (bulkBar) {
                bulkBar.classList.remove('visible');
            }
            if (selectAll) selectAll.checked = checkboxes.length > 0 && Array.from(checkboxes).every(function(c) { return c.checked; });
        }

        if (selectAll) selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateBulkBar();
        });
        checkboxes.forEach(function(cb) { cb.addEventListener('change', updateBulkBar); });

        /* Close bar - deselect all */
        if (bulkClose) bulkClose.addEventListener('click', function() {
            checkboxes.forEach(function(cb) { cb.checked = false; });
            if (selectAll) selectAll.checked = false;
            updateBulkBar();
        });

        /* Confirmation modals */
        var confirmDisable = document.getElementById('e2m-confirm-disable');
        var confirmEnable = document.getElementById('e2m-confirm-enable');
        var confirmDelete = document.getElementById('e2m-confirm-delete');

        /* Close confirm modals */
        document.querySelectorAll('.e2m-confirm-close').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = document.getElementById(this.getAttribute('data-target'));
                if (target) target.classList.remove('open');
                /* Reset toggle if cancelled */
                if (bulkToggle && this.getAttribute('data-target') === 'e2m-confirm-disable') bulkToggle.checked = true;
                if (bulkToggle && this.getAttribute('data-target') === 'e2m-confirm-enable') bulkToggle.checked = false;
            });
        });
        /* Backdrop click closes confirm */
        [confirmDisable, confirmEnable, confirmDelete].forEach(function(m) {
            if (!m) return;
            m.addEventListener('click', function(e) {
                if (e.target === m) {
                    m.classList.remove('open');
                    /* Reset toggle state */
                    if (m === confirmDisable && bulkToggle) bulkToggle.checked = true;
                    if (m === confirmEnable && bulkToggle) bulkToggle.checked = false;
                }
            });
        });

        /* Bulk enable/disable via toggle - open confirm modal */
        if (bulkToggle) bulkToggle.addEventListener('change', function() {
            if (this.checked) {
                confirmEnable.classList.add('open');
            } else {
                confirmDisable.classList.add('open');
            }
        });

        /* Confirm Yes buttons */
        var confirmDisableYes = document.getElementById('e2m-confirm-disable-yes');
        var confirmEnableYes = document.getElementById('e2m-confirm-enable-yes');
        var confirmDeleteYes = document.getElementById('e2m-confirm-delete-yes');

        if (confirmDisableYes) confirmDisableYes.addEventListener('click', function() {
            bulkAction.value = 'bulk_disable';
            bulkForm.submit();
        });
        if (confirmEnableYes) confirmEnableYes.addEventListener('click', function() {
            bulkAction.value = 'bulk_enable';
            bulkForm.submit();
        });
        if (confirmDeleteYes) confirmDeleteYes.addEventListener('click', function() {
            bulkAction.value = 'bulk_delete';
            bulkForm.submit();
        });

        /* Bulk delete - open confirm modal */
        if (bulkDelete) bulkDelete.addEventListener('click', function() {
            confirmDelete.classList.add('open');
        });
    })();
    </script>
    <?php
}

function e2m_engine_render_analytics_page_inner(array $settings): void
{
    $nonce = wp_create_nonce('e2m_engine_analytics_nonce');

    $ability_names = E2M_Engine_Analytics::get_ability_names();
    $mcp_methods = E2M_Engine_Analytics::get_mcp_methods();
    $log_users = E2M_Engine_Analytics::get_log_users();

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_ability = sanitize_text_field(wp_unslash($_GET['filter_ability'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_ability_search = sanitize_text_field(wp_unslash($_GET['filter_ability_search'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_date_from = sanitize_text_field(wp_unslash($_GET['filter_date_from'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_date_to = sanitize_text_field(wp_unslash($_GET['filter_date_to'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_status = sanitize_text_field(wp_unslash($_GET['filter_status'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_user = sanitize_text_field(wp_unslash($_GET['filter_user'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $filter_method = sanitize_text_field(wp_unslash($_GET['filter_method'] ?? ''));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $current_page = max(1, absint(wp_unslash($_GET['log_page'] ?? 1)));

    $per_page = 7;
    $filters = ['per_page' => $per_page, 'page' => $current_page];
    if ($filter_ability !== '') { $filters['ability'] = $filter_ability; }
    if ($filter_ability_search !== '') { $filters['ability_search'] = $filter_ability_search; }
    if ($filter_date_from !== '') { $filters['date_from'] = $filter_date_from; }
    if ($filter_date_to !== '') { $filters['date_to'] = $filter_date_to; }
    if ($filter_status !== '') { $filters['status'] = $filter_status; }
    if ($filter_user !== '') { $filters['user_id'] = (int) $filter_user; }
    if ($filter_method !== '') { $filters['mcp_method'] = $filter_method; }

    $logs = E2M_Engine_Analytics::get_logs($filters);
    $total_pages = (int) ceil($logs['total'] / $per_page);
    ?>
    <div>
        <!-- Table Card (contains filters + table + pagination) -->
        <div class="so-analytics-card">

            <!-- Search + Filters Bar -->
            <form id="e2m-analytics-filter-form" method="get" class="so-analytics-filter-bar">
                <input type="hidden" name="page" value="e2mconnect" />
                <input type="hidden" name="tab" value="activity" />
                <input type="hidden" name="view" value="logs" />
                <!-- Search input -->
                <div style="position:relative;display:inline-flex;align-items:center;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--so-text-weaker)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:12px;pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" name="filter_ability_search" placeholder="<?php esc_attr_e('Search', 'e2mconnect'); ?>" value="<?php echo esc_attr($filter_ability_search); ?>" class="so-search-input so-analytics-search" />
                </div>
                <!-- Filter dropdowns -->
                <div style="display:flex;align-items:center;gap:8px;">
                    <!-- All Abilities -->
                    <input type="hidden" name="filter_ability" value="<?php echo esc_attr($filter_ability); ?>" />
                    <div class="so-dropdown" data-input="filter_ability">
                        <button type="button" class="so-dropdown-trigger">
                            <span class="so-dropdown-label"><?php echo $filter_ability !== '' ? esc_html($filter_ability) : esc_html__('All Abilities', 'e2mconnect'); ?></span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="so-dropdown-menu">
                            <button type="button" class="so-dropdown-item<?php echo $filter_ability === '' ? ' active' : ''; ?>" data-value=""><?php esc_html_e('All Abilities', 'e2mconnect'); ?></button>
                            <?php foreach ($ability_names as $aname): ?>
                                <button type="button" class="so-dropdown-item<?php echo $filter_ability === $aname ? ' active' : ''; ?>" data-value="<?php echo esc_attr($aname); ?>"><?php echo esc_html($aname); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- All Statuses -->
                    <input type="hidden" name="filter_status" value="<?php echo esc_attr($filter_status); ?>" />
                    <div class="so-dropdown" data-input="filter_status">
                        <button type="button" class="so-dropdown-trigger">
                            <span class="so-dropdown-label"><?php
                                if ($filter_status === 'success') { esc_html_e('Success', 'e2mconnect'); }
                                elseif ($filter_status === 'error') { esc_html_e('Error', 'e2mconnect'); }
                                else { esc_html_e('All Statuses', 'e2mconnect'); }
                            ?></span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="so-dropdown-menu">
                            <button type="button" class="so-dropdown-item<?php echo $filter_status === '' ? ' active' : ''; ?>" data-value=""><?php esc_html_e('All Statuses', 'e2mconnect'); ?></button>
                            <button type="button" class="so-dropdown-item<?php echo $filter_status === 'success' ? ' active' : ''; ?>" data-value="success"><?php esc_html_e('Success', 'e2mconnect'); ?></button>
                            <button type="button" class="so-dropdown-item<?php echo $filter_status === 'error' ? ' active' : ''; ?>" data-value="error"><?php esc_html_e('Error', 'e2mconnect'); ?></button>
                        </div>
                    </div>

                    <!-- All Users -->
                    <input type="hidden" name="filter_user" value="<?php echo esc_attr($filter_user); ?>" />
                    <div class="so-dropdown" data-input="filter_user">
                        <button type="button" class="so-dropdown-trigger">
                            <span class="so-dropdown-label"><?php
                                if ($filter_user !== '' && isset($log_users[(int) $filter_user])) { echo esc_html($log_users[(int) $filter_user]); }
                                else { esc_html_e('All Users', 'e2mconnect'); }
                            ?></span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="so-dropdown-menu">
                            <button type="button" class="so-dropdown-item<?php echo $filter_user === '' ? ' active' : ''; ?>" data-value=""><?php esc_html_e('All Users', 'e2mconnect'); ?></button>
                            <?php foreach ($log_users as $uid => $uname): ?>
                                <button type="button" class="so-dropdown-item<?php echo $filter_user === (string) $uid ? ' active' : ''; ?>" data-value="<?php echo esc_attr($uid); ?>"><?php echo esc_html($uname); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- All Methods -->
                    <input type="hidden" name="filter_method" value="<?php echo esc_attr($filter_method); ?>" />
                    <div class="so-dropdown" data-input="filter_method">
                        <button type="button" class="so-dropdown-trigger">
                            <span class="so-dropdown-label"><?php echo $filter_method !== '' ? esc_html($filter_method) : esc_html__('All Methods', 'e2mconnect'); ?></span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="so-dropdown-menu">
                            <button type="button" class="so-dropdown-item<?php echo $filter_method === '' ? ' active' : ''; ?>" data-value=""><?php esc_html_e('All Methods', 'e2mconnect'); ?></button>
                            <?php foreach ($mcp_methods as $method): ?>
                                <button type="button" class="so-dropdown-item<?php echo $filter_method === $method ? ' active' : ''; ?>" data-value="<?php echo esc_attr($method); ?>"><?php echo esc_html($method); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Logs Table -->
            <div class="so-table-scroll">
            <table class="so-table-zebra" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th class="so-analytics-th"><?php esc_html_e('Date / Time', 'e2mconnect'); ?></th>
                        <th class="so-analytics-th"><?php esc_html_e('Method', 'e2mconnect'); ?></th>
                        <th class="so-analytics-th"><?php esc_html_e('MCP Tool', 'e2mconnect'); ?></th>
                        <th class="so-analytics-th"><?php esc_html_e('Status', 'e2mconnect'); ?></th>
                        <th class="so-analytics-th"><?php esc_html_e('Time', 'e2mconnect'); ?></th>
                        <th class="so-analytics-th"><?php esc_html_e('User', 'e2mconnect'); ?></th>
                        <th class="so-analytics-th so-analytics-th--right"><?php esc_html_e('Action', 'e2mconnect'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs['rows'])): ?>
                        <tr><td colspan="7" class="so-analytics-empty">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--so-border-default)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 8px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <?php esc_html_e('No log entries found.', 'e2mconnect'); ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($logs['rows'] as $row): ?>
                            <?php
                            $user_display = "\xe2\x80\x94";
                            if (!empty($row['user_id'])) {
                                $u = get_userdata((int) $row['user_id']);
                                $user_display = $u ? $u->user_login : '#' . $row['user_id'];
                            }
                            $is_success = $row['response_status'] === 'success';
                            $mcp_method = $row['mcp_method'] ?? '';
                            ?>
                            <tr>
                                <td class="so-analytics-td so-analytics-td--nowrap"><?php echo esc_html(wp_date('Y-m-d H:i:s', strtotime($row['created_at']))); ?></td>
                                <td class="so-analytics-td">
                                    <?php if ($mcp_method): ?>
                                        <span class="so-analytics-method-badge"><?php echo esc_html($mcp_method); ?></span>
                                    <?php else: ?>
                                        <span class="so-analytics-empty-dash">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="so-analytics-td so-analytics-td--strong"><?php echo esc_html($row['ability_name']); ?></td>
                                <td class="so-analytics-td" style="font-size:inherit;color:inherit;">
                                    <?php if ($is_success): ?>
                                        <span class="so-status-pill so-status-pill--success">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                            <span class="so-status-pill-text"><?php esc_html_e('Success', 'e2mconnect'); ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="so-status-pill so-status-pill--error">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--so-error);"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                            <span class="so-status-pill-text"><?php esc_html_e('Error', 'e2mconnect'); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="so-analytics-td"><?php echo esc_html(number_format_i18n((int) $row['execution_time_ms'])); ?>ms</td>
                                <td class="so-analytics-td"><?php echo esc_html($user_display); ?></td>
                                <td class="so-analytics-td" style="text-align:right;">
                                    <button type="button" class="e2m-view-detail so-btn-icon" data-log-id="<?php echo esc_attr($row['id']); ?>" title="<?php esc_attr_e('View Details', 'e2mconnect'); ?>" style="background:none;border:none;padding:4px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--so-text-weaker)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div><!-- /so-table-scroll -->

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <?php
                $base_url = admin_url('admin.php?page=e2mconnect&tab=activity');
                $filter_params = ['filter_ability', 'filter_ability_search', 'filter_date_from', 'filter_date_to', 'filter_status', 'filter_user', 'filter_method'];
                foreach ($filter_params as $fp) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $val = sanitize_text_field(wp_unslash($_GET[$fp] ?? ''));
                    if ($val !== '') { $base_url = add_query_arg($fp, $val, $base_url); }
                }
                ?>
                <div class="so-analytics-pagination">
                    <span class="so-analytics-pagination-label">
                        <?php /* translators: %1$d: current page number, %2$d: total pages */ printf(esc_html__('Page %1$d of %2$d', 'e2mconnect'), (int) $current_page, (int) $total_pages); ?>
                    </span>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <?php if ($current_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('log_page', $current_page - 1, $base_url)); ?>" class="so-btn-text so-pager-link"><?php esc_html_e('Previous', 'e2mconnect'); ?></a>
                        <?php else: ?>
                            <span class="so-pager-link so-pager-link--disabled"><?php esc_html_e('Previous', 'e2mconnect'); ?></span>
                        <?php endif; ?>

                        <?php
                        // Page number buttons
                        $range = 2;
                        $show_pages = [];
                        $show_pages[] = 1;
                        for ($i = max(2, $current_page - $range); $i <= min($total_pages - 1, $current_page + $range); $i++) {
                            $show_pages[] = $i;
                        }
                        if ($total_pages > 1) { $show_pages[] = $total_pages; }
                        $show_pages = array_unique($show_pages);
                        sort($show_pages);
                        $prev_page = 0;
                        foreach ($show_pages as $p):
                            if ($prev_page && $p - $prev_page > 1): ?>
                                <span class="so-pager-ellipsis">...</span>
                            <?php endif;
                            if ($p === $current_page): ?>
                                <span class="so-pager-page so-pager-page--current"><?php echo esc_html($p); ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url(add_query_arg('log_page', $p, $base_url)); ?>" class="so-btn-page so-pager-page"><?php echo esc_html($p); ?></a>
                            <?php endif;
                            $prev_page = $p;
                        endforeach; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('log_page', $current_page + 1, $base_url)); ?>" class="so-btn-text so-pager-link"><?php esc_html_e('Next', 'e2mconnect'); ?></a>
                        <?php else: ?>
                            <span class="so-pager-link so-pager-link--disabled"><?php esc_html_e('Next', 'e2mconnect'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="e2m-detail-modal" class="so-modal-backdrop">
        <div class="so-modal">
            <div class="so-modal-header">
                <h2 class="so-modal-title"><?php esc_html_e('Request Details', 'e2mconnect'); ?></h2>
                <button type="button" id="e2m-modal-close" class="so-modal-close"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div id="e2m-modal-loading" style="padding:var(--so-space-10);text-align:center;color:var(--so-text-tertiary);">
                <?php esc_html_e('Loading...', 'e2mconnect'); ?>
            </div>
            <div id="e2m-modal-content" class="so-modal-body" style="display:none;">
                <div style="display:flex;gap:var(--so-space-2);flex-wrap:wrap;margin-bottom:var(--so-space-5);">
                    <span id="smd-status" class="so-badge"></span>
                    <span id="smd-method-badge" class="so-badge so-badge-method"></span>
                    <span id="smd-mcp-method" class="so-badge so-badge-neutral"></span>
                    <span id="smd-tool" class="so-badge so-badge-info"></span>
                </div>
                <table class="so-table" style="margin-bottom:var(--so-space-5);">
                    <tbody>
                        <tr><td style="font-weight:var(--so-weight-semibold);width:140px;"><?php esc_html_e('Date / Time', 'e2mconnect'); ?></td><td id="smd-date"></td></tr>
                        <tr><td style="font-weight:var(--so-weight-semibold);"><?php esc_html_e('API Endpoint', 'e2mconnect'); ?></td><td><code id="smd-endpoint"></code></td></tr>
                        <tr><td style="font-weight:var(--so-weight-semibold);"><?php esc_html_e('Session ID', 'e2mconnect'); ?></td><td><code id="smd-session"></code></td></tr>
                        <tr><td style="font-weight:var(--so-weight-semibold);"><?php esc_html_e('User', 'e2mconnect'); ?></td><td id="smd-user"></td></tr>
                        <tr><td style="font-weight:var(--so-weight-semibold);"><?php esc_html_e('IP Address', 'e2mconnect'); ?></td><td id="smd-ip"></td></tr>
                        <tr><td style="font-weight:var(--so-weight-semibold);"><?php esc_html_e('Execution Time', 'e2mconnect'); ?></td><td id="smd-time"></td></tr>
                    </tbody>
                </table>
                <div id="smd-request-section" style="display:none;">
                    <h4 style="font-size:var(--so-text-sm);font-weight:var(--so-weight-semibold);margin:0 0 var(--so-space-2);"><?php esc_html_e('Request Body', 'e2mconnect'); ?></h4>
                    <pre id="smd-request-body" class="so-code-block"></pre>
                </div>
                <div id="smd-response-section" style="display:none;margin-top:var(--so-space-4);">
                    <h4 style="font-size:var(--so-text-sm);font-weight:var(--so-weight-semibold);margin:0 0 var(--so-space-2);"><?php esc_html_e('Response Data', 'e2mconnect'); ?></h4>
                    <pre id="smd-response-body" class="so-code-block"></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var purgeConfirm = <?php echo wp_json_encode(__('Permanently delete ALL log entries? This cannot be undone.', 'e2mconnect')); ?>;

        // Detail modal.
        var modal = document.getElementById('e2m-detail-modal');
        var modalContent = document.getElementById('e2m-modal-content');
        var modalLoading = document.getElementById('e2m-modal-loading');
        var closeBtn = document.getElementById('e2m-modal-close');

        function closeModal() { modal.classList.remove('open'); modalContent.style.display = 'none'; modalLoading.style.display = 'block'; }
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        document.querySelectorAll('.e2m-view-detail').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var logId = this.getAttribute('data-log-id');
                modal.classList.add('open');
                modalContent.style.display = 'none';
                modalLoading.style.display = 'block';

                var fd = new FormData();
                fd.append('action', 'e2m_engine_get_log_detail');
                fd.append('nonce', nonce);
                fd.append('log_id', logId);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) { modalLoading.textContent = res.data?.message || 'Error'; return; }
                        var d = res.data;
                        var statusEl = document.getElementById('smd-status');
                        statusEl.textContent = d.response_status === 'success' ? 'SUCCESS' : 'ERROR';
                        statusEl.className = 'so-badge ' + (d.response_status === 'success' ? 'so-badge-success' : 'so-badge-error');
                        document.getElementById('smd-method-badge').textContent = 'POST';
                        document.getElementById('smd-mcp-method').textContent = d.mcp_method || '-';
                        document.getElementById('smd-tool').textContent = d.ability_name;
                        document.getElementById('smd-date').textContent = d.created_at;
                        document.getElementById('smd-endpoint').textContent = d.api_endpoint || '-';
                        document.getElementById('smd-session').textContent = d.session_id || '-';
                        document.getElementById('smd-user').textContent = d.user_display + (d.user_id > 0 ? ' (#' + d.user_id + ')' : '');
                        document.getElementById('smd-ip').textContent = d.ip_address || '-';
                        document.getElementById('smd-time').textContent = d.execution_time_ms + ' ms';
                        var reqSection = document.getElementById('smd-request-section');
                        var reqBody = document.getElementById('smd-request-body');
                        if (d.request_body || d.request_params) {
                            var raw = d.request_body || d.request_params;
                            try { reqBody.textContent = JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { reqBody.textContent = raw; }
                            reqSection.style.display = 'block';
                        } else { reqSection.style.display = 'none'; }
                        var resSection = document.getElementById('smd-response-section');
                        var resBody = document.getElementById('smd-response-body');
                        if (d.response_data) {
                            try { resBody.textContent = JSON.stringify(JSON.parse(d.response_data), null, 2); } catch (e) { resBody.textContent = d.response_data; }
                            resSection.style.display = 'block';
                        } else { resSection.style.display = 'none'; }
                        modalLoading.style.display = 'none';
                        modalContent.style.display = 'block';
                    })
                    .catch(function () { modalLoading.textContent = <?php echo wp_json_encode(__('Failed to load details.', 'e2mconnect')); ?>; });
            });
        });

        // Purge all logs button.
        var purgeBtn = document.getElementById('e2m-purge-logs-btn');
        if (purgeBtn) {
            purgeBtn.addEventListener('click', function () {
                if (!window.confirm(purgeConfirm)) {
                    return;
                }

                var oldHtml = purgeBtn.innerHTML;
                purgeBtn.disabled = true;
                purgeBtn.style.opacity = '0.65';
                purgeBtn.innerHTML = '<span class="so-icon-button-label" style="color:var(--so-error);">Purging...</span>';

                var fd = new FormData();
                fd.append('action', 'e2m_engine_purge_logs');
                fd.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.text(); })
                    .then(function (raw) {
                        var payload = null;
                        try {
                            payload = JSON.parse(raw);
                        } catch (e) {
                            throw new Error('Unexpected response from server: ' + raw);
                        }

                        if (!payload || !payload.success) {
                            var msg = (payload && payload.data && payload.data.message) ? payload.data.message : 'Failed to purge logs.';
                            throw new Error(msg);
                        }

                        window.location.reload();
                    })
                    .catch(function (err) {
                        window.alert(err && err.message ? err.message : 'Purge failed.');
                        purgeBtn.disabled = false;
                        purgeBtn.style.opacity = '';
                        purgeBtn.innerHTML = oldHtml;
                    });
            });
        }

        // Custom .so-dropdown init is now wired up globally in
        // e2m_engine_render_main_page() so dropdowns work on every tab.
    })();
    </script>
    <?php
}

/* =========================================================================
 * Setup Guide Tab
 * ======================================================================= */

/**
 * Render the Setup Guide tab — Node.js / npm check + install instructions.
 */
function e2m_engine_render_setup_guide_tab(): void
{
    // Server-side npm / node check via exec (if available).
    $node_version = '';
    $npm_version  = '';
    if ( function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true ) ) {
        // phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged
        @exec( 'node --version 2>&1', $node_out );
        // phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged
        @exec( 'npm --version 2>&1', $npm_out );
        $node_raw = trim( implode( '', $node_out ?? [] ) );
        $npm_raw  = trim( implode( '', $npm_out ?? [] ) );
        if ( preg_match( '/v?\d+\.\d+\.\d+/', $node_raw ) ) {
            $node_version = $node_raw;
        }
        if ( preg_match( '/\d+\.\d+\.\d+/', $npm_raw ) ) {
            $npm_version = $npm_raw;
        }
    }

    $node_ok = $node_version !== '';
    $npm_ok  = $npm_version  !== '';
    ?>
    <div class="e2m-setup-guide">

        <!-- ── Status Cards ─────────────────────────────────────────── -->
        <div class="ssg-status-row">

            <div class="ssg-status-card <?php echo $node_ok ? 'ssg-ok' : 'ssg-warn'; ?>">
                <div class="ssg-status-icon">
                    <?php if ( $node_ok ): ?>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php endif; ?>
                </div>
                <div class="ssg-status-info">
                    <div class="ssg-status-label"><?php esc_html_e( 'Node.js', 'e2mconnect' ); ?></div>
                    <div class="ssg-status-value">
                        <?php echo $node_ok ? esc_html( $node_version ) : esc_html__( 'Not detected', 'e2mconnect' ); ?>
                    </div>
                </div>
            </div>

            <div class="ssg-status-card <?php echo $npm_ok ? 'ssg-ok' : 'ssg-warn'; ?>">
                <div class="ssg-status-icon">
                    <?php if ( $npm_ok ): ?>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php endif; ?>
                </div>
                <div class="ssg-status-info">
                    <div class="ssg-status-label"><?php esc_html_e( 'npm', 'e2mconnect' ); ?></div>
                    <div class="ssg-status-value">
                        <?php echo $npm_ok ? esc_html( $npm_version ) : esc_html__( 'Not detected', 'e2mconnect' ); ?>
                    </div>
                </div>
            </div>

            <div class="ssg-status-card ssg-info">
                <div class="ssg-status-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                </div>
                <div class="ssg-status-info">
                    <div class="ssg-status-label"><?php esc_html_e( 'Note', 'e2mconnect' ); ?></div>
                    <div class="ssg-status-value"><?php esc_html_e( 'Detection runs on the web server, not your local machine.', 'e2mconnect' ); ?></div>
                </div>
            </div>

        </div><!-- /.ssg-status-row -->

        <!-- ── Steps ────────────────────────────────────────────────── -->
        <div class="ssg-steps">

            <!-- Step 1 -->
            <div class="ssg-step">
                <div class="ssg-step-head">
                    <span class="ssg-step-num">1</span>
                    <div class="ssg-step-title"><?php esc_html_e( 'Check if Node.js & npm are installed', 'e2mconnect' ); ?></div>
                </div>
                <div class="ssg-step-body">
                    <p><?php esc_html_e( 'Open your Terminal (Mac/Linux) or Command Prompt / PowerShell (Windows) and run:', 'e2mconnect' ); ?></p>
                    <div class="ssg-code-block">
                        <div class="ssg-code-label"><?php esc_html_e( 'Terminal', 'e2mconnect' ); ?></div>
                        <pre><code>node --version
npm --version</code></pre>
                        <button type="button" class="ssg-copy-btn" data-copy="node --version&#10;npm --version">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <?php esc_html_e( 'Copy', 'e2mconnect' ); ?>
                        </button>
                    </div>
                    <p class="ssg-hint"><?php esc_html_e( 'If you see version numbers (e.g. v20.11.0 and 10.2.4), you are good to go — skip to Step 3.', 'e2mconnect' ); ?></p>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="ssg-step">
                <div class="ssg-step-head">
                    <span class="ssg-step-num">2</span>
                    <div class="ssg-step-title"><?php esc_html_e( 'Install Node.js & npm', 'e2mconnect' ); ?></div>
                </div>
                <div class="ssg-step-body">

                    <div class="ssg-os-tabs">
                        <button type="button" class="ssg-os-tab active" data-os="recommended"><?php esc_html_e( 'Recommended', 'e2mconnect' ); ?></button>
                        <button type="button" class="ssg-os-tab" data-os="windows"><?php esc_html_e( 'Windows', 'e2mconnect' ); ?></button>
                        <button type="button" class="ssg-os-tab" data-os="mac"><?php esc_html_e( 'macOS', 'e2mconnect' ); ?></button>
                        <button type="button" class="ssg-os-tab" data-os="linux"><?php esc_html_e( 'Linux', 'e2mconnect' ); ?></button>
                    </div>

                    <!-- Recommended -->
                    <div class="ssg-os-panel active" data-os="recommended">
                        <p><?php esc_html_e( 'The easiest way is to download the official installer from nodejs.org — it installs both Node.js and npm together.', 'e2mconnect' ); ?></p>
                        <a href="https://nodejs.org/en/download" target="_blank" rel="noopener noreferrer" class="ssg-btn-primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <?php esc_html_e( 'Download Node.js (LTS)', 'e2mconnect' ); ?>
                        </a>
                        <p class="ssg-hint"><?php esc_html_e( 'Choose the LTS (Long Term Support) version. After install, restart your terminal and run the check commands again.', 'e2mconnect' ); ?></p>
                    </div>

                    <!-- Windows -->
                    <div class="ssg-os-panel" data-os="windows">
                        <p><strong><?php esc_html_e( 'Option A — Official installer (easiest):', 'e2mconnect' ); ?></strong></p>
                        <a href="https://nodejs.org/en/download" target="_blank" rel="noopener noreferrer" class="ssg-btn-primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <?php esc_html_e( 'Download Node.js for Windows', 'e2mconnect' ); ?>
                        </a>
                        <p style="margin-top:16px;"><strong><?php esc_html_e( 'Option B — via winget:', 'e2mconnect' ); ?></strong></p>
                        <div class="ssg-code-block">
                            <div class="ssg-code-label">PowerShell</div>
                            <pre><code>winget install OpenJS.NodeJS.LTS</code></pre>
                            <button type="button" class="ssg-copy-btn" data-copy="winget install OpenJS.NodeJS.LTS">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <?php esc_html_e( 'Copy', 'e2mconnect' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- macOS -->
                    <div class="ssg-os-panel" data-os="mac">
                        <p><strong><?php esc_html_e( 'Option A — Official installer (recommended — no Terminal, no admin setup):', 'e2mconnect' ); ?></strong></p>
                        <a href="https://nodejs.org/en/download" target="_blank" rel="noopener noreferrer" class="ssg-btn-primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <?php esc_html_e( 'Download Node.js for macOS', 'e2mconnect' ); ?>
                        </a>
                        <p class="ssg-hint"><?php esc_html_e( 'Runs the standard "Node.js" installer (.pkg). Does not need Homebrew, sudo, or GitHub access.', 'e2mconnect' ); ?></p>
                        <p style="margin-top:16px;"><strong><?php esc_html_e( 'Option B — Homebrew (only if you already use it):', 'e2mconnect' ); ?></strong></p>
                        <div class="ssg-code-block">
                            <div class="ssg-code-label">Terminal</div>
                            <pre><code>brew install node</code></pre>
                            <button type="button" class="ssg-copy-btn" data-copy="brew install node">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <?php esc_html_e( 'Copy', 'e2mconnect' ); ?>
                            </button>
                        </div>
                        <p class="ssg-hint"><?php esc_html_e( "If brew reports permission errors or asks for sudo, don't fight it — use Option A instead.", 'e2mconnect' ); ?></p>
                    </div>

                    <!-- Linux -->
                    <div class="ssg-os-panel" data-os="linux">
                        <p><strong><?php esc_html_e( 'Ubuntu / Debian:', 'e2mconnect' ); ?></strong></p>
                        <div class="ssg-code-block">
                            <div class="ssg-code-label">Terminal</div>
                            <pre><code>sudo apt update
sudo apt install nodejs npm -y</code></pre>
                            <button type="button" class="ssg-copy-btn" data-copy="sudo apt update&#10;sudo apt install nodejs npm -y">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <?php esc_html_e( 'Copy', 'e2mconnect' ); ?>
                            </button>
                        </div>
                        <p style="margin-top:16px;"><strong><?php esc_html_e( 'Fedora / RHEL / CentOS:', 'e2mconnect' ); ?></strong></p>
                        <div class="ssg-code-block">
                            <div class="ssg-code-label">Terminal</div>
                            <pre><code>sudo dnf install nodejs npm -y</code></pre>
                            <button type="button" class="ssg-copy-btn" data-copy="sudo dnf install nodejs npm -y">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <?php esc_html_e( 'Copy', 'e2mconnect' ); ?>
                            </button>
                        </div>
                    </div>

                </div><!-- /.ssg-step-body -->
            </div><!-- /step 2 -->

            <!-- Step 3 -->
            <div class="ssg-step">
                <div class="ssg-step-head">
                    <span class="ssg-step-num">3</span>
                    <div class="ssg-step-title"><?php esc_html_e( 'Verify installation', 'e2mconnect' ); ?></div>
                </div>
                <div class="ssg-step-body">
                    <p><?php esc_html_e( 'After install, open a new terminal window and confirm both are working:', 'e2mconnect' ); ?></p>
                    <div class="ssg-code-block">
                        <div class="ssg-code-label"><?php esc_html_e( 'Terminal', 'e2mconnect' ); ?></div>
                        <pre><code>node --version   # should print e.g. v20.11.0
npm --version    # should print e.g. 10.2.4</code></pre>
                        <button type="button" class="ssg-copy-btn" data-copy="node --version&#10;npm --version">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <?php esc_html_e( 'Copy', 'e2mconnect' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 4 -->
            <div class="ssg-step">
                <div class="ssg-step-head">
                    <span class="ssg-step-num">4</span>
                    <div class="ssg-step-title"><?php esc_html_e( 'Connect your AI client via MCP', 'e2mconnect' ); ?></div>
                </div>
                <div class="ssg-step-body">
                    <p><?php esc_html_e( 'Once Node.js and npm are ready, go to the MCP Connect tab to generate your application password and get the config snippet for your AI client (Claude Desktop, Cursor, VS Code, etc.).', 'e2mconnect' ); ?></p>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'e2mconnect', 'tab' => 'connect' ], admin_url( 'admin.php' ) ) ); ?>" class="ssg-btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        <?php esc_html_e( 'Go to MCP Connect', 'e2mconnect' ); ?>
                    </a>
                </div>
            </div>

        </div><!-- /.ssg-steps -->

    </div><!-- /.e2m-setup-guide -->

    <?php // Styles for .e2m-setup-guide and .ssg-* live in e2m-ui/admin-design-system.css §22. ?>

    <script>
    (function(){
        // OS tab switching
        document.querySelectorAll('.ssg-os-tab').forEach(function(btn){
            btn.addEventListener('click', function(){
                var os = btn.dataset.os;
                var step = btn.closest('.ssg-step-body');
                step.querySelectorAll('.ssg-os-tab').forEach(function(t){ t.classList.remove('active'); });
                step.querySelectorAll('.ssg-os-panel').forEach(function(p){ p.classList.remove('active'); });
                btn.classList.add('active');
                var panel = step.querySelector('.ssg-os-panel[data-os="'+os+'"]');
                if(panel) panel.classList.add('active');
            });
        });

        // Copy buttons
        document.querySelectorAll('.ssg-copy-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                var text = (btn.dataset.copy || '').replace(/&#10;/g,'\n');
                navigator.clipboard.writeText(text).then(function(){
                    btn.classList.add('copied');
                    var svg = btn.querySelector('svg');
                    if(svg) svg.style.display='none';
                    var span = document.createElement('span');
                    span.textContent=<?php echo wp_json_encode(__('Copied!', 'e2mconnect')); ?>;
                    btn.appendChild(span);
                    setTimeout(function(){
                        btn.classList.remove('copied');
                        if(svg) svg.style.display='';
                        if(span.parentNode) span.parentNode.removeChild(span);
                    }, 2000);
                });
            });
        });
    })();
    </script>
    <?php
}
