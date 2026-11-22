<?php

namespace App\Modules\CRM\Jobs;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Support\Database\SqlValue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class CreateSlaAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $taskId, public string $partnerId) {}

    public function handle(TenantContextRunner $runner): void
    {
        $runner->runAsSystem($this->partnerId, function (): void {
            DB::transaction(function (): void {
                if (DB::table('sla_alerts')->where('task_id', $this->taskId)->lockForUpdate()->exists()) {
                    return;
                }
                $task = DB::table('tasks')->where('id', $this->taskId)->lockForUpdate()->first();
                if ($task === null || (string) $task->partner_id !== $this->partnerId || (string) $task->status !== 'PENDING') {
                    return;
                }
                DB::table('sla_alerts')->insert([
                    'task_id' => $this->taskId,
                    'partner_id' => $this->partnerId,
                    'contact_id' => $task->contact_id,
                    'assigned_user_id' => $task->assigned_user_id,
                    'status' => 'OPEN',
                    'created_at' => now(),
                ]);
                DB::table('timeline_activities')->insertOrIgnore([
                    'id' => 'TML_SLA_'.$this->taskId,
                    'partner_id' => $this->partnerId,
                    'contact_id' => $task->contact_id,
                    'type' => 'SLA_BREACH',
                    'description' => 'La tarea de seguimiento venció sin completarse.',
                    'metadata' => SqlValue::encode(['task_id' => $this->taskId]),
                    'actor_id' => 'SYSTEM_SLA',
                    'created_at' => now(),
                ]);
            }, 3);
        });
    }
}
