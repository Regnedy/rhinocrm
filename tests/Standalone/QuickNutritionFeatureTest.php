<?php

declare(strict_types=1);

function expectTrue(bool $value, string $message): void {
    if (!$value) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$snapshotFile = __DIR__.'/../../app/Modules/NutritionEngine/Support/QuickNutritionMeasurementSnapshot.php';
$recommendationFile = __DIR__.'/../../app/Modules/NutritionEngine/Support/QuickNutritionRecommendations.php';
expectTrue(is_file($snapshotFile), 'Debe existir QuickNutritionMeasurementSnapshot.php');
expectTrue(is_file($recommendationFile), 'Debe existir QuickNutritionRecommendations.php');
require_once $snapshotFile;
require_once $recommendationFile;

use App\Modules\NutritionEngine\Support\QuickNutritionMeasurementSnapshot;
use App\Modules\NutritionEngine\Support\QuickNutritionRecommendations;

$metrics = QuickNutritionMeasurementSnapshot::fromArrays(
    raw: ['peso_kg' => 87.4, 'impedancia_ohm' => 512],
    calculated: [
        'imc' => 29.8,
        'grasa_porcentaje' => 31.2,
        'masa_magra_kg' => 60.1,
        'musculo_porcentaje' => 43.2,
        'grasa_subcutanea_porcentaje' => 25.5,
        'grasa_visceral' => 13,
        'agua_porcentaje' => 42.1,
        'musculo_esqueletico_porcentaje' => 36.7,
        'masa_muscular_kg' => 57.4,
        'masa_osea_kg' => 3.1,
        'proteina_porcentaje' => 15.8,
        'bmr_kcal' => 1685,
        'edad_metabolica' => 49,
    ],
    person: ['age' => 39, 'gender' => 'M', 'height_cm' => 171.2],
);
expectTrue($metrics['weight_kg'] === 87.4, 'Debe reutilizar peso del Kiosco');
expectTrue($metrics['visceral_fat'] === 13.0, 'Debe reutilizar grasa visceral');
expectTrue($metrics['body_water_percent'] === 42.1, 'Debe reutilizar agua corporal');
expectTrue($metrics['metabolic_age'] === 49.0, 'Debe reutilizar edad metabólica');
expectTrue(count(array_filter($metrics, static fn ($value) => $value !== null && $value !== '')) >= 15, 'Debe conservar el snapshot biométrico completo');

$recommendations = QuickNutritionRecommendations::fromMetrics($metrics);
expectTrue(($recommendations['hydration']['is_low'] ?? false) === true, 'Agua baja debe activar recomendación de hidratación');
expectTrue((float) ($recommendations['hydration']['target_liters'] ?? 0) > 2.5, 'La meta de agua debe adaptarse al peso y agua baja');
expectTrue(str_contains(strtolower((string) ($recommendations['reset']['text'] ?? '')), 'reinicio'), 'Debe incluir recomendación de reinicio de hábitos');
expectTrue(str_contains(strtolower((string) ($recommendations['reset']['text'] ?? '')), 'desintoxic'), 'Debe conservar la referencia solicitada a desintoxicación con contexto seguro');

$routes = file_get_contents(__DIR__.'/../../routes/api_v1.php');
expectTrue(str_contains($routes, 'quick-nutrition'), 'Debe existir endpoint quick-nutrition para Kiosco/CRM');
expectTrue(str_contains($routes, "plans/{id}/images"), 'Debe existir endpoint para imágenes del plan');

$template = file_get_contents(__DIR__.'/../../resources/views/reports/nutrition-quick-plan.blade.php');
expectTrue(str_contains($template, 'termometro-imc'), 'El plan rápido debe conservar termómetro IMC estilo FitDoctor');
expectTrue(str_contains($template, 'Datos biométricos del pesaje'), 'Los biométricos deben aparecer arriba del PDF');
expectTrue(str_contains($template, 'Desintoxicación / reinicio'), 'Debe incluir el bloque de reinicio solicitado');

$pdfService = file_get_contents(__DIR__.'/../../app/Modules/NutritionEngine/Services/NutritionPlanPdfService.php');
expectTrue(str_contains($pdfService, 'CampaignAccessService'), 'La descarga de plan rápido debe respetar acceso colaborativo del evento');
expectTrue(str_contains($pdfService, 'measurement_campaign_id'), 'La autorización debe evaluarse contra el campaign_id de la medición');
expectTrue(str_contains($pdfService, 'canAccessQuickCampaignPlan'), 'La excepción cross-partner debe quedar limitada a planes rápidos de campañas autorizadas');

fwrite(STDOUT, "PASS: Quick Nutrition feature contract\n");
