<?php

namespace App\Console\Commands;

use App\Support\Database\SqlValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BootstrapRhinoCommand extends Command
{
    protected $signature = 'rhino:bootstrap {--force : Actualizar catálogos existentes sin eliminar datos}';
    protected $description = 'Inicializa planes y configuración base de Rhino CRM en MySQL';

    public function handle(): int
    {
        $plans = [
            'PLAN_STARTER' => ['name' => 'Starter', 'max_users' => 5, 'max_devices' => 3, 'max_measurements' => 5000],
            'PLAN_GROWTH' => ['name' => 'Growth', 'max_users' => 25, 'max_devices' => 20, 'max_measurements' => 50000],
            'PLAN_ENTERPRISE' => ['name' => 'Enterprise', 'max_users' => 250, 'max_devices' => 500, 'max_measurements' => 1000000],
        ];

        foreach ($plans as $id => $data) {
            $exists = DB::table('plans')->where('id', $id)->exists();
            if ($exists && !$this->option('force')) {
                $this->line("{$id}: ya existe; omitido.");
                continue;
            }
            DB::table('plans')->updateOrInsert(['id' => $id], $data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->info("{$id}: creado/actualizado.");
        }

        DB::table('system_settings')->updateOrInsert(['key' => 'core'], [
            'value' => SqlValue::encode(['schema_version' => 3, 'default_currency' => 'MXN', 'persistence' => 'mysql']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['teams', 'team_goals', 'advanced_reports', 'mobile_sessions', 'developer_console'] as $feature) {
            DB::table('feature_flags')->updateOrInsert(
                ['partner_id' => null, 'feature' => $feature],
                ['enabled' => true, 'configuration' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $this->info('Bootstrap de Rhino CRM MySQL completado sin eliminar datos.');
        return self::SUCCESS;
    }
}
