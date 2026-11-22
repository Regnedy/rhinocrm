<?php

namespace App\Console\Commands;

use App\Modules\CRM\Jobs\PublishOutboxEventsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process {--limit=100}';
    protected $description = 'Reclama y despacha eventos pendientes del Transactional Outbox MySQL';
    private const BACKOFF = [1, 5, 15, 60, 240];

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 500);
        $ids = DB::table('event_outbox')
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('status', 'PENDING')->where('available_at', '<=', now());
                })->orWhere(function ($processing): void {
                    $processing->where('status', 'PROCESSING')->where('locked_until', '<=', now());
                });
            })
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $count = 0;
        foreach ($ids as $id) {
            $claim = $this->claim((string) $id);
            if ($claim === null) {
                continue;
            }
            try {
                PublishOutboxEventsJob::dispatch($claim['event_id'], $claim['partner_id'], $claim['lock_token']);
                $count++;
            } catch (\Throwable $exception) {
                $this->release($claim['event_id'], $claim['lock_token'], $claim['attempt_count'], $exception->getMessage());
                report($exception);
            }
        }

        $this->info("Despachados {$count} eventos del outbox.");
        return self::SUCCESS;
    }

    /** @return array{event_id:string,partner_id:string,lock_token:string,attempt_count:int}|null */
    private function claim(string $eventId): ?array
    {
        return DB::transaction(function () use ($eventId): ?array {
            $row = DB::table('event_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if ($row === null || !in_array((string) $row->status, ['PENDING', 'PROCESSING'], true)) {
                return null;
            }
            if ((string) $row->status === 'PENDING' && now()->lessThan($row->available_at)) {
                return null;
            }
            if ((string) $row->status === 'PROCESSING' && $row->locked_until !== null && now()->lessThanOrEqualTo($row->locked_until)) {
                return null;
            }

            $attempt = (int) $row->attempt_count + 1;
            $max = max(1, (int) env('OUTBOX_MAX_ATTEMPTS', 5));
            if ($attempt > $max) {
                DB::table('event_outbox')->where('id', $eventId)->update([
                    'status' => 'FAILED', 'failed_at' => now(), 'lock_token' => null, 'locked_until' => null, 'updated_at' => now(),
                ]);
                return null;
            }

            $token = (string) Str::uuid();
            DB::table('event_outbox')->where('id', $eventId)->update([
                'status' => 'PROCESSING',
                'lock_token' => $token,
                'locked_until' => now()->addSeconds(max(30, (int) env('OUTBOX_LOCK_SECONDS', 300))),
                'attempt_count' => $attempt,
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return ['event_id' => $eventId, 'partner_id' => (string) $row->partner_id, 'lock_token' => $token, 'attempt_count' => $attempt];
        }, 3);
    }

    private function release(string $eventId, string $token, int $attempt, string $error): void
    {
        $minutes = self::BACKOFF[min(max($attempt - 1, 0), count(self::BACKOFF) - 1)];
        DB::table('event_outbox')->where('id', $eventId)->where('lock_token', $token)->update([
            'status' => 'PENDING', 'available_at' => now()->addMinutes($minutes), 'last_error' => mb_substr($error, 0, 1000),
            'lock_token' => null, 'locked_until' => null, 'updated_at' => now(),
        ]);
    }
}
