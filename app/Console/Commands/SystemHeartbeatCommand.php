<?php

namespace App\Console\Commands;

use App\Support\Database\SqlValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SystemHeartbeatCommand extends Command
{
    protected $signature = 'system:heartbeat';
    protected $description = 'Registra que el scheduler de Rhino CRM continúa activo';

    public function handle(): int
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'scheduler_heartbeat'],
            [
                'value' => SqlValue::encode([
                    'last_seen_at' => now()->toAtomString(),
                    'php_version' => PHP_VERSION,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->line('Heartbeat registrado.');
        return self::SUCCESS;
    }
}
