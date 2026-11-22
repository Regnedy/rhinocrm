<?php

namespace App\Modules\Partners\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Movements\Services\MovementService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ContactPartnerTransferService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly MovementService $movements,
    ) {}

    public function contacts(string $partnerId, string $search = '', int $limit = 200): array
    {
        $this->requireSuperAdmin();
        $this->partner($partnerId);
        $limit = min(max($limit, 1), 500);

        $query = DB::table('contacts as c')
            ->leftJoin('partners as rp', 'rp.id', '=', 'c.registered_by_partner_id')
            ->leftJoin('partners as op', 'op.id', '=', 'c.responsible_partner_id')
            ->where('c.partner_id', $partnerId)
            ->whereNull('c.deleted_at')
            ->select(
                'c.id',
                'c.public_code',
                'c.name',
                'c.whatsapp_normalized',
                'c.lifecycle_stage',
                'c.sales_stage',
                'c.status',
                'c.total_measurements',
                'c.last_measurement_at',
                'c.registered_by_partner_id',
                'c.responsible_partner_id',
                'rp.partner_number as registered_by_partner_number',
                'rp.business_name as registered_by_partner_name',
                'op.partner_number as responsible_partner_number',
                'op.business_name as responsible_partner_name',
            );

        if (trim($search) !== '') {
            $value = '%'.trim($search).'%';
            $query->where(function ($q) use ($value): void {
                $q->where('c.name', 'like', $value)
                    ->orWhere('c.whatsapp_normalized', 'like', $value)
                    ->orWhere('c.public_code', 'like', $value);
            });
        }

        return $query->orderBy('c.name')->limit($limit)->get()->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'public_code' => $row->public_code !== null ? (string) $row->public_code : null,
            'name' => (string) $row->name,
            'whatsapp' => (string) $row->whatsapp_normalized,
            'lifecycle_stage' => (string) $row->lifecycle_stage,
            'sales_stage' => (string) $row->sales_stage,
            'status' => (string) $row->status,
            'total_measurements' => (int) $row->total_measurements,
            'last_measurement_at' => $row->last_measurement_at,
            'registered_by_partner_id' => $row->registered_by_partner_id !== null ? (string) $row->registered_by_partner_id : null,
            'registered_by_partner_number' => $row->registered_by_partner_number !== null ? (string) $row->registered_by_partner_number : null,
            'registered_by_partner_name' => $row->registered_by_partner_name !== null ? (string) $row->registered_by_partner_name : null,
            'responsible_partner_id' => $row->responsible_partner_id !== null ? (string) $row->responsible_partner_id : null,
            'responsible_partner_number' => $row->responsible_partner_number !== null ? (string) $row->responsible_partner_number : null,
            'responsible_partner_name' => $row->responsible_partner_name !== null ? (string) $row->responsible_partner_name : null,
        ])->all();
    }

    /**
     * @param string[] $contactIds
     * @return array{responsible_partner_id:string,transferred:int,movements:array<int,array<string,mixed>>}
     */
    public function reassign(array $contactIds, string $responsiblePartnerId, string $reason): array
    {
        $this->requireSuperAdmin();
        $destination = $this->partner($responsiblePartnerId);
        if ((string) $destination->status !== 'ACTIVE') {
            throw new \RuntimeException('El nuevo socio responsable debe estar activo.', 422);
        }

        $contactIds = array_values(array_unique(array_filter(array_map('strval', $contactIds))));
        if ($contactIds === []) {
            throw new \RuntimeException('Selecciona al menos un prospecto.', 422);
        }

        $contacts = DB::table('contacts')
            ->whereIn('id', $contactIds)
            ->whereNull('deleted_at')
            ->get();

        if ($contacts->count() !== count($contactIds)) {
            throw new \RuntimeException('Uno o más prospectos no existen.', 404);
        }

        foreach ($contacts as $contact) {
            if ((string) $contact->partner_id === $responsiblePartnerId) {
                throw new \RuntimeException('El prospecto '.$contact->name.' ya pertenece al socio seleccionado.', 422);
            }

            $duplicate = DB::table('contacts')
                ->where('partner_id', $responsiblePartnerId)
                ->where('whatsapp_normalized', $contact->whatsapp_normalized)
                ->where('id', '<>', $contact->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($duplicate) {
                throw new \RuntimeException('El WhatsApp de '.$contact->name.' ya existe en el socio destino.', 409);
            }
        }

        $movements = [];

        DB::transaction(function () use ($contacts, $responsiblePartnerId, $reason, &$movements): void {
            foreach ($contacts as $contact) {
                $previousPartnerId = (string) $contact->partner_id;
                $registeredBy = $contact->registered_by_partner_id !== null
                    ? (string) $contact->registered_by_partner_id
                    : $previousPartnerId;

                $transferredTables = $this->transferRelatedRows(
                    (string) $contact->id,
                    $previousPartnerId,
                    $responsiblePartnerId,
                );

                DB::table('contacts')->where('id', $contact->id)->update([
                    'partner_id' => $responsiblePartnerId,
                    'registered_by_partner_id' => $registeredBy,
                    'responsible_partner_id' => $responsiblePartnerId,
                    'assigned_advisor_id' => null,
                    'origin_team_id' => null,
                    'updated_at' => now(),
                ]);

                $movement = $this->movements->record(
                    '561',
                    $responsiblePartnerId,
                    'CONTACT',
                    (string) $contact->id,
                    [
                        'registered_by_partner_id' => $registeredBy,
                        'previous_responsible_partner_id' => $previousPartnerId,
                        'responsible_partner_id' => $responsiblePartnerId,
                        'transferred_tables' => $transferredTables,
                    ],
                    $reason,
                );
                $movements[] = $movement;

                DB::table('contact_partner_transfers')->insert([
                    'id' => 'CPT_'.strtoupper((string) Str::ulid()),
                    'contact_id' => (string) $contact->id,
                    'registered_by_partner_id' => $registeredBy,
                    'previous_responsible_partner_id' => $previousPartnerId,
                    'responsible_partner_id' => $responsiblePartnerId,
                    'movement_id' => $movement['id'],
                    'changed_by_user_id' => $this->context->userId,
                    'reason' => $reason,
                    'transferred_tables' => SqlValue::encode($transferredTables),
                    'transferred_at' => now(),
                    'created_at' => now(),
                ]);

                if (Schema::hasTable('timeline_activities')) {
                    DB::table('timeline_activities')->insert([
                        'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                        'partner_id' => $responsiblePartnerId,
                        'contact_id' => (string) $contact->id,
                        'type' => 'RESPONSIBLE_PARTNER_CHANGED',
                        'description' => 'Socio responsable actualizado. El socio que registró al prospecto permanece sin cambios.',
                        'metadata' => SqlValue::encode([
                            'registered_by_partner_id' => $registeredBy,
                            'previous_responsible_partner_id' => $previousPartnerId,
                            'responsible_partner_id' => $responsiblePartnerId,
                            'movement_folio' => $movement['folio'],
                        ]),
                        'actor_id' => $this->context->userId,
                        'created_at' => now(),
                    ]);
                }
            }
        }, 3);

        return [
            'responsible_partner_id' => $responsiblePartnerId,
            'transferred' => count($contacts),
            'movements' => $movements,
        ];
    }

    /** @return string[] */
    private function transferRelatedRows(string $contactId, string $fromPartnerId, string $toPartnerId): array
    {
        $tables = [
            'measurements',
            'measurement_intakes',
            'tasks',
            'timeline_activities',
            'sla_alerts',
            'sales',
            'financial_transactions',
            'inventory_ledger_entries',
            'finance_ledger_entries',
            'whatsapp_messages',
            'workflow_executions',
            'crm_measurement_processing',
            'nutrition_plan_versions',
            'nutrition_engine_runs',
            'nutrition_plan_feedback',
            'campaign_contacts',
            'health_data_consents',
            'whatsapp_contact_links',
            'whatsapp_inbound_messages',
            'personal_reports',
            'report_deliveries',
            'nutrition_profiles',
            'nutrition_followup_members',
            'nutrition_followup_stage_history',
            'nutrition_followup_messages',
        ];

        $updated = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)
                || !Schema::hasColumn($table, 'contact_id')
                || !Schema::hasColumn($table, 'partner_id')) {
                continue;
            }

            $count = DB::table($table)
                ->where('contact_id', $contactId)
                ->where('partner_id', $fromPartnerId)
                ->update(['partner_id' => $toPartnerId]);

            if ($count > 0) {
                $updated[] = $table;
            }
        }

        return $updated;
    }

    private function partner(string $id): object
    {
        return DB::table('partners')->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw new \RuntimeException('Socio no encontrado.', 404);
    }

    private function requireSuperAdmin(): void
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo SUPER_ADMIN puede reasignar prospectos.', 403);
        }
    }
}
