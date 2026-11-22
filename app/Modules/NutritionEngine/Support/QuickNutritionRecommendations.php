<?php

namespace App\Modules\NutritionEngine\Support;

final class QuickNutritionRecommendations
{
    /** @param array<string,mixed> $metrics @return array<string,mixed> */
    public static function fromMetrics(array $metrics): array
    {
        $weight = self::number($metrics['weight_kg'] ?? null);
        $water = self::number($metrics['body_water_percent'] ?? null);
        $gender = strtoupper(trim((string) ($metrics['gender'] ?? '')));
        $waterFloor = $gender === 'M' ? 50.0 : ($gender === 'F' ? 45.0 : 47.5);
        $waterLow = $water !== null && $water < $waterFloor;

        $liters = $weight !== null ? $weight * ($waterLow ? 0.035 : 0.030) : ($waterLow ? 2.7 : 2.2);
        $liters = round(min(4.5, max(1.5, $liters)), 1);

        $bmi = self::number($metrics['bmi'] ?? null);
        $visceral = self::number($metrics['visceral_fat'] ?? null);
        $bodyFat = self::number($metrics['body_fat_percent'] ?? null);
        $resetRecommended = $waterLow || ($bmi !== null && $bmi >= 25.0) || ($visceral !== null && $visceral >= 10.0);

        $signals = [];
        if ($waterLow) {
            $signals[] = 'El porcentaje de agua corporal registrado está por debajo del rango de referencia usado por RHINO para orientación general.';
        }
        if ($bmi !== null && $bmi >= 25.0) {
            $signals[] = 'El IMC se encuentra por encima del rango de referencia de peso normal.';
        }
        if ($visceral !== null && $visceral >= 10.0) {
            $signals[] = 'La lectura de grasa visceral amerita priorizar hábitos consistentes y seguimiento.';
        }
        if ($bodyFat !== null && $bodyFat >= 30.0) {
            $signals[] = 'El porcentaje de grasa corporal sugiere priorizar alimentación con buena densidad nutricional y actividad progresiva.';
        }

        return [
            'hydration' => [
                'is_low' => $waterLow,
                'measured_percent' => $water,
                'reference_floor_percent' => $waterFloor,
                'target_liters' => $liters,
                'text' => $waterLow
                    ? "Tu lectura de agua corporal fue baja. Usa agua simple como bebida principal y apunta de forma orientativa a {$liters} L al día, ajustando por clima, actividad, tolerancia y recomendaciones médicas personales."
                    : "Mantén una hidratación constante. Como referencia práctica, apunta a aproximadamente {$liters} L de agua simple al día, ajustando por clima, actividad y tolerancia.",
            ],
            'reset' => [
                'recommended' => $resetRecommended,
                'title' => 'Desintoxicación / reinicio de hábitos',
                'text' => $resetRecommended
                    ? 'RHINO recomienda una fase inicial de reinicio de hábitos —también presentada comercialmente como “desintoxicación”— basada en agua simple, verduras, frutas enteras, proteínas adecuadas y alimentos mínimamente procesados, reduciendo bebidas azucaradas, alcohol y ultraprocesados. No significa “eliminar toxinas” ni sustituye valoración médica.'
                    : 'Puedes usar una semana de reinicio de hábitos para ordenar horarios, hidratación y calidad de alimentos. En este plan, “desintoxicación” significa simplificar la alimentación y reducir ultraprocesados; no implica eliminar toxinas ni sustituye atención médica.',
            ],
            'signals' => $signals,
        ];
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
