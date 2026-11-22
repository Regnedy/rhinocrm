<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignAccessService;
use App\Modules\CRM\Domain\ValueObjects\NormalizedPhoneNumber;
use App\Modules\NutritionEngine\Support\QuickNutritionMeasurementSnapshot;
use App\Modules\NutritionEngine\Support\QuickNutritionRecommendations;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QuickNutritionPlanService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CampaignAccessService $campaignAccess,
        private readonly NutritionPlanEngineService $engine,
    ) {}

    /** @return array<string,mixed> */
    public function generateForMeasurement(string $measurementId, ?string $whatsapp = null): array
    {
        $measurement = DB::table('measurements')->where('id', trim($measurementId))->first();
        if ($measurement === null) {
            throw new \RuntimeException('Pesaje no encontrado.', 404);
        }

        if ($measurement->campaign_id !== null) {
            $campaign = $this->campaignAccess->campaign((string) $measurement->campaign_id);
            return $this->campaignAccess->runInCampaign(
                $campaign,
                fn (): array => $this->generateScoped((string) $measurement->id, $whatsapp),
            );
        }

        if (!$this->context->isSuperAdmin() && (string) ($this->context->partnerId ?? '') !== (string) $measurement->partner_id) {
            throw new \RuntimeException('El pesaje pertenece a otra organización.', 403);
        }

        return $this->generateScoped((string) $measurement->id, $whatsapp);
    }

    /** @return array<string,mixed> */
    private function generateScoped(string $measurementId, ?string $whatsapp): array
    {
        $partnerId = $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);

        $measurement = DB::table('measurements')
            ->where('id', $measurementId)
            ->where('partner_id', $partnerId)
            ->first();
        if ($measurement === null) {
            throw new \RuntimeException('Pesaje no disponible en este contexto.', 404);
        }
        if ($measurement->contact_id === null || trim((string) $measurement->contact_id) === '') {
            throw new \RuntimeException('El pesaje todavía no está vinculado a un prospecto.', 409);
        }

        $contact = DB::table('contacts')
            ->where('id', (string) $measurement->contact_id)
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->first();
        if ($contact === null) {
            throw new \RuntimeException('Prospecto no encontrado para este pesaje.', 404);
        }

        $phone = trim((string) ($whatsapp ?? ''));
        if ($phone !== '') {
            $contact = $this->captureWhatsapp($contact, $phone);
        }

        $raw = SqlValue::json($measurement->biometrics_raw ?? null);
        $calculated = SqlValue::json($measurement->biometrics_calculated ?? null);
        $metrics = QuickNutritionMeasurementSnapshot::fromArrays($raw, $calculated, [
            'age' => $contact->age,
            'gender' => $contact->gender,
            'height_cm' => $contact->height_cm,
        ]);
        $recommendations = QuickNutritionRecommendations::fromMetrics($metrics);
        $bmi = is_numeric($metrics['bmi'] ?? null) ? (float) $metrics['bmi'] : null;

        $profileOverrides = [
            'goal' => $bmi !== null && $bmi >= 25.0 ? 'Control gradual de peso' : 'Mejorar hábitos',
            // FitDoctor usaba actividad media por defecto. En plan rápido se conserva ese supuesto
            // para evitar otro formulario y permitir estimación energética desde el BMR del pesaje.
            'activity_level' => 'Moderada',
            'meals_per_day' => 5,
        ];

        $plan = $this->engine->generateForContact(
            (string) $contact->id,
            $measurementId,
            null,
            $profileOverrides,
            'QUICK_MEASUREMENT',
        );

        $planId = (string) $plan['id'];
        $stored = DB::table('nutrition_plan_versions')->where('id', $planId)->where('partner_id', $partnerId)->first();
        if ($stored === null) {
            throw new \RuntimeException('El plan rápido no pudo persistirse.', 500);
        }

        $snapshot = SqlValue::json($stored->plan_snapshot ?? null);
        $snapshot['quick_plan'] = true;
        $snapshot['quick_plan_version'] = (string) config('rhino.nutrition_engine.quick_plan_version', 'fitdoctor8-kiosk-v1');
        $snapshot['title'] = 'Plan alimenticio rápido RHINO';
        $snapshot['measurement_snapshot'] = $metrics;
        $snapshot['quick_recommendations'] = $recommendations;
        $snapshot['generation_context'] = [
            'source' => 'QUICK_MEASUREMENT',
            'measurement_id' => $measurementId,
            'campaign_id' => $measurement->campaign_id !== null ? (string) $measurement->campaign_id : null,
            'device_id' => $measurement->device_id !== null ? (string) $measurement->device_id : null,
            'generated_by_user_id' => $this->context->userId,
        ];

        DB::transaction(function () use ($planId, $partnerId, $contact, $measurementId, $snapshot): void {
            DB::table('nutrition_plan_versions')
                ->where('id', $planId)
                ->where('partner_id', $partnerId)
                ->update([
                    'source_type' => 'QUICK_MEASUREMENT',
                    'plan_snapshot' => SqlValue::encode($snapshot),
                    'updated_at' => now(),
                ]);

            DB::table('timeline_activities')->insert([
                'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                'partner_id' => $partnerId,
                'contact_id' => (string) $contact->id,
                'type' => 'QUICK_NUTRITION_PLAN_GENERATED',
                'description' => 'Se generó un plan alimenticio rápido directamente desde el pesaje.',
                'metadata' => SqlValue::encode([
                    'plan_id' => $planId,
                    'measurement_id' => $measurementId,
                    'source' => 'QUICK_MEASUREMENT',
                ]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);

        $currentPhone = preg_replace('/\D+/', '', (string) ($contact->whatsapp_normalized ?? '')) ?? '';
        $hasWhatsapp = $currentPhone !== '' && !str_starts_with((string) ($contact->whatsapp_normalized ?? ''), 'PENDING_');

        return [
            ...$plan,
            'source_type' => 'QUICK_MEASUREMENT',
            'plan_snapshot' => $snapshot,
            'quick_plan' => true,
            'formats' => [
                'pdf_url' => '/api/v1/nutrition-engine/plans/'.rawurlencode($planId).'/pdf?download=1',
                'images_manifest_url' => '/api/v1/nutrition-engine/plans/'.rawurlencode($planId).'/images',
            ],
            'whatsapp_capture' => [
                'required' => !$hasWhatsapp,
                'captured' => $hasWhatsapp,
                'phone' => $hasWhatsapp ? $currentPhone : null,
                'message' => $hasWhatsapp
                    ? 'WhatsApp reutilizado del prospecto; no se requiere captura adicional.'
                    : 'El plan ya está generado. Captura únicamente el WhatsApp si deseas cerrar el contacto o compartirlo.',
            ],
        ];
    }

    private function captureWhatsapp(object $contact, string $rawPhone): object
    {
        $partner = DB::table('partners')->where('id', (string) $contact->partner_id)->first();
        if ($partner === null) {
            throw new \RuntimeException('Organización no encontrada.', 404);
        }

        $normalized = (new NormalizedPhoneNumber($rawPhone, (string) ($partner->country_dial_code ?? '52')))->getValue();
        $duplicate = DB::table('contacts')
            ->where('partner_id', (string) $contact->partner_id)
            ->where('whatsapp_normalized', $normalized)
            ->where('id', '<>', (string) $contact->id)
            ->whereNull('deleted_at')
            ->exists();
        if ($duplicate) {
            throw new \RuntimeException('Ese WhatsApp ya pertenece a otro prospecto de la organización.', 409);
        }

        DB::table('contacts')->where('id', (string) $contact->id)->update([
            'whatsapp' => $normalized,
            'whatsapp_normalized' => $normalized,
            'whatsapp_status' => 'VERIFIED',
            'whatsapp_verified_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('contacts')->where('id', (string) $contact->id)->first() ?? $contact;
    }
}
