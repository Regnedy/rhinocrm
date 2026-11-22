<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Domain\ValueObjects\NormalizedPhoneNumber;
use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Modules\PersonalReports\Services\ContactPublicCodeService;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly PartnerWorkModeService $workMode,
        private readonly ContactPublicCodeService $publicCodes,
        private readonly CampaignAccessService $access,
        private readonly CampaignActivityService $activity,
    ) {}

    public function list(array $filters = []): array
    {
        $partnerId = $this->partnerId();
        $query = DB::table('campaigns as c')
            ->leftJoin('users as responsible', 'responsible.id', '=', 'c.responsible_user_id')
            ->leftJoin('teams as team', 'team.id', '=', 'c.team_id')
            ->where(function (Builder $access) use ($partnerId): void {
                $access->where('c.partner_id', $partnerId)
                    ->orWhereExists(function (Builder $member): void {
                        $member->selectRaw('1')->from('campaign_members as visible_member')
                            ->whereColumn('visible_member.campaign_id', 'c.id')
                            ->where('visible_member.user_id', $this->context->userId)
                            ->where('visible_member.status', 'ACTIVE');
                    });
            })
            ->whereNull('c.deleted_at');

        $this->applyAccessScope($query, 'c');

        if (!empty($filters['status'])) {
            $query->where('c.status', strtoupper((string) $filters['status']));
        }
        if (!empty($filters['from'])) {
            $query->where('c.starts_at', '>=', (new DateTimeImmutable((string) $filters['from']))->format('Y-m-d H:i:s'));
        }
        if (!empty($filters['to'])) {
            $query->where('c.starts_at', '<=', (new DateTimeImmutable((string) $filters['to']))->format('Y-m-d H:i:s'));
        }
        if (!empty($filters['search'])) {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('c.name', 'like', $search)
                    ->orWhere('c.venue_name', 'like', $search)
                    ->orWhere('c.city', 'like', $search);
            });
        }

        return $query
            ->select('c.*', 'responsible.name as responsible_name', 'team.name as team_name')
            ->orderByRaw("CASE c.status WHEN 'ACTIVE' THEN 0 WHEN 'SCHEDULED' THEN 1 WHEN 'DRAFT' THEN 2 WHEN 'CLOSED' THEN 3 ELSE 4 END")
            ->orderBy('c.starts_at')
            ->get()
            ->map(fn (object $row): array => $this->campaignArray($row, true))
            ->all();
    }

    public function create(array $data): array
    {
        $this->assertCanCreate();
        $partnerId = $this->partnerId();
        $locationId = $this->principalLocationId($partnerId);
        if ($this->workMode->isTeamMode()) {
            $teamId = $this->nullableString($data['team_id'] ?? null);
            if ($teamId === null && $this->context->role === 'TEAM_LEADER') {
                $teamId = (new TeamAccessScope($this->context))->teamIds()[0] ?? null;
            }
            $responsibleId = $this->nullableString($data['responsible_user_id'] ?? null);
            if ($responsibleId === null && $this->context->role === 'TEAM_LEADER') {
                $responsibleId = $this->context->userId;
            }
            $this->validateTeam($teamId, $partnerId);
            $this->validateUser($responsibleId, $partnerId);
        } else {
            $teamId = null;
            $responsibleId = $this->context->userId;
            $data['member_user_ids'] = [$this->context->userId];
        }
        $this->validateSchedule($data['starts_at'], $data['ends_at'] ?? null);

        $campaignId = 'CAM_'.strtoupper((string) Str::ulid());
        DB::transaction(function () use ($campaignId, $partnerId, $locationId, $teamId, $responsibleId, $data): void {
            DB::table('campaigns')->insert([
                'id' => $campaignId,
                'partner_id' => $partnerId,
                'location_id' => $locationId,
                'team_id' => $teamId,
                'responsible_user_id' => $responsibleId,
                'name' => trim((string) $data['name']),
                'type' => strtoupper((string) $data['type']),
                'venue_name' => $this->nullableString($data['venue_name'] ?? null),
                'address' => $this->nullableString($data['address'] ?? null),
                'city' => $this->nullableString($data['city'] ?? null),
                'state' => $this->nullableString($data['state'] ?? null),
                'starts_at' => $this->dateTime($data['starts_at']),
                'ends_at' => isset($data['ends_at']) && $data['ends_at'] !== null && $data['ends_at'] !== '' ? $this->dateTime($data['ends_at']) : null,
                'status' => 'SCHEDULED',
                'prospects_target' => (int) ($data['prospects_target'] ?? 0),
                'measurements_target' => (int) ($data['measurements_target'] ?? 0),
                'budget_minor' => (int) ($data['budget_minor'] ?? 0),
                'currency' => strtoupper((string) ($data['currency'] ?? 'MXN')),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'created_by_user_id' => $this->context->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $members = array_values(array_unique(array_filter(array_map('strval', (array) ($data['member_user_ids'] ?? [])))));
            if ($responsibleId !== null && !in_array($responsibleId, $members, true)) {
                $members[] = $responsibleId;
            }
            $this->syncMembers($campaignId, $partnerId, $members, $responsibleId);
            $this->syncDevices($campaignId, $partnerId, (array) ($data['device_ids'] ?? []));
        }, 3);

        return $this->show($campaignId);
    }

    public function show(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $result = $this->campaignArray($campaign, false);
        $result['members'] = DB::table('campaign_members as cm')
            ->join('users as u', 'u.id', '=', 'cm.user_id')
            ->leftJoin('partners as member_partner', 'member_partner.id', '=', 'cm.member_partner_id')
            ->where('cm.campaign_id', $campaignId)
            ->where('cm.status', 'ACTIVE')
            ->select('u.id', 'u.name', 'u.email', 'u.role', 'u.status', 'cm.member_role', 'cm.joined_at', 'cm.accepted_at', 'cm.member_partner_id', 'member_partner.business_name as partner_name')
            ->orderBy('u.name')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
        $result['can_manage'] = $this->access->canManage($campaign);
        $result['is_collaborator'] = (string) $campaign->partner_id !== ($this->context->partnerId ?? '');
        $result['my_member'] = DB::table('campaign_members')->where('campaign_id', $campaignId)->where('user_id', $this->context->userId)->where('status', 'ACTIVE')->first();
        $result['invitations'] = $result['can_manage'] && DB::getSchemaBuilder()->hasTable('campaign_invitations')
            ? DB::table('campaign_invitations as invitation')
                ->leftJoin('users as invited', 'invited.id', '=', 'invitation.invited_user_id')
                ->leftJoin('partners as invited_partner', 'invited_partner.id', '=', 'invitation.invited_partner_id')
                ->where('invitation.campaign_id', $campaignId)
                ->select('invitation.*', 'invited.name as user_name', 'invited.email as user_email', 'invited_partner.business_name as partner_name')
                ->orderByDesc('invitation.created_at')->get()->map(static fn (object $row): array => (array) $row)->all()
            : [];
        $result['devices'] = DB::table('campaign_devices as cd')
            ->join('devices as d', 'd.id', '=', 'cd.device_id')
            ->where('cd.campaign_id', $campaignId)
            ->where('cd.status', 'ACTIVE')
            ->select('d.id', 'd.mac_address', 'd.status', 'd.total_measurements', 'd.last_measurement_at', 'cd.linked_at')
            ->orderBy('d.mac_address')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
        $result['report'] = $this->context->hasPermission('campaigns.report')
            ? $this->report($campaignId)
            : $this->personalReport($campaignId);
        return $result;
    }

    public function update(string $campaignId, array $data): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $this->assertCanManage($campaign);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('Un evento cerrado o cancelado no puede modificarse.', 409);
        }

        $partnerId = (string) $campaign->partner_id;
        if ($this->workMode->isTeamMode()) {
            $teamId = array_key_exists('team_id', $data) ? $this->nullableString($data['team_id']) : ($campaign->team_id !== null ? (string) $campaign->team_id : null);
            $responsibleId = array_key_exists('responsible_user_id', $data) ? $this->nullableString($data['responsible_user_id']) : ($campaign->responsible_user_id !== null ? (string) $campaign->responsible_user_id : null);
            $this->validateTeam($teamId, $partnerId);
            $this->validateUser($responsibleId, $partnerId);
        } else {
            $teamId = null;
            $responsibleId = $this->context->userId;
            $data['team_id'] = null;
            $data['responsible_user_id'] = $responsibleId;
            $data['member_user_ids'] = [$responsibleId];
        }
        $startsAt = $data['starts_at'] ?? $campaign->starts_at;
        $endsAt = array_key_exists('ends_at', $data) ? $data['ends_at'] : $campaign->ends_at;
        $this->validateSchedule($startsAt, $endsAt);

        DB::transaction(function () use ($campaignId, $partnerId, $campaign, $data, $teamId, $responsibleId): void {
            $allowed = ['name', 'type', 'venue_name', 'address', 'city', 'state', 'prospects_target', 'measurements_target', 'budget_minor', 'currency', 'notes'];
            $update = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $update[$field] = in_array($field, ['venue_name', 'address', 'city', 'state', 'notes'], true)
                        ? $this->nullableString($data[$field])
                        : ($field === 'type' || $field === 'currency' ? strtoupper((string) $data[$field]) : $data[$field]);
                }
            }
            if (array_key_exists('starts_at', $data)) $update['starts_at'] = $this->dateTime($data['starts_at']);
            if (array_key_exists('ends_at', $data)) $update['ends_at'] = $data['ends_at'] ? $this->dateTime($data['ends_at']) : null;
            if (array_key_exists('team_id', $data)) $update['team_id'] = $teamId;
            if (array_key_exists('responsible_user_id', $data)) $update['responsible_user_id'] = $responsibleId;
            $update['updated_at'] = now();
            DB::table('campaigns')->where('id', $campaignId)->update($update);

            if (array_key_exists('member_user_ids', $data)) {
                $members = array_values(array_unique(array_filter(array_map('strval', (array) $data['member_user_ids']))));
                if ($responsibleId !== null && !in_array($responsibleId, $members, true)) $members[] = $responsibleId;
                $this->syncMembers($campaignId, $partnerId, $members, $responsibleId);
            } elseif (array_key_exists('responsible_user_id', $data) && $responsibleId !== null) {
                $current = DB::table('campaign_members')->where('campaign_id', $campaignId)->where('status', 'ACTIVE')->pluck('user_id')->map('strval')->all();
                if (!in_array($responsibleId, $current, true)) $current[] = $responsibleId;
                $this->syncMembers($campaignId, $partnerId, $current, $responsibleId);
            }
            if (array_key_exists('device_ids', $data)) {
                $this->syncDevices($campaignId, $partnerId, (array) $data['device_ids']);
            }
        }, 3);

        return $this->show($campaignId);
    }

    public function activate(string $campaignId): array
    {
        return DB::transaction(function () use ($campaignId): array {
            $campaign = $this->authorizedCampaign($campaignId, true);
            $this->assertCanManage($campaign);
            if ((string) $campaign->status === 'ACTIVE') return $this->show($campaignId);
            if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new \RuntimeException('Este evento ya no puede activarse.', 409);
            }

            $deviceIds = DB::table('campaign_devices')->where('campaign_id', $campaignId)->where('status', 'ACTIVE')->pluck('device_id');
            if ($deviceIds->isNotEmpty()) {
                $conflict = DB::table('campaign_devices as cd')
                    ->join('campaigns as c', 'c.id', '=', 'cd.campaign_id')
                    ->whereIn('cd.device_id', $deviceIds)
                    ->where('cd.status', 'ACTIVE')
                    ->where('c.status', 'ACTIVE')
                    ->where('c.id', '<>', $campaignId)
                    ->whereNull('c.deleted_at')
                    ->select('c.name', 'cd.device_id')
                    ->first();
                if ($conflict !== null) {
                    throw new \RuntimeException("La báscula {$conflict->device_id} ya está activa en el evento {$conflict->name}.", 409);
                }
            }

            DB::table('campaigns')->where('id', $campaignId)->update([
                'status' => 'ACTIVE', 'activated_at' => now(), 'updated_at' => now(),
            ]);
            return $this->show($campaignId);
        }, 3);
    }

    public function close(string $campaignId): array
    {
        return $this->finish($campaignId, 'CLOSED');
    }

    public function cancel(string $campaignId): array
    {
        return $this->finish($campaignId, 'CANCELLED');
    }

    public function registerParticipant(string $campaignId, array $data): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('El evento está cerrado y no acepta participantes.', 409);
        }

        $partnerId = (string) $campaign->partner_id;
        $partner = DB::table('partners')->where('id', $partnerId)->whereNull('deleted_at')->first();
        if ($partner === null || (string) $partner->status !== 'ACTIVE') {
            throw new \RuntimeException('El socio está suspendido.', 403);
        }

        return DB::transaction(function () use ($campaign, $campaignId, $partnerId, $partner, $data): array {
            $contact = null;
            if (!empty($data['contact_id'])) {
                $contact = DB::table('contacts')->where('id', (string) $data['contact_id'])->whereNull('deleted_at')->lockForUpdate()->first();
            } else {
                $phone = (new NormalizedPhoneNumber((string) $data['whatsapp'], (string) ($partner->country_dial_code ?? '52')))->getValue();
                $contact = DB::table('contacts')->where('partner_id', $partnerId)->where('whatsapp_normalized', $phone)->whereNull('deleted_at')->lockForUpdate()->first();
            }
            if ($contact !== null && (string) $contact->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $contact->partner_id);
            }
            if ($contact !== null && (string) $contact->status !== 'ACTIVE') {
                throw new \RuntimeException('La persona está bloqueada y no puede registrarse.', 403);
            }

            $isNew = $contact === null;
            // En un evento el capturista solo queda como "Registrado por". La propiedad
            // se define después, cuando un colaborador genera la dieta.
            $advisorId = null;
            $registrarPartnerId = $this->access->actualPartnerId() ?? $partnerId;

            if ($isNew) {
                $phone = (new NormalizedPhoneNumber((string) $data['whatsapp'], (string) ($partner->country_dial_code ?? '52')))->getValue();
                $contactId = 'CNT_'.strtoupper((string) Str::ulid());
                DB::table('contacts')->insert([
                    'id' => $contactId,
                    'partner_id' => $partnerId,
                    'registered_by_partner_id' => $registrarPartnerId,
                    'responsible_partner_id' => null,
                    'whatsapp' => $phone,
                    'whatsapp_normalized' => $phone,
                    'name' => trim((string) $data['name']),
                    'age' => $data['age'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'height_cm' => $data['height_cm'] ?? null,
                    'profile' => $data['profile'] ?? null,
                    'lifecycle_stage' => 'PROSPECT',
                    'sales_stage' => 'NEW',
                    'status' => 'ACTIVE',
                    'contact_source' => SqlValue::encode([
                        'type' => 'CAMPAIGN',
                        'origin_campaign_id' => $campaignId,
                        'campaign_name' => (string) $campaign->name,
                        'created_from' => 'EVENT_REGISTRATION',
                    ]),
                    'generated_by_user_id' => $this->context->userId,
                    'origin_team_id' => $campaign->team_id,
                    'origin_campaign_id' => $campaignId,
                    'assigned_advisor_id' => $advisorId,
                    'metrics_summary' => SqlValue::encode([]),
                    'total_measurements' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $contact = DB::table('contacts')->where('id', $contactId)->first();
            } else {
                $contactId = (string) $contact->id;
                $updates = ['updated_at' => now()];
                if ($advisorId !== null && $contact->assigned_advisor_id === null) $updates['assigned_advisor_id'] = $advisorId;
                if ($contact->origin_team_id === null && $campaign->team_id !== null) $updates['origin_team_id'] = $campaign->team_id;
                DB::table('contacts')->where('id', $contactId)->update($updates);
            }

            $participant = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->lockForUpdate()->first();
            $participantType = $isNew
                ? 'NEW_PROSPECT'
                : ((string) $contact->lifecycle_stage === 'CUSTOMER' ? 'EXISTING_CUSTOMER' : 'EXISTING_PROSPECT');
            if ($participant === null) {
                DB::table('campaign_contacts')->insert([
                    'campaign_id' => $campaignId,
                    'partner_id' => $partnerId,
                    'contact_id' => $contactId,
                    'registered_by_user_id' => $this->context->userId,
                    'registered_by_partner_id' => $registrarPartnerId,
                    'assigned_advisor_id' => $this->context->userId,
                    'owner_user_id' => $this->context->userId,
                    'owner_partner_id' => $registrarPartnerId,
                    'participant_type' => $participantType,
                    'attention_status' => 'PENDING_DIET',
                    'is_new_contact' => $isNew,
                    'measurements_count' => 0,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('campaign_contacts')->where('id', $participant->id)->update([
                    'registered_by_user_id' => $participant->registered_by_user_id ?? $this->context->userId,
                    'registered_by_partner_id' => $participant->registered_by_partner_id ?? $registrarPartnerId,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('timeline_activities')->insert([
                'id' => 'TML_CAM_'.strtoupper((string) Str::ulid()),
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'type' => 'CAMPAIGN_PARTICIPATION',
                'description' => 'Participación registrada en el evento '.(string) $campaign->name.'.',
                'metadata' => SqlValue::encode(['campaign_id' => $campaignId, 'participant_type' => $participantType]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);

            if (!empty($data['follow_up_at']) && $advisorId !== null) {
                $taskId = 'TSK_'.strtoupper((string) Str::ulid());
                $assignee = $advisorId ?? $this->context->userId;
                $this->validateUser($assignee, $partnerId);
                DB::table('tasks')->insert([
                    'id' => $taskId,
                    'partner_id' => $partnerId,
                    'contact_id' => $contactId,
                    'assigned_user_id' => $assignee,
                    'team_id' => $campaign->team_id,
                    'campaign_id' => $campaignId,
                    'type' => 'FOLLOW_UP',
                    'title' => trim((string) ($data['follow_up_title'] ?? 'Seguimiento posterior a '.(string) $campaign->name)),
                    'status' => 'PENDING',
                    'due_at' => $this->dateTime($data['follow_up_at']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $publicCode = $this->publicCodes->ensure($contactId);
            $participantData = $this->participantByContact($campaignId, $contactId);
            $participantData['public_code'] = $publicCode;
            $this->activity->record($campaignId, $partnerId, 'PARTICIPANT_REGISTERED', $contactId, [
                'registered_by_user_id' => $this->context->userId,
                'registered_by_partner_id' => $registrarPartnerId,
                'is_new_contact' => $isNew,
            ]);
            return $participantData;
        }, 3);
    }

    public function participants(string $campaignId, array $filters = []): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $saleSummary = DB::table('sales as sale')
            ->where('sale.status', 'COMPLETED')
            ->groupBy('sale.origin_campaign_id', 'sale.contact_id')
            ->select(
                'sale.origin_campaign_id',
                'sale.contact_id',
                DB::raw('COUNT(*) as sale_count'),
                DB::raw('COALESCE(SUM(sale.total_revenue_minor), 0) as sale_revenue_minor'),
            );
        $waSummary = DB::table('whatsapp_contact_links as wa')
            ->where('wa.status', 'ACTIVE')
            ->groupBy('wa.contact_id')
            ->select('wa.contact_id', DB::raw('MAX(wa.last_inbound_at) as last_inbound_at'));

        $query = DB::table('campaign_contacts as cc')
            ->join('contacts as c', 'c.id', '=', 'cc.contact_id')
            ->leftJoin('users as registrar', 'registrar.id', '=', 'cc.registered_by_user_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'cc.owner_user_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'cc.assigned_advisor_id')
            ->leftJoin('nutrition_plan_versions as plan', 'plan.id', '=', 'cc.nutrition_plan_id')
            ->leftJoinSub($saleSummary, 'sale_summary', function ($join): void {
                $join->on('sale_summary.origin_campaign_id', '=', 'cc.campaign_id')
                    ->on('sale_summary.contact_id', '=', 'cc.contact_id');
            })
            ->leftJoinSub($waSummary, 'wa_summary', function ($join): void {
                $join->on('wa_summary.contact_id', '=', 'cc.contact_id');
            })
            ->where('cc.campaign_id', $campaignId);

        if ((string) $campaign->status === 'CLOSED' && !$this->context->isSuperAdmin()) {
            $query->where(function (Builder $visible): void {
                $visible->where('cc.owner_user_id', $this->context->userId)
                    ->orWhere('cc.assigned_advisor_id', $this->context->userId);
            });
        }

        $this->applyParticipantFilter($query, strtoupper(trim((string) ($filters['filter'] ?? 'ALL'))));
        $commercialStatus = strtoupper(trim((string) ($filters['commercial_status'] ?? '')));
        if ($commercialStatus === 'PURCHASE_COMPLETED') {
            $query->where(function (Builder $purchase): void {
                $purchase->where('cc.commercial_status', 'PURCHASE_COMPLETED')
                    ->orWhereRaw('COALESCE(sale_summary.sale_count, 0) > 0');
            });
        } elseif ($commercialStatus !== '') {
            $query->where('cc.commercial_status', $commercialStatus);
        }

        $total = (clone $query)->count('cc.id');
        $cursor = $this->decodeParticipantCursor((string) ($filters['cursor'] ?? ''));
        if ($cursor !== null) {
            $query->where(function (Builder $cursorQuery) use ($cursor): void {
                $cursorQuery->where('cc.last_seen_at', '<', $cursor['last_seen_at'])
                    ->orWhere(function (Builder $sameTime) use ($cursor): void {
                        $sameTime->where('cc.last_seen_at', '=', $cursor['last_seen_at'])
                            ->where('cc.contact_id', '<', $cursor['contact_id']);
                    });
            });
        }

        $paged = (bool) ($filters['_paged'] ?? false);
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 200);
        $query->select(
            'cc.*', 'c.name', 'c.public_code', 'c.whatsapp_normalized as whatsapp', 'c.whatsapp_status', 'c.whatsapp_verified_at', 'c.lifecycle_stage', 'c.sales_stage',
            'c.age', 'c.gender', 'c.height_cm', 'c.profile',
            'c.status as contact_status', 'c.total_measurements', 'registrar.name as registered_by_name',
            'owner.name as owner_name', 'assignee.name as assigned_advisor_name', 'plan.delivery_status as diet_delivery_status',
            DB::raw('COALESCE(sale_summary.sale_count, 0) as sale_count'),
            DB::raw('COALESCE(sale_summary.sale_revenue_minor, 0) as sale_revenue_minor'),
            'wa_summary.last_inbound_at as whatsapp_last_inbound_at',
        )->orderByDesc('cc.last_seen_at')->orderByDesc('cc.contact_id');

        if ($paged) $query->limit($limit + 1);
        $rows = $query->get();
        $hasMore = $paged && $rows->count() > $limit;
        if ($hasMore) $rows = $rows->take($limit);

        $deviceUsageByContact = [];
        $contactIds = $rows->pluck('contact_id')->filter()->unique()->values()->all();
        if ($contactIds !== [] && DB::getSchemaBuilder()->hasTable('personal_devices')) {
            $measurementRows = DB::table('measurements as measurement')
                ->leftJoin('devices as legacy_device', 'legacy_device.id', '=', 'measurement.device_id')
                ->leftJoin('personal_devices as personal_device', function ($join): void {
                    $join->on('personal_device.linked_device_id', '=', 'measurement.device_id')
                        ->where('personal_device.device_type', '=', 'SCALE');
                })
                ->leftJoin('users as device_owner', 'device_owner.id', '=', 'personal_device.owner_user_id')
                ->leftJoin('users as legacy_device_owner', 'legacy_device_owner.id', '=', 'legacy_device.linked_by_user_id')
                ->where('measurement.campaign_id', $campaignId)
                ->whereIn('measurement.contact_id', $contactIds)
                ->orderByDesc('measurement.timestamp_local')
                ->select(
                    'measurement.contact_id', 'measurement.device_id', 'measurement.timestamp_local',
                    'personal_device.display_name as personal_device_name',
                    'personal_device.hardware_identifier as personal_hardware_identifier',
                    'personal_device.model as personal_device_model',
                    'device_owner.name as personal_device_owner_name',
                    'legacy_device.mac_address as legacy_device_mac',
                    'legacy_device_owner.name as legacy_device_owner_name',
                )->get();
            foreach ($measurementRows as $measurementRow) {
                $contactId = (string) $measurementRow->contact_id;
                if (isset($deviceUsageByContact[$contactId])) continue;
                $deviceUsageByContact[$contactId] = [
                    'device_id' => (string) $measurementRow->device_id,
                    'display_name' => (string) ($measurementRow->personal_device_name ?: 'Báscula Rhino'),
                    'hardware_identifier' => $measurementRow->personal_hardware_identifier ?: $measurementRow->legacy_device_mac,
                    'model' => $measurementRow->personal_device_model,
                    'owner_name' => $measurementRow->personal_device_owner_name ?: $measurementRow->legacy_device_owner_name,
                    'measured_at' => $measurementRow->timestamp_local,
                ];
            }
        }

        $items = $rows->map(function (object $row) use ($deviceUsageByContact): array {
            $data = (array) $row;
            $data['device_used'] = $deviceUsageByContact[(string) $row->contact_id] ?? null;
            if (($data['whatsapp_status'] ?? 'VERIFIED') !== 'VERIFIED' || str_starts_with((string) ($data['whatsapp'] ?? ''), 'PENDING_')) {
                $data['whatsapp'] = null;
            }
            $data['whatsapp_capture_status'] = $data['whatsapp_capture_status'] ?? (($data['whatsapp_status'] ?? 'VERIFIED') === 'VERIFIED' ? 'CONFIRMED' : 'PENDING');
            $data['sale_count'] = (int) ($data['sale_count'] ?? 0);
            $data['sale_revenue_minor'] = (int) ($data['sale_revenue_minor'] ?? 0);
            $missing = $this->profileMissingFields($data);
            $data['profile_missing_fields'] = $missing;
            $data['profile_complete'] = $missing === [];
            $data['contact_quality'] = $data['profile_complete'] ? 'COMPLETE' : 'INCOMPLETE';
            $data['event_state'] = $this->participantEventState($data);
            return $data;
        })->values()->all();

        if (!$paged) return $items;
        $nextCursor = null;
        if ($hasMore && $items !== []) {
            $last = $items[array_key_last($items)];
            $nextCursor = $this->encodeParticipantCursor((string) $last['last_seen_at'], (string) $last['contact_id']);
        }
        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    public function pipeline(string $campaignId): array
    {
        $participants = $this->participants($campaignId);
        $stageDefinitions = [
            ['key' => 'MEASUREMENTS', 'label' => 'Pesajes', 'filter' => 'PIPELINE_MEASUREMENTS'],
            ['key' => 'WHATSAPP_CAPTURED', 'label' => 'WhatsApp capturado', 'filter' => 'PIPELINE_WHATSAPP'],
            ['key' => 'CONTACT_STARTED', 'label' => 'Contacto iniciado', 'filter' => 'PIPELINE_CONTACT_STARTED'],
            ['key' => 'PLAN_SENT', 'label' => 'Plan enviado', 'filter' => 'PIPELINE_PLAN_SENT'],
            ['key' => 'RESPONDED', 'label' => 'Respondió', 'filter' => 'PIPELINE_RESPONDED'],
            ['key' => 'INTERESTED_EVALUATING', 'label' => 'Interesado / evaluando', 'filter' => 'PIPELINE_INTERESTED'],
            ['key' => 'PURCHASE', 'label' => 'Compra', 'filter' => 'PIPELINE_PURCHASE'],
        ];

        $stages = [];
        $previous = null;
        foreach ($stageDefinitions as $definition) {
            $count = count(array_filter($participants, fn (array $row): bool => $this->matchesPipelineStage($row['event_state'], $definition['key'])));
            $conversion = $previous === null ? null : ($previous > 0 ? round(($count / $previous) * 100, 1) : 0.0);
            $stages[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'filter' => $definition['filter'],
                'count' => $count,
                'conversion_from_previous' => $conversion,
            ];
            $previous = $count;
        }
        $notInterested = count(array_filter($participants, fn (array $row): bool => ($row['event_state']['commercial_status'] ?? '') === 'NOT_INTERESTED_NOW'));
        $partyDefinitions = [
            ['key' => 'MEASUREMENTS_NO_CONTACT', 'label' => 'Pesajes sin contacto'],
            ['key' => 'MEASUREMENTS_WITH_CONTACT', 'label' => 'Pesajes con contacto'],
            ['key' => 'PLAN_SENT', 'label' => 'Dietas enviadas'],
            ['key' => 'POSITIVE_RESPONSE', 'label' => 'Respuesta positiva'],
            ['key' => 'PURCHASE', 'label' => 'Compraron producto'],
        ];
        $partyCounts = [];
        foreach ($partyDefinitions as $definition) {
            $partyCounts[$definition['key']] = count(array_filter(
                $participants,
                fn (array $row): bool => $this->matchesPartyPipelineStage($row['event_state'], $definition['key'])
            ));
        }
        $measuredTotal = $partyCounts['MEASUREMENTS_NO_CONTACT'] + $partyCounts['MEASUREMENTS_WITH_CONTACT'];
        $partyStages = [];
        foreach ($partyDefinitions as $definition) {
            $key = $definition['key'];
            $count = $partyCounts[$key];
            $conversion = match ($key) {
                'MEASUREMENTS_NO_CONTACT' => null,
                // Los dos primeros bloques son estados excluyentes del mismo universo de pesajes.
                // Por eso aquí mostramos tasa de captura de WhatsApp, no una falsa conversión no-contacto -> contacto.
                'MEASUREMENTS_WITH_CONTACT' => $measuredTotal > 0 ? round(($count / $measuredTotal) * 100, 1) : 0.0,
                'PLAN_SENT' => $partyCounts['MEASUREMENTS_WITH_CONTACT'] > 0 ? round(($count / $partyCounts['MEASUREMENTS_WITH_CONTACT']) * 100, 1) : 0.0,
                'POSITIVE_RESPONSE' => $partyCounts['PLAN_SENT'] > 0 ? round(($count / $partyCounts['PLAN_SENT']) * 100, 1) : 0.0,
                'PURCHASE' => $partyCounts['POSITIVE_RESPONSE'] > 0 ? round(($count / $partyCounts['POSITIVE_RESPONSE']) * 100, 1) : 0.0,
                default => null,
            };
            $partyStages[] = [
                'key' => $key,
                'label' => $definition['label'],
                'count' => $count,
                'conversion_from_previous' => $conversion,
            ];
        }

        return [
            'campaign_id' => $campaignId,
            'stages' => $stages,
            'party_pipeline' => $partyStages,
            'side_exits' => [[
                'key' => 'NOT_INTERESTED_NOW',
                'label' => 'No interesado por ahora',
                'filter' => 'NOT_INTERESTED_NOW',
                'count' => $notInterested,
            ]],
        ];
    }

    private function profileMissingFields(array $data): array
    {
        $missing = [];
        $whatsappOk = ($data['whatsapp_status'] ?? '') === 'VERIFIED'
            && !empty($data['whatsapp'])
            && !str_starts_with((string) $data['whatsapp'], 'PENDING_');
        if (!$whatsappOk) $missing[] = 'whatsapp';
        if (trim((string) ($data['name'] ?? '')) === '') $missing[] = 'name';
        $age = (int) ($data['age'] ?? 0);
        if ($age < 5 || $age > 120) $missing[] = 'age';
        if (trim((string) ($data['gender'] ?? '')) === '') $missing[] = 'gender';
        $height = (float) ($data['height_cm'] ?? 0);
        if ($height < 80 || $height > 250) $missing[] = 'height_cm';
        if (trim((string) ($data['profile'] ?? '')) === '') $missing[] = 'profile';
        return $missing;
    }

    private function matchesPartyPipelineStage(array $state, string $stage): bool
    {
        $measurement = (bool) ($state['has_measurement'] ?? false);
        $whatsapp = (bool) ($state['whatsapp_captured'] ?? false);
        $planSent = (bool) ($state['plan_sent'] ?? false);
        $positive = (bool) ($state['responded'] ?? false)
            && in_array((string) ($state['commercial_status'] ?? ''), ['INTERESTED', 'EVALUATING', 'PURCHASE_COMPLETED'], true);
        $purchase = (bool) ($state['purchase_completed'] ?? false);
        return match ($stage) {
            'MEASUREMENTS_NO_CONTACT' => $measurement && !$whatsapp,
            'MEASUREMENTS_WITH_CONTACT' => $measurement && $whatsapp,
            'PLAN_SENT' => $measurement && $whatsapp && $planSent,
            'POSITIVE_RESPONSE' => $measurement && $whatsapp && $planSent && $positive,
            'PURCHASE' => $measurement && $purchase,
            default => false,
        };
    }

    private function participantEventState(array $data): array
    {
        $hasMeasurement = (int) ($data['measurements_count'] ?? 0) > 0;
        $whatsappCaptured = ($data['whatsapp_status'] ?? '') === 'VERIFIED' && !empty($data['whatsapp']);
        $contactStarted = !empty($data['contact_started_at']);
        $manualResponded = !empty($data['manual_responded_at']);
        $inboundAfterStart = false;
        if ($contactStarted && !empty($data['whatsapp_last_inbound_at'])) {
            $started = strtotime((string) $data['contact_started_at']);
            $inbound = strtotime((string) $data['whatsapp_last_inbound_at']);
            $inboundAfterStart = $started !== false && $inbound !== false && $inbound >= $started;
        }
        $responded = $manualResponded || $inboundAfterStart;
        $planGenerated = !empty($data['nutrition_plan_id']);
        $planSent = in_array((string) ($data['diet_delivery_status'] ?? 'NOT_SENT'), ['SENT', 'DELIVERED', 'READ'], true);
        $purchase = (int) ($data['sale_count'] ?? 0) > 0;
        $commercialStatus = $purchase ? 'PURCHASE_COMPLETED' : strtoupper((string) ($data['commercial_status'] ?? 'UNEXPLORED'));
        if (!in_array($commercialStatus, ['UNEXPLORED', 'INTERESTED', 'EVALUATING', 'NOT_INTERESTED_NOW', 'PURCHASE_COMPLETED'], true)) {
            $commercialStatus = 'UNEXPLORED';
        }
        $visualLevel = $planSent ? 'PLAN_SENT' : ($planGenerated ? 'PLAN_READY' : ($whatsappCaptured ? 'WHATSAPP' : 'NEUTRAL'));
        $chips = [];
        if ($whatsappCaptured) $chips[] = 'WHATSAPP';
        if ($contactStarted) $chips[] = 'CONTACTO INICIADO';
        if ($responded) $chips[] = 'RESPONDIÓ';
        if ($planGenerated) $chips[] = 'PLAN GENERADO';
        if ($planSent) $chips[] = 'PLAN ENVIADO';
        if (in_array($commercialStatus, ['INTERESTED', 'EVALUATING'], true)) $chips[] = $commercialStatus === 'INTERESTED' ? 'INTERESADO' : 'EVALUANDO';
        if ($purchase) $chips[] = 'COMPRA';
        return [
            'has_measurement' => $hasMeasurement,
            'whatsapp_captured' => $whatsappCaptured,
            'contact_started' => $contactStarted,
            'responded' => $responded,
            'plan_generated' => $planGenerated,
            'plan_sent' => $planSent,
            'commercial_status' => $commercialStatus,
            'purchase_completed' => $purchase,
            'visual_level' => $visualLevel,
            'chips' => $chips,
        ];
    }

    private function matchesPipelineStage(array $state, string $stage): bool
    {
        $measurement = (bool) ($state['has_measurement'] ?? false);
        $whatsapp = $measurement && (bool) ($state['whatsapp_captured'] ?? false);
        $started = $whatsapp && (bool) ($state['contact_started'] ?? false);
        $planSent = $started && (bool) ($state['plan_sent'] ?? false);
        $responded = $planSent && (bool) ($state['responded'] ?? false);
        $interested = $responded && in_array((string) ($state['commercial_status'] ?? ''), ['INTERESTED', 'EVALUATING', 'PURCHASE_COMPLETED'], true);
        $purchase = $interested && (bool) ($state['purchase_completed'] ?? false);
        return match ($stage) {
            'MEASUREMENTS' => $measurement,
            'WHATSAPP_CAPTURED' => $whatsapp,
            'CONTACT_STARTED' => $started,
            'PLAN_SENT' => $planSent,
            'RESPONDED' => $responded,
            'INTERESTED_EVALUATING' => $interested,
            'PURCHASE' => $purchase,
            default => false,
        };
    }

    private function applyParticipantFilter(Builder $query, string $filter): void
    {
        $verified = function (Builder $q): void {
            $q->where('c.whatsapp_status', 'VERIFIED')
                ->whereNotNull('c.whatsapp_normalized')
                ->where('c.whatsapp_normalized', 'not like', 'PENDING\\_%');
        };
        $responded = function (Builder $q): void {
            $q->whereNotNull('cc.manual_responded_at')
                ->orWhere(function (Builder $inbound): void {
                    $inbound->whereNotNull('cc.contact_started_at')
                        ->whereNotNull('wa_summary.last_inbound_at')
                        ->whereColumn('wa_summary.last_inbound_at', '>=', 'cc.contact_started_at');
                });
        };
        switch ($filter) {
            case 'WITHOUT_WHATSAPP':
                $query->where('cc.measurements_count', '>', 0)->where(function (Builder $q): void {
                    $q->where('c.whatsapp_status', '<>', 'VERIFIED')
                        ->orWhereNull('c.whatsapp_status')
                        ->orWhereNull('c.whatsapp_normalized')
                        ->orWhere('c.whatsapp_normalized', 'like', 'PENDING\_%');
                });
                break;
            case 'WITH_WHATSAPP':
                $query->where('cc.measurements_count', '>', 0)->where($verified);
                break;
            case 'PLAN_PENDING':
                $query->where('cc.measurements_count', '>', 0)->where(function (Builder $q): void {
                    $q->whereNull('cc.nutrition_plan_id')->orWhereNotIn('plan.delivery_status', ['SENT', 'DELIVERED', 'READ']);
                });
                break;
            case 'PLAN_SENT':
                $query->whereIn('plan.delivery_status', ['SENT', 'DELIVERED', 'READ']);
                break;
            case 'UNEXPLORED': case 'INTERESTED': case 'EVALUATING': case 'NOT_INTERESTED_NOW':
                $query->where('cc.commercial_status', $filter);
                break;
            case 'PURCHASE_COMPLETED':
                $query->where(function (Builder $purchase): void {
                    $purchase->where('cc.commercial_status', 'PURCHASE_COMPLETED')
                        ->orWhereRaw('COALESCE(sale_summary.sale_count, 0) > 0');
                });
                break;
            case 'PIPELINE_MEASUREMENTS':
                $query->where('cc.measurements_count', '>', 0);
                break;
            case 'PIPELINE_WHATSAPP':
                $query->where('cc.measurements_count', '>', 0)->where($verified);
                break;
            case 'PIPELINE_CONTACT_STARTED':
                $query->where('cc.measurements_count', '>', 0)->where($verified)->whereNotNull('cc.contact_started_at');
                break;
            case 'PIPELINE_PLAN_SENT':
                $query->where('cc.measurements_count', '>', 0)->where($verified)->whereNotNull('cc.contact_started_at')->whereIn('plan.delivery_status', ['SENT', 'DELIVERED', 'READ']);
                break;
            case 'PIPELINE_RESPONDED':
                $query->where('cc.measurements_count', '>', 0)->where($verified)->whereNotNull('cc.contact_started_at')->whereIn('plan.delivery_status', ['SENT', 'DELIVERED', 'READ'])->where($responded);
                break;
            case 'PIPELINE_INTERESTED':
                $query->where('cc.measurements_count', '>', 0)->where($verified)->whereNotNull('cc.contact_started_at')->whereIn('plan.delivery_status', ['SENT', 'DELIVERED', 'READ'])->where($responded)
                    ->where(function (Builder $interest): void {
                        $interest->whereIn('cc.commercial_status', ['INTERESTED', 'EVALUATING', 'PURCHASE_COMPLETED'])
                            ->orWhereRaw('COALESCE(sale_summary.sale_count, 0) > 0');
                    });
                break;
            case 'PIPELINE_PURCHASE':
                $query->where('cc.measurements_count', '>', 0)->where($verified)->whereNotNull('cc.contact_started_at')->whereIn('plan.delivery_status', ['SENT', 'DELIVERED', 'READ'])->where($responded)
                    ->whereRaw('COALESCE(sale_summary.sale_count, 0) > 0');
                break;
            case 'ALL': default:
                break;
        }
    }

    private function encodeParticipantCursor(string $lastSeenAt, string $contactId): string
    {
        return rtrim(strtr(base64_encode(json_encode(['last_seen_at' => $lastSeenAt, 'contact_id' => $contactId], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodeParticipantCursor(string $cursor): ?array
    {
        if ($cursor === '') return null;
        $padding = strlen($cursor) % 4;
        if ($padding !== 0) $cursor .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) return null;
        $value = json_decode($decoded, true);
        if (!is_array($value) || empty($value['last_seen_at']) || empty($value['contact_id'])) return null;
        return ['last_seen_at' => (string) $value['last_seen_at'], 'contact_id' => (string) $value['contact_id']];
    }

    public function report(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $partnerId = (string) $campaign->partner_id;
        $participants = DB::table('campaign_contacts')->where('campaign_id', $campaignId);
        $measurements = DB::table('measurements')->where('campaign_id', $campaignId)->count();
        $tasks = DB::table('tasks')->where('campaign_id', $campaignId);
        $sales = DB::table('sales')->where('origin_campaign_id', $campaignId)->where('status', 'COMPLETED');

        $totals = [
            'participants' => (clone $participants)->count(),
            'new_prospects' => (clone $participants)->where('is_new_contact', true)->count(),
            'existing_prospects' => (clone $participants)->where('participant_type', 'EXISTING_PROSPECT')->count(),
            'existing_customers' => (clone $participants)->where('participant_type', 'EXISTING_CUSTOMER')->count(),
            'measurements' => $measurements,
            'follow_ups' => (clone $tasks)->count(),
            'pending_follow_ups' => (clone $tasks)->where('status', 'PENDING')->count(),
            'conversions' => (clone $participants)->whereNotNull('converted_at')->count(),
            'sales' => (clone $sales)->count(),
            'revenue_minor' => (int) (clone $sales)->sum('total_revenue_minor'),
        ];
        $totals['prospects_progress'] = $this->progress($totals['new_prospects'], (int) $campaign->prospects_target);
        $totals['measurements_progress'] = $this->progress($totals['measurements'], (int) $campaign->measurements_target);

        $byAdvisor = DB::table('campaign_contacts as cc')
            ->leftJoin('users as u', 'u.id', '=', 'cc.assigned_advisor_id')
            ->where('cc.campaign_id', $campaignId)
            ->groupBy('cc.assigned_advisor_id', 'u.name')
            ->select('cc.assigned_advisor_id', DB::raw("COALESCE(u.name, 'Sin asignar') as advisor_name"), DB::raw('COUNT(*) as participants'), DB::raw('SUM(cc.measurements_count) as measurements'), DB::raw('SUM(CASE WHEN cc.converted_at IS NOT NULL THEN 1 ELSE 0 END) as conversions'))
            ->orderByDesc('participants')
            ->get()->map(static fn (object $row): array => [
                'advisor_id' => $row->assigned_advisor_id,
                'advisor_name' => $row->advisor_name,
                'participants' => (int) $row->participants,
                'measurements' => (int) $row->measurements,
                'conversions' => (int) $row->conversions,
            ])->all();

        return [
            'campaign_id' => $campaignId,
            'partner_id' => $partnerId,
            'totals' => $totals,
            'by_advisor' => $byAdvisor,
        ];
    }

    private function personalReport(string $campaignId): array
    {
        $participants = DB::table('campaign_contacts')
            ->where('campaign_id', $campaignId)
            ->where(function (Builder $scope): void {
                $scope->where('owner_user_id', $this->context->userId)
                    ->orWhere('diet_generated_by_user_id', $this->context->userId)
                    ->orWhere('registered_by_user_id', $this->context->userId);
            });
        $tasks = DB::table('tasks')
            ->where('campaign_id', $campaignId)
            ->where('assigned_user_id', $this->context->userId);
        $sales = DB::table('sales')
            ->where('origin_campaign_id', $campaignId)
            ->where('advisor_id', $this->context->userId)
            ->where('status', 'COMPLETED');

        return [
            'campaign_id' => $campaignId,
            'partner_id' => (string) DB::table('campaigns')->where('id', $campaignId)->value('partner_id'),
            'restricted_to_user_id' => $this->context->userId,
            'totals' => [
                'participants' => (clone $participants)->count(),
                'new_prospects' => (clone $participants)->where('is_new_contact', true)->count(),
                'existing_prospects' => (clone $participants)->where('participant_type', 'EXISTING_PROSPECT')->count(),
                'existing_customers' => (clone $participants)->where('participant_type', 'EXISTING_CUSTOMER')->count(),
                'measurements' => (int) (clone $participants)->sum('measurements_count'),
                'follow_ups' => (clone $tasks)->count(),
                'pending_follow_ups' => (clone $tasks)->where('status', 'PENDING')->count(),
                'conversions' => (clone $participants)->whereNotNull('converted_at')->count(),
                'sales' => (clone $sales)->count(),
                'revenue_minor' => (int) (clone $sales)->sum('total_revenue_minor'),
                'prospects_progress' => null,
                'measurements_progress' => null,
            ],
            'by_advisor' => [],
        ];
    }

    private function finish(string $campaignId, string $status): array
    {
        return DB::transaction(function () use ($campaignId, $status): array {
            $campaign = $this->authorizedCampaign($campaignId, true);
            $this->assertCanManage($campaign);
            if ((string) $campaign->status === $status) return $this->show($campaignId);
            if ((string) $campaign->status === 'CLOSED' && $status === 'CANCELLED') {
                throw new \RuntimeException('Un evento cerrado no puede cancelarse.', 409);
            }
            DB::table('campaigns')->where('id', $campaignId)->update([
                'status' => $status,
                'closed_at' => now(),
                'ends_at' => $campaign->ends_at ?? now(),
                'updated_at' => now(),
            ]);
            if (in_array($status, ['CLOSED', 'CANCELLED'], true)) {
                app(\App\Modules\Devices\Services\PersonalDeviceService::class)->releaseCampaign($campaignId);
                if (DB::getSchemaBuilder()->hasTable('campaign_join_tokens')) DB::table('campaign_join_tokens')->where('campaign_id', $campaignId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
                if (DB::getSchemaBuilder()->hasTable('campaign_invitations')) DB::table('campaign_invitations')->where('campaign_id', $campaignId)->where('status', 'PENDING')->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
            }
            return $this->show($campaignId);
        }, 3);
    }

    private function syncMembers(string $campaignId, string $partnerId, array $userIds, ?string $responsibleId): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        foreach ($userIds as $userId) $this->validateUser($userId, $partnerId);
        // Solo sincroniza integrantes internos seleccionados en el formulario. Las
        // invitaciones aceptadas se administran desde su propio flujo y no se revocan al editar.
        DB::table('campaign_members')->where('campaign_id', $campaignId)
            ->whereNull('invitation_id')
            ->when($userIds !== [], fn (Builder $q) => $q->whereNotIn('user_id', $userIds))
            ->update(['status' => 'INACTIVE', 'left_at' => now(), 'updated_at' => now()]);
        if ($userIds === []) {
            DB::table('campaign_members')->where('campaign_id', $campaignId)->whereNull('invitation_id')
                ->update(['status' => 'INACTIVE', 'left_at' => now(), 'updated_at' => now()]);
            return;
        }
        foreach ($userIds as $userId) {
            DB::table('campaign_members')->updateOrInsert(
                ['campaign_id' => $campaignId, 'user_id' => $userId],
                [
                    'partner_id' => $partnerId,
                    'member_partner_id' => DB::table('users')->where('id', $userId)->value('partner_id') ?? $partnerId,
                    'member_role' => $userId === $responsibleId ? 'ORGANIZER' : 'COLLABORATOR',
                    'status' => 'ACTIVE',
                    'joined_at' => now(),
                    'accepted_at' => now(),
                    'left_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function syncDevices(string $campaignId, string $partnerId, array $deviceIds): void
    {
        $deviceIds = array_values(array_unique(array_filter(array_map('strval', $deviceIds))));
        foreach ($deviceIds as $deviceId) $this->validateDevice($deviceId, $partnerId);
        DB::table('campaign_devices')->where('campaign_id', $campaignId)
            ->when($deviceIds !== [], fn (Builder $q) => $q->whereNotIn('device_id', $deviceIds))
            ->update(['status' => 'INACTIVE', 'unlinked_at' => now(), 'updated_at' => now()]);
        if ($deviceIds === []) {
            DB::table('campaign_devices')->where('campaign_id', $campaignId)->update(['status' => 'INACTIVE', 'unlinked_at' => now(), 'updated_at' => now()]);
            return;
        }
        foreach ($deviceIds as $deviceId) {
            DB::table('campaign_devices')->updateOrInsert(
                ['campaign_id' => $campaignId, 'device_id' => $deviceId],
                [
                    'partner_id' => $partnerId,
                    'status' => 'ACTIVE',
                    'linked_at' => now(),
                    'unlinked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function authorizedCampaign(string $campaignId, bool $lock = false): object
    {
        if ($lock) {
            // Bloqueamos exclusivamente la fila principal. Algunos servidores MySQL/MariaDB
            // rechazan FOR UPDATE cuando la consulta contiene LEFT JOIN sobre tablas opcionales.
            $campaign = DB::table('campaigns as c')
                ->where('c.id', $campaignId)
                ->whereNull('c.deleted_at')
                ->select('c.*')
                ->lockForUpdate()
                ->first();

            if ($campaign !== null) {
                $campaign->responsible_name = $campaign->responsible_user_id !== null
                    ? DB::table('users')->where('id', $campaign->responsible_user_id)->value('name')
                    : null;
                $campaign->team_name = $campaign->team_id !== null
                    ? DB::table('teams')->where('id', $campaign->team_id)->value('name')
                    : null;
            }
        } else {
            $campaign = DB::table('campaigns as c')
                ->leftJoin('users as responsible', 'responsible.id', '=', 'c.responsible_user_id')
                ->leftJoin('teams as team', 'team.id', '=', 'c.team_id')
                ->where('c.id', $campaignId)
                ->whereNull('c.deleted_at')
                ->select('c.*', 'responsible.name as responsible_name', 'team.name as team_name')
                ->first();
        }

        if ($campaign === null) throw new \RuntimeException('Evento no encontrado.', 404);
        if (!$this->access->canAccess($campaign)) throw new \RuntimeException('No tienes acceso a este evento.', 403);
        return $campaign;
    }

    private function canAccess(object $campaign): bool
    {
        if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) return true;
        if ((string) ($campaign->responsible_user_id ?? '') === $this->context->userId) return true;
        if (DB::table('campaign_members')->where('campaign_id', $campaign->id)->where('user_id', $this->context->userId)->where('status', 'ACTIVE')->exists()) return true;
        $teamId = $campaign->team_id !== null ? (string) $campaign->team_id : null;
        if ($teamId === null) return false;
        if ($this->context->role === 'TEAM_LEADER') {
            return (new TeamAccessScope($this->context))->canManageTeam($teamId);
        }
        return DB::table('team_members')
            ->where('partner_id', $this->partnerId())
            ->where('team_id', $teamId)
            ->where('user_id', $this->context->userId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    private function applyAccessScope(Builder $query, string $alias): void
    {
        if ($this->context->isSuperAdmin() || in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) return;
        $teamIds = (new TeamAccessScope($this->context))->teamIds();
        $query->where(function (Builder $q) use ($alias, $teamIds): void {
            $q->where("{$alias}.responsible_user_id", $this->context->userId)
                ->orWhereExists(function (Builder $member): void {
                    $member->selectRaw('1')->from('campaign_members as cm')
                        ->whereColumn('cm.campaign_id', 'c.id')
                        ->where('cm.user_id', $this->context->userId)
                        ->where('cm.status', 'ACTIVE');
                })
                ->orWhereExists(function (Builder $teamMember): void {
                    $teamMember->selectRaw('1')->from('team_members as ctm')
                        ->whereColumn('ctm.team_id', 'c.team_id')
                        ->where('ctm.user_id', $this->context->userId)
                        ->where('ctm.status', 'ACTIVE');
                });
            if ($teamIds !== []) $q->orWhereIn("{$alias}.team_id", $teamIds);
        });
    }

    private function campaignArray(object $row, bool $withCounts): array
    {
        $data = [
            'id' => $row->id,
            'partner_id' => $row->partner_id,
            'location_id' => $row->location_id,
            'team_id' => $row->team_id,
            'team_name' => $row->team_name ?? null,
            'responsible_user_id' => $row->responsible_user_id,
            'responsible_name' => $row->responsible_name ?? null,
            'name' => $row->name,
            'type' => $row->type,
            'venue_name' => $row->venue_name,
            'address' => $row->address,
            'city' => $row->city,
            'state' => $row->state,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'status' => $row->status,
            'display_status' => (string) $row->status === 'CLOSED' ? 'FINISHED' : (string) $row->status,
            'prospects_target' => (int) $row->prospects_target,
            'measurements_target' => (int) $row->measurements_target,
            'budget_minor' => (int) $row->budget_minor,
            'currency' => $row->currency,
            'notes' => $row->notes,
            'closing_notes' => $row->closing_notes ?? null,
            'closed_by_user_id' => $row->closed_by_user_id ?? null,
            'closure_snapshot' => isset($row->closure_snapshot) ? SqlValue::json($row->closure_snapshot) : [],
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
        if ($withCounts) {
            $data['participants_count'] = DB::table('campaign_contacts')->where('campaign_id', $row->id)->count();
            $data['measurements_count'] = DB::table('measurements')->where('campaign_id', $row->id)->count();
        }
        return $data;
    }

    private function participantByContact(string $campaignId, string $contactId): array
    {
        $row = DB::table('campaign_contacts as cc')
            ->join('contacts as c', 'c.id', '=', 'cc.contact_id')
            ->leftJoin('users as registrar', 'registrar.id', '=', 'cc.registered_by_user_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'cc.owner_user_id')
            ->where('cc.campaign_id', $campaignId)
            ->where('cc.contact_id', $contactId)
            ->select(
                'cc.*', 'c.name', 'c.public_code', 'c.whatsapp_normalized as whatsapp', 'c.whatsapp_status', 'c.whatsapp_verified_at', 'c.lifecycle_stage', 'c.sales_stage',
                'registrar.name as registered_by_name', 'owner.name as owner_name', 'owner.name as advisor_name'
            )
            ->first();
        if ($row === null) return [];
        $data = (array) $row;
        if (($data['whatsapp_status'] ?? 'VERIFIED') !== 'VERIFIED' || str_starts_with((string) ($data['whatsapp'] ?? ''), 'PENDING_')) {
            $data['whatsapp'] = null;
        }
        $data['whatsapp_capture_status'] = $data['whatsapp_capture_status'] ?? (($data['whatsapp_status'] ?? 'VERIFIED') === 'VERIFIED' ? 'CONFIRMED' : 'PENDING');
        return $data;
    }

    private function assertCanCreate(): void
    {
        if (!$this->context->isSuperAdmin() && !in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER', 'TEAM_LEADER'], true)) {
            throw new \RuntimeException('No tienes permiso para crear eventos.', 403);
        }
    }

    private function assertCanManage(object $campaign): void
    {
        $this->access->assertManage($campaign);
    }

    private function validateTeam(?string $teamId, string $partnerId): void
    {
        if ($teamId === null) return;
        $valid = DB::table('teams')->where('id', $teamId)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists();
        if (!$valid) throw new \RuntimeException('Equipo inválido.', 422);
        if ($this->context->role === 'TEAM_LEADER' && !(new TeamAccessScope($this->context))->canManageTeam($teamId)) {
            throw new \RuntimeException('No puedes asignar un evento fuera de tu equipo.', 403);
        }
    }

    private function validateUser(?string $userId, string $partnerId): void
    {
        if ($userId === null) return;
        $valid = DB::table('users')->where('id', $userId)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists();
        if (!$valid) throw new \RuntimeException('Usuario responsable inválido o suspendido.', 422);
        if ($this->context->role === 'TEAM_LEADER' && !(new TeamAccessScope($this->context))->canManageUser($userId) && $userId !== $this->context->userId) {
            throw new \RuntimeException('No puedes asignar personas fuera de tu equipo.', 403);
        }
    }

    private function validateDevice(string $deviceId, string $partnerId): void
    {
        $valid = DB::table('devices')->where('id', $deviceId)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists();
        if (!$valid) throw new \RuntimeException('Báscula inválida o suspendida.', 422);
    }

    private function validateSchedule(mixed $startsAt, mixed $endsAt): void
    {
        $start = new DateTimeImmutable((string) $startsAt);
        if ($endsAt !== null && $endsAt !== '') {
            $end = new DateTimeImmutable((string) $endsAt);
            if ($end < $start) throw new \RuntimeException('La finalización no puede ser anterior al inicio.', 422);
        }
    }

    private function principalLocationId(string $partnerId): string
    {
        $id = DB::table('locations')->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->orderBy('created_at')->value('id');
        if ($id !== null) return (string) $id;
        $id = 'LOC_PRINCIPAL_'.strtoupper(substr(hash('sha256', $partnerId), 0, 20));
        DB::table('locations')->insertOrIgnore([
            'id' => $id, 'partner_id' => $partnerId, 'name' => 'Principal', 'city' => null, 'state' => null,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function partnerId(): string
    {
        return $this->context->partnerId ?? throw new \RuntimeException('Los eventos se administran desde la cuenta de un socio.', 403);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function dateTime(mixed $value): string
    {
        return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
    }

    private function progress(int $value, int $target): ?float
    {
        if ($target < 1) return null;
        return round(($value / $target) * 100, 2);
    }
}
