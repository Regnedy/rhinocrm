<?php

namespace App\Modules\CRM\Services;

use App\Support\Database\SqlValue;

final class ContactMeasurementPresentationService
{
    /**
     * Stable presentation contract shared by Rhino CRM web and the future mobile app.
     * Metric values are reported by the scale unless `derived` is true.
     */
    private const METRICS = [
        'weight_kg' => [
            'label' => 'Peso', 'short_label' => 'Peso', 'unit' => 'kg', 'decimals' => 1, 'icon' => 'scale',
            'raw' => ['peso_kg', 'weight_kg', 'weight'], 'calculated' => [],
            'description' => 'Peso corporal reportado por la báscula.',
        ],
        'bmi' => [
            'label' => 'IMC', 'short_label' => 'IMC', 'unit' => '', 'decimals' => 1, 'icon' => 'bmi',
            'raw' => ['imc', 'bmi'], 'calculated' => ['imc', 'bmi'],
            'description' => 'Relación entre peso y estatura utilizada como referencia general.',
        ],
        'body_fat_percent' => [
            'label' => 'Grasa corporal', 'short_label' => 'Grasa', 'unit' => '%', 'decimals' => 1, 'icon' => 'body-fat',
            'raw' => ['grasa_porcentaje', 'body_fat_percent', 'fat_percent'], 'calculated' => ['grasa_porcentaje', 'body_fat_percent', 'fat_percent'],
            'description' => 'Porcentaje estimado de grasa corporal reportado por la báscula.',
        ],
        'muscle_percent' => [
            'label' => 'Músculo', 'short_label' => 'Músculo', 'unit' => '%', 'decimals' => 1, 'icon' => 'muscle',
            'raw' => ['musculo_porcentaje', 'muscle_percent'], 'calculated' => ['musculo_porcentaje', 'muscle_percent'],
            'description' => 'Porcentaje estimado de masa muscular reportado por la báscula.',
        ],
        'lean_mass_kg' => [
            'label' => 'Masa libre de grasa', 'short_label' => 'Masa magra', 'unit' => 'kg', 'decimals' => 1, 'icon' => 'lean-mass',
            'raw' => ['masa_magra_kg', 'lean_body_mass_kg', 'fat_free_mass_kg'], 'calculated' => ['masa_magra_kg', 'lean_body_mass_kg', 'fat_free_mass_kg'],
            'description' => 'Peso estimado del cuerpo sin la masa grasa.',
        ],
        'subcutaneous_fat_percent' => [
            'label' => 'Grasa subcutánea', 'short_label' => 'Grasa subcutánea', 'unit' => '%', 'decimals' => 1, 'icon' => 'subcutaneous-fat',
            'raw' => ['grasa_subcutanea_porcentaje', 'subcutaneous_fat_percent'], 'calculated' => ['grasa_subcutanea_porcentaje', 'subcutaneous_fat_percent'],
            'description' => 'Porcentaje estimado de grasa localizada bajo la piel.',
        ],
        'visceral_fat' => [
            'label' => 'Grasa visceral', 'short_label' => 'Grasa visceral', 'unit' => '', 'decimals' => 1, 'icon' => 'visceral-fat',
            'raw' => ['grasa_visceral', 'visceral_fat'], 'calculated' => ['grasa_visceral', 'visceral_fat'],
            'description' => 'Índice estimado de grasa visceral reportado por la báscula.',
        ],
        'water_percent' => [
            'label' => 'Agua corporal', 'short_label' => 'Agua', 'unit' => '%', 'decimals' => 1, 'icon' => 'water',
            'raw' => ['agua_porcentaje', 'water_percent', 'body_water_percent'], 'calculated' => ['agua_porcentaje', 'water_percent', 'body_water_percent'],
            'description' => 'Porcentaje estimado de agua corporal reportado por la báscula.',
        ],
        'skeletal_muscle_percent' => [
            'label' => 'Músculo esquelético', 'short_label' => 'Músculo esquelético', 'unit' => '%', 'decimals' => 1, 'icon' => 'skeletal-muscle',
            'raw' => ['musculo_esqueletico_porcentaje', 'skeletal_muscle_percent'], 'calculated' => ['musculo_esqueletico_porcentaje', 'skeletal_muscle_percent'],
            'description' => 'Porcentaje estimado de músculo esquelético reportado por la báscula.',
        ],
        'muscle_mass_kg' => [
            'label' => 'Masa muscular', 'short_label' => 'Masa muscular', 'unit' => 'kg', 'decimals' => 1, 'icon' => 'muscle-mass',
            'raw' => ['masa_muscular_kg', 'muscle_mass_kg'], 'calculated' => ['masa_muscular_kg', 'muscle_mass_kg'],
            'description' => 'Masa muscular estimada reportada por la báscula.',
        ],
        'bone_mass_kg' => [
            'label' => 'Masa ósea', 'short_label' => 'Masa ósea', 'unit' => 'kg', 'decimals' => 1, 'icon' => 'bone',
            'raw' => ['masa_osea_kg', 'bone_mass_kg'], 'calculated' => ['masa_osea_kg', 'bone_mass_kg'],
            'description' => 'Masa ósea estimada reportada por la báscula.',
        ],
        'protein_percent' => [
            'label' => 'Proteína corporal', 'short_label' => 'Proteína', 'unit' => '%', 'decimals' => 1, 'icon' => 'protein',
            'raw' => ['proteina_porcentaje', 'protein_percent'], 'calculated' => ['proteina_porcentaje', 'protein_percent'],
            'description' => 'Porcentaje estimado de proteína corporal reportado por la báscula.',
        ],
        'bmr_kcal' => [
            'label' => 'Metabolismo basal', 'short_label' => 'Metabolismo basal', 'unit' => 'kcal/día', 'decimals' => 0, 'icon' => 'energy',
            'raw' => ['bmr_kcal', 'basal_metabolic_rate'], 'calculated' => ['bmr_kcal', 'basal_metabolic_rate'],
            'description' => 'Estimación de energía basal calculada por la báscula.',
        ],
        'metabolic_age' => [
            'label' => 'Edad metabólica', 'short_label' => 'Edad metabólica', 'unit' => 'años', 'decimals' => 0, 'icon' => 'metabolic-age',
            'raw' => ['edad_metabolica', 'metabolic_age'], 'calculated' => ['edad_metabolica', 'metabolic_age'],
            'description' => 'Edad metabólica estimada por el algoritmo de la báscula.',
        ],
    ];

    /** @var list<string> */
    private const SERIES_METRICS = [
        'weight_kg', 'bmi', 'body_fat_percent', 'muscle_percent', 'visceral_fat', 'water_percent',
    ];

    public function present(object|array $contact, iterable $measurements): array
    {
        $contactData = $this->toArray($contact);
        $history = [];
        foreach ($measurements as $measurement) {
            $history[] = $this->measurement($measurement, $contactData);
        }

        $latest = $history[0] ?? null;
        $previous = $history[1] ?? null;
        if ($latest !== null) {
            $latest['metrics'] = $this->withTrends($latest['metrics'], $previous['metrics'] ?? []);
        }

        return [
            'schema_version' => 1,
            'contract' => 'rhino.contact.measurement-dashboard',
            'contact' => [
                'id' => (string) ($contactData['id'] ?? $contactData['contact_id'] ?? ''),
                'name' => (string) ($contactData['name'] ?? ''),
                'age' => $this->nullableInteger($contactData['age'] ?? null),
                'gender' => $contactData['gender'] ?? null,
                'height_cm' => $this->nullableNumber($contactData['height_cm'] ?? null),
            ],
            'latest' => $latest,
            'weight_scale' => $latest === null ? null : $this->weightScale(
                $this->nullableNumber($contactData['height_cm'] ?? null),
                $this->metricValue($latest['metrics'], 'weight_kg'),
            ),
            'series' => $this->series($history),
            'history' => $history,
            'summary' => [
                'measurements_count' => count($history),
                'latest_measurement_at' => $latest['measured_at'] ?? null,
                'available_metric_codes' => $latest === null ? [] : array_values(array_map(
                    static fn (array $metric): string => (string) $metric['code'],
                    $latest['metrics'],
                )),
            ],
            'presentation' => [
                'title' => 'Resultados del pesaje',
                'subtitle' => 'Composición corporal e historial de mediciones.',
                'disclaimer' => 'Los valores son informativos y dependen del modelo y algoritmo de la báscula. Deben interpretarse junto con el contexto de la persona.',
            ],
        ];
    }

    private function measurement(object|array $row, array $contact): array
    {
        $data = $this->toArray($row);
        $raw = SqlValue::json($data['biometrics_raw'] ?? null);
        $calculated = SqlValue::json($data['biometrics_calculated'] ?? null);
        $metrics = [];

        foreach (self::METRICS as $code => $definition) {
            $value = $this->firstNumber($calculated, $definition['calculated']);
            if ($value === null) $value = $this->firstNumber($raw, $definition['raw']);
            $derived = false;

            if ($code === 'bmi' && $value === null) {
                $weight = $this->firstNumber($raw, self::METRICS['weight_kg']['raw']);
                $heightCm = $this->nullableNumber($contact['height_cm'] ?? null);
                if ($weight !== null && $heightCm !== null && $heightCm > 0) {
                    $heightM = $heightCm / 100;
                    $value = $weight / ($heightM * $heightM);
                    $derived = true;
                }
            }

            if ($code === 'lean_mass_kg' && $value === null) {
                $weight = $this->firstNumber($raw, self::METRICS['weight_kg']['raw']);
                $bodyFat = $this->firstNumber($calculated, self::METRICS['body_fat_percent']['calculated'])
                    ?? $this->firstNumber($raw, self::METRICS['body_fat_percent']['raw']);
                if ($weight !== null && $bodyFat !== null && $bodyFat >= 0 && $bodyFat <= 100) {
                    $value = $weight * (1 - ($bodyFat / 100));
                    $derived = true;
                }
            }

            if ($value === null) continue;
            $decimals = (int) $definition['decimals'];
            $metrics[] = [
                'code' => $code,
                'label' => $definition['label'],
                'short_label' => $definition['short_label'],
                'value' => round($value, $decimals),
                'unit' => $definition['unit'],
                'decimals' => $decimals,
                'icon' => $definition['icon'],
                'description' => $definition['description'],
                'derived' => $derived,
                'tone' => 'neutral',
                'reference_code' => null,
                'reference_label' => null,
                'trend' => null,
            ];
        }

        $weight = $this->metricValue($metrics, 'weight_kg');
        $bmi = $this->metricValue($metrics, 'bmi');
        $heightCm = $this->nullableNumber($contact['height_cm'] ?? null);
        $reference = $this->weightReference($heightCm, $weight, $bmi);
        if ($reference !== null) {
            foreach ($metrics as &$metric) {
                if (in_array($metric['code'], ['weight_kg', 'bmi'], true)) {
                    $metric['tone'] = $reference['tone'];
                    $metric['reference_code'] = $reference['code'];
                    $metric['reference_label'] = $reference['label'];
                }
            }
            unset($metric);
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'measured_at' => $data['timestamp_local'] ?? $data['measured_at'] ?? null,
            'received_at' => $data['received_at'] ?? null,
            'metrics' => $metrics,
            'source' => [
                'device_id' => $data['device_id'] ?? null,
                'device_name' => $data['device_name'] ?? $data['mac_address'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'location_name' => $data['location_name'] ?? null,
                'campaign_id' => $data['campaign_id'] ?? null,
                'campaign_name' => $data['campaign_name'] ?? null,
                'captured_by_user_id' => $data['captured_by_user_id'] ?? null,
                'captured_by_name' => $data['captured_by_name'] ?? null,
            ],
        ];
    }

    private function withTrends(array $latestMetrics, array $previousMetrics): array
    {
        $previous = [];
        foreach ($previousMetrics as $metric) $previous[(string) $metric['code']] = $metric;

        foreach ($latestMetrics as &$metric) {
            $prior = $previous[(string) $metric['code']] ?? null;
            if ($prior === null) continue;
            $delta = (float) $metric['value'] - (float) $prior['value'];
            $decimals = (int) $metric['decimals'];
            $epsilon = 1 / (10 ** max($decimals, 0));
            $metric['trend'] = [
                'previous_value' => $prior['value'],
                'delta' => round($delta, $decimals),
                'direction' => abs($delta) < $epsilon ? 'STABLE' : ($delta > 0 ? 'UP' : 'DOWN'),
            ];
        }
        unset($metric);
        return $latestMetrics;
    }

    private function series(array $history): array
    {
        $chronological = array_reverse($history);
        $series = [];
        foreach (self::SERIES_METRICS as $code) {
            $definition = self::METRICS[$code];
            $points = [];
            foreach ($chronological as $measurement) {
                $value = $this->metricValue($measurement['metrics'], $code);
                if ($value === null) continue;
                $points[] = [
                    'measurement_id' => $measurement['id'],
                    'measured_at' => $measurement['measured_at'],
                    'value' => $value,
                ];
            }
            if ($points === []) continue;
            $series[] = [
                'code' => $code,
                'label' => $definition['label'],
                'unit' => $definition['unit'],
                'decimals' => $definition['decimals'],
                'points' => $points,
            ];
        }
        return $series;
    }

    private function weightScale(?float $heightCm, ?float $weightKg): ?array
    {
        if ($heightCm === null || $heightCm <= 0 || $weightKg === null) return null;
        $heightM = $heightCm / 100;
        $low = 18.5 * $heightM * $heightM;
        $standard = 25 * $heightM * $heightM;
        $elevated = 30 * $heightM * $heightM;
        $minimum = max(1, floor(($low * 0.72) * 10) / 10);
        $maximum = ceil(max($elevated * 1.28, $weightKg * 1.12) * 10) / 10;
        $span = max(0.1, $maximum - $minimum);
        $marker = min(100, max(0, (($weightKg - $minimum) / $span) * 100));
        $reference = $this->weightReference($heightCm, $weightKg, null);

        return [
            'value' => round($weightKg, 1),
            'unit' => 'kg',
            'minimum' => round($minimum, 1),
            'maximum' => round($maximum, 1),
            'marker_percent' => round($marker, 2),
            'reference_code' => $reference['code'] ?? null,
            'reference_label' => $reference['label'] ?? null,
            'segments' => [
                ['code' => 'BELOW_REFERENCE', 'label' => 'Bajo', 'boundary_kg' => round($low, 1), 'tone' => 'info'],
                ['code' => 'REFERENCE', 'label' => 'Estándar', 'boundary_kg' => round($standard, 1), 'tone' => 'success'],
                ['code' => 'ELEVATED', 'label' => 'Alto', 'boundary_kg' => round($elevated, 1), 'tone' => 'warning'],
                ['code' => 'VERY_ELEVATED', 'label' => 'Muy alto', 'boundary_kg' => null, 'tone' => 'danger'],
            ],
        ];
    }

    private function weightReference(?float $heightCm, ?float $weightKg, ?float $bmi): ?array
    {
        if ($bmi === null && $heightCm !== null && $heightCm > 0 && $weightKg !== null) {
            $heightM = $heightCm / 100;
            $bmi = $weightKg / ($heightM * $heightM);
        }
        if ($bmi === null) return null;
        if ($bmi < 18.5) return ['code' => 'BELOW_REFERENCE', 'label' => 'Bajo', 'tone' => 'info'];
        if ($bmi < 25) return ['code' => 'REFERENCE', 'label' => 'Estándar', 'tone' => 'success'];
        if ($bmi < 30) return ['code' => 'ELEVATED', 'label' => 'Alto', 'tone' => 'warning'];
        return ['code' => 'VERY_ELEVATED', 'label' => 'Muy alto', 'tone' => 'danger'];
    }

    private function metricValue(array $metrics, string $code): ?float
    {
        foreach ($metrics as $metric) {
            if (($metric['code'] ?? null) === $code) return (float) $metric['value'];
        }
        return null;
    }

    private function firstNumber(array $source, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) continue;
            $value = $this->nullableNumber($source[$key]);
            if ($value !== null) return $value;
        }
        return null;
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        return (float) $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        return (int) $value;
    }

    private function toArray(object|array $value): array
    {
        return is_array($value) ? $value : (array) $value;
    }
}
