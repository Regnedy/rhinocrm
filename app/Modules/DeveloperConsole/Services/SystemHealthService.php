<?php

namespace App\Modules\DeveloperConsole\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class SystemHealthService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $database = $this->databaseCheck();
        $scheduler = $this->schedulerCheck();
        $queue = $this->queueCheck();
        $paths = $this->pathChecks();
        $migration = $this->migrationStatus();

        $checks = [
            $database['status'],
            $scheduler['status'],
            $queue['status'],
            ...array_column($paths, 'status'),
            $migration['status'],
        ];

        return [
            'overall_status' => in_array('ERROR', $checks, true)
                ? 'ERROR'
                : (in_array('WARNING', $checks, true) ? 'WARNING' : 'OK'),
            'application' => [
                'name' => (string) config('app.name'),
                'environment' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
                'version' => (string) config('rhino.version', '2026.07.20'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'timezone' => (string) config('app.timezone'),
                'installed' => is_file((string) config('rhino.installed_lock')),
            ],
            'database' => $database,
            'scheduler' => $scheduler,
            'queue' => $queue,
            'migrations' => $migration,
            'paths' => $paths,
            'extensions' => $this->extensions(),
            'integrations' => [
                'firebase_enabled' => (bool) config('firebase.auth_enabled', false),
                'firebase_auth_driver' => (string) config('rhino.auth.driver', 'local'),
                'firebase_sdk_installed' => class_exists(\Kreait\Firebase\Factory::class),
                'firebase_credentials_readable' => $this->firebaseCredentialsReadable(),
                'firebase_project_id_configured' => trim((string) config('firebase.project_id')) !== '',
                'openai_console_enabled' => $this->aiConfigured(),
            ],
            'runtime' => [
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'disabled_functions' => array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))))),
                'free_disk_bytes' => @disk_free_space(base_path()) ?: null,
                'total_disk_bytes' => @disk_total_space(base_path()) ?: null,
            ],
            'generated_at' => now()->toAtomString(),
        ];
    }

    /** @return array<string,mixed> */
    private function databaseCheck(): array
    {
        try {
            $started = hrtime(true);
            DB::select('SELECT 1');
            $latency = (int) round((hrtime(true) - $started) / 1_000_000);
            return ['status' => 'OK', 'connected' => true, 'latency_ms' => $latency, 'driver' => DB::connection()->getDriverName()];
        } catch (\Throwable $exception) {
            return ['status' => 'ERROR', 'connected' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function schedulerCheck(): array
    {
        try {
            if (!Schema::hasTable('system_settings')) {
                return ['status' => 'WARNING', 'last_seen_at' => null, 'message' => 'La tabla system_settings aún no existe.'];
            }
            $value = DB::table('system_settings')->where('key', 'scheduler_heartbeat')->value('value');
            if (!is_string($value) || trim($value) === '') {
                return ['status' => 'WARNING', 'last_seen_at' => null, 'message' => 'El cron todavía no ha registrado actividad.'];
            }
            $payload = json_decode($value, true);
            $lastSeen = is_array($payload) ? ($payload['last_seen_at'] ?? null) : null;
            if (!is_string($lastSeen)) {
                return ['status' => 'WARNING', 'last_seen_at' => null, 'message' => 'El heartbeat del cron no es válido.'];
            }
            $age = now()->diffInSeconds(Carbon::parse($lastSeen));
            return [
                'status' => $age <= 180 ? 'OK' : 'WARNING',
                'last_seen_at' => $lastSeen,
                'age_seconds' => $age,
                'message' => $age <= 180 ? 'Cron activo.' : 'El cron no se ha ejecutado durante más de tres minutos.',
            ];
        } catch (\Throwable $exception) {
            return ['status' => 'WARNING', 'last_seen_at' => null, 'message' => $exception->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function queueCheck(): array
    {
        try {
            $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null;
            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;
            $outboxPending = Schema::hasTable('event_outbox') ? (int) DB::table('event_outbox')->where('status', 'PENDING')->count() : null;
            $outboxFailed = Schema::hasTable('event_outbox') ? (int) DB::table('event_outbox')->where('status', 'FAILED')->count() : null;
            $status = (($failed ?? 0) > 0 || ($outboxFailed ?? 0) > 0) ? 'WARNING' : 'OK';
            return compact('status', 'pending', 'failed', 'outboxPending', 'outboxFailed');
        } catch (\Throwable $exception) {
            return ['status' => 'WARNING', 'message' => $exception->getMessage()];
        }
    }

    /** @return array<int,array{path:string,writable:bool,status:string}> */
    private function pathChecks(): array
    {
        $paths = [storage_path(), storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')];
        return array_map(static function (string $path): array {
            $writable = is_dir($path) && is_writable($path);
            return ['path' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), 'writable' => $writable, 'status' => $writable ? 'OK' : 'ERROR'];
        }, $paths);
    }

    /** @return array<int,array{name:string,loaded:bool,status:string}> */
    private function extensions(): array
    {
        return array_map(static fn (string $name): array => [
            'name' => $name,
            'loaded' => extension_loaded($name),
            'status' => extension_loaded($name) ? 'OK' : 'ERROR',
        ], ['bcmath', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql']);
    }

    /** @return array<string,mixed> */
    private function migrationStatus(): array
    {
        try {
            Artisan::call('migrate:status', ['--no-ansi' => true]);
            $output = trim(Artisan::output());
            $pending = preg_match_all('/\bPending\b/i', $output);
            return [
                'status' => $pending > 0 ? 'WARNING' : 'OK',
                'pending_count_detected' => $pending,
                'summary' => mb_substr($output, 0, 20000),
            ];
        } catch (\Throwable $exception) {
            return ['status' => 'WARNING', 'pending_count_detected' => null, 'summary' => $exception->getMessage()];
        }
    }


    private function firebaseCredentialsReadable(): bool
    {
        $credentials = trim((string) config('firebase.credentials'));
        if ($credentials === '') {
            return false;
        }
        $path = str_starts_with($credentials, DIRECTORY_SEPARATOR)
            ? $credentials
            : base_path($credentials);

        return is_file($path) && is_readable($path);
    }

    private function aiConfigured(): bool
    {
        return (bool) config('developer_console.ai.enabled')
            && trim((string) config('developer_console.ai.api_key')) !== ''
            && trim((string) config('developer_console.ai.model')) !== '';
    }
}
