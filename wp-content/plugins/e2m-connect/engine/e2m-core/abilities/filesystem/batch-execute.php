<?php
/**
 * E2M MCP - Batch Execute Ability.
 *
 * Runs multiple abilities in a single request to reduce round-trips
 * and save tokens for AI clients.
 */

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('e2m/batch-execute', [
    'label'       => __('[E2M] Batch Execute', 'e2mconnect'),
    'description' => __('Execute multiple abilities in a single request. Each operation specifies an ability name and its parameters. Results are returned per-operation.', 'e2mconnect'),
    'category' => 'e2m-code-execution',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'operations' => [
                'type' => 'array',
                'description' => 'List of ability calls to execute (max 20).',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'ability_name' => [
                            'type' => 'string',
                            'description' => 'Fully qualified ability name, e.g. e2m/read-file.',
                        ],
                        'parameters' => [
                            'type' => 'object',
                            'description' => 'Parameters to pass to the ability.',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['ability_name'],
                    'additionalProperties' => false,
                ],
                'maxItems' => 20,
            ],
            'stop_on_error' => [
                'type' => 'boolean',
                'description' => 'If true, stop executing remaining operations when one fails. Default: false.',
            ],
        ],
        'required' => ['operations'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'e2m_engine_batch_execute',
    'permission_callback' => 'e2m_engine_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions'  => 'Runs up to 20 abilities sequentially in one request. Use this to batch read operations or multi-step workflows. Each operation result includes success status, data or error, and execution time.',
            'readonly'      => false,
            'destructive'   => false,
            'idempotent'    => false,
        ],
    ],
]);

/**
 * Execute a batch of abilities.
 *
 * @param array $input {operations: array, stop_on_error?: bool}
 * @return array
 */
function e2m_engine_batch_execute(array $input): array
{
    $operations = $input['operations'] ?? [];
    $stop_on_error = (bool) ($input['stop_on_error'] ?? false);

    if (empty($operations)) {
        return ['success' => false, 'error' => 'No operations provided.'];
    }

    if (count($operations) > 20) {
        return ['success' => false, 'error' => 'Maximum 20 operations per batch.'];
    }

    if (!function_exists('wp_get_ability')) {
        return ['success' => false, 'error' => 'Abilities API not available.'];
    }

    $results = [];
    $total_success = 0;
    $total_error = 0;

    foreach ($operations as $index => $op) {
        $ability_name = sanitize_text_field($op['ability_name'] ?? '');
        $params = is_array($op['parameters'] ?? null) ? $op['parameters'] : [];

        if ($ability_name === '') {
            $results[] = [
                'index'         => $index,
                'ability_name'  => '',
                'success'       => false,
                'error'         => 'Missing ability_name.',
                'execution_time_ms' => 0,
            ];
            $total_error++;
            if ($stop_on_error) { break; }
            continue;
        }

        // Check if ability is disabled.
        if (function_exists('e2m_engine_is_ability_disabled') && e2m_engine_is_ability_disabled($ability_name)) {
            $results[] = [
                'index'         => $index,
                'ability_name'  => $ability_name,
                'success'       => false,
                'error'         => 'Ability is disabled.',
                'execution_time_ms' => 0,
            ];
            $total_error++;
            if ($stop_on_error) { break; }
            continue;
        }

        $ability = wp_get_ability($ability_name);
        if (!$ability || !($ability instanceof WP_Ability)) {
            $results[] = [
                'index'         => $index,
                'ability_name'  => $ability_name,
                'success'       => false,
                'error'         => 'Ability not found.',
                'execution_time_ms' => 0,
            ];
            $total_error++;
            if ($stop_on_error) { break; }
            continue;
        }

        // SECURITY: Enforce per-ability permission checks.
        $perm_check = $ability->check_permissions($params);
        if (is_wp_error($perm_check)) {
            $results[] = [
                'index'         => $index,
                'ability_name'  => $ability_name,
                'success'       => false,
                'error'         => $perm_check->get_error_message(),
                'execution_time_ms' => 0,
            ];
            $total_error++;
            if ($stop_on_error) { break; }
            continue;
        }
        if ($perm_check === false) {
            $results[] = [
                'index'         => $index,
                'ability_name'  => $ability_name,
                'success'       => false,
                'error'         => 'Permission denied for this ability.',
                'execution_time_ms' => 0,
            ];
            $total_error++;
            if ($stop_on_error) { break; }
            continue;
        }

        $start = microtime(true);
        try {
            $data = $ability->execute($params);
            $exec_ms = (int) round((microtime(true) - $start) * 1000);

            // Detect WP_Error returns from ability callbacks.
            if (is_wp_error($data)) {
                $results[] = [
                    'index'         => $index,
                    'ability_name'  => $ability_name,
                    'success'       => false,
                    'error'         => $data->get_error_message(),
                    'execution_time_ms' => $exec_ms,
                ];
                $total_error++;
                if ($stop_on_error) { break; }
                continue;
            }

            $results[] = [
                'index'         => $index,
                'ability_name'  => $ability_name,
                'success'       => true,
                'data'          => $data,
                'execution_time_ms' => $exec_ms,
            ];
            $total_success++;
        } catch (\Throwable $e) {
            $exec_ms = (int) round((microtime(true) - $start) * 1000);
            $results[] = [
                'index'         => $index,
                'ability_name'  => $ability_name,
                'success'       => false,
                'error'         => $e->getMessage(),
                'execution_time_ms' => $exec_ms,
            ];
            $total_error++;
            if ($stop_on_error) { break; }
        }
    }

    return [
        'success'       => $total_error === 0,
        'total'         => count($operations),
        'succeeded'     => $total_success,
        'failed'        => $total_error,
        'results'       => $results,
    ];
}
