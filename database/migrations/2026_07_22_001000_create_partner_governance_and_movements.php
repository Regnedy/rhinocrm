<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendPartners();
        $this->extendContacts();
        $this->createRankTables();
        $this->createCredentialTables();
        $this->createMovementTables();
        $this->createTransferTables();
        $this->createSupportTables();
        $this->seedRanks();
        $this->seedMovementTypes();
        $this->backfillPartnerIdentity();
        $this->backfillContactOwnership();
        $this->grantPermissions();
    }

    public function down(): void
    {
        // Migración deliberadamente no destructiva.
    }

    private function extendPartners(): void
    {
        if (!Schema::hasTable('partners')) {
            return;
        }

        Schema::table('partners', function (Blueprint $table): void {
            if (!Schema::hasColumn('partners', 'partner_number')) {
                $table->string('partner_number', 40)->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('partners', 'sponsor_partner_id')) {
                $table->string('sponsor_partner_id', 64)->nullable()->index()->after('plan_id');
            }
            if (!Schema::hasColumn('partners', 'current_rank_id')) {
                $table->string('current_rank_id', 64)->nullable()->index()->after('sponsor_partner_id');
            }
        });
    }

    private function extendContacts(): void
    {
        if (!Schema::hasTable('contacts')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            if (!Schema::hasColumn('contacts', 'registered_by_partner_id')) {
                $table->string('registered_by_partner_id', 64)->nullable()->index()->after('partner_id');
            }
            if (!Schema::hasColumn('contacts', 'responsible_partner_id')) {
                $table->string('responsible_partner_id', 64)->nullable()->index()->after('registered_by_partner_id');
            }
        });
    }

    private function createRankTables(): void
    {
        if (!Schema::hasTable('partner_ranks')) {
            Schema::create('partner_ranks', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('code', 60)->unique();
                $table->string('name', 120);
                $table->unsignedSmallInteger('hierarchy_order')->unique();
                $table->string('credential_label', 120)->nullable();
                $table->string('badge_key', 80)->nullable();
                $table->text('description')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('partner_rank_history')) {
            Schema::create('partner_rank_history', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('previous_rank_id', 64)->nullable()->index();
                $table->string('rank_id', 64)->index();
                $table->string('movement_id', 80)->nullable()->index();
                $table->string('changed_by_user_id', 128)->nullable()->index();
                $table->string('reason', 1000)->nullable();
                $table->dateTime('effective_at')->index();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['partner_id', 'effective_at'], 'partner_rank_history_time_idx');
            });
        }

        if (!Schema::hasTable('partner_relationship_history')) {
            Schema::create('partner_relationship_history', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('previous_sponsor_partner_id', 64)->nullable()->index();
                $table->string('sponsor_partner_id', 64)->nullable()->index();
                $table->string('movement_id', 80)->nullable()->index();
                $table->string('changed_by_user_id', 128)->nullable()->index();
                $table->string('reason', 1000)->nullable();
                $table->dateTime('effective_at')->index();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    private function createCredentialTables(): void
    {
        if (Schema::hasTable('partner_credentials')) {
            return;
        }

        Schema::create('partner_credentials', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('partner_id', 64)->index();
            $table->string('credential_number', 100)->unique();
            $table->string('public_code', 100)->unique();
            $table->string('partner_number_snapshot', 40);
            $table->string('partner_name_snapshot', 180);
            $table->string('rank_id_snapshot', 64)->nullable()->index();
            $table->string('rank_name_snapshot', 120)->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->dateTime('issued_at')->index();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_user_id', 128)->nullable();
            $table->string('revocation_reason', 1000)->nullable();
            $table->string('issued_by_user_id', 128)->nullable()->index();
            $table->timestamps();
            $table->index(['partner_id', 'status'], 'partner_credentials_state_idx');
        });
    }

    private function createMovementTables(): void
    {
        if (!Schema::hasTable('movement_types')) {
            Schema::create('movement_types', function (Blueprint $table): void {
                $table->string('code', 10)->primary();
                $table->string('module', 60)->index();
                $table->string('name', 180);
                $table->text('description')->nullable();
                $table->string('reversal_code', 10)->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('movement_sequences')) {
            Schema::create('movement_sequences', function (Blueprint $table): void {
                $table->unsignedSmallInteger('sequence_year')->primary();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('business_movements')) {
            Schema::create('business_movements', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('folio', 40)->unique();
                $table->string('movement_code', 10)->index();
                $table->string('module', 60)->index();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('actor_user_id', 128)->nullable()->index();
                $table->string('target_type', 80)->index();
                $table->string('target_id', 128)->nullable()->index();
                $table->string('reference_type', 80)->nullable()->index();
                $table->string('reference_id', 128)->nullable()->index();
                $table->string('original_movement_id', 80)->nullable()->index();
                $table->string('status', 32)->default('POSTED')->index();
                $table->string('reason', 1000)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('access_channel', 40)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->dateTime('occurred_at')->index();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->index(['partner_id', 'occurred_at'], 'business_movements_partner_time_idx');
                $table->index(['movement_code', 'occurred_at'], 'business_movements_code_time_idx');
            });
        }
    }

    private function createTransferTables(): void
    {
        if (Schema::hasTable('contact_partner_transfers')) {
            return;
        }

        Schema::create('contact_partner_transfers', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('contact_id', 64)->index();
            $table->string('registered_by_partner_id', 64)->index();
            $table->string('previous_responsible_partner_id', 64)->index();
            $table->string('responsible_partner_id', 64)->index();
            $table->string('movement_id', 80)->nullable()->index();
            $table->string('changed_by_user_id', 128)->nullable()->index();
            $table->string('reason', 1000);
            $table->json('transferred_tables')->nullable();
            $table->dateTime('transferred_at')->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contact_id', 'transferred_at'], 'contact_partner_transfers_time_idx');
        });
    }

    private function createSupportTables(): void
    {
        if (!Schema::hasTable('partner_support_requests')) {
            Schema::create('partner_support_requests', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('requester_partner_id', 64)->index();
                $table->string('assigned_partner_id', 64)->nullable()->index();
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->string('subject', 180);
                $table->text('description');
                $table->string('priority', 20)->default('NORMAL')->index();
                $table->string('status', 32)->default('OPEN')->index();
                $table->unsignedSmallInteger('escalation_level')->default(1);
                $table->dateTime('last_escalated_at')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->string('resolved_by_user_id', 128)->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->string('movement_id', 80)->nullable()->index();
                $table->timestamps();
                $table->index(['requester_partner_id', 'status'], 'partner_support_requester_state_idx');
                $table->index(['assigned_partner_id', 'status'], 'partner_support_assigned_state_idx');
            });
        }

        if (!Schema::hasTable('partner_support_history')) {
            Schema::create('partner_support_history', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('support_request_id', 80)->index();
                $table->string('action', 40)->index();
                $table->string('from_partner_id', 64)->nullable()->index();
                $table->string('to_partner_id', 64)->nullable()->index();
                $table->string('actor_user_id', 128)->nullable()->index();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    private function seedRanks(): void
    {
        if (!Schema::hasTable('partner_ranks')) {
            return;
        }

        $ranks = [
            ['RANK-AFILIADO', 'AFILIADO', 'Afiliado', 10],
            ['RANK-AFILIADO-CALIFICADO', 'AFILIADO_CALIFICADO', 'Afiliado Calificado', 20],
            ['RANK-APRENDIZ', 'APRENDIZ', 'Aprendiz', 30],
            ['RANK-APRENDIZ-ELITE', 'APRENDIZ_ELITE', 'Aprendiz Élite', 40],
            ['RANK-SUPERVISOR', 'SUPERVISOR', 'Supervisor', 50],
            ['RANK-BRONCE', 'BRONCE', 'Bronce', 60],
            ['RANK-PLATA', 'PLATA', 'Plata', 70],
            ['RANK-ORO', 'ORO', 'Oro', 80],
            ['RANK-PLATINO', 'PLATINO', 'Platino', 90],
            ['RANK-ZAFIRO', 'ZAFIRO', 'Zafiro', 100],
            ['RANK-RUBI', 'RUBI', 'Rubí', 110],
            ['RANK-ESMERALDA', 'ESMERALDA', 'Esmeralda', 120],
            ['RANK-DIAMANTE', 'DIAMANTE', 'Diamante', 130],
            ['RANK-DOBLE-DIAMANTE', 'DOBLE_DIAMANTE', 'Doble Diamante', 140],
            ['RANK-TRIPLE-DIAMANTE', 'TRIPLE_DIAMANTE', 'Triple Diamante', 150],
            ['RANK-DIAMANTE-CORONA', 'DIAMANTE_CORONA', 'Diamante Corona', 160],
        ];

        foreach ($ranks as [$id, $code, $name, $order]) {
            DB::table('partner_ranks')->updateOrInsert(
                ['id' => $id],
                [
                    'code' => $code,
                    'name' => $name,
                    'hierarchy_order' => $order,
                    'credential_label' => $name,
                    'badge_key' => strtolower(str_replace('_', '-', $code)),
                    'active' => true,
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
            ['101', 'INVENTORY', 'Entrada de inventario', 'Ingreso de existencias al almacén.', '102'],
            ['102', 'INVENTORY', 'Reverso de entrada de inventario', 'Revierte una entrada contabilizada.', null],
            ['201', 'INVENTORY', 'Salida de inventario', 'Salida de existencias del almacén.', '202'],
            ['202', 'INVENTORY', 'Reverso de salida de inventario', 'Revierte una salida contabilizada.', null],
            ['301', 'INVENTORY', 'Transferencia de inventario', 'Movimiento entre cuentas o almacenes.', null],
            ['401', 'SALES', 'Venta contabilizada', 'Registro de una venta.', '402'],
            ['402', 'SALES', 'Reembolso o reverso de venta', 'Reverso de una venta contabilizada.', null],
            ['501', 'CRM', 'Registro de prospecto', 'Alta inicial de un prospecto.', null],
            ['541', 'PARTNERS', 'Registro de socio', 'Alta de un socio en RHINO.', null],
            ['542', 'PARTNERS', 'Asignación de patrocinador', 'Asignación inicial del upline directo.', null],
            ['543', 'PARTNERS', 'Corrección de patrocinador', 'Cambio administrativo y auditado del upline directo.', null],
            ['544', 'PARTNERS', 'Actualización de ID de socio', 'Cambio del identificador visible de la compañía.', null],
            ['545', 'PARTNERS', 'Actualización de datos del socio', 'Cambio auditado del nombre visible u otros datos organizacionales.', null],
            ['551', 'PARTNERS', 'Cambio de rango', 'Actualización del rango organizacional.', null],
            ['561', 'CRM', 'Cambio de socio responsable', 'Transferencia operativa de un prospecto sin cambiar quién lo registró.', null],
            ['571', 'SUPPORT', 'Solicitud de apoyo al upline', 'Solicitud dirigida al patrocinador o upline inmediato.', null],
            ['572', 'SUPPORT', 'Escalamiento de solicitud', 'Escalamiento al siguiente upline disponible.', null],
            ['601', 'MEASUREMENTS', 'Registro de pesaje', 'Registro de una medición corporal.', null],
            ['602', 'NUTRITION', 'Generación de plan alimentario', 'Creación de una versión de plan alimentario.', null],
            ['603', 'NUTRITION', 'Actualización de plan alimentario', 'Nueva versión por seguimiento o pesaje.', null],
            ['701', 'CAMPAIGNS', 'Inclusión en campaña', 'Inclusión individual mediante checklist.', null],
            ['702', 'WHATSAPP', 'Invitación enviada', 'Envío de una invitación individual.', null],
            ['703', 'WHATSAPP', 'Confirmación recibida', 'Confirmación del prospecto.', null],
            ['801', 'DEVICES', 'Dispositivo registrado', 'Registro de una báscula o instalación.', null],
            ['802', 'DEVICES', 'Dispositivo autorizado', 'Reactivación o autorización de dispositivo.', null],
            ['803', 'DEVICES', 'Dispositivo revocado', 'Revocación de un dispositivo.', null],
            ['901', 'SECURITY', 'Socio suspendido', 'Suspensión reversible de acceso sin borrar información.', null],
            ['902', 'SECURITY', 'Socio reactivado', 'Reactivación de un socio suspendido.', null],
            ['911', 'CREDENTIALS', 'Credencial emitida', 'Emisión de una credencial digital verificable.', null],
            ['912', 'CREDENTIALS', 'Credencial revocada', 'Revocación de una credencial digital.', null],
        ];

        foreach ($types as [$code, $module, $name, $description, $reversal]) {
            DB::table('movement_types')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => $module,
                    'name' => $name,
                    'description' => $description,
                    'reversal_code' => $reversal,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function backfillPartnerIdentity(): void
    {
        if (!Schema::hasTable('partners')) {
            return;
        }

        $defaultRank = Schema::hasTable('partner_ranks')
            ? DB::table('partner_ranks')->where('code', 'AFILIADO')->value('id')
            : null;

        DB::table('partners')->whereNull('deleted_at')->orderBy('id')->chunk(100, function ($partners) use ($defaultRank): void {
            foreach ($partners as $partner) {
                $update = [];
                if (($partner->partner_number ?? null) === null || trim((string) $partner->partner_number) === '') {
                    $update['partner_number'] = 'LEGACY-'.strtoupper(substr(hash('sha256', (string) $partner->id), 0, 12));
                }
                if ($defaultRank !== null && (($partner->current_rank_id ?? null) === null || $partner->current_rank_id === '')) {
                    $update['current_rank_id'] = (string) $defaultRank;
                }
                if ($update !== []) {
                    $update['updated_at'] = now();
                    DB::table('partners')->where('id', $partner->id)->update($update);
                }

                if ($defaultRank !== null && Schema::hasTable('partner_rank_history')) {
                    DB::table('partner_rank_history')->insertOrIgnore([
                        'id' => 'PRH_'.strtoupper((string) Str::ulid()),
                        'partner_id' => (string) $partner->id,
                        'previous_rank_id' => null,
                        'rank_id' => (string) ($partner->current_rank_id ?? $defaultRank),
                        'movement_id' => null,
                        'changed_by_user_id' => null,
                        'reason' => 'Rango inicial asignado durante actualización.',
                        'effective_at' => $partner->created_at ?? now(),
                        'created_at' => now(),
                    ]);
                }
            }
        });
    }

    private function backfillContactOwnership(): void
    {
        if (!Schema::hasTable('contacts')) {
            return;
        }

        DB::table('contacts')
            ->whereNull('registered_by_partner_id')
            ->update(['registered_by_partner_id' => DB::raw('partner_id')]);

        DB::table('contacts')
            ->whereNull('responsible_partner_id')
            ->update(['responsible_partner_id' => DB::raw('partner_id')]);
    }

    private function grantPermissions(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'permissions')) {
            return;
        }

        $permissions = [
            'PARTNER_ADMIN' => [
                'organization.read',
                'organization.support.create',
                'organization.support.manage',
                'credentials.read',
                'movements.read',
            ],
            'LOCATION_MANAGER' => [
                'organization.read',
                'organization.support.create',
                'credentials.read',
            ],
            'TEAM_LEADER' => [
                'organization.read',
                'organization.support.create',
                'credentials.read',
            ],
            'ADVISOR' => [
                'organization.read',
                'organization.support.create',
                'credentials.read',
            ],
            'OPERATOR' => [
                'organization.read',
                'organization.support.create',
                'credentials.read',
            ],
        ];

        DB::table('users')
            ->whereIn('role', array_keys($permissions))
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(200, function ($users) use ($permissions): void {
                foreach ($users as $user) {
                    $current = json_decode((string) ($user->permissions ?? '[]'), true);
                    $current = is_array($current) ? $current : [];
                    if ($current === []) {
                        continue;
                    }

                    $merged = array_values(array_unique(array_merge(
                        array_map('strval', $current),
                        $permissions[(string) $user->role] ?? [],
                    )));

                    DB::table('users')->where('id', $user->id)->update([
                        'permissions' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
