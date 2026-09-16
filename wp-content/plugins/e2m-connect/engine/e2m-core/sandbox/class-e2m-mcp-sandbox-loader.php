<?php
/**
 * E2M Connect MCP Sandbox Loader.
 *
 * @package E2M Connect_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class E2M_Engine_Sandbox_Load_State
{
    public string $sandbox_dir;
    public string $sentinel_loading;
    public string $sentinel_crashed;
    public bool $safe_mode = false;
    public bool $halt = false;

    /** @var string */
    public string $auto_disabled_label = '';

    /** @var array<int,string> */
    public array $discovered_files = [];

    /** @var array<int,string> */
    public array $validated_files = [];

    /** @var array<string,string> */
    public array $syntax_errors = [];

    public function __construct(string $sandbox_dir)
    {
        $this->sandbox_dir = $sandbox_dir;
        $this->sentinel_loading = $sandbox_dir . '.loading';
        $this->sentinel_crashed = $sandbox_dir . '.crashed';
    }
}

interface E2M_Engine_Sandbox_Strategy
{
    public function run(E2M_Engine_Sandbox_Load_State $state): void;
}

final class E2M_Engine_Sandbox_Preflight_Strategy implements E2M_Engine_Sandbox_Strategy
{
    private int $legacy_crash_threshold;

    public function __construct(int $legacy_crash_threshold = 120)
    {
        $this->legacy_crash_threshold = $legacy_crash_threshold;
    }

    public function run(E2M_Engine_Sandbox_Load_State $state): void
    {
        $recovery = E2M_Engine_Sandbox_Helper::auto_disable_crashed_entry(
            $state->sandbox_dir,
            $state->sentinel_crashed
        );

        $state->auto_disabled_label = $recovery['label'];
        $state->safe_mode = (bool) $recovery['safe_mode'];

        // Legacy crash detection: a stale .loading sentinel from a previous
        // request that died without the shutdown handler cleaning up.
        // Only trigger safe mode when ALL conditions are met:
        //  1. .loading file exists and is older than threshold
        //  2. The process that wrote it is no longer running
        //  3. Sandbox actually has loadable files (not an unrelated slow request)
        if (file_exists($state->sentinel_loading)) {
            clearstatcache(true, $state->sentinel_loading);
            $age = time() - (int) filemtime($state->sentinel_loading);

            if ($age > $this->legacy_crash_threshold) {
                $stale_pid = $this->read_loading_sentinel_pid($state->sentinel_loading);
                $pid_alive = ($stale_pid > 0
                    && function_exists('posix_getpgid')
                    && @posix_getpgid($stale_pid) !== false);

                if ($pid_alive) {
                    // Process still running — this is a slow request, not a crash.
                    // Do nothing, let it finish.
                } else {
                    // Only trust stale-loading crash detection when the marker
                    // clearly belongs to a sandbox load from this process model.
                    // Older sentinels may contain UUIDs or partial writes.
                    $has_files = E2M_Engine_Sandbox_Helper::discover_entries($state->sandbox_dir) !== [];
                    $can_trust_stale_loading = ($stale_pid > 0) || !function_exists('posix_getpgid');
                    wp_delete_file($state->sentinel_loading);

                    if ($has_files && $can_trust_stale_loading) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
                        @file_put_contents($state->sentinel_crashed, '1', LOCK_EX);
                        $state->safe_mode = true;
                    }
                }
            }
        }

        // Allow an admin to force safe-mode via a signed URL.
        // Requires both manage_options capability AND a valid nonce so the
        // request origin is verified (WP.org security guideline).
        if (
            ! $state->safe_mode
            && sanitize_text_field( wp_unslash( $_GET['e2m_engine_safe_mode'] ?? '' ) ) === '1'
            && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && isset( $_GET['_e2m_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_e2m_nonce'] ) ), 'e2m_engine_safe_mode' )
        ) {
            $state->safe_mode = true;
        }

        $this->register_admin_notices($state);

        if ($state->safe_mode) {
            do_action('e2m_engine_sandbox_safe_mode', $state->sentinel_crashed);
            $state->halt = true;
        }
    }

    private function read_loading_sentinel_pid(string $sentinel_loading): int
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $raw = trim((string) @file_get_contents($sentinel_loading));
        if ($raw === '') {
            return 0;
        }

        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['pid']) && is_numeric($decoded['pid'])) {
            return (int) $decoded['pid'];
        }

        return 0;
    }

    private function register_admin_notices(E2M_Engine_Sandbox_Load_State $state): void
    {
        $auto_disabled_label = $state->auto_disabled_label;
        $sentinel_crashed = $state->sentinel_crashed;

        add_action('admin_notices', static function () use ($auto_disabled_label, $sentinel_crashed): void {
            if ($auto_disabled_label !== '') {
                wp_admin_notice(
                    sprintf(
                        '<strong>%s</strong> %s',
                        esc_html__('E2M Connect Sandbox:', 'e2mconnect'),
                        sprintf(
                            /* translators: %s: filename that caused the error */
                            esc_html__('The file "%s" caused a fatal error and was automatically disabled. All other sandbox files continue to run normally. Fix the file and re-enable it from the Sandbox tab.', 'e2mconnect'),
                            '<code>' . esc_html($auto_disabled_label) . '</code>'
                        )
                    ),
                    ['type' => 'warning', 'dismissible' => true]
                );
            }

            if (!file_exists($sentinel_crashed)) {
                return;
            }

            wp_admin_notice(
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__('E2M Connect Sandbox: Safe mode is active.', 'e2mconnect'),
                    esc_html__(
                        'A sandbox file caused a fatal error that could not be automatically isolated. All sandbox files are suspended. Fix or delete the broken file, then remove the .crashed file from the sandbox directory to resume.',
                        'e2mconnect'
                    )
                ),
                ['type' => 'error', 'dismissible' => false]
            );
        });
    }
}

final class E2M_Engine_Sandbox_Discovery_Strategy implements E2M_Engine_Sandbox_Strategy
{
    public function run(E2M_Engine_Sandbox_Load_State $state): void
    {
        $state->discovered_files = E2M_Engine_Sandbox_Helper::discover_entries($state->sandbox_dir);
    }
}

final class E2M_Engine_Sandbox_Validation_Strategy implements E2M_Engine_Sandbox_Strategy
{
    public function run(E2M_Engine_Sandbox_Load_State $state): void
    {
        if ($state->discovered_files === []) {
            return;
        }

        $can_lint = E2M_Engine_Sandbox_Helper::has_exec_php_lint_support();
        $php_binary = E2M_Engine_Sandbox_Helper::resolve_php_binary();

        foreach ($state->discovered_files as $file) {
            $contract = E2M_Engine_Sandbox_Helper::validate_entry_contract($file);
            if (!$contract['ok']) {
                $state->syntax_errors[basename($file)] = $contract['message'];
                continue;
            }

            if (E2M_Engine_Sandbox_Helper::is_validation_cache_fresh($file)) {
                $state->validated_files[] = $file;
                continue;
            }

            if (!$can_lint) {
                $state->validated_files[] = $file;
                continue;
            }

            // Lint the entry file AND all PHP files in its project folder.
            $files_to_lint = E2M_Engine_Sandbox_Helper::collect_project_php_files($file);
            $lint_failed   = false;

            foreach ($files_to_lint as $lint_target) {
                $lint = E2M_Engine_Sandbox_Helper::lint_php_file($lint_target, $php_binary);
                if (!$lint['ok']) {
                    $label = str_replace(E2M_ENGINE_SANDBOX_DIR, '', $lint_target);
                    $state->syntax_errors[$label] = $lint['message'];
                    E2M_Engine_Sandbox_Helper::maybe_log_syntax_error($lint_target, $lint['message']);
                    $lint_failed = true;
                    break; // Stop on first error in the project.
                }
            }

            if (!$lint_failed) {
                E2M_Engine_Sandbox_Helper::create_validation_cache($file);
                $state->validated_files[] = $file;
                continue;
            }

            // NOTE: We do NOT auto-disable on syntax errors.
            // The file stays enabled (.php) but is skipped from loading.
            // The admin sandbox tab shows a red "Syntax Error" indicator.
            // User can fix the error and refresh — file will load automatically.
            // Auto-disable only happens on RUNTIME fatal errors (via shutdown handler).
        }

        // if ($state->syntax_errors !== []) {
        //     $syntax_errors = $state->syntax_errors;
        //     add_action('admin_notices', static function () use ($syntax_errors): void {
        //         foreach ($syntax_errors as $filename => $error) {
        //             wp_admin_notice(
        //                 sprintf(
        //                     '<strong>%s</strong> %s<br><code style="display:block;margin-top:6px;padding:8px;background:#f8f8fb;border-radius:4px;font-size:12px;">%s</code>',
        //                     esc_html__('E2M Connect Sandbox:', 'e2mconnect'),
        //                     sprintf(
        //                         esc_html__('Syntax error in "%s" - file skipped until fixed:', 'e2mconnect'),
        //                         '<code>' . esc_html($filename) . '</code>'
        //                     ),
        //                     esc_html(trim($error))
        //                 ),
        //                 ['type' => 'warning', 'dismissible' => true]
        //             );
        //         }
        //     });
        // }
    }
}

final class E2M_Engine_Sandbox_Execution_Strategy implements E2M_Engine_Sandbox_Strategy
{
    public function run(E2M_Engine_Sandbox_Load_State $state): void
    {
        if ($state->validated_files === []) {
            return;
        }

        do_action('e2m_engine_before_sandbox_load', $state->validated_files);
        $loader_marker = wp_json_encode([
            'pid'  => function_exists('getmypid') ? (int) getmypid() : 0,
            'time' => time(),
        ]);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
        @file_put_contents($state->sentinel_loading, $loader_marker, LOCK_EX);

        $current_file_ref = '';
        register_shutdown_function(static function () use ($state, &$current_file_ref): void {
            $error = error_get_last();
            try {
                if ($error === null) {
                    return;
                }

                $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
                if (!($error['type'] & $fatal_types)) {
                    return;
                }

                $error_file = (string) ($error['file'] ?? '');
                $crashed_file = '';

                if ($current_file_ref !== '' && file_exists($current_file_ref)) {
                    $crashed_file = $current_file_ref;
                } elseif ($error_file !== '' && str_starts_with($error_file, $state->sandbox_dir)) {
                    $crashed_file = $error_file;
                }

                // Unrelated request fatals must not put the sandbox into safe mode.
                if ($crashed_file === '') {
                    return;
                }

                // Record the fatal in the per-file runtime log too.
                E2M_Engine_Sandbox_Helper::record_load(
                    $crashed_file,
                    0,
                    (string) ($error['message'] ?? 'Fatal error')
                );

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
                @file_put_contents($state->sentinel_crashed, $crashed_file, LOCK_EX);
            } finally {
                if (file_exists($state->sentinel_loading)) {
                    wp_delete_file($state->sentinel_loading);
                }
            }
        });

        $loaded = [];
        foreach ($state->validated_files as $file) {
            // Pre-load conflict check: skip files whose classes/functions
            // are already declared by an installed plugin or theme.
            $conflict = E2M_Engine_Sandbox_Helper::detect_symbol_conflicts($file);
            if ($conflict !== '') {
                $state->syntax_errors[basename($file)] = $conflict;
                E2M_Engine_Sandbox_Helper::maybe_log_syntax_error($file, $conflict);
                E2M_Engine_Sandbox_Helper::record_load($file, 0, $conflict);
                continue;
            }

            $current_file_ref = $file;
            $started_ns       = function_exists('hrtime') ? hrtime(true) : 0;
            require_once $file;
            $duration_ms = $started_ns > 0
                ? (int) round((hrtime(true) - $started_ns) / 1e6)
                : 0;
            E2M_Engine_Sandbox_Helper::record_load($file, $duration_ms, null);
            $loaded[] = $file;
        }
        $current_file_ref = '';

        if (file_exists($state->sentinel_loading)) {
            wp_delete_file($state->sentinel_loading);
        }

        do_action('e2m_engine_after_sandbox_load', $loaded);
    }
}

final class E2M_Engine_Sandbox_Loader
{
    public static function boot(): void
    {
        $state = new E2M_Engine_Sandbox_Load_State(E2M_ENGINE_SANDBOX_DIR);

        if (!is_dir($state->sandbox_dir)) {
            return;
        }

        if (!e2m_engine_is_sandbox_enabled()) {
            return;
        }

        if (!e2m_engine_is_enabled()) {
            return;
        }

        $strategies = [
            new E2M_Engine_Sandbox_Preflight_Strategy(),
            new E2M_Engine_Sandbox_Discovery_Strategy(),
            new E2M_Engine_Sandbox_Validation_Strategy(),
            new E2M_Engine_Sandbox_Execution_Strategy(),
        ];

        foreach ($strategies as $strategy) {
            $strategy->run($state);
            if ($state->halt) {
                return;
            }
        }
    }
}
