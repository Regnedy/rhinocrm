<?php

namespace App\Modules\NutritionEngine\Support;

final class QuickNutritionMeasurementSnapshot
{
    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $calculated
     * @param array<string,mixed> $person
     * @return array<string,float|int|string|null>
     */
    public static function fromArrays(array $raw, array $calculated, array $person = []): array
    {
        return [
            'age' => self::integer($person['age'] ?? $person['edad'] ?? null),
            'gender' => self::text($person['gender'] ?? $person['sexo'] ?? null),
            'height_cm' => self::number($person['height_cm'] ?? $person['altura_cm'] ?? null),
            'weight_kg' => self::metric($raw, $calculated, ['peso_kg', 'weight_kg', 'weight']),
            'impedance_ohm' => self::metric($raw, $calculated, ['impedancia_ohm', 'impedance_ohm', 'impedance']),
            'bmi' => self::metric($calculated, $raw, ['imc', 'bmi']),
            'body_fat_percent' => self::metric($calculated, $raw, ['grasa_porcentaje', 'body_fat_percent', 'fat_percent']),
            'lean_mass_kg' => self::metric($calculated, $raw, ['masa_magra_kg', 'lean_body_mass_kg', 'lean_mass_kg']),
            'muscle_percent' => self::metric($calculated, $raw, ['musculo_porcentaje', 'muscle_percent']),
            'subcutaneous_fat_percent' => self::metric($calculated, $raw, ['grasa_subcutanea_porcentaje', 'subcutaneous_fat_percent']),
            'visceral_fat' => self::metric($calculated, $raw, ['grasa_visceral', 'visceral_fat']),
            'body_water_percent' => self::metric($calculated, $raw, ['agua_porcentaje', 'body_water_percent', 'water_percent']),
            'skeletal_muscle_percent' => self::metric($calculated, $raw, ['musculo_esqueletico_porcentaje', 'skeletal_muscle_percent']),
            'muscle_mass_kg' => self::metric($calculated, $raw, ['masa_muscular_kg', 'muscle_mass_kg']),
            'bone_mass_kg' => self::metric($calculated, $raw, ['masa_osea_kg', 'bone_mass_kg']),
            'protein_percent' => self::metric($calculated, $raw, ['proteina_porcentaje', 'protein_percent']),
            'bmr_kcal' => self::metric($calculated, $raw, ['bmr_kcal', 'basal_metabolic_rate', 'bmr', 'metabolic_rate']),
            'metabolic_age' => self::metric($calculated, $raw, ['edad_metabolica', 'metabolic_age']),
        ];
    }

    /** @param array<string,mixed> $first @param array<string,mixed> $second @param array<int,string> $keys */
    private static function metric(array $first, array $second, array $keys): ?float
    {
        foreach ([$first, $second] as $source) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && is_numeric($source[$key])) {
                    return round((float) $source[$key], 2);
                }
            }
        }
        return null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
