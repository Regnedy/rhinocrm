<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionEngineService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly NutritionSafetyService $safety,
        private readonly NutritionPlanEngineService $plans,
    ) {}

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $partnerId = $this->partnerId();
        $pillQuery = DB::table('nutrition_pills')->where(function (Builder $scope) use ($partnerId): void {
            $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
        });
        $profileQuery = DB::table('nutrition_profiles as nutrition_profile')
            ->where('nutrition_profile.partner_id', $partnerId);
        $planQuery = DB::table('nutrition_plan_versions as nutrition_plan')
            ->where('nutrition_plan.partner_id', $partnerId);
        $this->applyRelatedContactVisibility($profileQuery, 'nutrition_profile.contact_id');
        $this->applyRelatedContactVisibility($planQuery, 'nutrition_plan.contact_id');

        return [
            'library' => [
                'approved' => (clone $pillQuery)->where('status', 'APPROVED')->count(),
                'drafts' => (clone $pillQuery)->where('status', 'DRAFT')->count(),
                'partner_pills' => (clone $pillQuery)->where('partner_id', $partnerId)->count(),
                'templates' => DB::table('nutrition_templates')
                    ->where('status', 'ACTIVE')
                    ->where(function (Builder $scope) use ($partnerId): void {
                        $scope->where('scope_key', 'GLOBAL')->orWhere('partner_id', $partnerId);
                    })->count(),
            ],
            'profiles' => [
                'total' => (clone $profileQuery)->count(),
                'complete' => (clone $profileQuery)->where('status', 'COMPLETED')->count(),
                'incomplete' => (clone $profileQuery)->where('status', '!=', 'COMPLETED')->count(),
            ],
            'plans' => [
                'total' => (clone $planQuery)->count(),
                'current' => (clone $planQuery)->where('is_current', true)->count(),
                'review_required' => (clone $planQuery)->where('requires_professional_review', true)->where('is_current', true)->count(),
                'delivered' => (clone $planQuery)->whereIn('delivery_status', ['DELIVERED', 'READ'])->count(),
            ],
            'ai_batches' => $this->context->hasPermission('nutrition.ai.manage') ? [
                'total' => DB::table('nutrition_ai_batches')->where('partner_id', $partnerId)->count(),
                'pending_review' => DB::table('nutrition_ai_batches')->where('partner_id', $partnerId)->where('status', 'REVIEW_REQUIRED')->count(),
            ] : ['total' => 0, 'pending_review' => 0],
            'algorithm_version' => NutritionPlanEngineService::ALGORITHM_VERSION,
        ];
    }

    /** @return array<string,mixed> */
    public function contactWorkspace(string $contactId): array
    {
        $contact = $this->authorizedContact($contactId);
        $profile = DB::table('nutrition_profiles')
            ->where('partner_id', $this->partnerId())
            ->where('contact_id', $contactId)
            ->first();
        $measurement = DB::table('measurements')
            ->where('partner_id', $this->partnerId())
            ->where('contact_id', $contactId)
            ->orderByDesc('timestamp_local')
            ->first();
        $raw = $measurement !== null ? SqlValue::json($measurement->biometrics_raw ?? null) : [];
        $calculated = $measurement !== null ? SqlValue::json($measurement->biometrics_calculated ?? null) : [];
        $snapshot = $this->profileSnapshot($contact, $profile, $raw, $calculated);

        return [
            'contact' => [
                'id' => (string) $contact->id,
                'name' => (string) $contact->name,
                'public_code' => $contact->public_code,
                'age' => $contact->age,
                'gender' => $contact->gender,
                'height_cm' => $contact->height_cm,
                'total_measurements' => (int) $contact->total_measurements,
                'last_measurement_at' => $contact->last_measurement_at,
            ],
            'profile' => $this->profileArray($profile, $snapshot),
            'safety' => $this->safety->evaluate($snapshot),
            'latest_measurement_id' => $measurement?->id,
            'latest_measurement_at' => $measurement?->timestamp_local,
            'plans' => $this->plans->listPlans($contactId, 20),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function updateProfile(string $contactId, array $data): array
    {
        $contact = $this->authorizedContact($contactId, true);
        $partnerId = $this->partnerId();
        $existing = DB::table('nutrition_profiles')
            ->where('partner_id', $partnerId)
            ->where('contact_id', $contactId)
            ->first();

        $goal = $this->nullable($data['goal'] ?? $existing?->goal ?? null);
        $activity = $this->nullable($data['activity_level'] ?? $existing?->activity_level ?? null);
        $meals = is_numeric($data['meals_per_day'] ?? null)
            ? min(5, max(3, (int) $data['meals_per_day']))
            : ($existing?->meals_per_day ?? 4);
        $dietaryPattern = $this->nullable($data['dietary_pattern'] ?? $existing?->dietary_pattern ?? null);
        $budget = $this->nullable($data['budget_level'] ?? $existing?->budget_level ?? null);
        $cooking = is_numeric($data['cooking_time_minutes'] ?? null)
            ? min(480, max(1, (int) $data['cooking_time_minutes']))
            : $existing?->cooking_time_minutes;
        $preferences = $this->arrayValue($data['preferences'] ?? ($existing !== null ? SqlValue::json($existing->preferences ?? null) : []));
        $allergies = $this->arrayValue($data['allergies'] ?? ($existing !== null ? SqlValue::json($existing->allergies ?? null) : []));
        $health = $this->arrayValue($data['health_flags'] ?? ($existing !== null ? SqlValue::json($existing->health_flags ?? null) : []));
        $medications = $this->arrayValue($data['medications'] ?? ($existing !== null ? SqlValue::json($existing->medications ?? null) : []));
        $schedule = $this->arrayValue($data['schedule_preferences'] ?? ($existing !== null ? SqlValue::json($existing->schedule_preferences ?? null) : []));
        $occupation = $this->arrayValue($data['occupation_schedule'] ?? ($existing !== null ? SqlValue::json($existing->occupation_schedule ?? null) : []));
        $kitchen = $this->arrayValue($data['kitchen_resources'] ?? ($existing !== null ? SqlValue::json($existing->kitchen_resources ?? null) : []));
        $foodAccess = $this->nullable($data['food_access_notes'] ?? $existing?->food_access_notes ?? null);
        $wizardAnswers = $this->arrayValue($data['mobile_wizard_answers'] ?? ($existing !== null ? SqlValue::json($existing->mobile_wizard_answers ?? null) : []));
        $adherence = is_numeric($data['adherence_score'] ?? null)
            ? min(100, max(0, (int) $data['adherence_score']))
            : $existing?->adherence_score;
        $complete = $goal !== null && $activity !== null && $meals >= 3;
        $values = [
            'status' => $complete ? 'COMPLETED' : 'INCOMPLETE',
            'goal' => $goal,
            'activity_level' => $activity,
            'dietary_pattern' => $dietaryPattern,
            'meals_per_day' => $meals,
            'budget_level' => $budget,
            'cooking_time_minutes' => $cooking,
            'preferences' => SqlValue::encode($preferences),
            'allergies' => SqlValue::encode($allergies),
            'health_flags' => SqlValue::encode($health),
            'medications' => SqlValue::encode($medications),
            'schedule_preferences' => SqlValue::encode($schedule),
            'mobile_wizard_answers' => SqlValue::encode($wizardAnswers),
            'occupation_schedule' => SqlValue::encode($occupation),
            'kitchen_resources' => SqlValue::encode($kitchen),
            'food_access_notes' => $foodAccess,
            'adherence_score' => $adherence,
            'completed_at' => $complete ? ($existing?->completed_at ?? now()) : null,
            'last_reviewed_at' => now(),
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($existing, $partnerId, $contactId, $values, $contact): void {
            if ($existing === null) {
                DB::table('nutrition_profiles')->insert(array_merge($values, [
                    'partner_id' => $partnerId,
                    'contact_id' => $contactId,
                    'created_at' => now(),
                ]));
            } else {
                DB::table('nutrition_profiles')->where('id', $existing->id)->update($values);
            }
            DB::table('timeline_activities')->insert([
                'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'type' => 'NUTRITION_PROFILE_UPDATED',
                'description' => 'Se actualizó el perfil alimentario de '.(string) $contact->name.'.',
                'metadata' => SqlValue::encode(['status' => $values['status']]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);

        return $this->contactWorkspace($contactId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function feedback(string $planId, array $data): array
    {
        $plan = DB::table('nutrition_plan_versions')
            ->where('id', $planId)
            ->where('partner_id', $this->partnerId())
            ->first();
        if ($plan === null) throw new \RuntimeException('Plan alimentario no encontrado.', 404);
        $this->authorizedContact((string) $plan->contact_id, true);
        $rating = is_numeric($data['adherence_rating'] ?? null)
            ? min(100, max(0, (int) $data['adherence_rating']))
            : null;

        DB::table('nutrition_plan_feedback')->insert([
            'plan_id' => $planId,
            'partner_id' => $this->partnerId(),
            'contact_id' => (string) $plan->contact_id,
            'adherence_rating' => $rating,
            'favorite_pill_ids' => SqlValue::encode($this->arrayValue($data['favorite_pill_ids'] ?? [])),
            'rejected_pill_ids' => SqlValue::encode($this->arrayValue($data['rejected_pill_ids'] ?? [])),
            'notes' => $this->nullable($data['notes'] ?? null),
            'created_by_user_id' => $this->context->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($rating !== null) {
            DB::table('nutrition_profiles')
                ->where('partner_id', $this->partnerId())
                ->where('contact_id', (string) $plan->contact_id)
                ->update(['adherence_score' => $rating, 'updated_at' => now()]);
        }
        return $this->plans->showPlan($planId);
    }

    private function applyRelatedContactVisibility(Builder $query, string $contactColumn): void
    {
        $partnerId = $this->partnerId();
        $query->whereExists(function (Builder $contact) use ($partnerId, $contactColumn): void {
            $contact->selectRaw('1')
                ->from('contacts as visible_contact')
                ->whereColumn('visible_contact.id', $contactColumn)
                ->where('visible_contact.partner_id', $partnerId)
                ->whereNull('visible_contact.deleted_at');

            if ($this->context->role === 'ADVISOR') {
                $contact->where('visible_contact.assigned_advisor_id', $this->context->userId);
                return;
            }

            if ($this->context->role === 'TEAM_LEADER') {
                $scope = new TeamAccessScope($this->context);
                $teamIds = $scope->teamIds();
                $userIds = $scope->memberUserIds();
                if ($teamIds === [] && $userIds === []) {
                    $contact->whereRaw('1 = 0');
                    return;
                }
                $contact->where(function (Builder $access) use ($teamIds, $userIds): void {
                    if ($teamIds !== []) $access->whereIn('visible_contact.origin_team_id', $teamIds);
                    if ($userIds !== []) {
                        $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                        $access->{$method}('visible_contact.assigned_advisor_id', $userIds);
                    }
                });
            }
        });
    }

    private function authorizedContact(string $contactId, bool $write = false): object
    {
        $contact = DB::table('contacts')
            ->where('id', $contactId)
            ->where('partner_id', $this->partnerId())
            ->whereNull('deleted_at')
            ->first();
        if ($contact === null) throw new \RuntimeException('Prospecto no encontrado.', 404);
        if ($this->context->role === 'ADVISOR' && (string) $contact->assigned_advisor_id !== $this->context->userId) {
            throw new \RuntimeException('Prospecto fuera de tu responsabilidad.', 403);
        }
        if ($this->context->role === 'TEAM_LEADER' && !(new TeamAccessScope($this->context))->canAccessContact($contact)) {
            throw new \RuntimeException('Prospecto fuera de tus equipos.', 403);
        }
        return $contact;
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(object $contact, ?object $profile, array $raw, array $calculated): array
    {
        return [
            'contact_id' => (string) $contact->id,
            'age' => $contact->age !== null ? (int) $contact->age : null,
            'gender' => (string) ($contact->gender ?? ''),
            'height_cm' => $contact->height_cm !== null ? (float) $contact->height_cm : null,
            'weight_kg' => $this->metric($raw, $calculated, ['peso_kg', 'weight_kg', 'weight']),
            'bmi' => $this->metric($calculated, $raw, ['imc', 'bmi']),
            'body_fat_percent' => $this->metric($calculated, $raw, ['grasa_porcentaje', 'body_fat_percent', 'fat_percent']),
            'lean_mass_kg' => $this->metric($calculated, $raw, ['masa_magra_kg', 'lean_mass_kg', 'muscle_mass_kg']),
            'bmr_kcal' => $this->metric($calculated, $raw, ['bmr_kcal', 'bmr', 'metabolic_rate']),
            'goal' => (string) ($profile->goal ?? ''),
            'activity_level' => (string) ($profile->activity_level ?? ''),
            'meals_per_day' => $profile?->meals_per_day !== null ? (int) $profile->meals_per_day : 4,
            'dietary_pattern' => (string) ($profile->dietary_pattern ?? ''),
            'budget_level' => (string) ($profile->budget_level ?? ''),
            'cooking_time_minutes' => $profile?->cooking_time_minutes !== null ? (int) $profile->cooking_time_minutes : null,
            'preferences' => $profile !== null ? SqlValue::json($profile->preferences ?? null) : [],
            'allergies' => $profile !== null ? SqlValue::json($profile->allergies ?? null) : [],
            'health_flags' => $profile !== null ? SqlValue::json($profile->health_flags ?? null) : [],
            'medications' => $profile !== null ? SqlValue::json($profile->medications ?? null) : [],
            'schedule_preferences' => $profile !== null ? SqlValue::json($profile->schedule_preferences ?? null) : [],
            'mobile_wizard_answers' => $profile !== null ? SqlValue::json($profile->mobile_wizard_answers ?? null) : [],
            'occupation_schedule' => $profile !== null ? SqlValue::json($profile->occupation_schedule ?? null) : [],
            'kitchen_resources' => $profile !== null ? SqlValue::json($profile->kitchen_resources ?? null) : [],
            'food_access_notes' => (string) ($profile->food_access_notes ?? ''),
            'adherence_score' => $profile?->adherence_score !== null ? (int) $profile->adherence_score : null,
        ];
    }

    /** @return array<string,mixed> */
    private function profileArray(?object $profile, array $snapshot): array
    {
        return [
            'exists' => $profile !== null,
            'status' => (string) ($profile->status ?? 'INCOMPLETE'),
            'goal' => $snapshot['goal'],
            'activity_level' => $snapshot['activity_level'],
            'meals_per_day' => $snapshot['meals_per_day'],
            'dietary_pattern' => $snapshot['dietary_pattern'],
            'budget_level' => $snapshot['budget_level'],
            'cooking_time_minutes' => $snapshot['cooking_time_minutes'],
            'preferences' => $snapshot['preferences'],
            'allergies' => $snapshot['allergies'],
            'health_flags' => $snapshot['health_flags'],
            'medications' => $snapshot['medications'],
            'schedule_preferences' => $snapshot['schedule_preferences'],
            'mobile_wizard_answers' => $snapshot['mobile_wizard_answers'],
            'occupation_schedule' => $snapshot['occupation_schedule'],
            'kitchen_resources' => $snapshot['kitchen_resources'],
            'food_access_notes' => $snapshot['food_access_notes'],
            'adherence_score' => $snapshot['adherence_score'],
            'last_reviewed_at' => $profile?->last_reviewed_at,
        ];
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b @param array<int,string> $keys */
    private function metric(array $a, array $b, array $keys): ?float
    {
        foreach ([$a, $b] as $source) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) return (float) $source[$key];
            }
        }
        return null;
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') return [];
            if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) return $decoded;
            }
            return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $value) ?: [])));
        }
        return [];
    }

    private function partnerId(): string
    {
        return $this->context->partnerId
            ?? throw new \RuntimeException('El usuario no pertenece a un socio.', 403);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
