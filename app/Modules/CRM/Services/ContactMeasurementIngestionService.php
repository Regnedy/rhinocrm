<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Domain\ValueObjects\NormalizedPhoneNumber;
use App\Modules\IoT\Domain\Entities\Measurement;
use App\Modules\IoT\Domain\Exceptions\MeasurementContactConflictException;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class ContactMeasurementIngestionService
{
    public function process(Measurement $measurement, array $patientData, UserContextDTO $context): array
    {
        if ($context->partnerId !== $measurement->partnerId) {
            throw new TenantViolationException($context->userId, $measurement->partnerId);
        }
        $partner = DB::table('partners')->where('id', $measurement->partnerId)->first();
        if ($partner === null || (string) $partner->status !== 'ACTIVE') {
            throw new \RuntimeException('El socio está suspendido.', 403);
        }
        $rawPhone = trim((string) ($patientData['telefono_whatsapp'] ?? ''));
        $hasPhone = preg_replace('/\D+/', '', $rawPhone) !== '';
        $phone = $hasPhone
            ? (new NormalizedPhoneNumber($rawPhone, (string) ($partner->country_dial_code ?? '52')))->getValue()
            : '';
        $secret = (string) config('app.contact_id_hash_key');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('CONTACT_ID_HASH_KEY no está configurada de forma segura.');
        }

        $identitySeed = $hasPhone ? 'phone|'.$phone : 'pending|'.$measurement->id;
        $contactId = 'CNT_'.strtoupper(substr(hash_hmac('sha256', $measurement->partnerId.'|'.$identitySeed, $secret), 0, 24));
        $storedPhone = $hasPhone ? $phone : 'PENDING_'.strtoupper(substr(hash('sha256', $measurement->id), 0, 24));
        $eventId = 'EVT_CRM_'.$measurement->id;
        $capturedByUserId = $context->userId;
        $capturedByPartnerId = $context->partnerId ?? $measurement->partnerId;

        return DB::transaction(function () use ($measurement, $patientData, $phone, $storedPhone, $hasPhone, $contactId, $eventId, $capturedByUserId, $capturedByPartnerId): array {
            $processing = DB::table('crm_measurement_processing')->where('measurement_id', $measurement->id)->lockForUpdate()->first();
            if ($processing !== null) {
                return [
                    'contact_id' => (string) $processing->contact_id,
                    'event_id' => $eventId,
                    'is_new_contact' => (bool) $processing->is_new_contact,
                    'already_processed' => true,
                ];
            }

            $storedMeasurement = DB::table('measurements')->where('id', $measurement->id)->lockForUpdate()->first();
            if ($storedMeasurement === null) {
                throw new \RuntimeException('La medición no existe.', 404);
            }
            if ((string) $storedMeasurement->partner_id !== $measurement->partnerId) {
                throw new TenantViolationException('SYSTEM', (string) $storedMeasurement->partner_id);
            }
            $intake = DB::table('measurement_intakes')->where('measurement_id', $measurement->id)->lockForUpdate()->first();
            if ($intake === null || (string) $intake->partner_id !== $measurement->partnerId || (string) $intake->status !== 'PENDING') {
                throw new \RuntimeException('Ficha temporal de medición inconsistente.', 409);
            }
            if ($storedMeasurement->contact_id !== null && (string) $storedMeasurement->contact_id !== $contactId) {
                throw new MeasurementContactConflictException($measurement->id);
            }

            $contact = DB::table('contacts')->where('id', $contactId)->lockForUpdate()->first();
            if ($contact === null) {
                $contact = $hasPhone
                    ? DB::table('contacts')->where('partner_id', $measurement->partnerId)->where('whatsapp_normalized', $phone)->lockForUpdate()->first()
                    : null;
                if ($contact !== null) {
                    $contactId = (string) $contact->id;
                }
            }
            if ($contact !== null && (string) $contact->status === 'BLOCKED') {
                throw new \RuntimeException('El cliente está bloqueado y no admite nuevas mediciones.', 403);
            }

            $summary = [
                'peso_kg' => $measurement->rawBiometrics['peso_kg'] ?? 0,
                'grasa_porcentaje' => $measurement->calculatedBiometrics['grasa_porcentaje'] ?? 0,
                'imc' => $measurement->calculatedBiometrics['imc'] ?? 0,
            ];
            $device = DB::table('devices')->where('id', $measurement->deviceId)->first();
            $generatedBy = $capturedByUserId ?: $device?->linked_by_user_id;
            $isNew = $contact === null;
            if ($isNew) {
                $originTeamId = $generatedBy ? DB::table('team_members')->where('user_id', $generatedBy)->where('partner_id', $measurement->partnerId)->where('status', 'ACTIVE')->value('team_id') : null;
                DB::table('contacts')->insert([
                    'id' => $contactId,
                    'partner_id' => $measurement->partnerId,
                    'registered_by_partner_id' => $capturedByPartnerId,
                    'responsible_partner_id' => $capturedByPartnerId,
                    'whatsapp' => $hasPhone ? $phone : '',
                    'whatsapp_normalized' => $storedPhone,
                    'whatsapp_status' => $hasPhone ? 'VERIFIED' : 'PENDING',
                    'whatsapp_verified_at' => $hasPhone ? now() : null,
                    'name' => (string) $patientData['nombre'],
                    'age' => $patientData['edad'] ?? null,
                    'gender' => $patientData['sexo'] ?? null,
                    'height_cm' => $patientData['altura_cm'] ?? null,
                    'profile' => $patientData['perfil'] ?? null,
                    'lifecycle_stage' => 'CONTACT',
                    'sales_stage' => 'NEW',
                    'status' => 'ACTIVE',
                    'assigned_advisor_id' => $capturedByUserId ?: $generatedBy,
                    'generated_by_user_id' => $generatedBy,
                    'origin_team_id' => $originTeamId,
                    'origin_campaign_id' => $storedMeasurement->campaign_id,
                    'contact_source' => SqlValue::encode([
                        'type' => 'SCALE',
                        'origin_device_id' => $measurement->deviceId,
                        'origin_location_id' => $measurement->locationId,
                        'origin_campaign_id' => $storedMeasurement->campaign_id,
                    ]),
                    'total_measurements' => 1,
                    'last_measurement_at' => SqlValue::timestamp($measurement->timestampLocal),
                    'metrics_summary' => SqlValue::encode($summary),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                if ((string) $contact->partner_id !== $measurement->partnerId) {
                    throw new TenantViolationException('SYSTEM', (string) $contact->partner_id);
                }
                DB::table('contacts')->where('id', $contactId)->update([
                    'total_measurements' => DB::raw('total_measurements + 1'),
                    'last_measurement_at' => SqlValue::timestamp($measurement->timestampLocal),
                    'metrics_summary' => SqlValue::encode($summary),
                    'updated_at' => now(),
                ]);
            }

            if ($storedMeasurement->contact_id === null) {
                DB::table('measurements')->where('id', $measurement->id)->update(['contact_id' => $contactId]);
            }
            $campaignId = $storedMeasurement->campaign_id !== null ? (string) $storedMeasurement->campaign_id : null;
            if ($campaignId !== null) {
                $campaign = DB::table('campaigns')->where('id', $campaignId)->where('status', 'ACTIVE')->whereNull('deleted_at')->first();
                if ($campaign !== null) {
                    $participant = DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->lockForUpdate()->first();
                    $participantType = $isNew ? 'NEW_PROSPECT' : ((string) ($contact->lifecycle_stage ?? 'PROSPECT') === 'CUSTOMER' ? 'EXISTING_CUSTOMER' : 'EXISTING_PROSPECT');
                    if ($participant === null) {
                        DB::table('campaign_contacts')->insert([
                            'campaign_id' => $campaignId,
                            'partner_id' => (string) $campaign->partner_id,
                            'contact_id' => $contactId,
                            'registered_by_user_id' => $capturedByUserId,
                            'registered_by_partner_id' => $capturedByPartnerId,
                            'assigned_advisor_id' => $capturedByUserId,
                            'owner_user_id' => $capturedByUserId,
                            'owner_partner_id' => $capturedByPartnerId,
                            'first_measurement_id' => $measurement->id,
                            'last_measurement_id' => $measurement->id,
                            'participant_type' => $participantType,
                            'attention_status' => 'PENDING_DIET',
                            'is_new_contact' => $isNew,
                            'measurements_count' => 1,
                            'first_seen_at' => SqlValue::timestamp($measurement->timestampLocal),
                            'last_seen_at' => SqlValue::timestamp($measurement->timestampLocal),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('campaign_contacts')->where('id', $participant->id)->update([
                            'last_measurement_id' => $measurement->id,
                            'registered_by_user_id' => $participant->registered_by_user_id ?? $capturedByUserId,
                            'registered_by_partner_id' => $participant->registered_by_partner_id ?? $capturedByPartnerId,
                            'measurements_count' => DB::raw('measurements_count + 1'),
                            'last_seen_at' => SqlValue::timestamp($measurement->timestampLocal),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::table('crm_measurement_processing')->insert([
                'measurement_id' => $measurement->id,
                'partner_id' => $measurement->partnerId,
                'contact_id' => $contactId,
                'status' => 'CONTACT_COMMITTED',
                'is_new_contact' => $isNew,
                'event_status' => 'PENDING',
                'event_id' => $eventId,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('measurement_intakes')->where('measurement_id', $measurement->id)->update([
                'status' => 'PROCESSED', 'patient_payload_encrypted' => null, 'processed_at' => now(),
            ]);
            DB::table('event_outbox')->insertOrIgnore([
                'id' => $eventId,
                'event_type' => 'CONTACT_MEASUREMENT_PROCESSED',
                'event_version' => 1,
                'partner_id' => $measurement->partnerId,
                'payload' => SqlValue::encode(['contact_id' => $contactId, 'measurement_id' => $measurement->id, 'campaign_id' => $campaignId, 'is_new_contact' => $isNew]),
                'status' => 'PENDING',
                'attempt_count' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['contact_id' => $contactId, 'event_id' => $eventId, 'is_new_contact' => $isNew, 'already_processed' => false];
        }, 3);
    }
}
