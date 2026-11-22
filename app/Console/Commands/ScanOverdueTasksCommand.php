<?php

namespace App\Console\Commands;

use App\Modules\CRM\Jobs\CreateSlaAlertJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ScanOverdueTasksCommand extends Command
{
    protected $signature = 'tasks:sla-scan {--limit=200}';
    protected $description = 'Detecta tareas vencidas y encola alertas SLA';

    public function handle(): int
    {
        $rows = DB::table('tasks')->select(['id', 'partner_id'])
            ->where('status', 'PENDING')->where('due_at', '<', now())
            ->limit(min(max((int) $this->option('limit'), 1), 500))->get();
        foreach ($rows as $row) {
            CreateSlaAlertJob::dispatch((string) $row->id, (string) $row->partner_id);
        }
        $this->info($rows->count().' tareas vencidas encoladas.');
        return self::SUCCESS;
    }
}
