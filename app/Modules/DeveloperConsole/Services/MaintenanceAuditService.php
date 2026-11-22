<?php

namespace App\Modules\DeveloperConsole\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MaintenanceAuditService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly Request $request,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function start(string $type, string $name, array $metadata = []): string
    {
        $id = 'MNT_'.strtoupper((string) Str::ulid());
        DB::table('maintenance_action_logs')->insert([
            'id' => $id,
            'actor_user_id' => $this->context->userId,
            'action_type' => strtoupper($type),
            'action_name' => $name,
            'status' => 'RUNNING',
            'request_metadata' => $metadata !== []
                ? SqlValue::encode($this->sanitizeMetadata($metadata))
                : null,
            'ip_address' => $this->request->ip(),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function success(string $id, string $summary, int $startedAtNanoseconds): void
    {
        DB::table('maintenance_action_logs')->where('id', $id)->update([
            'status' => 'SUCCEEDED',
            'result_summary' => $this->redactor->redact(mb_substr($summary, 0, 120000), false),
            'duration_ms' => $this->durationMs($startedAtNanoseconds),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failure(string $id, \Throwable $exception, int $startedAtNanoseconds): void
    {
        DB::table('maintenance_action_logs')->where('id', $id)->update([
            'status' => 'FAILED',
            'error_message' => $this->redactor->redact(mb_substr($exception->getMessage(), 0, 2000), false),
            'duration_ms' => $this->durationMs($startedAtNanoseconds),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, object> */
    public function recent(int $limit = 100): array
    {
        return DB::table('maintenance_action_logs')
            ->orderByDesc('created_at')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->all();
    }

    private function durationMs(int $startedAtNanoseconds): int
    {
        return max(0, (int) round((hrtime(true) - $startedAtNanoseconds) / 1_000_000));
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $redacted = $this->redactor->redact($encoded !== false ? $encoded : '{}');
        $decoded = json_decode($redacted, true);

        return is_array($decoded) ? $decoded : [];
    }
}
