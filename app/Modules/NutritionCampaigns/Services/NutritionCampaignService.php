<?php

namespace App\Modules\NutritionCampaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\NutritionCampaigns\Jobs\SendNutritionCampaignInvitationJob;
use App\Modules\Movements\Services\MovementService;
use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionCampaignService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly PartnerWorkModeService $workMode,
        private readonly NutritionCampaignStageService $stages,
        private readonly MovementService $movements,
    ) {}

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    public function list(array $filters): array
    {
        $partnerId = $this->partnerId();
        $query = DB::table('nutrition_followup_campaigns as nfc')
            ->where('nfc.partner_id', $partnerId)
            ->whereNull('nfc.deleted_at');

        if (($filters['status'] ?? null) !== null && trim((string) $filters['status']) !== '') {
            $query->where('nfc.status', strtoupper(trim((string) $filters['status'])));
        }
        if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $scope) use ($search): void {
                $scope->where('nfc.name', 'like', $search)
                    ->orWhere('nfc.notes', 'like', $search);
            });
        }
        if (($filters['from'] ?? null) !== null && trim((string) $filters['from']) !== '') {
            $query->whereDate('nfc.scheduled_at', '>=', (string) $filters['from']);
        }
        if (($filters['to'] ?? null) !== null && trim((string) $filters['to']) !== '') {
            $query->whereDate('nfc.scheduled_at', '<=', (string) $filters['to']);
        }

        $this->applyCampaignVisibility($query, 'nfc');

        return $query
            ->leftJoin('campaigns as event', 'event.id', '=', 'nfc.linked_event_id')
            ->select('nfc.*', 'event.name as linked_event_name', 'event.starts_at as linked_event_starts_at')
            ->orderByRaw("FIELD(nfc.status,'ACTIVE','READY','DRAFT','CLOSED','CANCELLED')")
            ->orderByDesc('nfc.created_at')
            ->get()
            ->map(fn (object $row): array => $this->campaignArray($row, true))
            ->all();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function create(array $data): array
    {
        $this->assertCanManage();
        $partnerId = $this->partnerId();
        $individual = !$this->workMode->isTeamMode();
        $responsibleUserId = $individual
            ? $this->context->userId
            : $this->nullableString($data['responsible_user_id'] ?? null);

        $this->assertLinkedEvent($partnerId, $this->nullableString($data['linked_event_id'] ?? null));
        $this->assertResponsibleUser($partnerId, $responsibleUserId);

        $campaignId = 'NFC_'.strtoupper((string) Str::ulid());
        $filters = array_merge($this->defaultFilters(), (array) ($data['source_filters'] ?? []));

        DB::table('nutrition_followup_campaigns')->insert([
            'id' => $campaignId,
            'partner_id' => $partnerId,
            'linked_event_id' => $this->nullableString($data['linked_event_id'] ?? null),
            'team_id' => $individual ? null : $this->nullableString($data['team_id'] ?? null),
            'responsible_user_id' => $responsibleUserId,
            'name' => trim((string) $data['name']),
            'type' => 'NUTRITION_REENGAGEMENT',
            'status' => 'DRAFT',
            'source_filters' => SqlValue::encode($filters),
            'whatsapp_template_name' => $this->nullableString($data['whatsapp_template_name'] ?? null),
            'scheduled_at' => $this->nullableString($data['scheduled_at'] ?? null),
            'created_by_user_id' => $this->context->userId,
            'activated_at' => null,
            'closed_at' => null,
            'notes' => $this->nullableString($data['notes'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->show($campaignId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function update(string $campaignId, array $data): array
    {
        $campaign = $this->authorizedCampaign($campaignId, true);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('Una campaña cerrada o cancelada no puede editarse.', 409);
        }

        $partnerId = $this->partnerId();
        $updates = [];
        foreach (['name', 'scheduled_at', 'whatsapp_template_name', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $field === 'name'
                    ? trim((string) $data[$field])
                    : $this->nullableString($data[$field]);
            }
        }
        if (array_key_exists('linked_event_id', $data)) {
            $linkedEventId = $this->nullableString($data['linked_event_id']);
            $this->assertLinkedEvent($partnerId, $linkedEventId);
            $updates['linked_event_id'] = $linkedEventId;
        }
        if (array_key_exists('team_id', $data) && $this->workMode->isTeamMode()) {
            $updates['team_id'] = $this->nullableString($data['team_id']);
        }
        if (array_key_exists('responsible_user_id', $data)) {
            $responsible = $this->workMode->isTeamMode()
                ? $this->nullableString($data['responsible_user_id'])
                : $this->context->userId;
            $this->assertResponsibleUser($partnerId, $responsible);
            $updates['responsible_user_id'] = $responsible;
        }
        if (array_key_exists('source_filters', $data)) {
            $updates['source_filters'] = SqlValue::encode(array_merge(
                $this->defaultFilters(),
                (array) $data['source_filters'],
            ));
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('nutrition_followup_campaigns')
                ->where('id', $campaignId)
                ->update($updates);
        }

        return $this->show($campaignId);
    }

    /** @return array<string,mixed> */
    public function show(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $row = DB::table('nutrition_followup_campaigns as nfc')
            ->leftJoin('campaigns as event', 'event.id', '=', 'nfc.linked_event_id')
            ->leftJoin('users as responsible', 'responsible.id', '=', 'nfc.responsible_user_id')
            ->where('nfc.id', $campaignId)
            ->select(
                'nfc.*',
                'event.name as linked_event_name',
                'event.starts_at as linked_event_starts_at',
                'responsible.name as responsible_name',
            )
            ->first();

        $members = DB::table('nutrition_followup_members as m')
            ->join('contacts as c', 'c.id', '=', 'm.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.responsible_user_id')
            ->where('m.campaign_id', $campaignId)
            ->where('m.partner_id', $campaign->partner_id)
            ->select(
                'm.*',
                'c.name as contact_name',
                'c.whatsapp',
                'c.public_code',
                'c.lifecycle_stage',
                'c.sales_stage',
                'u.name as responsible_name',
            )
            ->orderByRaw("FIELD(m.stage,'CONFIRMED','RESPONDED','READ','DELIVERED','INVITATION_SENT','INVITATION_PENDING','SELECTED','ATTENDED','REWEIGHED','TASTING_COMPLETED','INTERESTED','SALE_COMPLETED','NO_RESPONSE','DECLINED','NO_SHOW','OPTED_OUT')")
            ->orderBy('c.name')
            ->get()
            ->map(fn (object $member): array => $this->memberArray($member))
            ->all();

        $history = DB::table('nutrition_followup_stage_history as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
            ->where('h.campaign_id', $campaignId)
            ->select('h.*', 'u.name as changed_by_name')
            ->orderByDesc('h.created_at')
            ->limit(100)
            ->get()
            ->map(static fn (object $item): array => (array) $item)
            ->all();

        return [
            'campaign' => $this->campaignArray($row ?? $campaign, false),
            'members' => $members,
            'pipeline' => $this->pipeline($members),
            'stage_labels' => NutritionCampaignStageService::STAGES,
            'history' => $history,
            'report' => $this->report($campaignId),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function candidates(array $filters): array
    {
        $this->assertCanManage();
        $normalized = array_merge($this->defaultFilters(), $filters);
        $rows = $this->candidateRows($normalized);

        return [
            'filters' => $normalized,
            'count' => count($rows),
            'candidates' => $rows,
        ];
    }

    /** @param array<int,string> $contactIds @return array<string,mixed> */
    public function addMembers(string $campaignId, array $contactIds): array
    {
        $campaign = $this->authorizedCampaign($campaignId, true);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('No se pueden agregar personas a una campaña cerrada.', 409);
        }

        $filters = SqlValue::json($campaign->source_filters ?? null, $this->defaultFilters());
        $filters['contact_ids'] = array_values(array_unique(array_map('strval', $contactIds)));
        $filters['campaign_id'] = null;
        $candidates = $this->candidateRows($filters);
        $added = 0;

        DB::transaction(function () use ($campaign, $candidates, &$added): void {
            foreach ($candidates as $candidate) {
                $memberId = 'NFM_'.strtoupper(substr(hash(
                    'sha256',
                    $campaign->id.'|'.$candidate['contact_id']
                ), 0, 32));

                $exists = DB::table('nutrition_followup_members')
                    ->where('campaign_id', $campaign->id)
                    ->where('contact_id', $candidate['contact_id'])
                    ->exists();
                if ($exists) {
                    continue;
                }

                $responsible = $candidate['assigned_user_id']
                    ?? $campaign->responsible_user_id
                    ?? $this->context->userId;

                DB::table('nutrition_followup_members')->insert([
                    'id' => $memberId,
                    'campaign_id' => $campaign->id,
                    'partner_id' => $campaign->partner_id,
                    'contact_id' => $candidate['contact_id'],
                    'nutrition_plan_id' => $candidate['nutrition_plan_id'],
                    'personal_report_id' => $candidate['personal_report_id'],
                    'plan_recommended_at' => $candidate['plan_recommended_at'],
                    'plan_delivery_status' => $candidate['plan_delivery_status'],
                    'last_measurement_id' => $candidate['last_measurement_id'],
                    'last_measurement_at' => $candidate['last_measurement_at'],
                    'responsible_user_id' => $responsible,
                    'stage' => 'SELECTED',
                    'invitation_status' => 'PENDING',
                    'selection_snapshot' => SqlValue::encode($candidate),
                    'notes' => null,
                    'invited_at' => null,
                    'responded_at' => null,
                    'confirmed_at' => null,
                    'attended_at' => null,
                    'reweighed_at' => null,
                    'interested_at' => null,
                    'sale_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('nutrition_followup_stage_history')->insert([
                    'campaign_id' => $campaign->id,
                    'member_id' => $memberId,
                    'partner_id' => $campaign->partner_id,
                    'contact_id' => $candidate['contact_id'],
                    'from_stage' => null,
                    'to_stage' => 'SELECTED',
                    'source' => 'CHECKLIST',
                    'changed_by_user_id' => $this->context->userId,
                    'note' => 'Agregado mediante checklist de personas con alimentación recomendada.',
                    'created_at' => now(),
                ]);

                $this->movements->record(
                    '701',
                    (string) $campaign->partner_id,
                    'NUTRITION_CAMPAIGN_MEMBER',
                    $memberId,
                    [
                        'campaign_id' => (string) $campaign->id,
                        'contact_id' => (string) $candidate['contact_id'],
                        'nutrition_plan_id' => $candidate['nutrition_plan_id'],
                        'plan_recommended_at' => $candidate['plan_recommended_at'],
                    ],
                    'Agregado mediante checklist.',
                    'NUTRITION_CAMPAIGN',
                    (string) $campaign->id,
                );
                $added++;
            }

            if ($added > 0 && (string) $campaign->status === 'DRAFT') {
                DB::table('nutrition_followup_campaigns')
                    ->where('id', $campaign->id)
                    ->update(['status' => 'READY', 'updated_at' => now()]);
            }
        }, 3);

        return [
            'added_count' => $added,
            'requested_count' => count($contactIds),
            'campaign' => $this->show($campaignId),
        ];
    }

    /** @return array<string,mixed> */
    public function removeMember(string $campaignId, string $memberId): array
    {
        $campaign = $this->authorizedCampaign($campaignId, true);
        if (!in_array((string) $campaign->status, ['DRAFT', 'READY'], true)) {
            throw new \RuntimeException('Solo puedes retirar personas antes de activar la campaña.', 409);
        }

        $member = DB::table('nutrition_followup_members')
            ->where('id', $memberId)
            ->where('campaign_id', $campaignId)
            ->where('partner_id', $campaign->partner_id)
            ->first();
        if ($member === null) {
            throw new \RuntimeException('Persona no encontrada en la campaña.', 404);
        }
        if (DB::table('nutrition_followup_messages')->where('member_id', $memberId)->exists()) {
            throw new \RuntimeException('No puedes retirar a una persona que ya tiene invitaciones registradas.', 409);
        }

        DB::table('nutrition_followup_members')->where('id', $memberId)->delete();

        return ['removed' => true, 'member_id' => $memberId];
    }

    /** @return array<string,mixed> */
    public function activate(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId, true);
        $memberCount = DB::table('nutrition_followup_members')
            ->where('campaign_id', $campaignId)
            ->count();
        if ($memberCount < 1) {
            throw new \RuntimeException('Agrega al menos una persona antes de activar la campaña.', 409);
        }

        DB::table('nutrition_followup_campaigns')->where('id', $campaignId)->update([
            'status' => 'ACTIVE',
            'activated_at' => $campaign->activated_at ?? now(),
            'updated_at' => now(),
        ]);

        return $this->show($campaignId);
    }

    /** @return array<string,mixed> */
    public function close(string $campaignId): array
    {
        $this->authorizedCampaign($campaignId, true);
        DB::table('nutrition_followup_campaigns')->where('id', $campaignId)->update([
            'status' => 'CLOSED',
            'closed_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->show($campaignId);
    }

    /**
     * @param array<int,string> $memberIds
     * @return array<string,mixed>
     */
    public function dispatchInvitations(string $campaignId, array $memberIds): array
    {
        $campaign = $this->authorizedCampaign($campaignId, true);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('La campaña está cerrada y no admite invitaciones.', 409);
        }

        $members = DB::table('nutrition_followup_members')
            ->where('campaign_id', $campaignId)
            ->where('partner_id', $campaign->partner_id)
            ->whereIn('id', array_values(array_unique(array_map('strval', $memberIds))))
            ->get();

        if ($members->isEmpty()) {
            throw new \RuntimeException('Selecciona al menos una persona válida.', 422);
        }

        $queued = [];
        DB::transaction(function () use ($campaign, $members, &$queued): void {
            foreach ($members as $member) {
                if (in_array((string) $member->stage, [
                    'OPTED_OUT', 'SALE_COMPLETED', 'CLOSED',
                ], true)) {
                    continue;
                }

                $attempt = (int) DB::table('nutrition_followup_messages')
                    ->where('member_id', $member->id)
                    ->where('message_kind', 'INVITATION')
                    ->max('attempt_no') + 1;
                $messageId = 'NFMG_'.strtoupper((string) Str::ulid());

                DB::table('nutrition_followup_messages')->insert([
                    'id' => $messageId,
                    'campaign_id' => $campaign->id,
                    'member_id' => $member->id,
                    'partner_id' => $campaign->partner_id,
                    'contact_id' => $member->contact_id,
                    'message_kind' => 'INVITATION',
                    'attempt_no' => max(1, $attempt),
                    'recipient' => '',
                    'status' => 'QUEUED',
                    'external_id' => null,
                    'template_name' => $campaign->whatsapp_template_name,
                    'rendered_body' => null,
                    'skip_reason' => null,
                    'last_error' => null,
                    'queued_at' => now(),
                    'processing_at' => null,
                    'sent_at' => null,
                    'delivered_at' => null,
                    'read_at' => null,
                    'failed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->stages->transition(
                    (string) $member->id,
                    'INVITATION_PENDING',
                    'CHECKLIST',
                    $this->context->userId,
                    'Invitación seleccionada para envío individual.',
                    ['invitation_status' => 'QUEUED'],
                );
                $queued[] = $messageId;
            }

            DB::table('nutrition_followup_campaigns')->where('id', $campaign->id)->update([
                'status' => 'ACTIVE',
                'activated_at' => $campaign->activated_at ?? now(),
                'updated_at' => now(),
            ]);
        }, 3);

        foreach ($queued as $messageId) {
            SendNutritionCampaignInvitationJob::dispatch($messageId);
        }

        return [
            'queued_count' => count($queued),
            'message_ids' => $queued,
            'campaign' => $this->show($campaignId),
        ];
    }

    /** @return array<string,mixed> */
    public function changeStage(
        string $campaignId,
        string $memberId,
        string $stage,
        ?string $note,
    ): array {
        $campaign = $this->authorizedCampaign($campaignId);
        $member = DB::table('nutrition_followup_members')
            ->where('id', $memberId)
            ->where('campaign_id', $campaignId)
            ->where('partner_id', $campaign->partner_id)
            ->first();
        if ($member === null) {
            throw new \RuntimeException('Persona no encontrada en la campaña.', 404);
        }
        $this->assertCanChangeMember($member);

        return $this->stages->transition(
            $memberId,
            $stage,
            'MANUAL',
            $this->context->userId,
            $note,
        );
    }

    /** @return array<string,mixed> */
    public function report(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $stageCounts = DB::table('nutrition_followup_members')
            ->where('campaign_id', $campaignId)
            ->select('stage', DB::raw('COUNT(*) as total'))
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $messageCounts = DB::table('nutrition_followup_messages')
            ->where('campaign_id', $campaignId)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $total = array_sum($stageCounts);
        $confirmed = (int) ($stageCounts['CONFIRMED'] ?? 0)
            + (int) ($stageCounts['ATTENDED'] ?? 0)
            + (int) ($stageCounts['REWEIGHED'] ?? 0)
            + (int) ($stageCounts['TASTING_COMPLETED'] ?? 0)
            + (int) ($stageCounts['INTERESTED'] ?? 0)
            + (int) ($stageCounts['SALE_COMPLETED'] ?? 0);
        $attended = (int) ($stageCounts['ATTENDED'] ?? 0)
            + (int) ($stageCounts['REWEIGHED'] ?? 0)
            + (int) ($stageCounts['TASTING_COMPLETED'] ?? 0)
            + (int) ($stageCounts['INTERESTED'] ?? 0)
            + (int) ($stageCounts['SALE_COMPLETED'] ?? 0);
        $reweighed = (int) ($stageCounts['REWEIGHED'] ?? 0)
            + (int) ($stageCounts['TASTING_COMPLETED'] ?? 0)
            + (int) ($stageCounts['INTERESTED'] ?? 0)
            + (int) ($stageCounts['SALE_COMPLETED'] ?? 0);

        return [
            'campaign_id' => $campaignId,
            'total_members' => $total,
            'stage_counts' => $stageCounts,
            'message_counts' => $messageCounts,
            'confirmed_count' => $confirmed,
            'attended_count' => $attended,
            'reweighed_count' => $reweighed,
            'interested_count' => (int) ($stageCounts['INTERESTED'] ?? 0)
                + (int) ($stageCounts['SALE_COMPLETED'] ?? 0),
            'sales_count' => (int) ($stageCounts['SALE_COMPLETED'] ?? 0),
            'confirmation_rate' => $total > 0 ? round(($confirmed / $total) * 100, 1) : 0.0,
            'attendance_rate' => $total > 0 ? round(($attended / $total) * 100, 1) : 0.0,
            'reweigh_rate' => $total > 0 ? round(($reweighed / $total) * 100, 1) : 0.0,
        ];
    }

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    private function candidateRows(array $filters): array
    {
        $partnerId = $this->partnerId();
        $query = DB::table('nutrition_plan_versions as np')
            ->join('contacts as c', function ($join): void {
                $join->on('c.id', '=', 'np.contact_id')
                    ->on('c.partner_id', '=', 'np.partner_id');
            })
            ->leftJoin('personal_reports as pr', 'pr.id', '=', 'np.personal_report_id')
            ->leftJoin('report_deliveries as rd', function ($join): void {
                $join->on('rd.report_id', '=', 'pr.id')
                    ->where('rd.channel', '=', 'WHATSAPP');
            })
            ->leftJoin('whatsapp_contact_links as wl', function ($join): void {
                $join->on('wl.contact_id', '=', 'np.contact_id')
                    ->on('wl.partner_id', '=', 'np.partner_id');
            })
            ->where('np.partner_id', $partnerId)
            ->where('c.status', 'ACTIVE')
            ->whereNull('c.deleted_at')
            ->whereRaw(
                'np.generated_at = (
                    SELECT MAX(np2.generated_at)
                    FROM nutrition_plan_versions np2
                    WHERE np2.partner_id = np.partner_id
                      AND np2.contact_id = np.contact_id
                )'
            );

        $this->applyContactVisibility($query, 'c');

        if ((bool) ($filters['delivered_only'] ?? true)) {
            $query->whereIn(DB::raw("COALESCE(rd.status, np.delivery_status)"), ['SENT', 'DELIVERED', 'READ']);
        }
        if ((bool) ($filters['require_marketing_consent'] ?? true)) {
            $query->where('wl.status', 'ACTIVE')
                ->whereNotNull('wl.verified_at')
                ->whereNotNull('wl.marketing_consent_at');
        }
        if ((bool) ($filters['no_measurement_after_plan'] ?? true)) {
            $query->whereNotExists(function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('measurements as later_measurement')
                    ->whereColumn('later_measurement.partner_id', 'np.partner_id')
                    ->whereColumn('later_measurement.contact_id', 'np.contact_id')
                    ->whereColumn('later_measurement.timestamp_local', '>', 'np.generated_at');
            });
        }
        if ($this->nullableString($filters['plan_from'] ?? null) !== null) {
            $query->whereDate('np.generated_at', '>=', (string) $filters['plan_from']);
        }
        if ($this->nullableString($filters['plan_to'] ?? null) !== null) {
            $query->whereDate('np.generated_at', '<=', (string) $filters['plan_to']);
        }
        if (($filters['min_days_since_plan'] ?? null) !== null && $filters['min_days_since_plan'] !== '') {
            $query->where('np.generated_at', '<=', now()->subDays((int) $filters['min_days_since_plan']));
        }
        if (($filters['max_days_since_plan'] ?? null) !== null && $filters['max_days_since_plan'] !== '') {
            $query->where('np.generated_at', '>=', now()->subDays((int) $filters['max_days_since_plan']));
        }
        if ($this->nullableString($filters['lifecycle_stage'] ?? null) !== null) {
            $query->where('c.lifecycle_stage', strtoupper((string) $filters['lifecycle_stage']));
        }
        if ($this->nullableString($filters['assigned_user_id'] ?? null) !== null) {
            $query->where('c.assigned_advisor_id', (string) $filters['assigned_user_id']);
        }
        if ($this->nullableString($filters['search'] ?? null) !== null) {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $scope) use ($search): void {
                $scope->where('c.name', 'like', $search)
                    ->orWhere('c.whatsapp', 'like', $search)
                    ->orWhere('c.public_code', 'like', $search);
            });
        }
        if (isset($filters['contact_ids']) && is_array($filters['contact_ids'])) {
            $query->whereIn('c.id', array_values(array_unique(array_map('strval', $filters['contact_ids']))));
        }
        if ($this->nullableString($filters['campaign_id'] ?? null) !== null) {
            $campaignId = (string) $filters['campaign_id'];
            $query->whereNotExists(function (Builder $sub) use ($campaignId): void {
                $sub->selectRaw('1')
                    ->from('nutrition_followup_members as existing_member')
                    ->whereColumn('existing_member.contact_id', 'c.id')
                    ->where('existing_member.campaign_id', $campaignId);
            });
        }

        $limit = min(500, max(1, (int) ($filters['limit'] ?? 250)));

        return $query
            ->select(
                'c.id as contact_id',
                'c.name',
                'c.whatsapp',
                'c.whatsapp_normalized',
                'c.public_code',
                'c.lifecycle_stage',
                'c.sales_stage',
                'c.assigned_advisor_id as assigned_user_id',
                'c.last_measurement_at',
                'np.id as nutrition_plan_id',
                'np.personal_report_id',
                'np.status as nutrition_plan_status',
                'np.generated_at as plan_recommended_at',
                'pr.campaign_id as plan_origin_event_id',
                DB::raw('COALESCE(rd.status, np.delivery_status) as plan_delivery_status'),
                DB::raw('COALESCE(rd.sent_at, np.generated_at) as plan_sent_at'),
                DB::raw('COALESCE(rd.delivered_at, np.delivered_at) as plan_delivered_at'),
                'wl.phone_number as linked_whatsapp',
                'wl.marketing_consent_at',
            )
            ->selectSub(function (Builder $measurement): void {
                $measurement->from('measurements as latest_measurement')
                    ->select('latest_measurement.id')
                    ->whereColumn('latest_measurement.partner_id', 'np.partner_id')
                    ->whereColumn('latest_measurement.contact_id', 'np.contact_id')
                    ->orderByDesc('latest_measurement.timestamp_local')
                    ->limit(1);
            }, 'last_measurement_id')
            ->orderBy('np.generated_at')
            ->limit($limit)
            ->get()
            ->map(function (object $row): array {
                return [
                    'contact_id' => (string) $row->contact_id,
                    'name' => (string) $row->name,
                    'whatsapp' => (string) $row->whatsapp,
                    'public_code' => $row->public_code,
                    'lifecycle_stage' => (string) $row->lifecycle_stage,
                    'sales_stage' => (string) $row->sales_stage,
                    'assigned_user_id' => $row->assigned_user_id,
                    'nutrition_plan_id' => (string) $row->nutrition_plan_id,
                    'personal_report_id' => (string) $row->personal_report_id,
                    'nutrition_plan_status' => (string) $row->nutrition_plan_status,
                    'plan_recommended_at' => $row->plan_recommended_at,
                    'plan_origin_event_id' => $row->plan_origin_event_id,
                    'plan_delivery_status' => $row->plan_delivery_status,
                    'plan_sent_at' => $row->plan_sent_at,
                    'plan_delivered_at' => $row->plan_delivered_at,
                    'last_measurement_id' => $row->last_measurement_id,
                    'last_measurement_at' => $row->last_measurement_at,
                    'days_since_plan' => Carbon::parse((string) $row->plan_recommended_at)->diffInDays(now()),
                    'whatsapp_verified' => $row->linked_whatsapp !== null,
                    'marketing_consent_at' => $row->marketing_consent_at,
                ];
            })
            ->all();
    }

    private function authorizedCampaign(string $campaignId, bool $manage = false): object
    {
        $campaign = DB::table('nutrition_followup_campaigns')
            ->where('id', $campaignId)
            ->whereNull('deleted_at')
            ->first();

        if ($campaign === null) {
            throw new \RuntimeException('Campaña de seguimiento no encontrada.', 404);
        }
        if ((string) $campaign->partner_id !== $this->partnerId()) {
            throw new TenantViolationException($this->context->userId, (string) $campaign->partner_id);
        }

        if ($manage) {
            $this->assertCanManageCampaign($campaign);
        } elseif (!$this->canViewCampaign($campaign)) {
            throw new \RuntimeException('Campaña fuera de tu ámbito autorizado.', 403);
        }

        return $campaign;
    }

    private function assertCanManage(): void
    {
        if (!in_array($this->context->role, [
            'PARTNER_ADMIN', 'LOCATION_MANAGER', 'TEAM_LEADER',
        ], true)) {
            throw new \RuntimeException('No tienes permiso para administrar campañas nutricionales.', 403);
        }
    }

    private function assertCanManageCampaign(object $campaign): void
    {
        $this->assertCanManage();
        if ($this->context->role !== 'TEAM_LEADER') {
            return;
        }

        $scope = new TeamAccessScope($this->context);
        $responsibleAllowed = $campaign->responsible_user_id !== null
            && $scope->canManageUser((string) $campaign->responsible_user_id);
        $teamAllowed = $campaign->team_id !== null
            && $scope->canManageTeam((string) $campaign->team_id);

        if (!$responsibleAllowed && !$teamAllowed) {
            throw new \RuntimeException('Campaña fuera de los equipos que coordinas.', 403);
        }
    }

    private function canViewCampaign(object $campaign): bool
    {
        if (in_array($this->context->role, [
            'PARTNER_ADMIN', 'LOCATION_MANAGER',
        ], true)) {
            return true;
        }
        if ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            return ($campaign->responsible_user_id !== null
                    && $scope->canManageUser((string) $campaign->responsible_user_id))
                || ($campaign->team_id !== null
                    && $scope->canManageTeam((string) $campaign->team_id));
        }
        if ($this->context->role === 'ADVISOR') {
            return (string) ($campaign->responsible_user_id ?? '') === $this->context->userId
                || DB::table('nutrition_followup_members')
                    ->where('campaign_id', $campaign->id)
                    ->where('responsible_user_id', $this->context->userId)
                    ->exists();
        }

        return false;
    }

    private function assertCanChangeMember(object $member): void
    {
        if (in_array($this->context->role, [
            'PARTNER_ADMIN', 'LOCATION_MANAGER',
        ], true)) {
            return;
        }
        if ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            if ($scope->canManageUser($member->responsible_user_id !== null
                ? (string) $member->responsible_user_id
                : null)) {
                return;
            }
        }
        if ($this->context->role === 'ADVISOR'
            && (string) ($member->responsible_user_id ?? '') === $this->context->userId) {
            return;
        }

        throw new \RuntimeException('No puedes modificar el seguimiento de esta persona.', 403);
    }

    private function applyCampaignVisibility(Builder $query, string $alias): void
    {
        if (in_array($this->context->role, [
            'PARTNER_ADMIN', 'LOCATION_MANAGER',
        ], true)) {
            return;
        }
        if ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            $query->where(function (Builder $visibility) use ($scope, $alias): void {
                $teamIds = $scope->teamIds();
                $userIds = $scope->memberUserIds();
                if ($teamIds !== []) {
                    $visibility->whereIn($alias.'.team_id', $teamIds);
                }
                if ($userIds !== []) {
                    $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                    $visibility->{$method}($alias.'.responsible_user_id', $userIds);
                }
                if ($teamIds === [] && $userIds === []) {
                    $visibility->whereRaw('1 = 0');
                }
            });
            return;
        }
        if ($this->context->role === 'ADVISOR') {
            $query->where(function (Builder $visibility) use ($alias): void {
                $visibility->where($alias.'.responsible_user_id', $this->context->userId)
                    ->orWhereExists(function (Builder $member): void {
                        $member->selectRaw('1')
                            ->from('nutrition_followup_members as visible_member')
                            ->whereColumn('visible_member.campaign_id', 'nfc.id')
                            ->where('visible_member.responsible_user_id', $this->context->userId);
                    });
            });
            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function applyContactVisibility(Builder $query, string $alias): void
    {
        if (in_array($this->context->role, [
            'PARTNER_ADMIN', 'LOCATION_MANAGER',
        ], true)) {
            return;
        }
        if ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            $teamIds = $scope->teamIds();
            $userIds = $scope->memberUserIds();
            $query->where(function (Builder $visibility) use ($alias, $teamIds, $userIds): void {
                if ($teamIds !== []) {
                    $visibility->whereIn($alias.'.origin_team_id', $teamIds);
                }
                if ($userIds !== []) {
                    $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                    $visibility->{$method}($alias.'.assigned_advisor_id', $userIds);
                }
                if ($teamIds === [] && $userIds === []) {
                    $visibility->whereRaw('1 = 0');
                }
            });
            return;
        }
        if ($this->context->role === 'ADVISOR') {
            $query->where($alias.'.assigned_advisor_id', $this->context->userId);
            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** @return array<string,mixed> */
    private function campaignArray(object $row, bool $withCounts): array
    {
        $array = [
            'id' => (string) $row->id,
            'partner_id' => (string) $row->partner_id,
            'linked_event_id' => $row->linked_event_id,
            'linked_event_name' => $row->linked_event_name ?? null,
            'linked_event_starts_at' => $row->linked_event_starts_at ?? null,
            'team_id' => $row->team_id,
            'responsible_user_id' => $row->responsible_user_id,
            'responsible_name' => $row->responsible_name ?? null,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'source_filters' => SqlValue::json($row->source_filters ?? null, $this->defaultFilters()),
            'whatsapp_template_name' => $row->whatsapp_template_name,
            'scheduled_at' => $row->scheduled_at,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'activated_at' => $row->activated_at,
            'closed_at' => $row->closed_at,
        ];

        if ($withCounts) {
            $counts = DB::table('nutrition_followup_members')
                ->where('campaign_id', $row->id)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN stage IN ('CONFIRMED','ATTENDED','REWEIGHED','TASTING_COMPLETED','INTERESTED','SALE_COMPLETED') THEN 1 ELSE 0 END) as confirmed")
                ->selectRaw("SUM(CASE WHEN stage IN ('ATTENDED','REWEIGHED','TASTING_COMPLETED','INTERESTED','SALE_COMPLETED') THEN 1 ELSE 0 END) as attended")
                ->selectRaw("SUM(CASE WHEN stage IN ('REWEIGHED','TASTING_COMPLETED','INTERESTED','SALE_COMPLETED') THEN 1 ELSE 0 END) as reweighed")
                ->first();
            $array['members_count'] = (int) ($counts->total ?? 0);
            $array['confirmed_count'] = (int) ($counts->confirmed ?? 0);
            $array['attended_count'] = (int) ($counts->attended ?? 0);
            $array['reweighed_count'] = (int) ($counts->reweighed ?? 0);
        }

        return $array;
    }

    /** @return array<string,mixed> */
    private function memberArray(object $member): array
    {
        return [
            'id' => (string) $member->id,
            'campaign_id' => (string) $member->campaign_id,
            'contact_id' => (string) $member->contact_id,
            'contact_name' => (string) ($member->contact_name ?? ''),
            'whatsapp' => (string) ($member->whatsapp ?? ''),
            'public_code' => $member->public_code ?? null,
            'lifecycle_stage' => $member->lifecycle_stage ?? null,
            'sales_stage' => $member->sales_stage ?? null,
            'nutrition_plan_id' => $member->nutrition_plan_id,
            'personal_report_id' => $member->personal_report_id,
            'plan_recommended_at' => $member->plan_recommended_at,
            'plan_delivery_status' => $member->plan_delivery_status,
            'last_measurement_id' => $member->last_measurement_id,
            'last_measurement_at' => $member->last_measurement_at,
            'responsible_user_id' => $member->responsible_user_id,
            'responsible_name' => $member->responsible_name ?? null,
            'stage' => (string) $member->stage,
            'stage_label' => NutritionCampaignStageService::STAGES[(string) $member->stage] ?? (string) $member->stage,
            'invitation_status' => (string) $member->invitation_status,
            'notes' => $member->notes,
            'invited_at' => $member->invited_at,
            'responded_at' => $member->responded_at,
            'confirmed_at' => $member->confirmed_at,
            'attended_at' => $member->attended_at,
            'reweighed_at' => $member->reweighed_at,
            'interested_at' => $member->interested_at,
            'sale_id' => $member->sale_id,
            'created_at' => $member->created_at,
        ];
    }

    /** @param array<int,array<string,mixed>> $members @return array<string,array<int,array<string,mixed>>> */
    private function pipeline(array $members): array
    {
        $pipeline = [];
        foreach (array_keys(NutritionCampaignStageService::STAGES) as $stage) {
            $pipeline[$stage] = [];
        }
        foreach ($members as $member) {
            $pipeline[$member['stage']][] = $member;
        }
        return $pipeline;
    }

    /** @return array<string,mixed> */
    private function defaultFilters(): array
    {
        return [
            'plan_from' => null,
            'plan_to' => null,
            'min_days_since_plan' => (int) config('rhino.nutrition_campaigns.default_days_since_plan', 30),
            'max_days_since_plan' => null,
            'delivered_only' => true,
            'no_measurement_after_plan' => true,
            'require_marketing_consent' => true,
            'lifecycle_stage' => null,
            'assigned_user_id' => null,
            'limit' => 250,
        ];
    }

    private function assertLinkedEvent(string $partnerId, ?string $eventId): void
    {
        if ($eventId === null) {
            return;
        }
        if (!DB::table('campaigns')
            ->where('id', $eventId)
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->exists()) {
            throw new \RuntimeException('El evento de degustación seleccionado no existe.', 422);
        }
    }

    private function assertResponsibleUser(string $partnerId, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }
        if (!DB::table('users')
            ->where('id', $userId)
            ->where('partner_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->exists()) {
            throw new \RuntimeException('El responsable seleccionado no pertenece al socio.', 422);
        }
    }

    private function partnerId(): string
    {
        return $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
