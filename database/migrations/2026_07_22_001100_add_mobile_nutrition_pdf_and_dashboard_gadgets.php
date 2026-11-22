<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendNutritionPlans();
        $this->createDashboardTables();
        $this->seedDashboardGadgets();
        $this->seedMovementTypes();
        $this->normalizePartnerDialCodes();
    }

    public function down(): void
    {
        // Migración deliberadamente no destructiva.
    }

    private function extendNutritionPlans(): void
    {
        if (!Schema::hasTable('nutrition_plan_versions')) {
            return;
        }

        Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
            if (!Schema::hasColumn('nutrition_plan_versions', 'pdf_disk')) {
                $table->string('pdf_disk', 40)->nullable();
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'pdf_path')) {
                $table->string('pdf_path', 500)->nullable();
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'pdf_hash')) {
                $table->string('pdf_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'pdf_generated_at')) {
                $table->dateTime('pdf_generated_at')->nullable();
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'share_count')) {
                $table->unsignedInteger('share_count')->default(0);
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'last_shared_at')) {
                $table->dateTime('last_shared_at')->nullable();
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'last_shared_by_user_id')) {
                $table->string('last_shared_by_user_id', 128)->nullable()->index();
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'whatsapp_message_id')) {
                $table->string('whatsapp_message_id', 128)->nullable()->index();
            }
        });
    }

    private function createDashboardTables(): void
    {
        if (!Schema::hasTable('dashboard_gadgets')) {
            Schema::create('dashboard_gadgets', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('code', 80)->unique();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('module', 60)->index();
                $table->string('required_permission', 120)->nullable()->index();
                $table->json('allowed_roles')->nullable();
                $table->unsignedTinyInteger('default_width')->default(4);
                $table->json('allowed_widths')->nullable();
                $table->json('configuration')->nullable();
                $table->boolean('default_enabled')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->string('status', 24)->default('ACTIVE')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('dashboard_layouts')) {
            Schema::create('dashboard_layouts', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('user_id', 128)->unique();
                $table->string('partner_id', 64)->nullable()->index();
                $table->json('layout');
                $table->timestamps();
            });
        }
    }

    private function seedDashboardGadgets(): void
    {
        if (!Schema::hasTable('dashboard_gadgets')) {
            return;
        }

        $gadgets = [
            ['GADGET-PROSPECTS', 'PROSPECTS_NEW', 'Prospectos', 'Prospectos activos, nuevos y sin seguimiento.', 'CRM', 'contacts.read', null, 4, [3,4,6,8,12], true, 10],
            ['GADGET-TASKS', 'TASKS_PENDING', 'Actividades pendientes', 'Seguimientos pendientes y vencidos.', 'TASKS', 'tasks.read', null, 4, [4,6,8,12], true, 20],
            ['GADGET-NUTRITION', 'NUTRITION_PLANS', 'Planes alimentarios', 'Planes vigentes, pendientes de enviar y casos para revisión.', 'NUTRITION', 'nutrition.read', null, 4, [4,6,8,12], true, 30],
            ['GADGET-MEASUREMENTS', 'MEASUREMENTS_RECENT', 'Pesajes recientes', 'Pesajes de hoy y de los últimos siete días.', 'MEASUREMENTS', 'contacts.read', null, 4, [3,4,6,8,12], true, 40],
            ['GADGET-EVENTS', 'EVENTS_UPCOMING', 'Próximos eventos', 'Eventos activos o programados.', 'CAMPAIGNS', 'campaigns.read', null, 6, [4,6,8,12], true, 50],
            ['GADGET-ORGANIZATION', 'ORGANIZATION_SUMMARY', 'Mi organización', 'Rango, patrocinador y línea ascendente.', 'ORGANIZATION', 'organization.read', null, 6, [4,6,8,12], true, 60],
            ['GADGET-DEVICES', 'DEVICES_ACTIVE', 'Dispositivos', 'Sesiones, dispositivos y básculas activas.', 'DEVICES', 'devices.read', null, 4, [4,6,8,12], false, 70],
            ['GADGET-MOVEMENTS', 'MOVEMENTS_RECENT', 'Movimientos recientes', 'Folios y códigos de movimientos recientes.', 'MOVEMENTS', 'movements.read', null, 8, [6,8,12], false, 80],
            ['GADGET-SALES', 'SALES_MONTH', 'Resultados comerciales', 'Ventas e ingresos del mes.', 'SALES', 'analytics.read', ['PARTNER_ADMIN','LOCATION_MANAGER','TEAM_LEADER','ADVISOR','SUPER_ADMIN'], 4, [3,4,6,8,12], true, 90],
        ];

        foreach ($gadgets as [$id, $code, $name, $description, $module, $permission, $roles, $width, $allowedWidths, $enabled, $order]) {
            DB::table('dashboard_gadgets')->updateOrInsert(
                ['id' => $id],
                [
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'module' => $module,
                    'required_permission' => $permission,
                    'allowed_roles' => $roles === null ? null : json_encode($roles, JSON_UNESCAPED_UNICODE),
                    'default_width' => $width,
                    'allowed_widths' => json_encode($allowedWidths),
                    'configuration' => json_encode([]),
                    'default_enabled' => $enabled,
                    'sort_order' => $order,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedMovementTypes(): void
    {
        if (!Schema::hasTable('movement_types')) {
            return;
        }

        $types = [
            ['604', 'NUTRITION', 'PDF de plan alimentario generado', 'Generación del documento PDF de una versión de plan alimentario.'],
            ['605', 'NUTRITION', 'Compartición de plan iniciada', 'El usuario compartió o intentó compartir el PDF desde RHINO CRM.'],
            ['606', 'WHATSAPP', 'Plan alimentario enviado', 'Envío directo del PDF mediante WhatsApp Cloud API.'],
            ['607', 'WHATSAPP', 'Plan alimentario entregado', 'Meta confirmó la entrega del PDF al contacto.'],
            ['608', 'WHATSAPP', 'Plan alimentario leído', 'Meta confirmó la lectura del mensaje que contiene el PDF.'],
            ['609', 'WHATSAPP', 'Envío de plan alimentario fallido', 'Meta notificó un error en el envío del PDF.'],
        ];

        foreach ($types as [$code, $module, $name, $description]) {
            DB::table('movement_types')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => $module,
                    'name' => $name,
                    'description' => $description,
                    'reversal_code' => null,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function normalizePartnerDialCodes(): void
    {
        if (!Schema::hasTable('partners') || !Schema::hasColumn('partners', 'country_dial_code')) {
            return;
        }

        DB::table('partners')
            ->where(function ($query): void {
                $query->whereNull('country_dial_code')->orWhere('country_dial_code', '');
            })
            ->update(['country_dial_code' => '52', 'updated_at' => now()]);
    }
};
