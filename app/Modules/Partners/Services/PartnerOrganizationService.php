<?php

namespace App\Modules\Partners\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Movements\Services\MovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PartnerOrganizationService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly MovementService $movements,
    ) {}

    public function ranks(bool $includeInactive = false): array
    {
        $query = DB::table('partner_ranks')->orderBy('hierarchy_order');
        if (!$includeInactive || !$this->context->isSuperAdmin()) {
            $query->where('active', true);
        }

        return $query->get()->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'hierarchy_order' => (int) $row->hierarchy_order,
            'credential_label' => $row->credential_label !== null ? (string) $row->credential_label : null,
            'badge_key' => $row->badge_key !== null ? (string) $row->badge_key : null,
            'description' => $row->description !== null ? (string) $row->description : null,
            'active' => (bool) $row->active,
        ])->all();
    }

    public function workspace(?string $partnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($partnerId);
        $partner = $this->partner($partnerId);
        $upline = $this->upline($partnerId);
        $directs = DB::table('partners as p')
            ->leftJoin('partner_ranks as r', 'r.id', '=', 'p.current_rank_id')
            ->where('p.sponsor_partner_id', $partnerId)
            ->whereNull('p.deleted_at')
            ->select('p.id', 'p.partner_number', 'p.business_name', 'p.status', 'r.name as rank_name')
            ->orderBy('p.business_name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'partner_number' => (string) $row->partner_number,
                'business_name' => (string) $row->business_name,
                'status' => (string) $row->status,
                'rank_name' => $row->rank_name !== null ? (string) $row->rank_name : null,
            ])->all();

        $rankHistory = DB::table('partner_rank_history as h')
            ->leftJoin('partner_ranks as r', 'r.id', '=', 'h.rank_id')
            ->leftJoin('partner_ranks as pr', 'pr.id', '=', 'h.previous_rank_id')
            ->where('h.partner_id', $partnerId)
            ->select('h.*', 'r.name as rank_name', 'pr.name as previous_rank_name')
            ->orderByDesc('h.effective_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'rank_id' => (string) $row->rank_id,
                'rank_name' => (string) ($row->rank_name ?? ''),
                'previous_rank_id' => $row->previous_rank_id !== null ? (string) $row->previous_rank_id : null,
                'previous_rank_name' => $row->previous_rank_name !== null ? (string) $row->previous_rank_name : null,
                'reason' => $row->reason !== null ? (string) $row->reason : null,
                'effective_at' => $row->effective_at,
            ])->all();

        $credential = DB::table('partner_credentials')
            ->where('partner_id', $partnerId)
            ->orderByDesc('issued_at')
            ->first();

        $support = $this->supportRequests($partnerId);

        return [
            'partner' => $this->serializePartner($partner),
            'upline' => $upline,
            'direct_partners' => $directs,
            'rank_history' => $rankHistory,
            'credential' => $credential !== null ? $this->serializeCredential($credential) : null,
            'support_requests' => $support,
            'can_manage' => $this->context->isSuperAdmin(),
        ];
    }

    public function updateIdentity(string $partnerId, array $data): array
    {
        $this->requireSuperAdmin();
        $partner = $this->partner($partnerId);
        $update = [];
        $changes = [];
        $reason = trim((string) ($data['reason'] ?? 'Actualización administrativa del socio.'));

        if (array_key_exists('business_name', $data)) {
            $name = trim((string) $data['business_name']);
            if ($name === '') {
                throw new \RuntimeException('El nombre del socio es obligatorio.', 422);
            }
            if ((string) $partner->business_name !== $name) {
                $update['business_name'] = $name;
                $changes['business_name'] = [
                    'previous' => (string) $partner->business_name,
                    'current' => $name,
                ];
            }
        }

        if (array_key_exists('partner_number', $data)) {
            $number = trim((string) $data['partner_number']);
            if (!preg_match('/^\d{1,40}$/', $number)) {
                throw new \RuntimeException('El Socio ID debe contener únicamente números.', 422);
            }
            $exists = DB::table('partners')
                ->where('partner_number', $number)
                ->where('id', '<>', $partnerId)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                throw new \RuntimeException('Ese Socio ID ya está registrado.', 409);
            }
            if ((string) $partner->partner_number !== $number) {
                $update['partner_number'] = $number;
                $changes['partner_number'] = [
                    'previous' => $partner->partner_number !== null ? (string) $partner->partner_number : null,
                    'current' => $number,
                ];
            }
        }

        if (array_key_exists('sponsor_partner_id', $data)) {
            $sponsorId = $data['sponsor_partner_id'] !== null && trim((string) $data['sponsor_partner_id']) !== ''
                ? trim((string) $data['sponsor_partner_id'])
                : null;
            $this->validateSponsor($partnerId, $sponsorId);
            $previous = $partner->sponsor_partner_id !== null ? (string) $partner->sponsor_partner_id : null;
            if ($previous !== $sponsorId) {
                $update['sponsor_partner_id'] = $sponsorId;
                $changes['sponsor_partner_id'] = [
                    'previous' => $previous,
                    'current' => $sponsorId,
                ];
            }
        }

        if (array_key_exists('current_rank_id', $data)) {
            $rankId = trim((string) $data['current_rank_id']);
            $rank = DB::table('partner_ranks')->where('id', $rankId)->where('active', true)->first();
            if ($rank === null) {
                throw new \RuntimeException('Rango no encontrado o inactivo.', 422);
            }
            $previousRankId = $partner->current_rank_id !== null ? (string) $partner->current_rank_id : null;
            if ($previousRankId !== $rankId) {
                $update['current_rank_id'] = $rankId;
                $changes['current_rank_id'] = [
                    'previous' => $previousRankId,
                    'current' => $rankId,
                    'rank_name' => (string) $rank->name,
                ];
            }
        }

        if ($update === []) {
            return [
                'partner' => $this->serializePartner($partner),
                'movements' => [],
            ];
        }

        $movementResults = DB::transaction(function () use (
            $partnerId,
            $update,
            $changes,
            $reason,
        ): array {
            DB::table('partners')->where('id', $partnerId)->lockForUpdate()->first();
            DB::table('partners')->where('id', $partnerId)->update(array_merge($update, [
                'updated_at' => now(),
            ]));

            $movements = [];

            if (isset($changes['business_name'])) {
                $change = $changes['business_name'];
                $movements[] = $this->movements->record(
                    '545',
                    $partnerId,
                    'PARTNER',
                    $partnerId,
                    [
                        'previous_business_name' => $change['previous'],
                        'business_name' => $change['current'],
                    ],
                    $reason,
                );
            }

            if (isset($changes['partner_number'])) {
                $change = $changes['partner_number'];
                $movements[] = $this->movements->record(
                    '544',
                    $partnerId,
                    'PARTNER',
                    $partnerId,
                    [
                        'previous_partner_number' => $change['previous'],
                        'partner_number' => $change['current'],
                    ],
                    $reason,
                );
            }

            if (isset($changes['sponsor_partner_id'])) {
                $change = $changes['sponsor_partner_id'];
                $movement = $this->movements->record(
                    $change['previous'] === null ? '542' : '543',
                    $partnerId,
                    'PARTNER',
                    $partnerId,
                    [
                        'previous_sponsor_partner_id' => $change['previous'],
                        'sponsor_partner_id' => $change['current'],
                    ],
                    $reason,
                );
                DB::table('partner_relationship_history')->insert([
                    'id' => 'PRL_'.strtoupper((string) Str::ulid()),
                    'partner_id' => $partnerId,
                    'previous_sponsor_partner_id' => $change['previous'],
                    'sponsor_partner_id' => $change['current'],
                    'movement_id' => $movement['id'],
                    'changed_by_user_id' => $this->context->userId,
                    'reason' => $reason,
                    'effective_at' => now(),
                    'created_at' => now(),
                ]);
                $movements[] = $movement;
            }

            if (isset($changes['current_rank_id'])) {
                $change = $changes['current_rank_id'];
                $movement = $this->movements->record(
                    '551',
                    $partnerId,
                    'PARTNER',
                    $partnerId,
                    [
                        'previous_rank_id' => $change['previous'],
                        'rank_id' => $change['current'],
                        'rank_name' => $change['rank_name'],
                    ],
                    $reason,
                );
                DB::table('partner_rank_history')->insert([
                    'id' => 'PRH_'.strtoupper((string) Str::ulid()),
                    'partner_id' => $partnerId,
                    'previous_rank_id' => $change['previous'],
                    'rank_id' => $change['current'],
                    'movement_id' => $movement['id'],
                    'changed_by_user_id' => $this->context->userId,
                    'reason' => $reason,
                    'effective_at' => now(),
                    'created_at' => now(),
                ]);
                $movements[] = $movement;
            }

            if (isset($changes['partner_number']) || isset($changes['current_rank_id']) || isset($changes['business_name'])) {
                DB::table('partner_credentials')
                    ->where('partner_id', $partnerId)
                    ->where('status', 'ACTIVE')
                    ->update([
                        'status' => 'REVOKED',
                        'revoked_at' => now(),
                        'revoked_by_user_id' => $this->context->userId,
                        'revocation_reason' => 'Los datos visibles de la credencial cambiaron. Emite una nueva credencial.',
                        'updated_at' => now(),
                    ]);
            }

            return $movements;
        }, 3);

        return [
            'partner' => $this->serializePartner($this->partner($partnerId)),
            'movements' => $movementResults,
        ];
    }

    public function createRank(array $data): array
    {
        $this->requireSuperAdmin();
        $code = strtoupper(trim((string) $data['code']));
        $name = trim((string) $data['name']);
        $order = (int) $data['hierarchy_order'];
        if ($code === '' || $name === '' || $order < 1) {
            throw new \RuntimeException('Código, nombre y orden son obligatorios.', 422);
        }
        if (DB::table('partner_ranks')->where('code', $code)->orWhere('hierarchy_order', $order)->exists()) {
            throw new \RuntimeException('El código o el orden jerárquico ya está en uso.', 409);
        }

        $id = 'RANK-'.strtoupper((string) Str::ulid());
        DB::table('partner_ranks')->insert([
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'hierarchy_order' => $order,
            'credential_label' => trim((string) ($data['credential_label'] ?? $name)) ?: $name,
            'badge_key' => trim((string) ($data['badge_key'] ?? strtolower(str_replace('_', '-', $code)))) ?: null,
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'active' => (bool) ($data['active'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (array) DB::table('partner_ranks')->where('id', $id)->first();
    }

    public function updateRank(string $rankId, array $data): array
    {
        $this->requireSuperAdmin();
        $rank = DB::table('partner_ranks')->where('id', $rankId)->first()
            ?? throw new \RuntimeException('Rango no encontrado.', 404);
        $update = [];
        foreach (['name', 'credential_label', 'badge_key', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field] !== null ? trim((string) $data[$field]) : null;
            }
        }
        if (array_key_exists('code', $data)) {
            $code = strtoupper(trim((string) $data['code']));
            if (DB::table('partner_ranks')->where('code', $code)->where('id', '<>', $rankId)->exists()) {
                throw new \RuntimeException('El código ya está en uso.', 409);
            }
            $update['code'] = $code;
        }
        if (array_key_exists('hierarchy_order', $data)) {
            $order = (int) $data['hierarchy_order'];
            if ($order < 1 || DB::table('partner_ranks')->where('hierarchy_order', $order)->where('id', '<>', $rankId)->exists()) {
                throw new \RuntimeException('El orden jerárquico ya está en uso o no es válido.', 409);
            }
            $update['hierarchy_order'] = $order;
        }
        if (array_key_exists('active', $data)) {
            $update['active'] = (bool) $data['active'];
        }
        if ($update !== []) {
            $update['updated_at'] = now();
            DB::table('partner_ranks')->where('id', $rankId)->update($update);
        }

        return (array) DB::table('partner_ranks')->where('id', $rankId)->first();
    }

    public function issueCredential(string $partnerId, ?string $expiresAt = null): array
    {
        $this->requireSuperAdmin();
        $partner = $this->partner($partnerId);
        if ((string) $partner->status !== 'ACTIVE') {
            throw new \RuntimeException('El socio debe estar activo para emitir una credencial.', 422);
        }
        if ($partner->partner_number === null || str_starts_with((string) $partner->partner_number, 'LEGACY-')) {
            throw new \RuntimeException('Primero registra el Socio ID oficial de la compañía.', 422);
        }

        $rank = $partner->current_rank_id !== null
            ? DB::table('partner_ranks')->where('id', $partner->current_rank_id)->first()
            : null;

        return DB::transaction(function () use ($partner, $rank, $expiresAt): array {
            DB::table('partner_credentials')
                ->where('partner_id', $partner->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'REVOKED',
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $this->context->userId,
                    'revocation_reason' => 'Sustituida por una nueva credencial.',
                    'updated_at' => now(),
                ]);

            $id = 'CRED_'.strtoupper((string) Str::ulid());
            $credentialNumber = sprintf(
                'RH-CRED-%s-%s',
                (string) $partner->partner_number,
                strtoupper(substr((string) Str::ulid(), -8)),
            );
            $publicCode = bin2hex(random_bytes(24));

            DB::table('partner_credentials')->insert([
                'id' => $id,
                'partner_id' => (string) $partner->id,
                'credential_number' => $credentialNumber,
                'public_code' => $publicCode,
                'partner_number_snapshot' => (string) $partner->partner_number,
                'partner_name_snapshot' => (string) $partner->business_name,
                'rank_id_snapshot' => $rank?->id,
                'rank_name_snapshot' => $rank?->name,
                'status' => 'ACTIVE',
                'issued_at' => now(),
                'expires_at' => $expiresAt,
                'issued_by_user_id' => $this->context->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $movement = $this->movements->record(
                '911',
                (string) $partner->id,
                'PARTNER_CREDENTIAL',
                $id,
                ['credential_number' => $credentialNumber, 'rank_name' => $rank?->name],
            );

            $credential = DB::table('partner_credentials')->where('id', $id)->first();
            $result = $this->serializeCredential($credential);
            $result['verification_path'] = '/api/v1/public/credentials/'.$publicCode;
            $result['movement'] = $movement;

            return $result;
        }, 3);
    }

    public function revokeCredential(string $credentialId, string $reason): array
    {
        $this->requireSuperAdmin();
        $credential = DB::table('partner_credentials')->where('id', $credentialId)->first()
            ?? throw new \RuntimeException('Credencial no encontrada.', 404);
        if ((string) $credential->status === 'REVOKED') {
            return $this->serializeCredential($credential);
        }

        DB::table('partner_credentials')->where('id', $credentialId)->update([
            'status' => 'REVOKED',
            'revoked_at' => now(),
            'revoked_by_user_id' => $this->context->userId,
            'revocation_reason' => $reason,
            'updated_at' => now(),
        ]);
        $this->movements->record(
            '912',
            (string) $credential->partner_id,
            'PARTNER_CREDENTIAL',
            $credentialId,
            ['credential_number' => $credential->credential_number],
            $reason,
        );

        return $this->serializeCredential(
            DB::table('partner_credentials')->where('id', $credentialId)->first(),
        );
    }

    public function createSupportRequest(array $data): array
    {
        $partnerId = $this->context->partnerId
            ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $subject = trim((string) ($data['subject'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $priority = strtoupper(trim((string) ($data['priority'] ?? 'NORMAL')));
        if ($subject === '' || $description === '') {
            throw new \RuntimeException('Asunto y descripción son obligatorios.', 422);
        }
        if (!in_array($priority, ['LOW', 'NORMAL', 'HIGH', 'URGENT'], true)) {
            throw new \RuntimeException('Prioridad no válida.', 422);
        }

        $id = 'SUP_'.strtoupper((string) Str::ulid());

        DB::transaction(function () use ($id, $partnerId, $subject, $description, $priority): void {
            $assigned = $this->firstActiveSponsor($partnerId);
            $status = $assigned !== null ? 'OPEN' : 'ESCALATED_TO_ADMIN';

            $movement = $this->movements->record(
                '571',
                $partnerId,
                'PARTNER_SUPPORT_REQUEST',
                $id,
                ['assigned_partner_id' => $assigned],
            );

            DB::table('partner_support_requests')->insert([
                'id' => $id,
                'requester_partner_id' => $partnerId,
                'assigned_partner_id' => $assigned,
                'created_by_user_id' => $this->context->userId,
                'subject' => $subject,
                'description' => $description,
                'priority' => $priority,
                'status' => $status,
                'escalation_level' => 1,
                'last_escalated_at' => null,
                'movement_id' => $movement['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('partner_support_history')->insert([
                'id' => 'SUPH_'.strtoupper((string) Str::ulid()),
                'support_request_id' => $id,
                'action' => 'CREATED',
                'from_partner_id' => $partnerId,
                'to_partner_id' => $assigned,
                'actor_user_id' => $this->context->userId,
                'note' => null,
                'created_at' => now(),
            ]);
        }, 3);

        return $this->supportRequest($id);
    }

    public function escalateSupport(string $requestId, ?string $note = null): array
    {
        DB::transaction(function () use ($requestId, $note): void {
            $row = DB::table('partner_support_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first()
                ?? throw new \RuntimeException('Solicitud no encontrada.', 404);
            $this->assertSupportAccess($row);
            if (in_array((string) $row->status, ['RESOLVED', 'CLOSED'], true)) {
                throw new \RuntimeException('La solicitud ya está cerrada.', 409);
            }

            $from = $row->assigned_partner_id !== null ? (string) $row->assigned_partner_id : null;
            $next = $from !== null
                ? $this->firstActiveSponsor($from)
                : $this->firstActiveSponsor((string) $row->requester_partner_id);

            $movement = $this->movements->record(
                '572',
                (string) $row->requester_partner_id,
                'PARTNER_SUPPORT_REQUEST',
                $requestId,
                ['from_partner_id' => $from, 'assigned_partner_id' => $next],
                $note,
            );

            DB::table('partner_support_requests')->where('id', $requestId)->update([
                'assigned_partner_id' => $next,
                'status' => $next !== null ? 'ESCALATED' : 'ESCALATED_TO_ADMIN',
                'escalation_level' => ((int) $row->escalation_level) + 1,
                'last_escalated_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('partner_support_history')->insert([
                'id' => 'SUPH_'.strtoupper((string) Str::ulid()),
                'support_request_id' => $requestId,
                'action' => 'ESCALATED',
                'from_partner_id' => $from,
                'to_partner_id' => $next,
                'actor_user_id' => $this->context->userId,
                'note' => $note,
                'created_at' => now(),
            ]);
        }, 3);

        return $this->supportRequest($requestId);
    }

    public function resolveSupport(string $requestId, ?string $note = null): array
    {
        DB::transaction(function () use ($requestId, $note): void {
            $row = DB::table('partner_support_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first()
                ?? throw new \RuntimeException('Solicitud no encontrada.', 404);
            $this->assertSupportAccess($row);
            if (in_array((string) $row->status, ['RESOLVED', 'CLOSED'], true)) {
                return;
            }

            DB::table('partner_support_requests')->where('id', $requestId)->update([
                'status' => 'RESOLVED',
                'resolved_at' => now(),
                'resolved_by_user_id' => $this->context->userId,
                'updated_at' => now(),
            ]);
            DB::table('partner_support_history')->insert([
                'id' => 'SUPH_'.strtoupper((string) Str::ulid()),
                'support_request_id' => $requestId,
                'action' => 'RESOLVED',
                'from_partner_id' => $row->assigned_partner_id,
                'to_partner_id' => $row->requester_partner_id,
                'actor_user_id' => $this->context->userId,
                'note' => $note,
                'created_at' => now(),
            ]);
        }, 3);

        return $this->supportRequest($requestId);
    }

    public function supportRequests(?string $partnerId = null): array
    {
        $scope = $partnerId ?? $this->context->partnerId;
        if ($scope === null && !$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Contexto sin socio.', 403);
        }
        if (!$this->context->isSuperAdmin() && $scope !== $this->context->partnerId) {
            throw new TenantViolationException($this->context->userId, (string) $scope);
        }

        $query = DB::table('partner_support_requests as s')
            ->leftJoin('partners as rp', 'rp.id', '=', 's.requester_partner_id')
            ->leftJoin('partners as ap', 'ap.id', '=', 's.assigned_partner_id')
            ->select(
                's.*',
                'rp.partner_number as requester_partner_number',
                'rp.business_name as requester_partner_name',
                'ap.partner_number as assigned_partner_number',
                'ap.business_name as assigned_partner_name',
            );

        if (!$this->context->isSuperAdmin()) {
            $query->where(function ($q) use ($scope): void {
                $q->where('s.requester_partner_id', $scope)
                    ->orWhere('s.assigned_partner_id', $scope);
            });
        } elseif ($scope !== null) {
            $query->where(function ($q) use ($scope): void {
                $q->where('s.requester_partner_id', $scope)
                    ->orWhere('s.assigned_partner_id', $scope);
            });
        }

        return $query->orderByDesc('s.created_at')->limit(200)->get()
            ->map(fn (object $row): array => $this->serializeSupport($row))
            ->all();
    }

    private function supportRequest(string $id): array
    {
        $row = DB::table('partner_support_requests as s')
            ->leftJoin('partners as rp', 'rp.id', '=', 's.requester_partner_id')
            ->leftJoin('partners as ap', 'ap.id', '=', 's.assigned_partner_id')
            ->where('s.id', $id)
            ->select(
                's.*',
                'rp.partner_number as requester_partner_number',
                'rp.business_name as requester_partner_name',
                'ap.partner_number as assigned_partner_number',
                'ap.business_name as assigned_partner_name',
            )
            ->first() ?? throw new \RuntimeException('Solicitud no encontrada.', 404);

        return $this->serializeSupport($row);
    }

    private function serializeSupport(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'requester_partner_id' => (string) $row->requester_partner_id,
            'requester_partner_number' => $row->requester_partner_number !== null ? (string) $row->requester_partner_number : null,
            'requester_partner_name' => $row->requester_partner_name !== null ? (string) $row->requester_partner_name : null,
            'assigned_partner_id' => $row->assigned_partner_id !== null ? (string) $row->assigned_partner_id : null,
            'assigned_partner_number' => $row->assigned_partner_number !== null ? (string) $row->assigned_partner_number : null,
            'assigned_partner_name' => $row->assigned_partner_name !== null ? (string) $row->assigned_partner_name : null,
            'subject' => (string) $row->subject,
            'description' => (string) $row->description,
            'priority' => (string) $row->priority,
            'status' => (string) $row->status,
            'escalation_level' => (int) $row->escalation_level,
            'last_escalated_at' => $row->last_escalated_at,
            'resolved_at' => $row->resolved_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function upline(string $partnerId): array
    {
        $result = [];
        $seen = [$partnerId => true];
        $current = $this->partner($partnerId);
        $level = 1;

        while ($current->sponsor_partner_id !== null && $level <= 50) {
            $sponsorId = (string) $current->sponsor_partner_id;
            if (isset($seen[$sponsorId])) {
                break;
            }
            $seen[$sponsorId] = true;
            $sponsor = DB::table('partners as p')
                ->leftJoin('partner_ranks as r', 'r.id', '=', 'p.current_rank_id')
                ->where('p.id', $sponsorId)
                ->whereNull('p.deleted_at')
                ->select('p.*', 'r.name as rank_name')
                ->first();
            if ($sponsor === null) {
                break;
            }
            $result[] = [
                'level' => $level,
                'id' => (string) $sponsor->id,
                'partner_number' => (string) $sponsor->partner_number,
                'business_name' => (string) $sponsor->business_name,
                'rank_name' => $sponsor->rank_name !== null ? (string) $sponsor->rank_name : null,
                'status' => (string) $sponsor->status,
                'is_direct_sponsor' => $level === 1,
            ];
            $current = $sponsor;
            $level++;
        }

        return $result;
    }

    private function firstActiveSponsor(string $partnerId): ?string
    {
        foreach ($this->upline($partnerId) as $item) {
            if ((string) $item['status'] === 'ACTIVE') {
                return (string) $item['id'];
            }
        }
        return null;
    }

    private function validateSponsor(string $partnerId, ?string $sponsorId): void
    {
        if ($sponsorId === null) {
            return;
        }
        if ($sponsorId === $partnerId) {
            throw new \RuntimeException('Un socio no puede ser su propio patrocinador.', 422);
        }
        $this->partner($sponsorId);

        $seen = [$partnerId => true];
        $current = $sponsorId;
        for ($level = 0; $level < 100; $level++) {
            if (isset($seen[$current])) {
                throw new \RuntimeException('La asignación produciría un ciclo en la jerarquía.', 422);
            }
            $seen[$current] = true;
            $next = DB::table('partners')->where('id', $current)->value('sponsor_partner_id');
            if ($next === null || $next === '') {
                return;
            }
            $current = (string) $next;
        }

        throw new \RuntimeException('La jerarquía excede el límite permitido.', 422);
    }

    private function assertSupportAccess(object $request): void
    {
        if ($this->context->isSuperAdmin()) {
            return;
        }
        $partnerId = $this->context->partnerId
            ?? throw new \RuntimeException('Contexto sin socio.', 403);
        if (!in_array($partnerId, [
            (string) $request->requester_partner_id,
            (string) ($request->assigned_partner_id ?? ''),
        ], true)) {
            throw new \RuntimeException('No tienes acceso a esta solicitud.', 403);
        }
    }

    private function authorizedPartnerId(?string $requested): string
    {
        if ($this->context->isSuperAdmin()) {
            if ($requested === null || trim($requested) === '') {
                throw new \RuntimeException('partner_id es obligatorio.', 422);
            }
            return $this->partner(trim($requested))->id;
        }

        $partnerId = $this->context->partnerId
            ?? throw new \RuntimeException('Contexto sin socio.', 403);
        if ($requested !== null && trim($requested) !== '' && trim($requested) !== $partnerId) {
            throw new TenantViolationException($this->context->userId, trim($requested));
        }
        return $partnerId;
    }

    private function partner(string $id): object
    {
        return DB::table('partners as p')
            ->leftJoin('partner_ranks as r', 'r.id', '=', 'p.current_rank_id')
            ->leftJoin('partners as sp', 'sp.id', '=', 'p.sponsor_partner_id')
            ->where('p.id', $id)
            ->whereNull('p.deleted_at')
            ->select(
                'p.*',
                'r.code as rank_code',
                'r.name as rank_name',
                'r.hierarchy_order as rank_order',
                'sp.partner_number as sponsor_partner_number',
                'sp.business_name as sponsor_partner_name',
            )
            ->first() ?? throw new \RuntimeException('Socio no encontrado.', 404);
    }

    private function serializePartner(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'partner_number' => (string) $row->partner_number,
            'business_name' => (string) $row->business_name,
            'status' => (string) $row->status,
            'current_rank_id' => $row->current_rank_id !== null ? (string) $row->current_rank_id : null,
            'rank_code' => $row->rank_code !== null ? (string) $row->rank_code : null,
            'rank_name' => $row->rank_name !== null ? (string) $row->rank_name : null,
            'rank_order' => $row->rank_order !== null ? (int) $row->rank_order : null,
            'sponsor_partner_id' => $row->sponsor_partner_id !== null ? (string) $row->sponsor_partner_id : null,
            'sponsor_partner_number' => $row->sponsor_partner_number !== null ? (string) $row->sponsor_partner_number : null,
            'sponsor_partner_name' => $row->sponsor_partner_name !== null ? (string) $row->sponsor_partner_name : null,
            'created_at' => $row->created_at,
        ];
    }

    private function serializeCredential(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'partner_id' => (string) $row->partner_id,
            'credential_number' => (string) $row->credential_number,
            'public_code' => (string) $row->public_code,
            'partner_number' => (string) $row->partner_number_snapshot,
            'partner_name' => (string) $row->partner_name_snapshot,
            'rank_id' => $row->rank_id_snapshot !== null ? (string) $row->rank_id_snapshot : null,
            'rank_name' => $row->rank_name_snapshot !== null ? (string) $row->rank_name_snapshot : null,
            'status' => (string) $row->status,
            'issued_at' => $row->issued_at,
            'expires_at' => $row->expires_at,
            'revoked_at' => $row->revoked_at,
            'revocation_reason' => $row->revocation_reason !== null ? (string) $row->revocation_reason : null,
            'verification_path' => '/api/v1/public/credentials/'.(string) $row->public_code,
        ];
    }

    private function requireSuperAdmin(): void
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo SUPER_ADMIN puede realizar esta operación.', 403);
        }
    }
}
