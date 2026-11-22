<?php

namespace App\Modules\NutritionEngine\Services;

final class NutritionSafetyService
{
    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function evaluate(array $profile): array
    {
        $reasons = [];
        $missing = [];
        $age = $this->integer($profile['age'] ?? null);
        $height = $this->number($profile['height_cm'] ?? null);
        $weight = $this->number($profile['weight_kg'] ?? null);
        $goal = $this->normalizeGoal((string) ($profile['goal'] ?? ''));
        $activity = $this->normalizeActivity((string) ($profile['activity_level'] ?? ''));

        if ($age === null) $missing[] = 'edad';
        if ($height === null) $missing[] = 'estatura';
        if ($weight === null) $missing[] = 'peso';
        if ($activity === 'UNKNOWN') $missing[] = 'actividad física';
        if (trim((string) ($profile['goal'] ?? '')) === '') $missing[] = 'objetivo';

        if ($age !== null && ($age < 18 || $age > 70)) {
            $reasons[] = 'La edad declarada requiere revisión profesional antes de personalizar cantidades.';
        }

        $riskText = $this->lower(implode(' ', array_merge(
            $this->flatten($profile['health_flags'] ?? []),
            $this->flatten($profile['medications'] ?? []),
            $this->flatten($profile['allergies'] ?? []),
        )));
        $riskTerms = [
            'embarazo', 'embarazada', 'lactancia', 'amamant', 'menor de edad',
            'diabetes', 'insulina', 'renal', 'riñon', 'riñón', 'dialisis', 'diálisis',
            'hepatic', 'hepática', 'higado', 'hígado', 'anorexia', 'bulimia',
            'trastorno aliment', 'alergia grave', 'anafilax', 'hipertension no controlada',
            'hipertensión no controlada', 'cancer', 'cáncer', 'cirugia bariatr', 'cirugía bariátr',
        ];
        foreach ($riskTerms as $term) {
            if (str_contains($riskText, $term)) {
                $reasons[] = 'Existe una condición o antecedente declarado que requiere revisión profesional.';
                break;
            }
        }

        if ($this->flatten($profile['medications'] ?? []) !== []) {
            $reasons[] = 'Se registraron medicamentos; el plan debe revisarse antes de ajustar cantidades.';
        }

        $bmi = $this->number($profile['bmi'] ?? null);
        if ($bmi !== null && ($bmi < 16.0 || $bmi >= 40.0)) {
            $reasons[] = 'La relación peso-estatura está fuera del rango utilizado por el modo automático general.';
        }

        $status = $reasons !== []
            ? 'PROFESSIONAL_REVIEW_RECOMMENDED'
            : ($missing !== [] ? 'DATA_INCOMPLETE' : 'AUTOMATIC_GENERAL_GUIDANCE');

        return [
            'status' => $status,
            'requires_professional_review' => $reasons !== [],
            'allow_automatic_plan' => true,
            'allow_energy_estimate' => $reasons === [] && $missing === [],
            'missing_fields' => array_values(array_unique($missing)),
            'reasons' => array_values(array_unique($reasons)),
            'goal' => $goal,
            'activity_level' => $activity,
            'dietary_pattern' => $this->normalizePattern((string) ($profile['dietary_pattern'] ?? '')),
            'allergen_codes' => $this->allergenCodes($profile['allergies'] ?? []),
            'avoid_words' => $this->avoidWords($profile['preferences'] ?? []),
        ];
    }

    public function normalizeGoal(string $value): string
    {
        $value = $this->lower(trim($value));
        foreach (['bajar', 'perder', 'reducir', 'disminuir'] as $term) {
            if ($value !== '' && str_contains($value, $term)) return 'REDUCE';
        }
        if ($value !== '' && str_contains($value, 'control') && str_contains($value, 'peso')) {
            return 'REDUCE';
        }
        foreach (['aumentar', 'ganar', 'masa muscular', 'subir'] as $term) {
            if ($value !== '' && str_contains($value, $term)) return 'GAIN';
        }
        foreach (['mejorar hábito', 'mejorar habito', 'bienestar', 'alimentación general', 'alimentacion general'] as $term) {
            if ($value !== '' && str_contains($value, $term)) return 'GENERAL_WELLNESS';
        }
        return $value === '' ? 'GENERAL_WELLNESS' : 'MAINTAIN';
    }

    public function normalizeActivity(string $value): string
    {
        $value = $this->lower(trim($value));
        if ($value === '') return 'UNKNOWN';
        if (str_contains($value, 'sedent') || str_contains($value, 'baj')) return 'LOW';
        if (str_contains($value, 'alt') || str_contains($value, 'intens') || str_contains($value, '4+')) return 'HIGH';
        return 'MODERATE';
    }

    public function normalizePattern(string $value): string
    {
        $value = $this->lower(trim($value));
        if (str_contains($value, 'vegan')) return 'VEGAN';
        if (str_contains($value, 'vegetarian')) return 'VEGETARIAN';
        return 'OMNIVORE';
    }

    /** @return array<int,string> */
    public function allergenCodes(mixed $value): array
    {
        $text = $this->lower(implode(' ', $this->flatten($value)));
        $map = [
            'DAIRY' => ['leche', 'lactosa', 'lácteo', 'lacteo', 'queso', 'yogur'],
            'EGG' => ['huevo'],
            'PEANUT' => ['cacahuate'],
            'TREE_NUTS' => ['nuez', 'almendra', 'frutos secos'],
            'GLUTEN' => ['gluten', 'trigo', 'celiac', 'celíac'],
            'FISH' => ['pescado', 'atún', 'atun', 'sardina'],
            'SHELLFISH' => ['marisco', 'camarón', 'camaron'],
            'SOY' => ['soya', 'soja', 'tofu'],
            'SESAME' => ['sésamo', 'sesamo', 'ajonjolí', 'ajonjoli', 'tahini'],
        ];
        $codes = [];
        foreach ($map as $code => $terms) {
            foreach ($terms as $term) {
                if (str_contains($text, $term)) {
                    $codes[] = $code;
                    break;
                }
            }
        }
        return array_values(array_unique($codes));
    }

    /** @return array<int,string> */
    public function avoidWords(mixed $preferences): array
    {
        if (!is_array($preferences)) return [];
        $values = [];
        foreach (['avoid', 'dislikes', 'excluded_foods', 'foods_to_avoid', 'no_consume'] as $key) {
            if (array_key_exists($key, $preferences)) {
                $values = array_merge($values, $this->flatten($preferences[$key]));
            }
        }
        return array_values(array_unique(array_filter(array_map(
            fn (string $value): string => trim($this->lower($value)),
            $values,
        ), fn (string $value): bool => $value !== '' && $this->length($value) >= 3 && $this->length($value) <= 50)));
    }

    /** @return array<int,string> */
    public function flatten(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) return [];
        if (is_scalar($value)) return [trim((string) $value)];
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $key => $item) {
            if (is_bool($item)) {
                if ($item && is_string($key)) $result[] = $key;
                continue;
            }
            foreach ($this->flatten($item) as $nested) $result[] = $nested;
        }
        return array_values(array_unique(array_filter($result, static fn (string $item): bool => $item !== '')));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
