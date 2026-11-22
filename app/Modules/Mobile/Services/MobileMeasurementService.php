<?php

namespace App\Modules\Mobile\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignWhatsappCaptureService;
use App\Modules\CRM\Services\ContactMeasurementIngestionService;
use App\Modules\IoT\DTOs\DeviceContextDTO;
use App\Modules\IoT\Domain\Entities\Measurement;
use App\Modules\IoT\Domain\Exceptions\DuplicateMeasurementException;
use App\Modules\IoT\Services\MeasurementIngestionService;
use App\Modules\NutritionCampaigns\Services\NutritionCampaignStageService;
use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Modules\PersonalReports\Services\ContactPublicCodeService;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

final class MobileMeasurementService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly Container $container,
        private readonly ContactMeasurementIngestionService $contacts,
        private readonly PartnerWorkModeService $workMode,
        private readonly ContactPublicCodeService $publicCodes,
        private readonly NutritionCampaignStageService $nutritionCampaignStages,
        private readonly CampaignWhatsappCaptureService $whatsappCapture,
    ) {}

    /** @return array<string,mixed> */
    public function store(array $payload): array
    {
        $partnerId = $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);

        $partner = DB::table('partners')->where('id', $partnerId)->whereNull('deleted_at')->first();
        if ($partner === null || (string) $partner->status !== 'ACTIVE') {
            throw new \RuntimeException('El socio está suspendido.', 403);
        }

        $selector = strtoupper(trim((string) $payload['device_id']));
        $device = DB::table('devices')
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($selector): void {
                $query->whereRaw('UPPER(id) = ?', [$selector])
                    ->orWhereRaw('UPPER(mac_address) = ?', [$selector]);
            })
            ->first();
        $requestedCampaignId = trim((string) ($payload['campaign_id'] ?? ''));
        if ($device === null && $requestedCampaignId !== '') {
            // RHINO 2.7.0: un dispositivo disponible en el Party puede pertenecer a otro socio.
            $device = DB::table('campaign_device_availability as availability')
                ->join('personal_devices as personal', 'personal.id', '=', 'availability.personal_device_id')
                ->join('devices as device', 'device.id', '=', 'personal.linked_device_id')
                ->join('campaigns as campaign', 'campaign.id', '=', 'availability.campaign_id')
                ->where('availability.campaign_id', $requestedCampaignId)
                ->where('availability.status', 'ACTIVE')
                ->where('campaign.status', 'ACTIVE')
                ->whereNull('campaign.deleted_at')
                ->whereNull('device.deleted_at')
                ->where(function ($query) use ($selector): void {
                    $query->whereRaw('UPPER(device.id) = ?', [$selector])
                        ->orWhereRaw('UPPER(device.mac_address) = ?', [$selector])
                        ->orWhereRaw('UPPER(personal.hardware_identifier) = ?', [$selector]);
                })
                ->select('device.*')
                ->first();
        }
        if ($device === null) {
            throw new \RuntimeException('La báscula no está registrada para este socio ni disponible en este evento.', 404);
        }
        if ((string) $device->status !== 'ACTIVE') {
            throw new \RuntimeException('La báscula está suspendida.', 403);
        }

        $location = DB::table('locations')->where('id', $device->location_id)->whereNull('deleted_at')->first();
        if ($location === null || (string) $location->status !== 'ACTIVE') {
            throw new \RuntimeException('La ubicación técnica de la báscula está suspendida.', 403);
        }

        $campaign = $this->resolveCampaign($partnerId, (string) $device->id, $payload['campaign_id'] ?? null);
        if ($campaign === null && (bool) config('rhino.mobile.require_active_campaign', true)) {
            throw new \RuntimeException('No hay un evento activo vinculado a esta báscula.', 409);
        }
        if ($campaign !== null) {
            $this->assertCampaignAccess($campaign);
            $payload['campaign_id'] = (string) $campaign->id;
        }

        $deviceContext = new DeviceContextDTO(
            (string) $device->id,
            (string) $device->mac_address,
            $partnerId,
            (string) $device->location_id,
        );
        $this->container->forgetInstance(DeviceContextDTO::class);
        $this->container->instance(DeviceContextDTO::class, $deviceContext);

        try {
            try {
                /** @var MeasurementIngestionService $ingestion */
                $ingestion = $this->container->make(MeasurementIngestionService::class);
                $measurement = $ingestion->ingest($payload);
            } catch (DuplicateMeasurementException) {
                $measurement = $this->existingMeasurement((string) $payload['id_medicion'], $partnerId);
                if ($campaign !== null) {
                    $storedCampaignId = DB::table('measurements')->where('id', $measurement->id)->value('campaign_id');
                    if ($storedCampaignId !== null && (string) $storedCampaignId !== (string) $campaign->id) {
                        throw new \RuntimeException('La medición ya fue registrada en otro evento.', 409);
                    }
                }
            }

            $processed = $this->contacts->process($measurement, (array) $payload['paciente'], $this->context);
            $contactId = (string) $processed['contact_id'];
            $isNew = (bool) ($processed['is_new_contact'] ?? false);
            $actualCampaignId = DB::table('measurements')->where('id', $measurement->id)->value('campaign_id');
            $actualCampaignId = $actualCampaignId !== null ? (string) $actualCampaignId : null;
            $isEventCapture = $actualCampaignId !== null;

            $measurementUpdates = [];
            if (DB::getSchemaBuilder()->hasColumn('measurements', 'captured_by_user_id')) {
                $measurementUpdates['captured_by_user_id'] = $this->context->userId;
            }
            if (DB::getSchemaBuilder()->hasColumn('measurements', 'captured_by_partner_id')) {
                $measurementUpdates['captured_by_partner_id'] = $partnerId;
            }
            if ($measurementUpdates !== []) {
                DB::table('measurements')->where('id', $measurement->id)->update($measurementUpdates);
            }

            $contact = DB::table('contacts')->where('id', $contactId)->where('partner_id', $partnerId)->first();
            $updates = [];
            $teamMode = $this->workMode->isTeamMode();
            if ($isNew && $contact !== null) {
                if ((string) $contact->lifecycle_stage === 'CONTACT') {
                    $updates['lifecycle_stage'] = 'PROSPECT';
                }
                $updates['generated_by_user_id'] = $this->context->userId;
                if (DB::getSchemaBuilder()->hasColumn('contacts', 'registered_by_partner_id')) {
                    $updates['registered_by_partner_id'] = $partnerId;
                }

                if ($isEventCapture) {
                    // RHINO 2.7.0: quien captura es el responsable inicial; una dieta no cambia esta asignación.
                    $updates['assigned_advisor_id'] = $this->context->userId;
                    if (DB::getSchemaBuilder()->hasColumn('contacts', 'responsible_partner_id')) {
                        $updates['responsible_partner_id'] = $partnerId;
                    }
                    $updates['origin_team_id'] = (new TeamAccessScope($this->context))->primaryTeamForUser($this->context->userId);
                } elseif ($teamMode) {
                    $teamId = (new TeamAccessScope($this->context))->primaryTeamForUser($this->context->userId);
                    if ($teamId !== null) {
                        $updates['origin_team_id'] = $teamId;
                    }
                } else {
                    $updates['origin_team_id'] = null;
                    $updates['assigned_advisor_id'] = $this->context->userId;
                }
            }

            if (!$isEventCapture && $contact !== null) {
                if ($teamMode && $contact->assigned_advisor_id === null && $this->context->role === 'ADVISOR') {
                    $updates['assigned_advisor_id'] = $this->context->userId;
                } elseif (!$teamMode) {
                    $updates['assigned_advisor_id'] = $this->context->userId;
                }
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('contacts')->where('id', $contactId)->update($updates);
            }

            if ($actualCampaignId !== null) {
                $participant = DB::table('campaign_contacts')
                    ->where('campaign_id', $actualCampaignId)
                    ->where('contact_id', $contactId)
                    ->first();
                if ($participant !== null) {
                    $participantUpdates = [
                        'registered_by_user_id' => $participant->registered_by_user_id ?? $this->context->userId,
                        'updated_at' => now(),
                    ];
                    if (DB::getSchemaBuilder()->hasColumn('campaign_contacts', 'registered_by_partner_id')) {
                        $participantUpdates['registered_by_partner_id'] = $participant->registered_by_partner_id ?? $partnerId;
                    }
                    if (DB::getSchemaBuilder()->hasColumn('campaign_contacts', 'owner_user_id') && empty($participant->owner_user_id)) {
                        $participantUpdates['owner_user_id'] = $this->context->userId;
                        $participantUpdates['owner_partner_id'] = $partnerId;
                    }
                    if (empty($participant->assigned_advisor_id)) {
                        $participantUpdates['assigned_advisor_id'] = $this->context->userId;
                    }
                    if (DB::getSchemaBuilder()->hasColumn('campaign_contacts', 'attention_status') && empty($participant->attention_status)) {
                        $participantUpdates['attention_status'] = 'PENDING_DIET';
                    }
                    DB::table('campaign_contacts')->where('id', $participant->id)->update($participantUpdates);
                }
            }

            $publicCode = $this->publicCodes->ensure($contactId);
            DB::table('health_data_consents')->updateOrInsert(
                ['contact_id' => $contactId, 'purpose' => 'MEASUREMENT_PROCESSING'],
                [
                    'partner_id' => $partnerId,
                    'status' => 'GRANTED',
                    'source' => 'ANDROID_KIOSK',
                    'evidence_text' => 'Consentimiento otorgado en la aplicación antes de vincular el pesaje al CRM.',
                    'evidence' => SqlValue::encode(['measurement_id' => $measurement->id, 'campaign_id' => $actualCampaignId]),
                    'granted_at' => now(),
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $this->markNutritionFollowUpReweighed(
                $partnerId,
                $contactId,
                (string) $measurement->id,
                $measurement->timestampLocal->format('Y-m-d H:i:s'),
            );

            $freshContact = DB::table('contacts')->where('id', $contactId)->first();
            $capture = $actualCampaignId !== null
                ? $this->whatsappCapture->ensure($actualCampaignId, $contactId)
                : null;
            $businessPhone = preg_replace('/\D+/', '', (string) config('rhino.personal_reports.whatsapp_business_phone')) ?? '';
            $optInMessage = 'Hola, mi código RHINO es '.$publicCode.'. Autorizo recibir mi informe de pesaje por este número. También deseo información sobre próximos eventos.';

            return [
                'measurement_id' => $measurement->id,
                'contact_id' => $contactId,
                'campaign_id' => $actualCampaignId,
                'is_new_contact' => $isNew,
                'already_processed' => (bool) ($processed['already_processed'] ?? false),
                'contact' => $freshContact === null ? null : [
                    'id' => (string) $freshContact->id,
                    'name' => (string) $freshContact->name,
                    'lifecycle_stage' => (string) $freshContact->lifecycle_stage,
                    'sales_stage' => (string) $freshContact->sales_stage,
                    'assigned_advisor_id' => $freshContact->assigned_advisor_id,
                    'public_code' => $publicCode,
                    'whatsapp_status' => $freshContact->whatsapp_status ?? 'VERIFIED',
                ],
                'whatsapp_capture' => $capture,
                'whatsapp_opt_in' => [
                    'available' => $businessPhone !== '',
                    'business_phone' => $businessPhone !== '' ? $businessPhone : null,
                    'message' => $optInMessage,
                    'url' => $businessPhone !== '' ? 'https://wa.me/'.$businessPhone.'?text='.rawurlencode($optInMessage) : null,
                ],
            ];
        } finally {
            $this->container->forgetInstance(DeviceContextDTO::class);
        }
    }

    private function markNutritionFollowUpReweighed(
        string $partnerId,
        string $contactId,
        string $measurementId,
        string $measuredAt,
    ): void {
        if (!DB::getSchemaBuilder()->hasTable('nutrition_followup_members')) {
            return;
        }

        $member = DB::table('nutrition_followup_members as member')
            ->join(
                'nutrition_followup_campaigns as campaign',
                'campaign.id',
                '=',
                'member.campaign_id'
            )
            ->where('member.partner_id', $partnerId)
            ->where('member.contact_id', $contactId)
            ->whereIn('campaign.status', ['READY', 'ACTIVE'])
            ->whereNull('campaign.deleted_at')
            ->where(function ($query) use ($measuredAt): void {
                $query->whereNull('member.plan_recommended_at')
                    ->orWhere('member.plan_recommended_at', '<', $measuredAt);
            })
            ->whereNotIn('member.stage', [
                'SALE_COMPLETED', 'OPTED_OUT', 'DECLINED',
            ])
            ->select('member.*')
            ->orderByDesc('member.plan_recommended_at')
            ->first();

        if ($member === null) {
            return;
        }

        $this->nutritionCampaignStages->transition(
            (string) $member->id,
            'REWEIGHED',
            'MOBILE_MEASUREMENT',
            $this->context->userId,
            'Nuevo pesaje registrado después de la alimentación recomendada.',
            [
                'last_measurement_id' => $measurementId,
                'last_measurement_at' => $measuredAt,
                'reweighed_at' => now(),
            ],
        );
    }

    private function resolveCampaign(string $partnerId, string $deviceId, mixed $requestedCampaignId): ?object
    {
        $requested = trim((string) ($requestedCampaignId ?? ''));
        if ($requested !== '') {
            $campaign = DB::table('campaigns')->where('id', $requested)->where('status', 'ACTIVE')->whereNull('deleted_at')->first();
            if ($campaign === null) return null;
            $legacy = DB::table('campaign_devices')->where('campaign_id', $requested)->where('device_id', $deviceId)->where('status', 'ACTIVE')->exists();
            $shared = DB::table('campaign_device_availability as availability')
                ->join('personal_devices as personal', 'personal.id', '=', 'availability.personal_device_id')
                ->where('availability.campaign_id', $requested)->where('availability.status', 'ACTIVE')
                ->where('personal.linked_device_id', $deviceId)->exists();
            return ($legacy || $shared) ? $campaign : null;
        }
        return DB::table('campaign_devices as cd')
            ->join('campaigns as c', 'c.id', '=', 'cd.campaign_id')
            ->where('cd.partner_id', $partnerId)->where('cd.device_id', $deviceId)
            ->where('cd.status', 'ACTIVE')->where('c.status', 'ACTIVE')->whereNull('c.deleted_at')
            ->select('c.*')->orderByDesc('c.activated_at')->first();
    }

    private function assertCampaignAccess(object $campaign): void
    {
        if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) {
            return;
        }
        if ((string) ($campaign->responsible_user_id ?? '') === $this->context->userId) {
            return;
        }
        if (DB::table('campaign_members')
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $this->context->userId)
            ->where('status', 'ACTIVE')
            ->exists()) {
            return;
        }
        if ($this->context->role === 'TEAM_LEADER' && $campaign->team_id !== null) {
            $scope = new TeamAccessScope($this->context);
            if (in_array((string) $campaign->team_id, $scope->teamIds(), true)) {
                return;
            }
        }

        throw new \RuntimeException('El evento no está asignado a este usuario.', 403);
    }

    private function existingMeasurement(string $measurementId, string $partnerId): Measurement
    {
        $row = DB::table('measurements')
            ->where('id', $measurementId)
            ->where('partner_id', $partnerId)
            ->first();
        if ($row === null) {
            throw new \RuntimeException('La medición duplicada pertenece a otro contexto.', 409);
        }

        return new Measurement(
            id: (string) $row->id,
            deviceId: (string) $row->device_id,
            partnerId: (string) $row->partner_id,
            locationId: (string) $row->location_id,
            contactId: $row->contact_id !== null ? (string) $row->contact_id : null,
            rawBiometrics: SqlValue::json($row->biometrics_raw),
            calculatedBiometrics: SqlValue::json($row->biometrics_calculated),
            timestampLocal: new DateTimeImmutable((string) $row->timestamp_local),
        );
    }
}
