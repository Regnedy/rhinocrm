<?php

namespace App\Modules\IoT\Services;

use App\Modules\IoT\DTOs\DeviceContextDTO;
use App\Modules\IoT\Domain\Entities\Measurement;
use App\Modules\IoT\Domain\Exceptions\DuplicateMeasurementException;
use App\Modules\Partners\Services\PlanLimitService;
use App\Modules\Movements\Services\MovementService;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class MeasurementIngestionService
{
    public function __construct(
        private readonly DeviceContextDTO $context,
        private readonly IntakePayloadCipher $intakeCipher,
        private readonly PlanLimitService $limits,
        private readonly MovementService $movements,
    ) {}

    public function ingest(array $payload): Measurement
    {
        if (isset($payload['device_id']) && $payload['device_id'] !== null && !in_array(
            strtoupper((string) $payload['device_id']),
            [strtoupper($this->context->deviceId), strtoupper($this->context->macAddress)],
            true,
        )) {
            throw new \InvalidArgumentException('device_id no coincide con el dispositivo autenticado.');
        }

        $measurement = new Measurement(
            id: (string) $payload['id_medicion'],
            deviceId: $this->context->deviceId,
            partnerId: $this->context->partnerId,
            locationId: $this->context->locationId,
            contactId: null,
            rawBiometrics: (array) $payload['resultados']['crudos'],
            calculatedBiometrics: (array) $payload['resultados']['calculados'],
            timestampLocal: new DateTimeImmutable((string) $payload['timestamp_local']),
        );

        $billingPeriod = (new DateTimeImmutable())->format('Y-m');
        $measurementLimit = $this->limits->measurementLimit($measurement->partnerId);
        $eventId = 'EVT_IOT_'.$measurement->id;
        $encryptedPatientData = $this->intakeCipher->encrypt((array) $payload['paciente']);
        $requestedCampaignId = isset($payload['campaign_id']) && trim((string) $payload['campaign_id']) !== ''
            ? trim((string) $payload['campaign_id'])
            : null;

        DB::transaction(function () use ($measurement, $billingPeriod, $measurementLimit, $eventId, $encryptedPatientData, $requestedCampaignId): void {
            if (DB::table('measurements')->where('id', $measurement->id)->lockForUpdate()->exists()) {
                throw new DuplicateMeasurementException($measurement->id);
            }
            $device = DB::table('devices')->where('id', $measurement->deviceId)->lockForUpdate()->first();
            $sharedInRequestedCampaign = false;
            if ($device !== null && $requestedCampaignId !== null && (string) $device->partner_id !== $measurement->partnerId) {
                $sharedInRequestedCampaign = DB::table('campaign_device_availability as availability')
                    ->join('personal_devices as personal', 'personal.id', '=', 'availability.personal_device_id')
                    ->join('campaigns as campaign', 'campaign.id', '=', 'availability.campaign_id')
                    ->where('availability.campaign_id', $requestedCampaignId)
                    ->where('availability.status', 'ACTIVE')
                    ->where('personal.linked_device_id', $measurement->deviceId)
                    ->where('campaign.status', 'ACTIVE')
                    ->whereNull('campaign.deleted_at')
                    ->exists();
            }
            if ($device === null || ((string) $device->partner_id !== $measurement->partnerId && !$sharedInRequestedCampaign) || (string) $device->status !== 'ACTIVE' || $device->deleted_at !== null) {
                throw new \RuntimeException('Dispositivo inconsistente, suspendido o no disponible en este evento.', 409);
            }
            $partner = DB::table('partners')->where('id', $measurement->partnerId)->first();
            if ($partner === null || (string) $partner->status !== 'ACTIVE' || $partner->deleted_at !== null) {
                throw new \RuntimeException('El socio propietario está suspendido.', 403);
            }

            if ($requestedCampaignId !== null) {
                $campaign = DB::table('campaigns')->where('id', $requestedCampaignId)->where('status', 'ACTIVE')->whereNull('deleted_at')->first();
                $legacyLinked = DB::table('campaign_devices')->where('campaign_id', $requestedCampaignId)
                    ->where('device_id', $measurement->deviceId)->where('status', 'ACTIVE')->exists();
                $sharedLinked = DB::table('campaign_device_availability as availability')
                    ->join('personal_devices as personal', 'personal.id', '=', 'availability.personal_device_id')
                    ->where('availability.campaign_id', $requestedCampaignId)->where('availability.status', 'ACTIVE')
                    ->where('personal.linked_device_id', $measurement->deviceId)->exists();
                if ($campaign === null || (!$legacyLinked && !$sharedLinked)) {
                    throw new \RuntimeException('El evento solicitado no está activo o no está vinculado a esta báscula.', 409);
                }
                $campaignId = $requestedCampaignId;
            } else {
                $campaignId = DB::table('campaign_devices as cd')
                    ->join('campaigns as c', 'c.id', '=', 'cd.campaign_id')
                    ->where('cd.device_id', $measurement->deviceId)
                    ->where('cd.partner_id', $measurement->partnerId)
                    ->where('cd.status', 'ACTIVE')->where('c.status', 'ACTIVE')->whereNull('c.deleted_at')
                    ->orderByDesc('c.activated_at')->value('c.id');
                $campaignId = $campaignId !== null ? (string) $campaignId : null;
            }

            $usage = DB::table('partner_usage')->where('partner_id', $measurement->partnerId)->where('period', $billingPeriod)->lockForUpdate()->first();
            $current = $usage ? (int) $usage->measurement_count : 0;
            if ($current >= $measurementLimit) {
                throw new \RuntimeException('El plan alcanzó el límite mensual de mediciones.', 429);
            }

            DB::table('measurements')->insert([
                'id' => $measurement->id,
                'device_id' => $measurement->deviceId,
                'partner_id' => $measurement->partnerId,
                'location_id' => $measurement->locationId,
                'contact_id' => null,
                'campaign_id' => $campaignId,
                'biometrics_raw' => SqlValue::encode($measurement->rawBiometrics),
                'biometrics_calculated' => SqlValue::encode($measurement->calculatedBiometrics),
                'timestamp_local' => SqlValue::timestamp($measurement->timestampLocal),
                'received_at' => now(),
            ]);
            DB::table('measurement_intakes')->insert([
                'measurement_id' => $measurement->id,
                'partner_id' => $measurement->partnerId,
                'status' => 'PENDING',
                'patient_payload_encrypted' => $encryptedPatientData,
                'created_at' => now(),
            ]);
            DB::table('devices')->where('id', $measurement->deviceId)->update([
                'total_measurements' => DB::raw('total_measurements + 1'),
                'last_measurement_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('partner_usage')->updateOrInsert(
                ['partner_id' => $measurement->partnerId, 'period' => $billingPeriod],
                ['measurement_count' => $current + 1, 'created_at' => $usage?->created_at ?? now(), 'updated_at' => now()],
            );
            DB::table('event_outbox')->insertOrIgnore([
                'id' => $eventId,
                'event_type' => 'MEASUREMENT_RECEIVED',
                'event_version' => 1,
                'partner_id' => $measurement->partnerId,
                'payload' => SqlValue::encode(['measurement_id' => $measurement->id, 'campaign_id' => $campaignId]),
                'status' => 'PENDING',
                'attempt_count' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        if (!DB::table('business_movements')
            ->where('movement_code', '601')
            ->where('target_type', 'MEASUREMENT')
            ->where('target_id', $measurement->id)
            ->exists()) {
            $this->movements->record(
                '601',
                $measurement->partnerId,
                'MEASUREMENT',
                $measurement->id,
                [
                    'device_id' => $measurement->deviceId,
                    'location_id' => $measurement->locationId,
                    'campaign_id' => DB::table('measurements')->where('id', $measurement->id)->value('campaign_id'),
                ],
            );
        }

        return $measurement;
    }
}
