<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\CRM\Services\ContactMeasurementPresentationService;
use App\Modules\Movements\Services\MovementService;
use App\Modules\NutritionEngine\Services\NutritionEngineService;
use App\Modules\NutritionEngine\Services\NutritionPlanEngineService;
use App\Modules\NutritionEngine\Services\NutritionPlanDeliveryService;
use App\Modules\Sales\Services\SaleProcessingService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CampaignCollaborationService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CampaignAccessService $access,
        private readonly CampaignActivityService $activity,
        private readonly MovementService $movements,
        private readonly ContactMeasurementPresentationService $measurementPresentation,
    ) {}

    public function participantWorkspace(string $campaignId, string $contactId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $participant = $this->participant($campaign, $contactId);
        $this->assertParticipantVisible($campaign, $participant);

        $nutritionScope = clone $campaign;
        $nutritionScope->partner_id = (string) ($participant->contact_partner_id ?? $campaign->partner_id);
        $nutrition = $this->access->runInCampaign($nutritionScope, function () use ($contactId): array {
            return app(NutritionEngineService::class)->contactWorkspace($contactId);
        });

        $measurements = $this->participantMeasurements($campaignId, $contactId);
        $measurementDashboard = $this->measurementPresentation->present($participant, $measurements);
        $whatsappCapture = app(CampaignWhatsappCaptureService::class)->ensure($campaignId, $contactId);

        $sales = DB::table('sales as sale')
            ->leftJoin('users as seller', 'seller.id', '=', 'sale.advisor_id')
            ->where('sale.origin_campaign_id', $campaignId)
            ->where('sale.contact_id', $contactId)
            ->orderByDesc('sale.closed_at')
            ->select('sale.*', 'seller.name as seller_name')
            ->get()->map(static function (object $sale): array {
                $data = (array) $sale;
                $data['items'] = DB::table('sale_items')->where('sale_id', $sale->id)->get()->map(static fn (object $item): array => (array) $item)->all();
                return $data;
            })->all();

        $canEdit = !in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true);
        $canAssign = $canEdit && (
            $this->access->canManage($campaign)
            || (string) ($participant->assigned_advisor_id ?? '') === $this->context->userId
            || (string) ($participant->owner_user_id ?? '') === $this->context->userId
        );

        return [
            'campaign' => $this->campaignSummary($campaign),
            'participant' => $this->participantArray($participant),
            'nutrition' => $nutrition,
            'measurements' => $measurements,
            'measurement_dashboard' => $measurementDashboard,
            'whatsapp_capture' => $whatsappCapture,
            'sales' => $sales,
            'collaborators' => $this->collaborators($campaignId),
            'can_edit' => $canEdit,
            'can_assign' => $canAssign,
            'can_view_full' => true,
        ];
    }

    public function collaborators(string $campaignId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $rows = DB::table('campaign_members as member')
            ->join('users as user', 'user.id', '=', 'member.user_id')
            ->where('member.campaign_id', $campaignId)
            ->where('member.status', 'ACTIVE')
            ->where('user.status', 'ACTIVE')
            ->whereNull('user.deleted_at')
            ->select('user.id', 'user.name', 'user.email', 'user.partner_id', 'member.member_role')
            ->orderByRaw("CASE WHEN user.id = ? THEN 0 ELSE 1 END", [$campaign->responsible_user_id])
            ->orderBy('user.name')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
        if ($campaign->responsible_user_id !== null && !array_filter($rows, fn (array $row): bool => (string) $row['id'] === (string) $campaign->responsible_user_id)) {
            $responsible = DB::table('users')->where('id', $campaign->responsible_user_id)->where('status', 'ACTIVE')->whereNull('deleted_at')->first();
            if ($responsible !== null) array_unshift($rows, [
                'id' => (string) $responsible->id,
                'name' => (string) $responsible->name,
                'email' => (string) $responsible->email,
                'partner_id' => (string) $responsible->partner_id,
                'member_role' => 'RESPONSIBLE',
            ]);
        }
        $devices = DB::table('campaign_device_availability as availability')
            ->join('personal_devices as device', 'device.id', '=', 'availability.personal_device_id')
            ->where('availability.campaign_id', $campaignId)->where('availability.status', 'ACTIVE')
            ->select('availability.owner_user_id', 'device.id', 'device.device_type', 'device.display_name', 'device.hardware_identifier', 'device.model')
            ->get()->groupBy('owner_user_id');
        foreach ($rows as &$row) {
            $owned = $devices->get((string) $row['id'], collect());
            $row['devices_available'] = $owned->map(static fn (object $d): array => [
                'id' => (string) $d->id, 'device_type' => (string) $d->device_type,
                'display_name' => (string) $d->display_name, 'hardware_identifier' => $d->hardware_identifier, 'model' => $d->model,
            ])->values()->all();
        }
        unset($row);
        return array_values($rows);
    }

    public function markContactStarted(string $campaignId, string $contactId): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $updated = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->whereNull('contact_started_at')->update([
            'contact_started_at' => now(),
            'contact_started_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ]);
        if ($updated > 0) $this->activity->record($campaignId, (string) $campaign->partner_id, 'CONTACT_STARTED', $contactId, [
            'channel' => 'WHATSAPP_APP',
            'actor_user_id' => $this->context->userId,
        ]);
        return $this->participantArray($this->participant($campaign, $contactId));
    }

    public function markResponded(string $campaignId, string $contactId): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $respondedChanged = DB::transaction(function () use ($campaignId, $contactId): bool {
            $row = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->lockForUpdate()->first();
            if ($row === null) throw new \RuntimeException('La persona no pertenece a este evento.', 404);
            $updates = [];
            if ($row->contact_started_at === null) {
                $updates['contact_started_at'] = now();
                $updates['contact_started_by_user_id'] = $this->context->userId;
            }
            $respondedChanged = $row->manual_responded_at === null;
            if ($respondedChanged) {
                $updates['manual_responded_at'] = now();
                $updates['manual_responded_by_user_id'] = $this->context->userId;
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('campaign_contacts')->where('id', $row->id)->update($updates);
            }
            return $respondedChanged;
        }, 3);
        if ($respondedChanged) $this->activity->record($campaignId, (string) $campaign->partner_id, 'WHATSAPP_RESPONSE_CONFIRMED', $contactId, ['source' => 'MANUAL']);
        return $this->participantArray($this->participant($campaign, $contactId));
    }

    public function updateCommercialStatus(string $campaignId, string $contactId, string $status): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $status = strtoupper(trim($status));
        $allowed = ['UNEXPLORED', 'INTERESTED', 'EVALUATING', 'NOT_INTERESTED_NOW', 'PURCHASE_COMPLETED'];
        if (!in_array($status, $allowed, true)) throw new \RuntimeException('Estado comercial inválido.', 422);
        if ($status === 'PURCHASE_COMPLETED' && !DB::table('sales')->where('origin_campaign_id', $campaignId)->where('contact_id', $contactId)->where('status', 'COMPLETED')->exists()) {
            throw new \RuntimeException('Compra realizada solo puede usarse cuando existe una venta completada.', 422);
        }
        $previous = (string) (DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->value('commercial_status') ?? 'UNEXPLORED');
        DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->update([
            'commercial_status' => $status,
            'commercial_status_updated_at' => now(),
            'commercial_status_updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ]);
        if ($previous !== $status) $this->activity->record($campaignId, (string) $campaign->partner_id, 'COMMERCIAL_STATUS_CHANGED', $contactId, [
            'from' => $previous, 'to' => $status, 'actor_user_id' => $this->context->userId,
        ]);
        return $this->participantArray($this->participant($campaign, $contactId));
    }

    public function updatePurchaseOutcome(string $campaignId, string $contactId, string $outcome, ?string $notes): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $outcome = strtoupper(trim($outcome));
        if (!in_array($outcome, ['UNKNOWN', 'NO_PURCHASE'], true)) {
            throw new \RuntimeException('Resultado de compra inválido.', 422);
        }
        $previous = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->value('purchase_outcome');
        DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->update([
            'purchase_outcome' => $outcome,
            'purchase_notes' => $notes !== null ? trim($notes) : null,
            'purchase_outcome_updated_at' => now(),
            'purchase_outcome_updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ]);
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'PURCHASE_OUTCOME_UPDATED', $contactId, [
            'from' => $previous, 'to' => $outcome, 'notes' => $notes,
        ]);
        return $this->participantArray($this->participant($campaign, $contactId));
    }

    public function assignOwner(string $campaignId, string $contactId, string $userId): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $participant = $this->participant($campaign, $contactId);
        $canAssign = $this->access->canManage($campaign)
            || (string) ($participant->assigned_advisor_id ?? '') === $this->context->userId
            || (string) ($participant->owner_user_id ?? '') === $this->context->userId;
        if (!$canAssign) throw new \RuntimeException('Solo el responsable actual o el organizador pueden reasignar este prospecto.', 403);
        $allowed = array_filter($this->collaborators($campaignId), fn (array $row): bool => (string) $row['id'] === $userId);
        if ($allowed === []) throw new \RuntimeException('El nuevo responsable debe ser colaborador activo del evento.', 422);
        $target = DB::table('users')->where('id', $userId)->where('status', 'ACTIVE')->whereNull('deleted_at')->first();
        if ($target === null || $target->partner_id === null) throw new \RuntimeException('El nuevo responsable no tiene una organización activa.', 422);
        $previous = $participant->assigned_advisor_id ?? $participant->owner_user_id ?? null;
        DB::transaction(function () use ($campaignId, $contactId, $userId, $target, $previous, $participant): void {
            DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->update([
                'assigned_advisor_id' => $userId,
                'owner_user_id' => $userId,
                'owner_partner_id' => (string) $target->partner_id,
                'updated_at' => now(),
            ]);
            DB::table('contacts')->where('id', $contactId)->update([
                'assigned_advisor_id' => $userId,
                'responsible_partner_id' => (string) $target->partner_id,
                'updated_at' => now(),
            ]);
            if (DB::getSchemaBuilder()->hasTable('campaign_assignment_history')) {
                DB::table('campaign_assignment_history')->insert([
                    'id' => 'CAH_'.strtoupper((string) Str::ulid()),
                    'campaign_id' => $campaignId,
                    'campaign_contact_id' => $participant->id,
                    'contact_id' => $contactId,
                    'from_user_id' => $previous,
                    'to_user_id' => $userId,
                    'changed_by_user_id' => $this->context->userId,
                    'reason' => 'Reasignación de responsable durante el evento.',
                    'created_at' => now(),
                ]);
            }
        }, 3);
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'PARTICIPANT_ASSIGNED', $contactId, [
            'previous_user_id' => $previous, 'assigned_by_user_id' => $this->context->userId, 'assigned_user_id' => $userId,
        ]);
        return $this->participantArray($this->participant($campaign, $contactId));
    }

    public function history(string $campaignId, string $contactId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $participant = $this->participant($campaign, $contactId);
        $this->assertParticipantVisible($campaign, $participant);
        $events = [[
            'type' => 'PROSPECT_CREATED',
            'title' => 'Prospecto creado',
            'occurred_at' => $participant->created_at,
            'details' => ['event' => (string) $campaign->name, 'captured_by' => $participant->registered_by_name ?? null],
        ]];
        foreach ($this->activity->list($campaignId, 300) as $row) {
            if ((string) ($row['contact_id'] ?? '') !== $contactId) continue;
            $events[] = [
                'type' => (string) $row['activity_type'],
                'title' => $this->historyTitle((string) $row['activity_type']),
                'occurred_at' => $row['created_at'],
                'details' => $row['metadata'] ?? [],
                'actor_name' => $row['actor_name'] ?? null,
            ];
        }
        if (DB::getSchemaBuilder()->hasTable('campaign_assignment_history')) {
            foreach (DB::table('campaign_assignment_history as h')
                ->leftJoin('users as from_user', 'from_user.id', '=', 'h.from_user_id')
                ->leftJoin('users as to_user', 'to_user.id', '=', 'h.to_user_id')
                ->where('h.campaign_id', $campaignId)->where('h.contact_id', $contactId)
                ->select('h.*', 'from_user.name as from_name', 'to_user.name as to_name')->get() as $row) {
                $events[] = [
                    'type' => 'RESPONSIBLE_ASSIGNED', 'title' => 'Responsable actualizado', 'occurred_at' => $row->created_at,
                    'details' => ['from' => $row->from_name, 'to' => $row->to_name, 'reason' => $row->reason],
                ];
            }
        }
        usort($events, static fn (array $a, array $b): int => strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? '')));
        return $events;
    }

    public function participantMeasurementDashboard(string $campaignId, string $contactId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $participant = $this->participant($campaign, $contactId);
        $this->assertParticipantVisible($campaign, $participant);
        return $this->measurementPresentation->present(
            $participant,
            $this->participantMeasurements($campaignId, $contactId),
        );
    }

    public function updateNutritionProfile(string $campaignId, string $contactId, array $data): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $result = $this->access->runInCampaign($campaign, function () use ($contactId, $data): array {
            return app(NutritionEngineService::class)->updateProfile($contactId, $data);
        });
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'NUTRITION_PROFILE_UPDATED', $contactId);
        return $result;
    }

    public function generateDiet(string $campaignId, string $contactId, ?string $measurementId, ?string $templateId, ?string $idempotencyKey = null): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $measurementQuery = DB::table('measurements')
            ->where('campaign_id', $campaignId)
            ->where('contact_id', $contactId);
        if ($measurementId !== null && trim($measurementId) !== '') {
            $measurementQuery->where('id', trim($measurementId));
        } else {
            $measurementQuery->orderByDesc('timestamp_local');
        }
        $measurement = $measurementQuery->first();
        if ($measurement === null) {
            throw new \RuntimeException('La persona necesita un pesaje realizado dentro de este evento.', 422);
        }
        $measurementId = (string) $measurement->id;

        $generate = function () use ($campaign, $contactId, $measurementId, $templateId): array {
            $plan = $this->access->runInCampaign($campaign, function () use ($contactId, $measurementId, $templateId): array {
                return app(NutritionPlanEngineService::class)->generateForContact($contactId, $measurementId, $templateId);
            });
            return [
                'plan' => $plan,
                'participant' => $this->participantArray($this->participant($campaign, $contactId)),
            ];
        };

        if ($idempotencyKey === null || trim($idempotencyKey) === '') return $generate();
        $partnerId = (string) $campaign->partner_id;
        $dietIdempotencyKeyHash = hash('sha256', $partnerId.'|campaign_diet|'.$campaignId.'|'.$contactId.'|'.trim($idempotencyKey));
        return DB::transaction(function () use ($dietIdempotencyKeyHash, $partnerId, $campaignId, $contactId, $measurementId, $templateId, $generate, $campaign): array {
            DB::table('command_idempotency')->insertOrIgnore([
                'key_hash' => $dietIdempotencyKeyHash,
                'partner_id' => $partnerId,
                'type' => 'diet_idempotency',
                'command_hash' => hash('sha256', json_encode([$campaignId, $contactId, $measurementId, $templateId], JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);
            $record = DB::table('command_idempotency')->where('key_hash', $dietIdempotencyKeyHash)->lockForUpdate()->first();
            if ($record !== null && !empty($record->resource_id)) {
                $plan = DB::table('nutrition_plan_versions')->where('id', (string) $record->resource_id)->first();
                if ($plan !== null) {
                    return [
                        'plan' => (array) $plan,
                        'participant' => $this->participantArray($this->participant($campaign, $contactId)),
                        'already_processed' => true,
                    ];
                }
            }
            $result = $generate();
            $planId = (string) ($result['plan']['id'] ?? '');
            if ($planId !== '') {
                DB::table('command_idempotency')->where('key_hash', $dietIdempotencyKeyHash)->update(['resource_id' => $planId]);
            }
            $result['already_processed'] = false;
            return $result;
        }, 3);
    }

    public function planAccess(string $campaignId, string $contactId, string $planId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $participant = $this->participant($campaign, $contactId);
        $this->assertParticipantVisible($campaign, $participant);
        $plan = DB::table('nutrition_plan_versions as plan')
            ->join('measurements as measurement', 'measurement.id', '=', 'plan.measurement_id')
            ->where('plan.id', $planId)
            ->where('plan.contact_id', $contactId)
            ->where('measurement.campaign_id', $campaignId)
            ->select('plan.*')
            ->first();
        if ($plan === null) throw new \RuntimeException('Plan alimentario no encontrado dentro del evento.', 404);
        // Después del cierre el plan puede haberse transferido a la organización propietaria.
        $pdfScope = clone $campaign;
        $pdfScope->partner_id = (string) $plan->partner_id;
        return ['campaign' => $pdfScope, 'participant' => $participant, 'plan' => $plan];
    }

    public function sendPlanWhatsapp(string $campaignId, string $contactId, string $planId, string $format = 'pdf'): array
    {
        $scope = $this->planAccess($campaignId, $contactId, $planId);
        $format = strtolower(trim($format));
        if (!in_array($format, ['pdf', 'images', 'both'], true)) {
            throw new \RuntimeException('Formato de entrega no soportado.', 422);
        }
        return $this->access->runInCampaign(
            $scope['campaign'],
            fn (): array => app(NutritionPlanDeliveryService::class)->sendDirect($planId, $format),
        );
    }

    public function products(string $campaignId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $locationId = (string) $campaign->location_id;
        return DB::table('products as product')
            ->leftJoin('inventory_balances as balance', function ($join) use ($campaign, $locationId): void {
                $join->on('balance.product_id', '=', 'product.id')
                    ->where('balance.partner_id', '=', (string) $campaign->partner_id)
                    ->where('balance.location_id', '=', $locationId);
            })
            ->where('product.partner_id', (string) $campaign->partner_id)
            ->where('product.active', true)
            ->whereNull('product.deleted_at')
            ->select('product.id', 'product.sku', 'product.name', 'product.category', 'product.base_price_minor', 'product.currency', DB::raw('COALESCE(balance.quantity, 0) as stock'))
            ->orderBy('product.name')->get()->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'category' => $row->category,
                'base_price_minor' => (int) $row->base_price_minor,
                'currency' => (string) $row->currency,
                'stock' => (int) $row->stock,
            ])->all();
    }

    public function recordSale(string $campaignId, string $contactId, array $data, string $idempotencyKey): array
    {
        $campaign = $this->activeCampaignForParticipant($campaignId, $contactId);
        $sale = $this->access->runInCampaign($campaign, function () use ($campaign, $contactId, $data, $idempotencyKey) {
            return app(SaleProcessingService::class)->processSale(
                $contactId,
                (string) $campaign->location_id,
                (string) $data['payment_method'],
                (array) $data['cart'],
                $idempotencyKey,
            );
        });
        $saleId = $sale->getId();
        // La venta se atribuye al evento abierto, incluso si el contacto ya existía
        // y conservaba un origin_campaign_id de una jornada anterior.
        DB::table('sales')->where('id', $saleId)->update([
            'origin_campaign_id' => $campaignId,
            'updated_at' => now(),
        ]);
        DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->update([
            'sale_id' => $saleId,
            'attention_status' => DB::raw("CASE WHEN nutrition_plan_id IS NULL THEN 'SALE_RECORDED' ELSE 'DIET_AND_SALE' END"),
            'commercial_status' => 'PURCHASE_COMPLETED',
            'purchase_outcome' => 'PURCHASED',
            'purchase_outcome_updated_at' => now(),
            'purchase_outcome_updated_by_user_id' => $this->context->userId,
            'commercial_status_updated_at' => now(),
            'commercial_status_updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ]);
        $this->activity->record($campaignId, (string) $campaign->partner_id, 'SALE_RECORDED', $contactId, [
            'sale_id' => $saleId,
            'revenue_minor' => $sale->getTotalRevenueMinor(),
            'seller_user_id' => $this->context->userId,
        ]);
        return [
            'sale_id' => $saleId,
            'revenue_minor' => $sale->getTotalRevenueMinor(),
            'profit_minor' => $sale->getTotalProfitMinor(),
            'currency' => $sale->getCurrencySnapshot(),
        ];
    }

    public function closePreview(string $campaignId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $this->access->assertManage($campaign);
        return $this->closureData($campaign);
    }

    public function close(string $campaignId, ?string $notes, array $confirmedProducts = []): array
    {
        return DB::transaction(function () use ($campaignId, $notes, $confirmedProducts): array {
            $campaign = $this->access->campaign($campaignId, true);
            $this->access->assertManage($campaign);
            if ((string) $campaign->status === 'CLOSED') return $this->closureData($campaign);
            if ((string) $campaign->status === 'CANCELLED') throw new \RuntimeException('Un evento cancelado no puede cerrarse.', 409);

            $data = $this->closureData($campaign);
            if ($data['ownership_conflicts'] !== []) {
                $names = implode(', ', array_column($data['ownership_conflicts'], 'contact_name'));
                throw new \RuntimeException('No se puede cerrar: ya existe el mismo WhatsApp en la organización de destino para '.$names.'.', 409);
            }

            $transferred = [];
            $participants = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->lockForUpdate()->get();
            foreach ($participants as $participant) {
                if ($participant->owner_user_id !== null && $participant->owner_partner_id !== null) {
                    $transferred[] = $this->transferOwnedContact($campaign, $participant);
                    $disposition = 'ASSIGNED_RESPONSIBLE';
                } else {
                    $disposition = 'UNASSIGNED';
                }
                DB::table('campaign_contacts')->where('id', $participant->id)->update([
                    'closure_disposition' => $disposition,
                    'closed_at' => now(),
                    'attention_status' => $participant->owner_user_id !== null ? 'CLOSED_ASSIGNED' : 'CLOSED_UNASSIGNED',
                    'updated_at' => now(),
                ]);
            }

            $computedProducts = collect($data['products'])->keyBy('product_id');
            $confirmed = [];
            foreach ($confirmedProducts as $item) {
                $productId = trim((string) ($item['product_id'] ?? ''));
                if ($productId === '' || !$computedProducts->has($productId)) continue;
                $row = $computedProducts->get($productId);
                $confirmed[] = [
                    'product_id' => $productId,
                    'product_name' => $row['product_name'],
                    'calculated_quantity' => (int) $row['quantity'],
                    'confirmed_quantity' => max(0, (int) ($item['confirmed_quantity'] ?? $row['quantity'])),
                    'revenue_minor' => (int) $row['revenue_minor'],
                ];
            }
            if ($confirmed === []) {
                $confirmed = array_map(static fn (array $row): array => [
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'calculated_quantity' => (int) $row['quantity'],
                    'confirmed_quantity' => (int) $row['quantity'],
                    'revenue_minor' => (int) $row['revenue_minor'],
                ], $data['products']);
            }

            $snapshot = [
                'closed_at' => now()->toAtomString(),
                'closed_by_user_id' => $this->context->userId,
                'totals' => $data['totals'],
                'member_stats' => $data['member_stats'],
                'products' => $confirmed,
                'contacts_transferred' => $transferred,
                'notes' => $this->nullable($notes),
            ];
            DB::table('campaigns')->where('id', $campaignId)->update([
                'status' => 'CLOSED',
                'closing_notes' => $this->nullable($notes),
                'closure_snapshot' => SqlValue::encode($snapshot),
                'closed_by_user_id' => $this->context->userId,
                'closed_at' => now(),
                'ends_at' => $campaign->ends_at ?? now(),
                'updated_at' => now(),
            ]);
            app(\App\Modules\Devices\Services\PersonalDeviceService::class)->releaseCampaign($campaignId);
            if (DB::getSchemaBuilder()->hasTable('campaign_join_tokens')) DB::table('campaign_join_tokens')->where('campaign_id', $campaignId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            if (DB::getSchemaBuilder()->hasTable('campaign_invitations')) DB::table('campaign_invitations')->where('campaign_id', $campaignId)->where('status', 'PENDING')->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
            $this->activity->record($campaignId, (string) $campaign->partner_id, 'EVENT_CLOSED', null, [
                'notes' => $this->nullable($notes),
                'transferred_count' => count($transferred),
            ]);
            return $snapshot;
        }, 3);
    }

    public function activity(string $campaignId): array
    {
        $campaign = $this->access->campaign($campaignId);
        $rows = $this->activity->list($campaignId, 200);
        if ((string) $campaign->status === 'CLOSED' && !$this->context->isSuperAdmin()) {
            $rows = array_values(array_filter($rows, fn (array $row): bool =>
                (string) ($row['actor_user_id'] ?? '') === $this->context->userId
                || (string) ($row['activity_type'] ?? '') === 'EVENT_CLOSED'
            ));
        }
        return $rows;
    }

    private function closureData(object $campaign): array
    {
        $campaignId = (string) $campaign->id;
        $participants = DB::table('campaign_contacts as participant')
            ->join('contacts as contact', 'contact.id', '=', 'participant.contact_id')
            ->where('participant.campaign_id', $campaignId)
            ->select('participant.*', 'contact.name as contact_name', 'contact.whatsapp_normalized')
            ->get();
        $sales = DB::table('sales')->where('origin_campaign_id', $campaignId)->where('status', 'COMPLETED');
        $products = DB::table('sales as sale')
            ->join('sale_items as item', 'item.sale_id', '=', 'sale.id')
            ->where('sale.origin_campaign_id', $campaignId)
            ->where('sale.status', 'COMPLETED')
            ->groupBy('item.product_id', 'item.product_name_snapshot')
            ->select('item.product_id', 'item.product_name_snapshot as product_name', DB::raw('SUM(item.quantity) as quantity'), DB::raw('SUM(item.subtotal_revenue_minor) as revenue_minor'))
            ->orderBy('item.product_name_snapshot')
            ->get()->map(static fn (object $row): array => [
                'product_id' => (string) $row->product_id,
                'product_name' => (string) $row->product_name,
                'quantity' => (int) $row->quantity,
                'revenue_minor' => (int) $row->revenue_minor,
            ])->all();

        $members = DB::table('campaign_members as member')
            ->join('users as user', 'user.id', '=', 'member.user_id')
            ->where('member.campaign_id', $campaignId)
            ->where('member.status', 'ACTIVE')
            ->select('member.user_id', 'member.member_partner_id', 'member.member_role', 'user.name', 'user.email')
            ->get();
        $memberStats = $members->map(function (object $member) use ($campaignId): array {
            $registered = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('registered_by_user_id', $member->user_id)->count();
            $diets = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('diet_generated_by_user_id', $member->user_id)->count();
            $owned = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('owner_user_id', $member->user_id)->count();
            $measurements = Schema::hasColumn('measurements', 'captured_by_user_id')
                ? DB::table('measurements')->where('campaign_id', $campaignId)->where('captured_by_user_id', $member->user_id)->count()
                : 0;
            $saleQuery = DB::table('sales')->where('origin_campaign_id', $campaignId)->where('advisor_id', $member->user_id)->where('status', 'COMPLETED');
            return [
                'user_id' => (string) $member->user_id,
                'name' => (string) $member->name,
                'email' => (string) $member->email,
                'member_role' => (string) $member->member_role,
                'registered' => $registered,
                'measurements' => $measurements,
                'diets' => $diets,
                'contacts_owned' => $owned,
                'sales' => (clone $saleQuery)->count(),
                'revenue_minor' => (int) (clone $saleQuery)->sum('total_revenue_minor'),
            ];
        })->all();

        $conflicts = [];
        foreach ($participants as $participant) {
            if ($participant->owner_partner_id === null || (string) $participant->owner_partner_id === (string) $campaign->partner_id) continue;
            $duplicate = DB::table('contacts')
                ->where('partner_id', (string) $participant->owner_partner_id)
                ->where('whatsapp_normalized', (string) $participant->whatsapp_normalized)
                ->where('id', '<>', (string) $participant->contact_id)
                ->whereNull('deleted_at')->first();
            if ($duplicate !== null) {
                $conflicts[] = [
                    'contact_id' => (string) $participant->contact_id,
                    'contact_name' => (string) $participant->contact_name,
                    'destination_contact_id' => (string) $duplicate->id,
                    'owner_partner_id' => (string) $participant->owner_partner_id,
                ];
            }
        }

        return [
            'campaign_id' => $campaignId,
            'status' => (string) $campaign->status,
            'display_status' => (string) $campaign->status === 'CLOSED' ? 'FINISHED' : (string) $campaign->status,
            'closing_notes' => $campaign->closing_notes ?? null,
            'totals' => [
                'participants' => $participants->count(),
                'registered_contacts' => $participants->where('is_new_contact', 1)->count(),
                'measurements' => DB::table('measurements')->where('campaign_id', $campaignId)->count(),
                'diets_generated' => $participants->whereNotNull('nutrition_plan_id')->count(),
                'contacts_assigned' => $participants->whereNotNull('owner_user_id')->count(),
                'without_diet' => $participants->whereNull('nutrition_plan_id')->count(),
                'sales' => (clone $sales)->count(),
                'revenue_minor' => (int) (clone $sales)->sum('total_revenue_minor'),
                'products_sold' => array_sum(array_column($products, 'quantity')),
            ],
            'products' => $products,
            'member_stats' => $memberStats,
            'without_diet' => $participants->whereNull('nutrition_plan_id')->map(static fn (object $row): array => [
                'contact_id' => (string) $row->contact_id,
                'contact_name' => (string) $row->contact_name,
            ])->values()->all(),
            'ownership_conflicts' => $conflicts,
            'closure_snapshot' => SqlValue::json($campaign->closure_snapshot ?? null),
        ];
    }

    private function transferOwnedContact(object $campaign, object $participant): array
    {
        $contact = DB::table('contacts')->where('id', $participant->contact_id)->lockForUpdate()->first();
        if ($contact === null) return [];
        $fromPartner = (string) $contact->partner_id;
        $toPartner = (string) $participant->owner_partner_id;
        $ownerUser = (string) $participant->owner_user_id;
        if ($fromPartner === $toPartner) {
            DB::table('contacts')->where('id', $contact->id)->update([
                'assigned_advisor_id' => $ownerUser,
                'responsible_partner_id' => $toPartner,
                'updated_at' => now(),
            ]);
            return ['contact_id' => (string) $contact->id, 'from_partner_id' => $fromPartner, 'to_partner_id' => $toPartner, 'moved' => false];
        }

        $tables = [
            'measurements', 'crm_measurement_processing', 'tasks', 'timeline_activities', 'sla_alerts',
            'whatsapp_messages', 'workflow_executions', 'health_data_consents', 'whatsapp_contact_links',
            'whatsapp_inbound_messages', 'personal_reports', 'nutrition_profiles', 'nutrition_engine_runs',
            'nutrition_plan_versions', 'nutrition_plan_feedback', 'nutrition_recommendations',
            'nutrition_followup_members', 'nutrition_followup_stage_history', 'nutrition_followup_messages',
        ];
        $transferredTables = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'contact_id') || !Schema::hasColumn($table, 'partner_id')) continue;
            $count = DB::table($table)->where('contact_id', $contact->id)->where('partner_id', $fromPartner)->update(['partner_id' => $toPartner]);
            if ($count > 0) $transferredTables[$table] = $count;
        }

        DB::table('contacts')->where('id', $contact->id)->update([
            'partner_id' => $toPartner,
            'responsible_partner_id' => $toPartner,
            'assigned_advisor_id' => $ownerUser,
            'updated_at' => now(),
        ]);

        $saleSummary = DB::table('sales')
            ->where('origin_campaign_id', (string) $campaign->id)
            ->where('contact_id', (string) $contact->id)
            ->where('status', 'COMPLETED')
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_revenue_minor), 0) as revenue_minor')
            ->first();
        DB::table('timeline_activities')->insert([
            'id' => 'ACT_'.strtoupper((string) Str::ulid()),
            'partner_id' => $toPartner,
            'contact_id' => (string) $contact->id,
            'type' => 'CAMPAIGN_CONTACT_TRANSFERRED',
            'description' => 'Contacto recibido del evento '.(string) $campaign->name.' por asignación de responsabilidad.',
            'metadata' => SqlValue::encode([
                'campaign_id' => (string) $campaign->id,
                'owner_user_id' => $ownerUser,
                'sales_count' => (int) ($saleSummary->sales_count ?? 0),
                'event_revenue_minor' => (int) ($saleSummary->revenue_minor ?? 0),
            ]),
            'actor_id' => $this->context->userId,
            'created_at' => now(),
        ]);

        $movement = $this->movements->record(
            '561',
            $toPartner,
            'CONTACT',
            (string) $contact->id,
            [
                'campaign_id' => (string) $campaign->id,
                'registered_by_partner_id' => $contact->registered_by_partner_id ?? $fromPartner,
                'previous_responsible_partner_id' => $fromPartner,
                'responsible_partner_id' => $toPartner,
                'owner_user_id' => $ownerUser,
                'transferred_tables' => $transferredTables,
            ],
            'Asignación del prospecto al responsable actual al finalizar el evento.',
            'CAMPAIGN',
            (string) $campaign->id,
        );
        if (Schema::hasTable('contact_partner_transfers')) {
            DB::table('contact_partner_transfers')->insert([
                'id' => 'CPT_'.strtoupper((string) Str::ulid()),
                'contact_id' => (string) $contact->id,
                'registered_by_partner_id' => (string) ($contact->registered_by_partner_id ?? $fromPartner),
                'previous_responsible_partner_id' => $fromPartner,
                'responsible_partner_id' => $toPartner,
                'movement_id' => $movement['id'],
                'changed_by_user_id' => $this->context->userId,
                'reason' => 'Responsabilidad asignada durante el evento '.(string) $campaign->name.'.',
                'transferred_tables' => SqlValue::encode($transferredTables),
                'transferred_at' => now(),
                'created_at' => now(),
            ]);
        }
        return ['contact_id' => (string) $contact->id, 'from_partner_id' => $fromPartner, 'to_partner_id' => $toPartner, 'moved' => true];
    }

    private function participantMeasurements(string $campaignId, string $contactId): array
    {
        return DB::table('measurements as measurement')
            ->leftJoin('devices as device', 'device.id', '=', 'measurement.device_id')
            ->leftJoin('locations as location', 'location.id', '=', 'measurement.location_id')
            ->leftJoin('campaigns as campaign', 'campaign.id', '=', 'measurement.campaign_id')
            ->leftJoin('users as captured', 'captured.id', '=', 'measurement.captured_by_user_id')
            ->where('measurement.campaign_id', $campaignId)
            ->where('measurement.contact_id', $contactId)
            ->orderByDesc('measurement.timestamp_local')
            ->limit(100)
            ->select(
                'measurement.id', 'measurement.device_id', 'measurement.location_id', 'measurement.campaign_id',
                'measurement.captured_by_user_id', 'measurement.timestamp_local', 'measurement.received_at',
                'measurement.biometrics_raw', 'measurement.biometrics_calculated',
                'device.mac_address as device_name', 'location.name as location_name',
                'campaign.name as campaign_name', 'captured.name as captured_by_name',
            )
            ->get()->map(static function (object $row): array {
                return [
                    'id' => (string) $row->id,
                    'device_id' => $row->device_id,
                    'device_name' => $row->device_name,
                    'location_id' => $row->location_id,
                    'location_name' => $row->location_name,
                    'campaign_id' => $row->campaign_id,
                    'campaign_name' => $row->campaign_name,
                    'captured_by_user_id' => $row->captured_by_user_id,
                    'captured_by_name' => $row->captured_by_name,
                    'timestamp_local' => $row->timestamp_local,
                    'received_at' => $row->received_at,
                    'biometrics_raw' => SqlValue::json($row->biometrics_raw ?? null),
                    'biometrics_calculated' => SqlValue::json($row->biometrics_calculated ?? null),
                ];
            })->all();
    }

    private function participant(object $campaign, string $contactId): object
    {
        $row = DB::table('campaign_contacts as participant')
            ->join('contacts as contact', 'contact.id', '=', 'participant.contact_id')
            ->leftJoin('users as registrar', 'registrar.id', '=', 'participant.registered_by_user_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'participant.owner_user_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'participant.assigned_advisor_id')
            ->where('participant.campaign_id', (string) $campaign->id)
            ->where('participant.contact_id', $contactId)
            ->select('participant.*', 'contact.partner_id as contact_partner_id', 'contact.name', 'contact.whatsapp_normalized as whatsapp', 'contact.whatsapp_status', 'contact.whatsapp_verified_at', 'contact.public_code', 'contact.age', 'contact.gender', 'contact.height_cm', 'contact.lifecycle_stage', 'contact.sales_stage', 'registrar.name as registered_by_name', 'owner.name as owner_name', 'assignee.name as assigned_advisor_name')
            ->first();
        if ($row === null) throw new \RuntimeException('La persona no pertenece a este evento.', 404);
        return $row;
    }

    private function activeCampaignForParticipant(string $campaignId, string $contactId): object
    {
        $campaign = $this->access->campaign($campaignId);
        if (in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('El evento está cerrado y ya no permite cambios.', 409);
        }
        $participant = $this->participant($campaign, $contactId);
        $this->assertParticipantVisible($campaign, $participant);
        return $campaign;
    }

    private function assertParticipantVisible(object $campaign, object $participant): void
    {
        if ((string) $campaign->status !== 'CLOSED' || $this->context->isSuperAdmin()) return;
        $ownsParticipant = (string) ($participant->owner_user_id ?? '') === $this->context->userId;
        $isAssignedAdvisor = (string) ($participant->assigned_advisor_id ?? '') === $this->context->userId;
        if (!$ownsParticipant && !$isAssignedAdvisor) {
            throw new \RuntimeException('Después del cierre solo puedes abrir los contactos que quedaron a tu cargo.', 403);
        }
    }

    private function participantArray(object $row): array
    {
        $deviceUsed = null;
        if (!empty($row->last_measurement_id)) {
            $device = DB::table('measurements as measurement')
                ->join('devices as device', 'device.id', '=', 'measurement.device_id')
                ->leftJoin('personal_devices as personal', 'personal.linked_device_id', '=', 'device.id')
                ->leftJoin('users as owner', 'owner.id', '=', 'personal.owner_user_id')
                ->where('measurement.id', $row->last_measurement_id)
                ->select('device.id as technical_device_id', 'device.mac_address', 'personal.id as personal_device_id', 'personal.display_name', 'personal.owner_user_id', 'owner.name as owner_name')
                ->first();
            if ($device !== null) $deviceUsed = [
                'technical_device_id' => $device->technical_device_id,
                'personal_device_id' => $device->personal_device_id,
                'display_name' => $device->display_name ?: 'Báscula Rhino',
                'hardware_identifier' => $device->mac_address,
                'owner_user_id' => $device->owner_user_id,
                'owner_name' => $device->owner_name,
            ];
        }
        return [
            'id' => $row->id,
            'campaign_id' => $row->campaign_id,
            'contact_id' => $row->contact_id,
            'contact_partner_id' => $row->contact_partner_id ?? null,
            'name' => $row->name,
            'whatsapp' => (($row->whatsapp_status ?? 'VERIFIED') === 'VERIFIED' && !str_starts_with((string) $row->whatsapp, 'PENDING_')) ? $row->whatsapp : null,
            'whatsapp_status' => $row->whatsapp_status ?? 'VERIFIED',
            'whatsapp_verified_at' => $row->whatsapp_verified_at ?? null,
            'whatsapp_capture_status' => $row->whatsapp_capture_status ?? (($row->whatsapp_status ?? 'VERIFIED') === 'VERIFIED' ? 'CONFIRMED' : 'PENDING'),
            'public_code' => $row->public_code,
            'age' => $row->age ?? null,
            'gender' => $row->gender ?? null,
            'height_cm' => $row->height_cm !== null ? (float) $row->height_cm : null,
            'registered_by_user_id' => $row->registered_by_user_id,
            'registered_by_partner_id' => $row->registered_by_partner_id ?? null,
            'registered_by_name' => $row->registered_by_name ?? null,
            'diet_generated_by_user_id' => $row->diet_generated_by_user_id ?? null,
            'diet_generated_by_partner_id' => $row->diet_generated_by_partner_id ?? null,
            'owner_user_id' => $row->owner_user_id ?? null,
            'owner_partner_id' => $row->owner_partner_id ?? null,
            'owner_name' => $row->owner_name ?? null,
            'assigned_advisor_id' => $row->assigned_advisor_id ?? null,
            'assigned_advisor_name' => $row->assigned_advisor_name ?? null,
            'contact_started_at' => $row->contact_started_at ?? null,
            'manual_responded_at' => $row->manual_responded_at ?? null,
            'commercial_status' => $row->commercial_status ?? 'UNEXPLORED',
            'nutrition_plan_id' => $row->nutrition_plan_id ?? null,
            'nutrition_generated_at' => $row->nutrition_generated_at ?? null,
            'attention_status' => $row->attention_status ?? 'PENDING_DIET',
            'measurements_count' => (int) $row->measurements_count,
            'sale_id' => $row->sale_id,
            'last_seen_at' => $row->last_seen_at,
            'device_used' => $deviceUsed,
        ];
    }

    private function historyTitle(string $type): string
    {
        return match (strtoupper($type)) {
            'WHATSAPP_CONFIRMED' => 'WhatsApp confirmado',
            'DIET_GENERATED' => 'Dieta generada',
            'MANUAL_RESPONDED', 'RESPONDED' => 'Respuesta positiva registrada',
            'SALE_RECORDED', 'PURCHASE_COMPLETED' => 'Compra registrada',
            'PARTICIPANT_ASSIGNED' => 'Responsable actualizado',
            'CONTACT_STARTED' => 'Contacto realizado',
            default => ucfirst(strtolower(str_replace('_', ' ', $type))),
        };
    }

    private function campaignSummary(object $campaign): array
    {
        return [
            'id' => (string) $campaign->id,
            'name' => (string) $campaign->name,
            'status' => (string) $campaign->status,
            'partner_id' => (string) $campaign->partner_id,
            'location_id' => $campaign->location_id,
            'closing_notes' => $campaign->closing_notes ?? null,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
