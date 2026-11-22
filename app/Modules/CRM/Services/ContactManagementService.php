<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Domain\ValueObjects\NormalizedPhoneNumber;
use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Modules\Movements\Services\MovementService;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContactManagementService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly PartnerWorkModeService $workMode,
        private readonly MovementService $movements,
        private readonly ContactMeasurementPresentationService $measurementPresentation,
    ) {}

    /** @var array<string,string|null> */
    private array $partnerNameCache = [];

    public function list(array $filters): array
    {
        $partnerId = $this->partnerId();
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 200);
        $query = DB::table('contacts')->where('partner_id', $partnerId)->whereNull('deleted_at');
        if (!$this->canSeeBlocked()) $query->where('status', 'ACTIVE');
        if ($this->context->role === 'ADVISOR') {
            $query->where('assigned_advisor_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            $this->teamScope()->applyToContacts($query);
        }
        if (!empty($filters['status'])) $query->where('status', strtoupper((string) $filters['status']));
        if (!empty($filters['stage'])) $query->where('sales_stage', strtoupper((string) $filters['stage']));
        if (!empty($filters['lifecycle'])) $query->where('lifecycle_stage', strtoupper((string) $filters['lifecycle']));
        if (!empty($filters['advisor_id']) && $this->context->role !== 'ADVISOR') {
            $advisorId = (string) $filters['advisor_id'];
            if ($this->context->role === 'TEAM_LEADER' && !$this->teamScope()->canManageUser($advisorId)) {
                throw new \RuntimeException('El asesor solicitado no pertenece a tu equipo.', 403);
            }
            $query->where('assigned_advisor_id', $advisorId);
        }
        if (!empty($filters['campaign_id'])) {
            $campaignId = (string) $filters['campaign_id'];
            $query->whereExists(function ($sub) use ($campaignId): void {
                $sub->selectRaw('1')->from('campaign_contacts as cc')
                    ->whereColumn('cc.contact_id', 'contacts.id')
                    ->where('cc.campaign_id', $campaignId);
            });
        }
        if (!empty($filters['team_id'])) {
            $teamId = (string) $filters['team_id'];
            if ($this->context->role === 'TEAM_LEADER' && !$this->teamScope()->canManageTeam($teamId)) {
                throw new \RuntimeException('El equipo solicitado no está autorizado.', 403);
            }
            $query->where('origin_team_id', $teamId);
        }
        if (!empty($filters['search'])) {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhere('whatsapp_normalized', 'like', $search));
        }
        if (!empty($filters['cursor'])) $query->where('id', '>', (string) $filters['cursor']);
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (object $row): array => $this->serialize($row))->all();
        return ['items' => $items, 'next_cursor' => $hasMore ? end($items)['id'] : null, 'has_more' => $hasMore];
    }

    public function create(array $data): array
    {
        $partnerId = $this->partnerId();
        $partner = DB::table('partners')->where('id', $partnerId)->first();
        $phone = (new NormalizedPhoneNumber((string) $data['whatsapp'], (string) ($partner->country_dial_code ?? '52')))->getValue();
        if (DB::table('contacts')->where('partner_id', $partnerId)->where('whatsapp_normalized', $phone)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Ya existe un contacto con ese WhatsApp.', 409);
        }
        if ($this->workMode->isTeamMode()) {
            $advisorId = $data['assigned_advisor_id'] ?? ($this->context->role === 'ADVISOR' ? $this->context->userId : null);
            $teamId = $data['team_id'] ?? $this->currentTeam();
            $this->validateAdvisor($advisorId, $partnerId);
            $this->validateTeam($teamId, $partnerId);
        } else {
            $advisorId = $this->context->userId;
            $teamId = null;
        }
        $id = 'CNT_'.strtoupper((string) Str::ulid());

        DB::transaction(function () use ($id, $partnerId, $phone, $data, $teamId, $advisorId): void {
            DB::table('contacts')->insert([
                'id' => $id, 'partner_id' => $partnerId,
                'registered_by_partner_id' => $partnerId, 'responsible_partner_id' => $partnerId,
                'whatsapp' => $phone, 'whatsapp_normalized' => $phone,
                'name' => trim((string) $data['name']), 'age' => $data['age'] ?? null, 'gender' => $data['gender'] ?? null,
                'height_cm' => $data['height_cm'] ?? null, 'profile' => $data['profile'] ?? null,
                'lifecycle_stage' => 'PROSPECT', 'sales_stage' => 'NEW', 'status' => 'ACTIVE',
                'contact_source' => SqlValue::encode(['type' => strtoupper((string) ($data['source'] ?? 'MANUAL')), 'created_from' => 'API']),
                'generated_by_user_id' => $this->context->userId, 'origin_team_id' => $teamId,
                'assigned_advisor_id' => $advisorId, 'metrics_summary' => SqlValue::encode([]),
                'total_measurements' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->movements->record(
                '501',
                $partnerId,
                'CONTACT',
                $id,
                ['registered_by_partner_id' => $partnerId, 'responsible_partner_id' => $partnerId],
            );
        }, 3);

        return $this->show($id);
    }

    public function show(string $id): array
    {
        $row = $this->authorizedContact($id);
        return $this->serialize($row);
    }

    public function update(string $id, array $data): array
    {
        $row = $this->authorizedContact($id);
        if ((string) $row->status === 'BLOCKED' && !$this->canSeeBlocked()) throw new \RuntimeException('El cliente está bloqueado.', 403);
        $update = [];
        foreach (['name', 'age', 'gender', 'height_cm', 'profile'] as $field) {
            if (array_key_exists($field, $data)) $update[$field] = $data[$field];
        }
        if (array_key_exists('whatsapp', $data)) {
            $partner = DB::table('partners')->where('id', $row->partner_id)->first();
            $phone = (new NormalizedPhoneNumber((string) $data['whatsapp'], (string) ($partner->country_dial_code ?? '52')))->getValue();
            $exists = DB::table('contacts')->where('partner_id', $row->partner_id)->where('whatsapp_normalized', $phone)->where('id', '<>', $id)->whereNull('deleted_at')->exists();
            if ($exists) throw new \RuntimeException('Ya existe un contacto con ese WhatsApp.', 409);
            $update['whatsapp'] = $phone; $update['whatsapp_normalized'] = $phone;
        }
        if ($this->workMode->isTeamMode()) {
            if (array_key_exists('assigned_advisor_id', $data)) {
                $this->validateAdvisor($data['assigned_advisor_id'], (string) $row->partner_id);
                $update['assigned_advisor_id'] = $data['assigned_advisor_id'];
                if ($data['assigned_advisor_id'] !== null && (string) $row->lifecycle_stage === 'CONTACT') $update['lifecycle_stage'] = 'PROSPECT';
            }
            if (array_key_exists('team_id', $data)) {
                $this->validateTeam($data['team_id'], (string) $row->partner_id);
                $update['origin_team_id'] = $data['team_id'];
            }
        } else {
            $update['assigned_advisor_id'] = $this->context->userId;
            $update['origin_team_id'] = null;
        }
        $update['updated_at'] = now();
        DB::table('contacts')->where('id', $id)->update($update);
        return $this->show($id);
    }

    public function measurements(string $contactId, int $limit = 100): array
    {
        $this->authorizedContact($contactId);
        return DB::table('measurements')->where('contact_id', $contactId)->where('partner_id', $this->partnerId())
            ->orderByDesc('timestamp_local')->limit(min(max($limit, 1), 200))->get()->map(fn (object $row): array => [
                'id' => $row->id, 'device_id' => $row->device_id, 'location_id' => $row->location_id, 'campaign_id' => $row->campaign_id,
                'biometrics_raw' => SqlValue::json($row->biometrics_raw),
                'biometrics_calculated' => SqlValue::json($row->biometrics_calculated),
                'timestamp_local' => $row->timestamp_local, 'received_at' => $row->received_at,
            ])->all();
    }

    public function measurementDashboard(string $contactId, int $limit = 30): array
    {
        $contact = $this->authorizedContact($contactId);
        $rows = DB::table('measurements as measurement')
            ->leftJoin('devices as device', 'device.id', '=', 'measurement.device_id')
            ->leftJoin('locations as location', 'location.id', '=', 'measurement.location_id')
            ->leftJoin('campaigns as campaign', 'campaign.id', '=', 'measurement.campaign_id')
            ->leftJoin('users as captured', 'captured.id', '=', 'measurement.captured_by_user_id')
            ->where('measurement.contact_id', $contactId)
            ->where('measurement.partner_id', $this->partnerId())
            ->orderByDesc('measurement.timestamp_local')
            ->limit(min(max($limit, 1), 100))
            ->select(
                'measurement.id', 'measurement.device_id', 'measurement.location_id', 'measurement.campaign_id',
                'measurement.captured_by_user_id', 'measurement.biometrics_raw', 'measurement.biometrics_calculated',
                'measurement.timestamp_local', 'measurement.received_at',
                'device.mac_address as device_name', 'location.name as location_name',
                'campaign.name as campaign_name', 'captured.name as captured_by_name',
            )
            ->get();

        return $this->measurementPresentation->present($contact, $rows);
    }

    private function authorizedContact(string $id): object
    {
        $row = DB::table('contacts')->where('id', $id)->whereNull('deleted_at')->first();
        if ($row === null) throw new \RuntimeException('Contacto no encontrado.', 404);
        if ((string) $row->partner_id !== $this->partnerId()) throw new TenantViolationException($this->context->userId, (string) $row->partner_id);
        if ($this->context->role === 'ADVISOR' && (string) ($row->assigned_advisor_id ?? '') !== $this->context->userId) throw new \RuntimeException('No tienes acceso a este contacto.', 403);
        if (!$this->teamScope()->canAccessContact($row)) throw new \RuntimeException('Contacto fuera del equipo autorizado.', 403);
        return $row;
    }
    private function serialize(object $row): array
    {
        return [
            'id' => $row->id, 'partner_id' => $row->partner_id,
            'registered_by_partner_id' => $row->registered_by_partner_id ?? $row->partner_id,
            'registered_by_partner_name' => $this->partnerName((string) ($row->registered_by_partner_id ?? $row->partner_id)),
            'responsible_partner_id' => $row->responsible_partner_id ?? $row->partner_id,
            'responsible_partner_name' => $this->partnerName((string) ($row->responsible_partner_id ?? $row->partner_id)),
            'public_code' => $row->public_code ?? null, 'name' => $row->name,
            'whatsapp' => $row->whatsapp_normalized, 'age' => $row->age, 'gender' => $row->gender,
            'height_cm' => $row->height_cm !== null ? (float) $row->height_cm : null, 'profile' => $row->profile,
            'lifecycle_stage' => $row->lifecycle_stage, 'sales_stage' => $row->sales_stage, 'status' => $row->status,
            'assigned_advisor_id' => $row->assigned_advisor_id, 'generated_by_user_id' => $row->generated_by_user_id,
            'origin_team_id' => $row->origin_team_id, 'origin_campaign_id' => $row->origin_campaign_id, 'contact_source' => SqlValue::json($row->contact_source),
            'metrics_summary' => SqlValue::json($row->metrics_summary), 'total_measurements' => (int) $row->total_measurements,
            'last_measurement_at' => $row->last_measurement_at, 'block_reason' => $this->canSeeBlocked() ? $row->block_reason : null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }
    private function partnerName(string $partnerId): ?string
    {
        if (!array_key_exists($partnerId, $this->partnerNameCache)) {
            $value = DB::table('partners')->where('id', $partnerId)->value('business_name');
            $this->partnerNameCache[$partnerId] = $value !== null ? (string) $value : null;
        }
        return $this->partnerNameCache[$partnerId];
    }

    private function validateAdvisor(?string $id, string $partnerId): void
    {
        if ($id === null || $id === '') return;
        $valid = DB::table('users')->where('id', $id)->where('partner_id', $partnerId)->whereIn('role', ['ADVISOR','TEAM_LEADER','LOCATION_MANAGER','PARTNER_ADMIN'])->where('status', 'ACTIVE')->whereNull('deleted_at')->exists();
        if (!$valid) throw new \RuntimeException('Asesor inválido.', 422);
        if ($this->context->role === 'TEAM_LEADER' && !$this->teamScope()->canManageUser($id)) {
            throw new \RuntimeException('No puedes asignar prospectos fuera de tu equipo.', 403);
        }
    }
    private function validateTeam(?string $id, string $partnerId): void
    {
        if ($id === null || $id === '') return;
        if (!DB::table('teams')->where('id', $id)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists()) throw new \RuntimeException('Equipo inválido.', 422);
        if ($this->context->role === 'TEAM_LEADER' && !$this->teamScope()->canManageTeam($id)) {
            throw new \RuntimeException('No puedes usar un equipo fuera de tu alcance.', 403);
        }
    }
    private function currentTeam(): ?string
    {
        if ($this->context->role === 'TEAM_LEADER') {
            return $this->teamScope()->teamIds()[0] ?? null;
        }
        $teamId = DB::table('team_members')->where('partner_id', $this->partnerId())->where('user_id', $this->context->userId)->where('status', 'ACTIVE')->value('team_id');
        return $teamId !== null ? (string) $teamId : null;
    }
    private function partnerId(): string { return $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403); }
    private function canSeeBlocked(): bool { return in_array($this->context->role, ['SUPER_ADMIN','PARTNER_ADMIN','LOCATION_MANAGER','TEAM_LEADER'], true); }
    private function teamScope(): TeamAccessScope { return new TeamAccessScope($this->context); }
}
