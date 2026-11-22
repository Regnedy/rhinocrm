<?php

namespace App\Modules\CRM\Jobs;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Services\OutboxEventDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PublishOutboxEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly string $outboxEventId,
        public readonly string $partnerId,
        public readonly string $lockToken,
    ) {}

    public function handle(TenantContextRunner $runner, OutboxEventDispatcher $dispatcher): void
    {
        $runner->runAsSystem($this->partnerId, function () use ($dispatcher): void {
            $row = DB::table('event_outbox')->where('id', $this->outboxEventId)->first();
            if ($row === null || (string) $row->partner_id !== $this->partnerId || (string) $row->status !== 'PROCESSING'
                || (string) $row->lock_token !== $this->lockToken || $row->locked_until === null || now()->greaterThanOrEqualTo($row->locked_until)) {
                Log::warning('Outbox lock inválido o vencido.', ['event_id' => $this->outboxEventId]);
                return;
            }

            try {
                $dispatcher->dispatch(get_object_vars($row));
                DB::table('event_outbox')->where('id', $this->outboxEventId)->where('lock_token', $this->lockToken)->update([
                    'status' => 'PUBLISHED', 'published_at' => now(), 'lock_token' => null, 'locked_until' => null,
                    'last_error' => null, 'updated_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                $this->release($exception->getMessage());
                Log::error('Fallo al publicar evento Outbox; será reintentado.', [
                    'event_id' => $this->outboxEventId,
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
            }
        });
    }

    private function release(string $error): void
    {
        DB::transaction(function () use ($error): void {
            $row = DB::table('event_outbox')->where('id', $this->outboxEventId)->lockForUpdate()->first();
            if ($row === null || (string) $row->lock_token !== $this->lockToken) {
                return;
            }
            $backoff = [1, 5, 15, 60, 240];
            $minutes = $backoff[min(max((int) $row->attempt_count - 1, 0), count($backoff) - 1)];
            DB::table('event_outbox')->where('id', $this->outboxEventId)->update([
                'status' => 'PENDING', 'available_at' => now()->addMinutes($minutes),
                'last_error' => mb_substr($error, 0, 1000), 'lock_token' => null, 'locked_until' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }
}
