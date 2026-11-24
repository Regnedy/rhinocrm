<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices')) {
            $hasPartnerId = Schema::hasColumn('devices', 'partner_id');
            $hasLocationId = Schema::hasColumn('devices', 'location_id');

            if ($hasPartnerId || $hasLocationId) {
                Schema::table('devices', function (Blueprint $table) use ($hasPartnerId, $hasLocationId): void {
                    if ($hasPartnerId) {
                        $table->string('partner_id', 64)->nullable()->change();
                    }
                    if ($hasLocationId) {
                        $table->string('location_id', 64)->nullable()->change();
                    }
                });
            }
        }

        if (Schema::hasTable('movement_types')) {
            $types = [
                [
                    'code' => '804',
                    'module' => 'DEVICES',
                    'name' => 'Báscula desvinculada del socio',
                    'description' => 'Retira la asignación de una báscula sin eliminar el dispositivo ni sus mediciones históricas.',
                ],
                [
                    'code' => '805',
                    'module' => 'DEVICES',
                    'name' => 'Báscula reasignada a socio',
                    'description' => 'Asigna a un socio una báscula que estaba disponible y sin propietario.',
                ],
            ];

            foreach ($types as $type) {
                DB::table('movement_types')->updateOrInsert(
                    ['code' => $type['code']],
                    $type + [
                        'reversal_code' => null,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente no destructiva: puede haber básculas UNASSIGNED y
        // movimientos 804/805 que dependen de esta ampliación.
    }
};
