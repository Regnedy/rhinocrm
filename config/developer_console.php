<?php

$allowedIps = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('RHINO_DEV_CONSOLE_ALLOWED_IPS', '')),
)));

return [
    'enabled' => filter_var(env('RHINO_DEV_CONSOLE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'allowed_ips' => $allowedIps,
    'max_log_lines' => max(50, (int) env('RHINO_DEV_CONSOLE_MAX_LOG_LINES', 1000)),
    'max_log_bytes' => max(65536, (int) env('RHINO_DEV_CONSOLE_MAX_LOG_BYTES', 2097152)),
    'redact_pii' => filter_var(env('RHINO_DEV_CONSOLE_REDACT_PII', true), FILTER_VALIDATE_BOOL),
    'source_roots' => ['app', 'routes', 'config', 'database/migrations', 'docs'],
    'source_extensions' => ['php', 'md', 'json', 'yml', 'yaml', 'xml'],
    'max_source_file_bytes' => max(8192, (int) env('RHINO_DEV_CONSOLE_MAX_SOURCE_FILE_BYTES', 131072)),
    'max_source_files' => max(1, min(12, (int) env('RHINO_DEV_CONSOLE_MAX_SOURCE_FILES', 8))),
    'max_command_output_chars' => max(10000, (int) env('RHINO_DEV_CONSOLE_MAX_COMMAND_OUTPUT_CHARS', 120000)),
    'reauth_minutes' => max(1, (int) env('RHINO_DEV_CONSOLE_REAUTH_MINUTES', 10)),
    'ai' => [
        'enabled' => filter_var(env('RHINO_DEV_CONSOLE_AI_ENABLED', false), FILTER_VALIDATE_BOOL),
        'endpoint' => rtrim((string) env('OPENAI_API_BASE', 'https://api.openai.com/v1'), '/').'/responses',
        'api_key' => (string) env('OPENAI_API_KEY', ''),
        'model' => (string) env('OPENAI_MODEL', ''),
        'timeout_seconds' => max(10, (int) env('OPENAI_TIMEOUT_SECONDS', 60)),
        'max_output_tokens' => max(256, (int) env('OPENAI_MAX_OUTPUT_TOKENS', 2400)),
        'max_context_chars' => max(10000, (int) env('OPENAI_MAX_CONTEXT_CHARS', 90000)),
    ],
];
