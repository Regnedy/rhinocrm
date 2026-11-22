<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignDietOwnershipService;
use App\Modules\Movements\Services\MovementService;
use App\Modules\NutritionEngine\Support\NutritionDocumentTemplate;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionPlanEngineService
{
    public const ALGORITHM_VERSION = 'rhino-nutrition-engine-v2';

    private const DAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    public function __construct(
        private readonly UserContextDTO $context,
        private readonly NutritionSafetyService $safety,
        private readonly NutritionPillService $pills,
        private readonly MovementService $movements,
        private readonly CampaignDietOwnershipService $campaignOwnership,
    ) {}

    /** @return array<string,mixed> */
    public function buildForReport(object $report, ?object $nutrition, array $raw, array $calculated): array
    {
        $profile = $this->profileSnapshot($report, $nutrition, $raw, $calculated);
        return $this->buildPlan(
            partnerId: (string) $report->partner_id,
            contactId: (string) $report->contact_id,
            measurementId: (string) $report->measurement_id,
            seed: (string) $report->id,
            profile: $profile,
            requestedTemplateId: null,
        );
    }

    /** @return array<string,mixed> */
    public function generateForContact(string $contactId, ?string $measurementId = null, ?string $templateId = null, array $profileOverrides = [], string $sourceType = 'CRM'): array
    {
        $partnerId = $this->partnerId();
        $contact = DB::table('contacts')
            ->where('id', $contactId)
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->first();
        if ($contact === null) throw new \RuntimeException('Prospecto no encontrado.', 404);
        $this->assertContactAccess($contact);

        $measurementQuery = DB::table('measurements')
            ->where('partner_id', $partnerId)
            ->where('contact_id', $contactId);
        if ($measurementId !== null && trim($measurementId) !== '') {
            $measurementQuery->where('id', $measurementId);
        } else {
            $measurementQuery->orderByDesc('timestamp_local');
        }
        $measurement = $measurementQuery->first();
        if ($measurement === null) throw new \RuntimeException('La persona necesita al menos un pesaje vinculado.', 422);
        $claim = $this->campaignOwnership->claimForGeneration($contactId, (string) $measurement->id);

        try {
            $nutrition = DB::table('nutrition_profiles')
                ->where('partner_id', $partnerId)
                ->where('contact_id', $contactId)
                ->first();
            $raw = SqlValue::json($measurement->biometrics_raw ?? null);
            $calculated = SqlValue::json($measurement->biometrics_calculated ?? null);
            $report = (object) [
                'id' => 'ENGINE_'.strtoupper((string) Str::ulid()),
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'measurement_id' => (string) $measurement->id,
                'age' => $contact->age,
                'gender' => $contact->gender,
                'height_cm' => $contact->height_cm,
                'public_code' => $contact->public_code,
            ];
            $profile = $this->profileSnapshot($report, $nutrition, $raw, $calculated);
            foreach ($profileOverrides as $key => $value) {
                if (array_key_exists($key, $profile) && $value !== null && $value !== '') {
                    $profile[$key] = $value;
                }
            }
            $plan = $this->buildPlan(
                partnerId: $partnerId,
                contactId: $contactId,
                measurementId: (string) $measurement->id,
                seed: (string) $report->id,
                profile: $profile,
                requestedTemplateId: $templateId,
            );
            $planId = DB::transaction(function () use ($report, $plan, $contactId, $measurement, $sourceType): string {
                $generatedPlanId = $this->persist($report, $plan, $sourceType, $this->context->userId);
                $this->campaignOwnership->assignFromGeneratedPlan($contactId, (string) $measurement->id, $generatedPlanId);
                return $generatedPlanId;
            }, 3);
            $plan['id'] = $planId;
            return $plan;
        } catch (\Throwable $exception) {
            $this->campaignOwnership->releaseGenerationClaim($claim);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $plan */
    public function persistForReport(object $report, array $plan): string
    {
        return $this->persist($report, $plan, 'REPORT', null);
    }

    /** @return array<int,array<string,mixed>> */
    public function listPlans(string $contactId, int $limit = 30): array
    {
        $partnerId = $this->partnerId();
        $contact = DB::table('contacts')->where('id', $contactId)->where('partner_id', $partnerId)->whereNull('deleted_at')->first();
        if ($contact === null) {
            throw new \RuntimeException('Prospecto no encontrado.', 404);
        }
        $this->assertContactAccess($contact);

        return DB::table('nutrition_plan_versions as np')
            ->leftJoin('nutrition_templates as nt', 'nt.id', '=', 'np.template_id')
            ->where('np.partner_id', $partnerId)
            ->where('np.contact_id', $contactId)
            ->orderByDesc('np.generated_at')
            ->limit(min(100, max(1, $limit)))
            ->select('np.*', 'nt.name as template_name')
            ->get()
            ->map(fn (object $row): array => $this->planRow($row, false))
            ->all();
    }

    /** @return array<string,mixed> */
    public function showPlan(string $planId): array
    {
        $row = DB::table('nutrition_plan_versions as np')
            ->leftJoin('nutrition_templates as nt', 'nt.id', '=', 'np.template_id')
            ->where('np.id', $planId)
            ->where('np.partner_id', $this->partnerId())
            ->select('np.*', 'nt.name as template_name')
            ->first();
        if ($row === null) throw new \RuntimeException('Plan alimentario no encontrado.', 404);
        $this->assertContactAccessById((string) $row->contact_id);
        return $this->planRow($row, true);
    }

    /** @return array<string,mixed> */
    public function markDelivery(string $planId, string $status): array
    {
        $status = strtoupper(trim($status));
        if (!in_array($status, ['NOT_SENT', 'SENT', 'DELIVERED', 'READ'], true)) {
            throw new \RuntimeException('Estado de entrega no válido.', 422);
        }
        $plan = DB::table('nutrition_plan_versions')
            ->where('id', $planId)
            ->where('partner_id', $this->partnerId())
            ->first();
        if ($plan === null) throw new \RuntimeException('Plan alimentario no encontrado.', 404);
        $this->assertContactAccessById((string) $plan->contact_id);

        DB::table('nutrition_plan_versions')
            ->where('id', $planId)
            ->where('partner_id', $this->partnerId())
            ->update([
                'delivery_status' => $status,
                'delivered_at' => in_array($status, ['DELIVERED', 'READ'], true) ? ($plan->delivered_at ?? now()) : null,
                'updated_at' => now(),
            ]);
        return $this->showPlan($planId);
    }

    /** @return array<int,array<string,mixed>> */
    public function templates(): array
    {
        $partnerId = $this->partnerId();
        return DB::table('nutrition_templates')
            ->where('status', 'ACTIVE')
            ->where(function (Builder $scope) use ($partnerId): void {
                $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
            })
            ->orderByRaw("CASE WHEN partner_id IS NULL THEN 1 ELSE 0 END")
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'description' => $row->description,
                'compatible_goals' => SqlValue::json($row->compatible_goals ?? null),
                'dietary_patterns' => SqlValue::json($row->dietary_patterns ?? null),
                'rules' => SqlValue::json($row->rules ?? null),
            ])
            ->all();
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function buildPlan(
        string $partnerId,
        string $contactId,
        string $measurementId,
        string $seed,
        array $profile,
        ?string $requestedTemplateId,
    ): array {
        $safety = $this->safety->evaluate($profile);
        $trend = $this->measurementTrend($partnerId, $contactId);
        $previous = DB::table('nutrition_plan_versions')
            ->where('partner_id', $partnerId)
            ->where('contact_id', $contactId)
            ->where('is_current', true)
            ->orderByDesc('generated_at')
            ->first();
        $template = $this->selectTemplate($partnerId, $safety, $profile, $requestedTemplateId);
        $rules = SqlValue::json($template->rules ?? null);
        $rules['document'] = NutritionDocumentTemplate::normalize($rules['document'] ?? []);
        $mealsPerDay = min(5, max(3, (int) ($profile['meals_per_day'] ?? $rules['default_meals_per_day'] ?? 4)));
        $slots = $this->slotsForMeals($mealsPerDay);
        $library = $this->pills->availableForEngine($partnerId);
        if ($library === []) {
            throw new \RuntimeException('La biblioteca nutricional no está instalada. Ejecuta php artisan rhino:nutrition-seed-library.', 409);
        }

        $eligible = [];
        $counts = [];
        foreach (['BREAKFAST', 'SNACK', 'LUNCH', 'DINNER'] as $mealType) {
            $eligible[$mealType] = array_values(array_filter(
                $library,
                fn (array $pill): bool => $this->isEligible($pill, $mealType, $profile, $safety, $rules),
            ));
            $counts[$mealType] = count($eligible[$mealType]);
            if ($counts[$mealType] === 0) {
                throw new \RuntimeException('No existen píldoras aprobadas compatibles para '.$mealType.'. Revisa alergias, preferencias o biblioteca.', 422);
            }
        }

        $used = [];
        $items = [];
        $weekly = [];
        $maxRepeats = max(1, (int) ($rules['max_same_pill_per_week'] ?? 1));
        $previousPillIds = $previous !== null
            ? DB::table('nutrition_plan_items')->where('plan_id', $previous->id)->pluck('pill_id')->map('strval')->all()
            : [];

        foreach (self::DAYS as $dayIndex => $dayLabel) {
            $day = ['day' => $dayLabel, 'breakfast' => null, 'snack_am' => null, 'lunch' => null, 'snack_pm' => null, 'dinner' => null];
            foreach ($slots as $slot) {
                $mealType = $this->mealTypeForSlot($slot);
                $pill = $this->pick(
                    candidates: $eligible[$mealType],
                    seed: $seed.'|'.$dayIndex.'|'.$slot,
                    used: $used,
                    maxRepeats: $maxRepeats,
                    profile: $profile,
                    safety: $safety,
                    rules: $rules,
                    previousPillIds: $previousPillIds,
                );
                $used[$pill['id']] = (int) ($used[$pill['id']] ?? 0) + 1;
                $multiplier = $this->servingMultiplier($safety['goal'], $safety['activity_level'], $trend);
                $emoji = trim((string) ($pill['emoji'] ?? '🍽️'));
                $text = ($emoji !== '' ? $emoji.' ' : '').trim((string) $pill['name']).'. Porción base: '.trim((string) ($pill['portion_text'] ?? 'según tolerancia')).'.';
                $day[$this->outputKeyForSlot($slot)] = $text;
                $items[] = [
                    'day_index' => $dayIndex + 1,
                    'day_label' => $dayLabel,
                    'meal_slot' => $slot,
                    'pill_id' => $pill['id'],
                    'pill_version' => $pill['current_version'],
                    'serving_multiplier' => $multiplier,
                    'snapshot' => [
                        'name' => $pill['name'],
                        'emoji' => $pill['emoji'],
                        'portion_text' => $pill['portion_text'],
                        'ingredients' => $pill['ingredients'],
                        'preparation' => $pill['preparation'],
                        'food_groups' => $pill['food_groups'],
                        'allergens' => $pill['allergens'],
                    ],
                ];
            }
            $weekly[] = $day;
        }

        $energy = $safety['allow_energy_estimate']
            ? $this->estimatedEnergyRange($profile, $safety['activity_level'], $safety['goal'])
            : null;
        $macroTargets = $this->macroTargets($energy, $rules, $safety['goal']);
        $adjustmentReason = $this->adjustmentReason($trend, $previous !== null, $safety);
        $runId = 'NER_'.strtoupper((string) Str::ulid());
        $decisionLog = [
            'template' => ['id' => $template->id, 'code' => $template->code, 'name' => $template->name],
            'eligible_counts' => $counts,
            'selected_pill_ids' => array_values(array_unique(array_column($items, 'pill_id'))),
            'trend' => $trend,
            'adjustment_reason' => $adjustmentReason,
            'previous_plan_id' => $previous?->id,
        ];

        DB::table('nutrition_engine_runs')->insert([
            'id' => $runId,
            'partner_id' => $partnerId,
            'contact_id' => $contactId,
            'measurement_id' => $measurementId,
            'template_id' => $template->id,
            'previous_plan_id' => $previous?->id,
            'status' => 'PROCESSING',
            'safety_status' => $safety['status'],
            'algorithm_version' => self::ALGORITHM_VERSION,
            'input_snapshot' => SqlValue::encode($profile),
            'decision_log' => SqlValue::encode($decisionLog),
            'last_error' => null,
            'generated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notes = [
            'El plan se construyó con píldoras alimentarias aprobadas de la biblioteca RHINO.',
            'Las porciones indicadas son una base práctica; ajusta verduras, agua simple y horarios según apetito, tolerancia y rutina.',
            'No intercambies una colación por una comida principal. Las sustituciones deben respetar el mismo tipo de comida y tus restricciones.',
        ];
        if ($safety['missing_fields'] !== []) {
            $notes[] = 'Faltan datos: '.implode(', ', $safety['missing_fields']).'. El plan se mantiene en orientación general.';
        }
        foreach ($safety['reasons'] as $reason) $notes[] = $reason;

        return [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'engine_run_id' => $runId,
            'template_id' => (string) $template->id,
            'template_code' => (string) $template->code,
            'template_name' => (string) $template->name,
            'status' => $safety['status'],
            'title' => $safety['requires_professional_review']
                ? 'Alimentación recomendada para revisión profesional'
                : 'Plan alimentario RHINO',
            'requires_professional_review' => (bool) $safety['requires_professional_review'],
            'profile_complete' => $safety['missing_fields'] === [],
            'goal' => $safety['goal'],
            'goal_label' => $this->goalLabel($safety['goal']),
            'activity_level' => $safety['activity_level'],
            'activity_label' => $this->activityLabel($safety['activity_level']),
            'dietary_pattern' => $safety['dietary_pattern'],
            'meals_per_day' => $mealsPerDay,
            'estimated_energy_range' => $energy,
            'macro_targets' => $macroTargets,
            'document_template' => $rules['document'],
            'based_on' => $this->basedOn($profile, $trend),
            'trend' => $trend,
            'adjustment_reason' => $adjustmentReason,
            'notes' => $notes,
            'portion_guide' => $this->portionGuide($safety['goal'], $trend),
            'weekly_plan' => $weekly,
            'substitutions' => $this->substitutions($eligible, $items, $profile, $safety, $rules),
            'shopping_list' => $this->shoppingList($items),
            'hydration' => 'Usa agua simple como bebida principal. La cantidad debe ajustarse al clima, actividad, tolerancia y recomendaciones médicas personales.',
            'medical_note' => $safety['requires_professional_review']
                ? 'Este plan es un borrador educativo. Por los datos declarados, debe revisarlo un profesional de salud o nutrición antes de modificar cantidades o restricciones.'
                : 'Este plan es una orientación alimentaria y no sustituye una consulta. Ante enfermedad, síntomas, embarazo, lactancia, medicamentos o alergias graves, requiere valoración profesional.',
            'profile_snapshot' => $profile,
            '_items' => $items,
            '_decision_log' => $decisionLog,
        ];
    }

    /** @param array<string,mixed> $plan */
    private function persist(object $report, array $plan, string $sourceType, ?string $approvedBy): string
    {
        $planId = $sourceType === 'REPORT'
            ? 'NPL_'.strtoupper(substr(hash('sha256', (string) $report->id.'|'.self::ALGORITHM_VERSION), 0, 30))
            : 'NPL_'.strtoupper((string) Str::ulid());
        $previous = DB::table('nutrition_plan_versions')
            ->where('partner_id', (string) $report->partner_id)
            ->where('contact_id', (string) $report->contact_id)
            ->where('id', '!=', $planId)
            ->where('is_current', true)
            ->orderByDesc('generated_at')
            ->first();
        $reportReference = $sourceType === 'REPORT'
            ? (string) $report->id
            : 'ENGINE_'.substr($planId, 4);
        $approvalStatus = (bool) ($plan['requires_professional_review'] ?? false)
            ? 'REVIEW_REQUIRED'
            : 'AUTO_APPROVED';
        $measurementCount = DB::table('measurements')
            ->where('partner_id', (string) $report->partner_id)
            ->where('contact_id', (string) $report->contact_id)
            ->count();

        DB::transaction(function () use (
            $report, $plan, $sourceType, $approvedBy, $planId, $previous,
            $reportReference, $approvalStatus, $measurementCount
        ): void {
            DB::table('nutrition_plan_versions')
                ->where('partner_id', (string) $report->partner_id)
                ->where('contact_id', (string) $report->contact_id)
                ->where('id', '!=', $planId)
                ->update(['is_current' => false, 'updated_at' => now()]);

            $existing = DB::table('nutrition_plan_versions')->where('id', $planId)->first();
            $values = [
                'partner_id' => (string) $report->partner_id,
                'contact_id' => (string) $report->contact_id,
                'measurement_id' => (string) $report->measurement_id,
                'personal_report_id' => $reportReference,
                'source_type' => $sourceType,
                'template_id' => $plan['template_id'] ?? null,
                'engine_run_id' => $plan['engine_run_id'] ?? null,
                'previous_plan_id' => $previous?->id,
                'status' => (string) ($plan['status'] ?? 'AUTOMATIC_GENERAL_GUIDANCE'),
                'approval_status' => $approvalStatus,
                'approved_by_user_id' => $approvalStatus === 'AUTO_APPROVED' ? ($approvedBy ?? 'SYSTEM') : null,
                'approved_at' => $approvalStatus === 'AUTO_APPROVED' ? now() : null,
                'delivery_status' => $existing?->delivery_status ?? 'NOT_SENT',
                'delivered_at' => $existing?->delivered_at,
                'is_current' => true,
                'requires_professional_review' => (bool) ($plan['requires_professional_review'] ?? false),
                'algorithm_version' => self::ALGORITHM_VERSION,
                'adjustment_reason' => $plan['adjustment_reason'] ?? null,
                'source_measurement_count' => $measurementCount,
                'profile_snapshot' => SqlValue::encode($plan['profile_snapshot'] ?? []),
                'plan_snapshot' => SqlValue::encode($this->publicPlan($plan)),
                'generated_at' => now(),
                'updated_at' => now(),
            ];
            if ($existing === null) {
                DB::table('nutrition_plan_versions')->insert(array_merge($values, [
                    'id' => $planId,
                    'created_at' => now(),
                ]));
            } else {
                DB::table('nutrition_plan_versions')->where('id', $planId)->update($values);
                DB::table('nutrition_plan_items')->where('plan_id', $planId)->delete();
            }

            foreach (($plan['_items'] ?? []) as $item) {
                DB::table('nutrition_plan_items')->insert([
                    'plan_id' => $planId,
                    'day_index' => (int) $item['day_index'],
                    'day_label' => (string) $item['day_label'],
                    'meal_slot' => (string) $item['meal_slot'],
                    'pill_id' => (string) $item['pill_id'],
                    'pill_version' => (int) $item['pill_version'],
                    'serving_multiplier' => (float) $item['serving_multiplier'],
                    'item_snapshot' => SqlValue::encode($item['snapshot']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($sourceType === 'REPORT' && DB::table('personal_reports')->where('id', (string) $report->id)->exists()) {
                DB::table('personal_reports')->where('id', (string) $report->id)->update([
                    'nutrition_plan_id' => $planId,
                    'nutrition_plan_status' => (string) ($plan['status'] ?? ''),
                    'nutrition_algorithm_version' => self::ALGORITHM_VERSION,
                    'nutrition_profile_snapshot' => SqlValue::encode($plan['profile_snapshot'] ?? []),
                    'nutrition_plan_snapshot' => SqlValue::encode($this->publicPlan($plan)),
                    'nutrition_generated_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (($plan['engine_run_id'] ?? null) !== null) {
                DB::table('nutrition_engine_runs')->where('id', (string) $plan['engine_run_id'])->update([
                    'status' => 'COMPLETED',
                    'generated_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('timeline_activities')->insert([
                'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                'partner_id' => (string) $report->partner_id,
                'contact_id' => (string) $report->contact_id,
                'type' => 'NUTRITION_PLAN_GENERATED',
                'description' => 'Se generó el plan alimentario '.$planId.' con '.$measurementCount.' pesaje(s) disponible(s).',
                'metadata' => SqlValue::encode([
                    'plan_id' => $planId,
                    'measurement_id' => (string) $report->measurement_id,
                    'template_id' => $plan['template_id'] ?? null,
                    'approval_status' => $approvalStatus,
                ]),
                'actor_id' => $approvedBy ?? 'SYSTEM',
                'created_at' => now(),
            ]);
        }, 3);

        if (!DB::table('business_movements')
            ->where('target_type', 'NUTRITION_PLAN')
            ->where('target_id', $planId)
            ->exists()) {
            $this->movements->record(
                $previous !== null ? '603' : '602',
                (string) $report->partner_id,
                'NUTRITION_PLAN',
                $planId,
                [
                    'contact_id' => (string) $report->contact_id,
                    'measurement_id' => (string) $report->measurement_id,
                    'previous_plan_id' => $previous?->id,
                    'algorithm_version' => self::ALGORITHM_VERSION,
                    'source_type' => $sourceType,
                ],
            );
        }

        return $planId;
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(object $report, ?object $nutrition, array $raw, array $calculated): array
    {
        $preferences = $nutrition !== null ? SqlValue::json($nutrition->preferences ?? null) : [];
        return [
            'contact_id' => (string) $report->contact_id,
            'age' => $this->integer($report->age ?? null),
            'gender' => trim((string) ($report->gender ?? '')),
            'height_cm' => $this->number($report->height_cm ?? null),
            'weight_kg' => $this->metric($raw, $calculated, ['peso_kg', 'weight_kg', 'weight']),
            'bmi' => $this->metric($calculated, $raw, ['imc', 'bmi']),
            'body_fat_percent' => $this->metric($calculated, $raw, ['grasa_porcentaje', 'body_fat_percent', 'fat_percent']),
            'lean_mass_kg' => $this->metric($calculated, $raw, ['masa_magra_kg', 'lean_mass_kg', 'muscle_mass_kg']),
            'bmr_kcal' => $this->metric($calculated, $raw, ['bmr_kcal', 'bmr', 'metabolic_rate']),
            'goal' => trim((string) ($nutrition->goal ?? 'Mantener hábitos equilibrados')),
            'activity_level' => trim((string) ($nutrition->activity_level ?? '')),
            'meals_per_day' => $this->integer($nutrition->meals_per_day ?? null) ?? 4,
            'dietary_pattern' => trim((string) ($nutrition->dietary_pattern ?? '')),
            'budget_level' => strtoupper(trim((string) ($nutrition->budget_level ?? ''))),
            'cooking_time_minutes' => $this->integer($nutrition->cooking_time_minutes ?? null),
            'preferences' => $preferences,
            'allergies' => $nutrition !== null ? SqlValue::json($nutrition->allergies ?? null) : [],
            'health_flags' => $nutrition !== null ? SqlValue::json($nutrition->health_flags ?? null) : [],
            'medications' => $nutrition !== null ? SqlValue::json($nutrition->medications ?? null) : [],
            'schedule_preferences' => $nutrition !== null ? SqlValue::json($nutrition->schedule_preferences ?? null) : [],
            'occupation_schedule' => $nutrition !== null ? SqlValue::json($nutrition->occupation_schedule ?? null) : [],
            'kitchen_resources' => $nutrition !== null ? SqlValue::json($nutrition->kitchen_resources ?? null) : [],
            'food_access_notes' => trim((string) ($nutrition->food_access_notes ?? '')),
            'adherence_score' => $this->integer($nutrition->adherence_score ?? null),
            'profile_complete' => $nutrition !== null && strtoupper((string) ($nutrition->status ?? '')) === 'COMPLETED',
        ];
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $safety @param array<string,mixed> $rules */
    private function isEligible(array $pill, string $mealType, array $profile, array $safety, array $rules): bool
    {
        if (($pill['meal_type'] ?? '') !== $mealType) return false;
        if (array_intersect($pill['allergens'] ?? [], $safety['allergen_codes']) !== []) return false;
        $patterns = $pill['dietary_patterns'] ?? [];
        if ($safety['dietary_pattern'] !== 'OMNIVORE' && !in_array($safety['dietary_pattern'], $patterns, true)) return false;
        if (($rules['required_dietary_pattern'] ?? null) !== null
            && !in_array((string) $rules['required_dietary_pattern'], $patterns, true)) return false;

        $text = $this->lower(($pill['name'] ?? '').' '.($pill['portion_text'] ?? '').' '.json_encode($pill['ingredients'] ?? [], JSON_UNESCAPED_UNICODE));
        foreach ($safety['avoid_words'] as $word) {
            if ($word !== '' && str_contains($text, $word)) return false;
        }
        $healthText = $this->lower(implode(' ', $this->safety->flatten($profile['health_flags'] ?? [])));
        foreach (($pill['excluded_flags'] ?? []) as $flag) {
            if ($flag !== '' && str_contains($healthText, $this->lower((string) $flag))) return false;
        }
        $maxPrep = $this->integer($rules['max_prep_minutes'] ?? $profile['cooking_time_minutes'] ?? null);
        if ($maxPrep !== null && $pill['prep_minutes'] !== null && (int) $pill['prep_minutes'] > $maxPrep) return false;
        return true;
    }

    /** @param array<int,array<string,mixed>> $candidates @param array<string,int> $used @param array<string,mixed> $profile @param array<string,mixed> $safety @param array<string,mixed> $rules @param array<int,string> $previousPillIds @return array<string,mixed> */
    private function pick(array $candidates, string $seed, array $used, int $maxRepeats, array $profile, array $safety, array $rules, array $previousPillIds): array
    {
        $ranked = [];
        foreach ($candidates as $pill) {
            $useCount = (int) ($used[$pill['id']] ?? 0);
            if ($useCount >= $maxRepeats && count($candidates) > count($used)) continue;
            $score = 10.0;
            if (in_array($safety['goal'], $pill['compatible_goals'] ?? [], true)) $score += 25;
            if (in_array('GENERAL', $pill['tags'] ?? [], true)) $score += 5;
            foreach (($rules['prefer_tags'] ?? []) as $tag) {
                if (in_array(strtoupper((string) $tag), $pill['tags'] ?? [], true)) $score += 12;
            }
            foreach (($rules['prefer_food_groups'] ?? []) as $group) {
                if (in_array(strtoupper((string) $group), $pill['food_groups'] ?? [], true)) $score += 4;
            }
            if (($profile['budget_level'] ?? '') !== '' && ($pill['budget_level'] ?? '') === $profile['budget_level']) $score += 10;
            if (in_array($pill['budget_level'] ?? '', $rules['prefer_budget_levels'] ?? [], true)) $score += 8;
            if (($profile['cooking_time_minutes'] ?? null) !== null && ($pill['prep_minutes'] ?? null) !== null
                && (int) $pill['prep_minutes'] <= (int) $profile['cooking_time_minutes']) $score += 8;
            if (in_array($pill['id'], $previousPillIds, true)) $score -= 8;
            $score -= $useCount * 25;
            $score += (abs((int) crc32($seed.'|'.$pill['id'])) % 1000) / 1000;
            $ranked[] = ['score' => $score, 'pill' => $pill];
        }
        if ($ranked === []) {
            foreach ($candidates as $pill) {
                $ranked[] = ['score' => (abs((int) crc32($seed.'|'.$pill['id'])) % 1000) / 1000, 'pill' => $pill];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $ranked[0]['pill'];
    }

    private function selectTemplate(string $partnerId, array $safety, array $profile, ?string $requestedId): object
    {
        $query = DB::table('nutrition_templates')
            ->where('status', 'ACTIVE')
            ->where(function (Builder $scope) use ($partnerId): void {
                $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
            });
        if ($requestedId !== null && trim($requestedId) !== '') {
            $template = (clone $query)->where('id', $requestedId)->first();
            if ($template === null) throw new \RuntimeException('Plantilla alimentaria no encontrada.', 422);
            return $template;
        }

        $code = match (true) {
            $safety['dietary_pattern'] === 'VEGETARIAN' => 'VEGETARIAN_GENERAL',
            ($profile['budget_level'] ?? '') === 'LOW' && (($profile['cooking_time_minutes'] ?? 999) <= 20) => 'ECONOMIC_QUICK',
            $safety['goal'] === 'REDUCE' => 'GRADUAL_WEIGHT_MANAGEMENT',
            $safety['goal'] === 'GAIN' || $safety['activity_level'] === 'HIGH' => 'ACTIVE_SUPPORT',
            default => 'BALANCED_GENERAL',
        };
        return (clone $query)->where('code', $code)->first()
            ?? (clone $query)->orderByRaw("CASE WHEN partner_id IS NULL THEN 1 ELSE 0 END")->first()
            ?? throw new \RuntimeException('No hay plantillas nutricionales activas. Ejecuta la carga de biblioteca.', 409);
    }

    /** @return array<string,mixed> */
    private function measurementTrend(string $partnerId, string $contactId): array
    {
        $rows = DB::table('measurements')
            ->where('partner_id', $partnerId)
            ->where('contact_id', $contactId)
            ->orderByDesc('timestamp_local')
            ->limit(4)
            ->get()
            ->reverse()
            ->values();
        if ($rows->count() < 2) {
            return ['status' => 'INSUFFICIENT_HISTORY', 'measurements_count' => $rows->count(), 'weight_change_kg' => null, 'body_fat_change_pp' => null, 'lean_mass_change_kg' => null];
        }
        $first = $this->measurementMetrics($rows->first());
        $last = $this->measurementMetrics($rows->last());
        $weightChange = $this->difference($last['weight_kg'], $first['weight_kg']);
        $fatChange = $this->difference($last['body_fat_percent'], $first['body_fat_percent']);
        $leanChange = $this->difference($last['lean_mass_kg'], $first['lean_mass_kg']);
        $status = 'STABLE_OR_MIXED';
        if ($weightChange !== null && $weightChange < -0.5 && $leanChange !== null && $leanChange < -0.5) $status = 'LEAN_MASS_LOSS_ALERT';
        elseif ($fatChange !== null && $fatChange < -0.5 && ($leanChange === null || $leanChange >= -0.3)) $status = 'FAVORABLE_TREND';
        elseif ($weightChange !== null && abs($weightChange) < 0.4 && ($fatChange === null || abs($fatChange) < 0.5)) $status = 'NO_CLEAR_CHANGE';
        return [
            'status' => $status,
            'measurements_count' => $rows->count(),
            'from' => $rows->first()->timestamp_local,
            'to' => $rows->last()->timestamp_local,
            'weight_change_kg' => $weightChange,
            'body_fat_change_pp' => $fatChange,
            'lean_mass_change_kg' => $leanChange,
        ];
    }

    /** @return array<string,?float> */
    private function measurementMetrics(object $row): array
    {
        $raw = SqlValue::json($row->biometrics_raw ?? null);
        $calculated = SqlValue::json($row->biometrics_calculated ?? null);
        return [
            'weight_kg' => $this->metric($raw, $calculated, ['peso_kg', 'weight_kg', 'weight']),
            'body_fat_percent' => $this->metric($calculated, $raw, ['grasa_porcentaje', 'body_fat_percent', 'fat_percent']),
            'lean_mass_kg' => $this->metric($calculated, $raw, ['masa_magra_kg', 'lean_mass_kg', 'muscle_mass_kg']),
        ];
    }

    private function servingMultiplier(string $goal, string $activity, array $trend): float
    {
        if (($trend['status'] ?? '') === 'LEAN_MASS_LOSS_ALERT') return 1.0;
        $value = match ($goal) {
            'REDUCE' => 0.90,
            'GAIN' => 1.10,
            default => 1.0,
        };
        if ($activity === 'HIGH' && $goal !== 'REDUCE') $value += 0.05;
        return round($value, 2);
    }

    /** @return array{min:int,max:int,label:string}|null */
    private function estimatedEnergyRange(array $profile, string $activity, string $goal): ?array
    {
        $bmr = $this->number($profile['bmr_kcal'] ?? null);
        if ($bmr === null || $activity === 'UNKNOWN') return null;
        $factor = match ($activity) {'LOW' => 1.20, 'HIGH' => 1.55, default => 1.375};
        $target = $bmr * $factor;
        if ($goal === 'REDUCE') $target *= 0.90;
        if ($goal === 'GAIN') $target *= 1.10;
        $target = min(3500.0, max(1300.0, $target));
        $min = (int) (floor(($target - 100) / 50) * 50);
        $max = (int) (ceil(($target + 100) / 50) * 50);
        return ['min' => $min, 'max' => $max, 'label' => number_format($min).'–'.number_format($max).' kcal/día, estimación orientativa'];
    }

    /** @param array{min:int,max:int,label:string}|null $energy @param array<string,mixed> $rules @return array<string,int|null> */
    private function macroTargets(?array $energy, array $rules, string $goal): array
    {
        if ($energy === null) {
            return ['kcal' => null, 'protein_g' => null, 'carbs_g' => null, 'fat_g' => null];
        }

        $defaults = match ($goal) {
            'GAIN' => ['protein' => 25, 'carbs' => 50, 'fat' => 25],
            'REDUCE' => ['protein' => 30, 'carbs' => 40, 'fat' => 30],
            default => ['protein' => 25, 'carbs' => 45, 'fat' => 30],
        };
        $percentages = is_array($rules['macro_percentages'] ?? null) ? $rules['macro_percentages'] : $defaults;
        $protein = max(10, min(50, (int) ($percentages['protein'] ?? $defaults['protein'])));
        $carbs = max(10, min(70, (int) ($percentages['carbs'] ?? $defaults['carbs'])));
        $fat = max(10, min(50, (int) ($percentages['fat'] ?? $defaults['fat'])));
        if ($protein + $carbs + $fat !== 100) {
            [$protein, $carbs, $fat] = [$defaults['protein'], $defaults['carbs'], $defaults['fat']];
        }

        $kcal = (int) round((((int) $energy['min']) + ((int) $energy['max'])) / 2 / 10) * 10;
        return [
            'kcal' => $kcal,
            'protein_g' => (int) round(($kcal * ($protein / 100)) / 4),
            'carbs_g' => (int) round(($kcal * ($carbs / 100)) / 4),
            'fat_g' => (int) round(($kcal * ($fat / 100)) / 9),
        ];
    }

    /** @return array<int,string> */
    private function basedOn(array $profile, array $trend): array
    {
        $items = [];
        foreach ([
            'Peso actual' => $profile['weight_kg'] !== null ? number_format((float) $profile['weight_kg'], 1).' kg' : null,
            'Grasa corporal estimada' => $profile['body_fat_percent'] !== null ? number_format((float) $profile['body_fat_percent'], 1).' %' : null,
            'Masa libre de grasa estimada' => $profile['lean_mass_kg'] !== null ? number_format((float) $profile['lean_mass_kg'], 1).' kg' : null,
            'Historial considerado' => ($trend['measurements_count'] ?? 0).' pesaje(s) reciente(s)',
        ] as $label => $value) {
            if ($value !== null) $items[] = $label.': '.$value;
        }
        return $items;
    }

    /** @return array<int,array{label:string,amount:string}> */
    private function portionGuide(string $goal, array $trend): array
    {
        $item = static fn (string $group, string $guidance): array => [
            'group' => $group,
            'guidance' => $guidance,
            'label' => $group,
            'amount' => $guidance,
        ];
        $guide = [
            $item('Verduras', '2 palmas o aproximadamente la mitad del plato en comida y cena.'),
            $item('Proteína o leguminosa', '1 palma o la porción base indicada en la píldora.'),
            $item('Cereal o tubérculo', '1 puño o la porción base indicada.'),
            $item('Fruta', '1 pieza o 1 taza, preferentemente entera.'),
            $item('Grasa', '1 pulgar de aceite, aguacate, semillas o equivalente.'),
        ];
        if ($goal === 'GAIN') $guide[] = $item('Actividad física', 'Puede añadirse una porción de cereal o leguminosa alrededor de la actividad, según hambre y tolerancia.');
        if (($trend['status'] ?? '') === 'LEAN_MASS_LOSS_ALERT') $guide[] = $item('Alerta de seguimiento', 'No reduzcas más las porciones hasta revisar el cambio de masa estimada.');
        return $guide;
    }

    /** @param array<string,array<int,array<string,mixed>>> $eligible @param array<int,array<string,mixed>> $items @return array<int,array{from:string,to:string}> */
    private function substitutions(array $eligible, array $items, array $profile, array $safety, array $rules): array
    {
        $selected = array_fill_keys(array_column($items, 'pill_id'), true);
        $result = [];
        foreach (['BREAKFAST', 'LUNCH', 'DINNER', 'SNACK'] as $type) {
            $usedForType = array_values(array_filter($items, fn (array $item): bool => $this->mealTypeForSlot($item['meal_slot']) === $type));
            $alternatives = array_values(array_filter($eligible[$type] ?? [], fn (array $pill): bool => !isset($selected[$pill['id']])));
            if ($usedForType === [] || $alternatives === []) continue;
            $from = $usedForType[0]['snapshot']['name'];
            $to = $alternatives[abs((int) crc32($from.'|'.$profile['contact_id'])) % count($alternatives)];
            $result[] = ['from' => (string) $from, 'to' => (string) $to['name'].' — '.(string) ($to['portion_text'] ?? 'porción base')];
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $items @return array<string,array<int,string>> */
    private function shoppingList(array $items): array
    {
        $categories = [
            'Verduras y frutas' => [],
            'Cereales y tubérculos' => [],
            'Leguminosas y proteínas' => [],
            'Lácteos y alternativas' => [],
            'Grasas y complementos' => [],
            'Otros' => [],
        ];
        foreach ($items as $item) {
            $groups = $item['snapshot']['food_groups'] ?? [];
            $category = match (true) {
                array_intersect($groups, ['VEGETABLES', 'FRUIT']) !== [] => 'Verduras y frutas',
                in_array('CEREALS_TUBERS', $groups, true) => 'Cereales y tubérculos',
                array_intersect($groups, ['LEGUMES', 'ANIMAL_PROTEIN']) !== [] => 'Leguminosas y proteínas',
                in_array('DAIRY', $groups, true) => 'Lácteos y alternativas',
                in_array('HEALTHY_FATS', $groups, true) => 'Grasas y complementos',
                default => 'Otros',
            };
            foreach (($item['snapshot']['ingredients'] ?? []) as $ingredient) {
                $display = is_array($ingredient)
                    ? trim((string) ($ingredient['name'] ?? $ingredient['display'] ?? ''))
                    : trim((string) $ingredient);
                if ($display !== '') $categories[$category][] = $display;
            }
        }
        foreach ($categories as $category => $values) {
            $values = array_values(array_unique($values));
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $categories[$category] = array_slice($values, 0, 25);
            if ($categories[$category] === []) unset($categories[$category]);
        }
        return $categories;
    }

    private function adjustmentReason(array $trend, bool $hasPrevious, array $safety): string
    {
        if (!$hasPrevious) return 'Primer plan RHINO registrado para esta persona.';
        return match ($trend['status'] ?? '') {
            'FAVORABLE_TREND' => 'La tendencia estimada es favorable; se mantiene la estructura y se cambia la variedad para apoyar adherencia.',
            'LEAN_MASS_LOSS_ALERT' => 'Se detectó disminución simultánea de peso y masa magra estimada; no se intensificó la reducción de porciones.',
            'NO_CLEAR_CHANGE' => 'No hay una tendencia clara; se priorizó una versión más práctica sin aplicar una reducción adicional.',
            default => $safety['requires_professional_review']
                ? 'Se conservó orientación general y se marcó revisión profesional.'
                : 'El plan se actualizó con el pesaje más reciente y la biblioteca vigente.',
        };
    }

    /** @return array<string,mixed> */
    private function planRow(object $row, bool $withItems): array
    {
        $plan = [
            'id' => (string) $row->id,
            'contact_id' => (string) $row->contact_id,
            'measurement_id' => (string) $row->measurement_id,
            'personal_report_id' => (string) $row->personal_report_id,
            'source_type' => (string) ($row->source_type ?? 'REPORT'),
            'template_id' => $row->template_id ?? null,
            'template_name' => $row->template_name ?? null,
            'previous_plan_id' => $row->previous_plan_id ?? null,
            'status' => (string) $row->status,
            'approval_status' => (string) ($row->approval_status ?? 'AUTO_APPROVED'),
            'delivery_status' => (string) ($row->delivery_status ?? 'NOT_SENT'),
            'delivered_at' => $row->delivered_at ?? null,
            'is_current' => (bool) ($row->is_current ?? true),
            'requires_professional_review' => (bool) $row->requires_professional_review,
            'algorithm_version' => (string) $row->algorithm_version,
            'adjustment_reason' => $row->adjustment_reason ?? null,
            'source_measurement_count' => (int) ($row->source_measurement_count ?? 1),
            'profile_snapshot' => SqlValue::json($row->profile_snapshot ?? null),
            'plan_snapshot' => SqlValue::json($row->plan_snapshot ?? null),
            'generated_at' => $row->generated_at,
            'pdf_generated_at' => $row->pdf_generated_at ?? null,
            'pdf_hash' => $row->pdf_hash ?? null,
            'share_count' => (int) ($row->share_count ?? 0),
            'last_shared_at' => $row->last_shared_at ?? null,
            'whatsapp_message_id' => $row->whatsapp_message_id ?? null,
        ];
        if ($withItems) {
            $plan['items'] = DB::table('nutrition_plan_items')
                ->where('plan_id', $row->id)
                ->orderBy('day_index')
                ->orderByRaw("FIELD(meal_slot,'BREAKFAST','SNACK_AM','LUNCH','SNACK_PM','DINNER')")
                ->get()
                ->map(fn (object $item): array => [
                    'day_index' => (int) $item->day_index,
                    'day_label' => (string) $item->day_label,
                    'meal_slot' => (string) $item->meal_slot,
                    'pill_id' => (string) $item->pill_id,
                    'pill_version' => (int) $item->pill_version,
                    'serving_multiplier' => (float) $item->serving_multiplier,
                    'snapshot' => SqlValue::json($item->item_snapshot ?? null),
                ])->all();
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['_items'], $plan['_decision_log']);
        return $plan;
    }

    /** @return array<int,string> */
    private function slotsForMeals(int $meals): array
    {
        return match ($meals) {
            3 => ['BREAKFAST', 'LUNCH', 'DINNER'],
            5 => ['BREAKFAST', 'SNACK_AM', 'LUNCH', 'SNACK_PM', 'DINNER'],
            default => ['BREAKFAST', 'SNACK_AM', 'LUNCH', 'DINNER'],
        };
    }

    private function mealTypeForSlot(string $slot): string
    {
        return str_starts_with($slot, 'SNACK') ? 'SNACK' : $slot;
    }

    private function outputKeyForSlot(string $slot): string
    {
        return match ($slot) {
            'BREAKFAST' => 'breakfast',
            'SNACK_AM' => 'snack_am',
            'LUNCH' => 'lunch',
            'SNACK_PM' => 'snack_pm',
            'DINNER' => 'dinner',
            default => strtolower($slot),
        };
    }

    private function goalLabel(string $goal): string
    {
        return match ($goal) {
            'REDUCE' => 'Control gradual de peso',
            'GAIN' => 'Apoyo a actividad y aumento moderado',
            'MAINTAIN' => 'Mantenimiento',
            default => 'Mejora de hábitos',
        };
    }

    private function activityLabel(string $activity): string
    {
        return match ($activity) {'LOW' => 'Baja', 'HIGH' => 'Alta', 'MODERATE' => 'Moderada', default => 'No registrada'};
    }

    /** @param array<string,mixed> $primary @param array<string,mixed> $secondary @param array<int,string> $keys */
    private function metric(array $primary, array $secondary, array $keys): ?float
    {
        foreach ([$primary, $secondary] as $source) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) return (float) $source[$key];
            }
        }
        return null;
    }

    private function difference(?float $a, ?float $b): ?float
    {
        return $a !== null && $b !== null ? round($a - $b, 2) : null;
    }

    private function assertContactAccessById(string $contactId): void
    {
        $contact = DB::table('contacts')
            ->where('id', $contactId)
            ->where('partner_id', $this->partnerId())
            ->whereNull('deleted_at')
            ->first();
        if ($contact === null) throw new \RuntimeException('Prospecto no encontrado.', 404);
        $this->assertContactAccess($contact);
    }

    private function assertContactAccess(object $contact): void
    {
        if ($this->context->role === 'ADVISOR' && (string) ($contact->assigned_advisor_id ?? '') !== $this->context->userId) {
            throw new \RuntimeException('Prospecto fuera de tu responsabilidad.', 403);
        }
        if ($this->context->role === 'TEAM_LEADER' && !(new TeamAccessScope($this->context))->canAccessContact($contact)) {
            throw new \RuntimeException('Prospecto fuera de tus equipos.', 403);
        }
    }

    private function partnerId(): string
    {
        return $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
